<?php
declare(strict_types=1);
namespace Sokna\Local\Relay;
use PDO;
use Sokna\Local\Domain\Inventory\{InventoryService,InventoryCountService,InventoryException};
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Domain\Operations\OperationsWorkspaceService;
final class OperationsRealtimeAdapter
{
    public function __construct(private readonly PDO $pdo,private readonly InventoryService $inventory,private readonly InventoryCountService $counts,private readonly SupplyService $supply,private readonly OperationsWorkspaceService $workspace,private readonly ?\Sokna\Local\Domain\Expenses\ExpenseService $expenses=null){}

    public function dispatch(array $envelope): array
    {
        $owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();
        try{$out=$this->dispatchTx($envelope);if($owns)$this->pdo->commit();return $out;}
        catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function recordExpenseTx(array $data,array $user,bool $allowClosed): array
    {
        if(($user['role']??'')!=='admin')throw new InventoryException('forbidden','ثبت هزینه فقط برای مدیر فعال است.',403);
        if(!$this->expenses)throw new \LogicException('Expense domain missing');
        $data['source_request_id']='operations-expense:'.hash('sha256',$data['request_id']);
        $data['amount']=InventoryService::moneyValue($data['amount']??'');
        return $this->expenses->createTx($data,(int)$user['id'],$allowClosed);
    }

    private function dispatchTx(array $envelope): array
    {
        if(($envelope['kind']??'')==='operations.mutate')$this->pdo->query('SELECT `cursor` FROM operations_command_clock WHERE id=1 FOR UPDATE')->fetchColumn();
        if(!preg_match('/^user:(\d+)$/D',(string)($envelope['actor_projection_id']??''),$m))throw new InventoryException('actor_invalid','حساب معتبر نیست.',403);
        $q=$this->pdo->prepare('SELECT id,role,active FROM users WHERE id=?');$q->execute([(int)$m[1]]);$user=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($user)||(int)$user['active']!==1)throw new InventoryException('actor_invalid','حساب فعال نیست.',403);
        $permissions=$this->inventory->permissions($user);
        if(!$permissions['can_view'])throw new InventoryException('forbidden','دسترسی انبار برای این حساب فعال نیست.',403);
        $data=is_array($envelope['payload']??null)?$envelope['payload']:[];$action=(string)($data['action']??'');
        if(in_array($action,['supply_receipt_cost_complete','supply_receipt_cost_correct','purchase_price_policy_save'],true)&&!$permissions['can_view_cost'])throw new InventoryException('forbidden','دسترسی هزینه برای این عملیات فعال نیست.',403);
        if($action==='purchase_price_policy_save'&&empty($permissions['can_manage']))throw new InventoryException('forbidden','تنظیم سیاست اعلان برای مدیر انبار فعال است.',403);
        if((string)($envelope['kind']??'')==='operations.query'){
            if($action!=='inventory_movements')throw new InventoryException('invalid_action','درخواست نمایش معتبر نیست.',422);
            $rows=$this->workspace->movementHistory($data,(int)($data['limit']??300));
            $filtered=\Sokna\Shared\OperationsSnapshotPolicy::apply(['inventory'=>['recent_movements'=>$rows]],$permissions['can_view_cost']);
            return ['success'=>true,'movements'=>$filtered['inventory']['recent_movements'],'history_scope'=>'local_query'];
        }
        if((string)($envelope['kind']??'')!=='operations.mutate')throw new InventoryException('unsupported_kind','عملیات معتبر نیست.',422);
        $id=trim((string)($envelope['request_id']??''));if($id===''||strlen($id)>96)throw new InventoryException('request_id_required','شناسه ثبت معتبر نیست.',422);
        // Trust the receipt envelope's identity, never a second client-supplied operation id.
        $data['request_id']=$id;$data['request_token']=substr(hash('sha256','operations:'.$id),0,32);$data['batch_token']=$data['request_token'];
        $remote=!empty($envelope['operations_remote']);
        if($remote&&$action==='count_start'&&($data['session_type']??'periodic')==='opening')throw new InventoryException('opening_local_only','موجودی اولیه را از داخل کافه ثبت کن.',409);
        if($remote&&in_array($action,['count_finalize','count_cancel'],true)){
            $check=$this->pdo->prepare('SELECT session_type FROM inventory_count_sessions WHERE id=?');$check->execute([(int)($data['session_id']??0)]);
            if($check->fetchColumn()==='opening')throw new InventoryException('opening_local_only','موجودی اولیه را از داخل کافه تکمیل کن.',409);
        }

        $logical=$data;foreach(['request_id','request_token','batch_token','operation_id','operation_created_at','csrf_token'] as $k)unset($logical[$k]);
        foreach(array_keys($logical) as $key)if(str_starts_with((string)$key,'_'))unset($logical[$key]);
        if(isset($logical['occurred_at'])&&($eventTime=strtotime((string)$logical['occurred_at']))!==false)$logical['occurred_at']=gmdate('Y-m-d\TH:i:s\Z',$eventTime);
        $hash=DeferredReceiptService::requestHash(['actor'=>(int)$user['id'],'payload'=>$logical]);
        $j=$this->pdo->prepare('SELECT request_hash,`cursor`,result_json FROM operations_command_journal WHERE request_id=?');$j->execute([$id]);$prior=$j->fetch(PDO::FETCH_ASSOC);
        if($prior&&!hash_equals($prior['request_hash'],$hash))throw new InventoryException('request_id_conflict','این شناسه قبلاً با محتوای دیگری ثبت شده است.',409);
        if($prior){$value=json_decode($prior['result_json'],true,512,JSON_THROW_ON_ERROR);$cursor=(int)$prior['cursor'];}
        else {
        if(isset($data['_quantity_base'])){
            try{$v=\Sokna\Shared\DailySupply::describe($this->workspace->snapshot($user),$data);}catch(\DomainException $e){throw new InventoryException('daily_masterdata_changed',$e->getMessage(),409);}
            if((int)$data['_quantity_base']!==$v['quantity_base']||($data['_base_unit']??'')!==$v['base_unit'])throw new InventoryException('daily_masterdata_changed','واحد یا مقدار از زمان مشاهده تغییر کرده است؛ ثبت را مرور کن.',409);
        }
        $value=match($action){
            'expense_create'=>$this->recordExpenseTx($data,$user,!empty($envelope['daily_review_allow_closed'])),
            'supply_purchase_record'=>$this->supply->recordPurchase($data,$user),
            'supply_purchase_cancel'=>$this->supply->cancelPurchase($data,$user),
            'supply_purchase_deliver'=>$this->supply->deliverPurchase($data,$user),
            'supply_need'=>$this->supply->upsertNeed($data,$user),
            'supply_prepare'=>$this->supply->prepare((string)($data['group_key']??''),isset($data['expected_uncommitted'])?(int)$data['expected_uncommitted']:null,$user),
            'supply_return'=>$this->supply->returnPreparing((string)($data['group_key']??''),(string)($data['outcome']??''),isset($data['expected_preparing'])?(int)$data['expected_preparing']:null,$user),
            'supply_cancel'=>$this->supply->cancelUncommitted((string)($data['group_key']??''),isset($data['expected_uncommitted'])?(int)$data['expected_uncommitted']:null,$user),
            'supply_receive'=>$this->supply->receive((string)($data['group_key']??''),$data,$user),
            'supply_receive_direct'=>$this->supply->receiveDirect($data,$user),
            'supply_receive_batch'=>$this->supply->receiveBatch((array)($data['lines']??[]),$data['batch_token'],$user),
            'supply_receipt_cost_complete'=>$this->supply->completeReceiptCost((int)($data['receipt_id']??0),$data['total_cost']??'',$user),
            'supply_receipt_cost_correct'=>$this->supply->correctReceiptCost($data,$user),
            'inventory_receive'=>$this->inventory->recordManualReceive($data,$user),
            'inventory_waste'=>$this->inventory->recordWaste($data,$user),
            'inventory_adjust'=>$this->inventory->recordManualAdjustment($data,$user),
            'count_start'=>$this->counts->start($data,$user),
            'count_update'=>$this->counts->updateLine($data,$user,$remote),
            'count_finalize'=>$this->counts->finalize((int)($data['session_id']??0),$user),
            'count_cancel'=>$this->counts->cancel((int)($data['session_id']??0),$user),
            'inventory_create'=>$this->inventory->createItem($data,$user),
            'inventory_update'=>$this->inventory->updateItem($data,$user),
            'inventory_copy'=>$this->inventory->copyItem((int)($data['item_id']??0),$user),
            'inventory_review'=>$this->inventory->reviewItem((int)($data['item_id']??0),(string)($data['decision']??''),$user),
            'inventory_category_save'=>$this->inventory->saveCategory($data,$user),
            'inventory_category_delete'=>$this->inventory->deleteCategory((string)($data['category_key']??''),$user),
            'purchase_unit_save'=>$this->inventory->savePurchaseUnit($data,$user),
            'purchase_price_policy_save'=>$this->supply->savePurchasePricePolicy($data,$user),
            default=>throw new InventoryException('invalid_action','عملیات این بخش پشتیبانی نمی‌شود.',422),
        };
        $this->pdo->exec('UPDATE operations_command_clock SET `cursor`=`cursor`+1 WHERE id=1');
        $cursor=(int)$this->pdo->query('SELECT `cursor` FROM operations_command_clock WHERE id=1')->fetchColumn();
        $this->pdo->prepare('INSERT INTO operations_command_journal(request_id,actor_user_id,request_hash,`cursor`,result_json) VALUES(?,?,?,?,?)')->execute([$id,(int)$user['id'],$hash,$cursor,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        }
        $response=['business_cursor'=>$cursor,'success'=>true,'result'=>\Sokna\Shared\OperationsSnapshotPolicy::result($value,$permissions['can_view_cost']),'snapshot_sequence'=>(int)$this->pdo->query("SELECT COALESCE(MAX(id),0) FROM public_projection_outbox WHERE FIND_IN_SET('operations_workspace',model_keys)>0")->fetchColumn(),'snapshot_generated_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')];
        // Autosave receipts retain just the changed line, rather than a full workspace per keystroke.
        if($action!=='count_update')$response['snapshot']=$this->workspace->snapshot($user);
        return $response;
    }
}
