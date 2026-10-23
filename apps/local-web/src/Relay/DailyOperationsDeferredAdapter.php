<?php
declare(strict_types=1);
namespace Sokna\Local\Relay;
use PDO;
use Sokna\Shared\DailySupply;

/** Domain failures require review; infrastructure failures roll back and remain retryable. */
final class DailyOperationsDeferredAdapter
{
    public function __construct(private readonly PDO $pdo,private readonly OperationsRealtimeAdapter $operations,private readonly DeferredReceiptService $receipts,private readonly \Sokna\Local\Domain\Inventory\InventoryService $inventory){}
    public function resolveReview(int $id,string $decision,string $reason,array $user): array
    {
        $manager=$this->inventory->assertActor($user,'inventory_manage');
        if(!in_array($decision,['approve','reject'],true)||trim($reason)==='')throw new \DomainException('تصمیم و دلیل را ثبت کن.');
        $this->pdo->beginTransaction();
        try{
            $review=$this->receipts->reviewLockedTx($id);if(!$review||$review['kind']!=='operations.daily')throw new \DomainException('مورد بررسی پیدا نشد.');
            $original=json_decode($review['envelope_json'],true,512,JSON_THROW_ON_ERROR);if(($original['payload']['action']??'')==='expense_create'&&($manager['role']??'')!=='admin')throw new \DomainException('بررسی هزینه مربوط به دوره بسته فقط برای مدیر مالی سامانه فعال است.');
            if($decision==='reject')$out=$this->receipts->rejectReviewTx($review,(int)$manager['id'],$reason);
            else{
                $this->pdo->query('SELECT `cursor` FROM operations_command_clock WHERE id=1 FOR UPDATE')->fetchColumn();
                $e=json_decode($review['envelope_json'],true,512,JSON_THROW_ON_ERROR);
                foreach($e['depends_on']??[] as $dependency){$q=$this->pdo->prepare('SELECT state FROM deferred_work_receipts WHERE installation_id=? AND request_id=?');$q->execute([$review['installation_id'],$dependency]);if($q->fetchColumn()!=='committed')throw new \DomainException('ابتدا ثبت مرتبط قبلی را بررسی کن.');}
                $e['kind']='operations.mutate';$e['operations_remote']=true;$e['daily_review_allow_closed']=true;$result=$this->operations->dispatch($e);
                $out=$this->receipts->approveReviewTx($review,(int)$manager['id'],$reason,['business_cursor'=>$result['business_cursor'],'result'=>$result['result']]);
            }
            $this->pdo->commit();return $out;
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function dispatch(string $installation,array $e): array
    {
        if(($e['kind']??'')!=='operations.daily'||!in_array($e['payload']['action']??'',DailySupply::ACTIONS,true))throw new \InvalidArgumentException('Unsupported daily command');
        $this->pdo->beginTransaction();
        try{
            if($prior=$this->receipts->replayTx($installation,$e)){$this->pdo->commit();return $prior;}
            $this->pdo->query('SELECT `cursor` FROM operations_command_clock WHERE id=1 FOR UPDATE')->fetchColumn();
            $q=$this->pdo->prepare('SELECT id,role,active FROM users WHERE id=? FOR UPDATE');$q->execute([(int)substr((string)($e['actor_projection_id']??''),5)]);$actor=$q->fetch(PDO::FETCH_ASSOC)?:['id'=>0];
            $this->pdo->exec('SAVEPOINT daily_apply');
            try{
                foreach((array)($e['depends_on']??[]) as $dependency){$d=$this->pdo->prepare('SELECT state FROM deferred_work_receipts WHERE installation_id=? AND request_id=?');$d->execute([$installation,$dependency]);if($d->fetchColumn()!=='committed')throw new \DomainException('ثبت قبلی مرتبط هنوز پذیرفته نشده است؛ زنجیره خرید را مرور کن.');}
                $command=$e;$command['kind']='operations.mutate';$command['operations_remote']=true;
                // Preserve physical event time rather than the later application time.
                if(in_array($command['payload']['action'],['supply_purchase_record','supply_purchase_deliver','inventory_waste','expense_create'],true))$command['payload']['occurred_at']=$e['payload']['occurred_at']??$e['occurred_at'];
                $result=$this->operations->dispatch($command);
                $out=$this->receipts->committedTx($installation,$e,$actor,['business_cursor'=>$result['business_cursor'],'result'=>$result['result']]);
            }catch(\Sokna\Local\Domain\Supply\SupplyException|\Sokna\Local\Domain\Inventory\InventoryException|\Sokna\Local\Domain\Expenses\ExpenseException|\DomainException $failure){
                $this->pdo->exec('ROLLBACK TO SAVEPOINT daily_apply');
                $out=$this->receipts->reviewTx($installation,$e,$actor,property_exists($failure,'errorCode')?$failure->errorCode:'dependency_failed',$failure->getMessage(),'daily_operations');
            }
            $this->pdo->exec('RELEASE SAVEPOINT daily_apply');$this->pdo->commit();return $out;
        }catch(\Throwable $failure){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $failure;}
    }
}
