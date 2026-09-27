<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use PDO;
use Sokna\Local\Domain\Inventory\InventoryCountService;
use Sokna\Local\Domain\Inventory\InventoryException;
use Sokna\Local\Domain\Inventory\InventoryService;
use Sokna\Local\Domain\Inventory\InventoryStateConflict;
use Throwable;

final class InventoryDeferredAdapter
{
    private const KINDS=['inventory.waste','inventory.count_draft'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly InventoryService $inventory,
        private readonly InventoryCountService $counts,
        private readonly DeferredReceiptService $receipts,
    ) {}

    public function dispatch(string|array $installationId,array $envelope=[]): array
    {
        if(is_array($installationId)){$envelope=$installationId;$installationId='local-inventory-deferred';}
        $installationId=trim($installationId);$errors=$this->validateEnvelope($envelope);
        if($installationId===''||$errors!==[])return ['state'=>'rejected','result'=>[],'error_code'=>'invalid_envelope','fields'=>$errors,'idempotent'=>false];

        $this->pdo->beginTransaction();
        try{
            $replay=$this->receipts->replayTx($installationId,$envelope);
            if($replay!==null){$this->pdo->commit();return $replay;}
            $actor=$this->actorLocked((string)$envelope['actor_projection_id']);
            if($actor===null){$this->pdo->rollBack();return ['state'=>'rejected','result'=>[],'error_code'=>'actor_invalid','idempotent'=>false];}
            try{$actor=$this->inventory->assertActor($actor,'inventory_operations');}
            catch(InventoryException){$out=$this->receipts->rejectedTx($installationId,$envelope,$actor,'permission_denied');$this->pdo->commit();return $out;}

            try{
                $result=$this->applyTx($envelope,$actor,false);
                $out=$this->receipts->committedTx($installationId,$envelope,$actor,$result);
            }catch(InventoryStateConflict $e){
                $out=$this->receipts->reviewTx($installationId,$envelope,$actor,$e->errorCode,$e->getMessage(),'conflict');
            }catch(InventoryException $e){
                $out=$this->receipts->rejectedTx($installationId,$envelope,$actor,$e->errorCode,$e->details);
            }
            $this->pdo->commit();return $out;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function resolveReview(int $reviewId,string $decision,string $reason,array $reviewer): array
    {
        if(!in_array($decision,['approve','reject'],true))throw new InventoryException('invalid_decision','تصمیم بررسی معتبر نیست.',422);
        $reason=self::truncate(trim($reason),500);if($reason==='')throw new InventoryException('reason_required','دلیل تصمیم را ثبت کن.',422);
        $this->pdo->beginTransaction();
        try{
            $manager=$this->actorByIdLocked((int)($reviewer['id']??0));
            if($manager===null||(string)($manager['role']??'')!=='admin')throw new InventoryException('forbidden','فقط مدیر فعال می‌تواند مورد Deferred انبار را تعیین تکلیف کند.',403);
            $review=$this->receipts->reviewLockedTx($reviewId);
            if($review===null)throw new InventoryException('review_not_found','مورد بررسی پیدا نشد.',404);
            if((string)$review['state']!=='pending'){$this->pdo->commit();return ['state'=>(string)$review['state'],'idempotent'=>true];}
            if(!in_array((string)$review['kind'],self::KINDS,true))throw new InventoryException('wrong_review_owner','این مورد بررسی متعلق به انبار نیست.',409);
            if($decision==='reject'){$out=$this->receipts->rejectReviewTx($review,(int)$manager['id'],$reason);$this->pdo->commit();return $out;}
            $envelope=json_decode((string)$review['envelope_json'],true);if(!is_array($envelope))throw new InventoryException('invalid_envelope','اطلاعات رخداد Deferred قابل بازیابی نیست.',500);
            $actor=$this->actorLocked((string)$review['actor_projection_id']);if($actor===null)throw new InventoryException('actor_invalid','حساب کاربر اصلی دیگر فعال نیست.',409);
            $result=$this->applyTx($envelope,$actor,true);
            $out=$this->receipts->approveReviewTx($review,(int)$manager['id'],$reason,$result);$this->pdo->commit();return $out;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function applyTx(array $envelope,array $actor,bool $allowConflict): array
    {
        $kind=(string)$envelope['kind'];$requestId=(string)$envelope['request_id'];$payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        if($kind==='inventory.waste'){
            $dedupeKey='deferred:waste:'.$requestId;
            $dup=$this->pdo->prepare('SELECT id,quantity_base FROM inventory_movements WHERE idempotency_key=? LIMIT 1 FOR UPDATE');$dup->execute([$dedupeKey]);$existing=$dup->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing))return ['movement_id'=>(int)$existing['id'],'quantity_base'=>abs((int)$existing['quantity_base'])];
            $itemId=(int)($payload['inventory_item_id']??0);$item=$this->inventory->itemTx($itemId);
            if($item===null||(int)$item['active']!==1)throw new InventoryException('invalid_inventory_item','قلم انبار معتبر نیست.',422);
            $balance=$this->inventory->balanceTx($itemId);$expected=trim((string)($envelope['expected_version']??($payload['expected_balance_version']??'')));
            if(!$allowConflict&&$expected!==''&&!hash_equals((string)$balance['updated_at'],$expected))throw new InventoryStateConflict('inventory_balance_changed','موجودی قلم بعد از نسخه راه‌دور تغییر کرده است.',409,['current_version'=>(string)$balance['updated_at'],'current_quantity_base'=>(int)$balance['quantity_base']]);
            $qty=InventoryService::majorToBase($payload['quantity_major']??'',(string)$item['base_unit']);if($qty<1)throw new InventoryException('invalid_quantity','مقدار ضایعات معتبر نیست.',422);
            $movement=$this->inventory->recordMovementTx([
                'item_id'=>$itemId,'movement_type'=>'waste','quantity_base'=>-$qty,
                'department'=>InventoryService::normalizeDepartment((string)($payload['department']??$item['default_department']))??'shared',
                'source_type'=>'deferred_waste','source_id'=>$requestId,'idempotency_key'=>$dedupeKey,'metadata'=>['origin'=>'public_deferred'],
                'note'=>self::truncate(trim((string)($payload['note']??'')),500)?:null,'actor_user_id'=>(int)$actor['id'],
                'occurred_at'=>trim((string)$envelope['occurred_at']),
            ]);
            return ['movement_id'=>$movement,'quantity_base'=>$qty];
        }

        $sessionId=(int)($payload['session_id']??0);$lineId=(int)($payload['line_id']??0);
        $sessionStmt=$this->pdo->prepare('SELECT session_type,status FROM inventory_count_sessions WHERE id=? FOR UPDATE');$sessionStmt->execute([$sessionId]);$session=$sessionStmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($session)||(string)$session['status']!=='draft')throw new InventoryStateConflict('count_not_draft','شمارش انبار دیگر در وضعیت پیش‌نویس نیست.',409);
        if((string)$session['session_type']==='opening')throw new InventoryException('opening_local_only','شمارش افتتاحیه فقط در Local قابل تغییر است.',409);
        $data=['session_id'=>$sessionId,'line_id'=>$lineId,'actual_major'=>array_key_exists('actual_major',$payload)?(string)$payload['actual_major']:null,'note'=>(string)($payload['note']??''),'expected_version'=>$allowConflict?'':(string)($envelope['expected_version']??'')];
        try{return $this->counts->updateLineTx($data,(int)$actor['id'],true);}catch(InventoryStateConflict $e){
            $line=$this->pdo->prepare('SELECT l.actual_quantity,l.note,l.updated_at,i.base_unit FROM inventory_count_lines l JOIN inventory_items i ON i.id=l.inventory_item_id WHERE l.id=? AND l.session_id=?');$line->execute([$lineId,$sessionId]);$current=$line->fetch(PDO::FETCH_ASSOC);
            if(is_array($current)){$raw=trim((string)($payload['actual_major']??''));$desired=$raw===''?null:InventoryService::majorToBase($raw,(string)$current['base_unit']);$note=self::truncate(trim((string)($payload['note']??'')),500);$same=($current['actual_quantity']===null?$desired===null:(int)$current['actual_quantity']===$desired)&&(string)($current['note']??'')===$note;if($same)return ['line_id'=>$lineId,'session_id'=>$sessionId,'actual_quantity'=>$current['actual_quantity']===null?null:(int)$current['actual_quantity'],'version'=>(string)$current['updated_at']];}
            throw $e;
        }
    }

    private function actorLocked(string $projectionId): ?array{if(!preg_match('/^user:(\d+)$/',trim($projectionId),$m))return null;return $this->actorByIdLocked((int)$m[1]);}
    private function actorByIdLocked(int $userId): ?array{$stmt=$this->pdo->prepare('SELECT id,username,display_name,role,active FROM users WHERE id=? FOR UPDATE');$stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)&&(int)$row['active']===1?$row:null;}
    private function validateEnvelope(array $envelope): array{$errors=[];$requestId=trim((string)($envelope['request_id']??''));if($requestId===''||strlen($requestId)>96||preg_match('/^[A-Za-z0-9._:-]+$/',$requestId)!==1)$errors[]='request_id';if(!in_array((string)($envelope['kind']??''),self::KINDS,true))$errors[]='kind';$occurred=strtotime(trim((string)($envelope['occurred_at']??'')))?:0;if($occurred<=0||$occurred>time()+300)$errors[]='occurred_at';if(!is_array($envelope['payload']??null))$errors[]='payload';if(trim((string)($envelope['actor_projection_id']??''))==='')$errors[]='actor_projection_id';return $errors;}
    private static function truncate(string $value,int $length): string{return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);}
}
