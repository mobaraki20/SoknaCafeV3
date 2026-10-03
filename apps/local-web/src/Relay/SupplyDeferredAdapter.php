<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use PDO;
use Sokna\Local\Domain\Inventory\InventoryService;
use Sokna\Local\Domain\Supply\SupplyAccessService;
use Sokna\Local\Domain\Supply\SupplyException;
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Domain\Supply\SupplyStateConflict;
use Throwable;

final class SupplyDeferredAdapter
{
    private const KINDS=[
        'supply.need.create',
        'supply.status.prepare',
        'supply.status.return',
        'supply.receipt',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly InventoryService $inventory,
        private readonly SupplyService $supply,
        private readonly SupplyAccessService $access,
        private readonly DeferredReceiptService $receipts,
    ) {}

    public function dispatch(string $installationId,array $envelope): array
    {
        $installationId=trim($installationId);
        $validation=$this->validateEnvelope($envelope);
        if($installationId===''||$validation!==[]){
            return ['state'=>'rejected','result'=>[],'error_code'=>'invalid_envelope','fields'=>$validation,'idempotent'=>false];
        }

        $this->pdo->beginTransaction();
        try{
            $replay=$this->receipts->replayTx($installationId,$envelope);
            if($replay!==null){$this->pdo->commit();return $replay;}

            $actor=$this->actorLocked((string)$envelope['actor_projection_id']);
            if($actor===null){
                // There is no canonical actor to attach a durable receipt to.
                $this->pdo->rollBack();
                return ['state'=>'rejected','result'=>[],'error_code'=>'actor_invalid','idempotent'=>false];
            }

            try{
                $result=$this->applyTx($envelope,$actor,false);
                $out=$this->receipts->committedTx($installationId,$envelope,$actor,$result);
            }catch(SupplyStateConflict $e){
                $out=$this->receipts->reviewTx(
                    $installationId,$envelope,$actor,$e->errorCode,$e->getMessage(),'conflict'
                );
            }catch(SupplyException $e){
                $out=$this->receipts->rejectedTx(
                    $installationId,$envelope,$actor,$e->errorCode,$e->details
                );
            }
            $this->pdo->commit();
            return $out;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function resolveReview(
        int $reviewId,string $decision,string $reason,array $reviewer
    ): array {
        if(!in_array($decision,['approve','reject'],true))
            throw new SupplyException('invalid_decision','تصمیم بررسی معتبر نیست.',422);
        $reason=self::truncate(trim($reason),500);
        if($reason==='')throw new SupplyException('reason_required','دلیل تصمیم را ثبت کن.',422);

        $this->pdo->beginTransaction();
        try{
            $managerId=(int)($reviewer['id']??0);
            $manager=$this->actorByIdLocked($managerId);
            if($manager===null||(string)($manager['role']??'')!=='admin')
                throw new SupplyException('forbidden','فقط مدیر فعال می‌تواند مورد Deferred را تعیین تکلیف کند.',403);

            $review=$this->receipts->reviewLockedTx($reviewId);
            if($review===null)throw new SupplyException('review_not_found','مورد بررسی پیدا نشد.',404);
            if((string)$review['state']!=='pending'){
                $this->pdo->commit();
                return ['state'=>(string)$review['state'],'idempotent'=>true];
            }

            if($decision==='reject'){
                $out=$this->receipts->rejectReviewTx($review,$managerId,$reason);
                $this->pdo->commit();
                return $out;
            }

            $envelope=json_decode((string)$review['envelope_json'],true);
            if(!is_array($envelope))throw new SupplyException('invalid_envelope','اطلاعات رخداد Deferred قابل بازیابی نیست.',500);
            $actor=$this->actorLocked((string)$review['actor_projection_id']);
            if($actor===null)throw new SupplyException('actor_invalid','حساب کاربر اصلی دیگر فعال نیست.',409);

            $result=$this->applyTx($envelope,$actor,true);
            $out=$this->receipts->approveReviewTx($review,$managerId,$reason,$result);
            $this->pdo->commit();
            return $out;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function applyTx(array $envelope,array $actor,bool $allowConflict): array
    {
        $kind=(string)$envelope['kind'];
        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        $actorId=(int)$actor['id'];

        if($kind==='supply.need.create'){
            $department=InventoryService::normalizeDepartment((string)($payload['department']??'shared'))??'shared';
            $this->access->assertReporter($actor,$department);
            return ['supply_need_id'=>$this->supply->addNeedDeferredTx($payload+['department'=>$department],$actorId)];
        }

        $this->access->assertBuyer($actor);
        if($kind==='supply.status.prepare'){
            return $this->supply->prepareTx(
                trim((string)($payload['group_key']??'')),
                $allowConflict?null:(int)($payload['expected_quantity_base']??-1),
                $actorId
            );
        }
        if($kind==='supply.status.return'){
            $outcome=(string)($payload['outcome']??'returned');
            return $this->supply->returnPreparingTx(
                trim((string)($payload['group_key']??'')),
                $outcome,
                $allowConflict?null:(int)($payload['expected_preparing_quantity_base']??-1),
                $actorId
            );
        }
        if($kind==='supply.receipt'){
            $data=$payload;
            $data['request_token']=substr(hash('sha256','deferred:'.(string)$envelope['request_id']),0,32);
            $data['occurred_at']=$this->inventory->normalizeOccurredAt((string)$envelope['occurred_at']);
            if($allowConflict)unset($data['expected_preparing_quantity_base']);
            return $this->supply->receiveTx(trim((string)($payload['group_key']??'')),$data,$actorId);
        }
        throw new SupplyException('unsupported_kind','نوع کار Deferred پشتیبانی نمی‌شود.',422);
    }

    private function actorLocked(string $projectionId): ?array
    {
        if(!preg_match('/^user:(\d+)$/',trim($projectionId),$m))return null;
        return $this->actorByIdLocked((int)$m[1]);
    }

    private function actorByIdLocked(int $userId): ?array
    {
        if($userId<1)return null;
        $stmt=$this->pdo->prepare(
            'SELECT id,username,display_name,role,active FROM users WHERE id=? FOR UPDATE'
        );
        $stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)&&(int)$row['active']===1?$row:null;
    }

    private function validateEnvelope(array $envelope): array
    {
        $errors=[];
        $requestId=trim((string)($envelope['request_id']??''));
        if($requestId===''||strlen($requestId)>96||preg_match('/^[A-Za-z0-9._:-]+$/',$requestId)!==1)$errors[]='request_id';
        $kind=trim((string)($envelope['kind']??''));
        if(!in_array($kind,self::KINDS,true))$errors[]='kind';
        $occurred=strtotime(trim((string)($envelope['occurred_at']??'')))?:0;
        if($occurred<=0||$occurred>time()+300)$errors[]='occurred_at';
        if(!is_array($envelope['payload']??null))$errors[]='payload';
        if(trim((string)($envelope['actor_projection_id']??''))==='')$errors[]='actor_projection_id';
        return $errors;
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
