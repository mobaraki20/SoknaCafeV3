<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Supply;

use PDO;
use PDOException;
use Sokna\Local\Domain\Inventory\InventoryException;
use Sokna\Local\Domain\Inventory\InventoryService;
use Throwable;

final class SupplyService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly InventoryService $inventory,
        private readonly SupplyAccessService $access,
    ) {}

    public function permissions(array $user): array
    {
        return ['can_report'=>$this->access->canReportNeeds($user),'can_buy'=>$this->access->canManagePurchases($user),'need_departments'=>$this->access->allowedNeedDepartments($user)];
    }

    public function upsertNeed(array $data,array $user): array
    {
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $department=InventoryService::normalizeDepartment((string)($data['department']??'shared'))??'shared';
            $actor=$this->access->assertReporter($user,$department);
            $id=$this->upsertNeedTx($data+['department'=>$department],(int)$actor['id']);
            if($ownsTransaction)$this->pdo->commit();
            return ['need_id'=>$id];
        }catch(Throwable $e){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function addNeedDeferredTx(array $data,int $actorUserId): int
    {
        $this->requireTx();
        $this->requireDemandReadyTx();
        $itemId=max(0,(int)($data['inventory_item_id']??0));
        $department=InventoryService::normalizeDepartment((string)($data['department']??'shared'))??'shared';
        $note=self::truncate(trim((string)($data['note']??'')),500);
        if($itemId>0){
            $item=$this->inventory->itemTx($itemId);
            if($item===null||(int)$item['active']!==1)throw new SupplyException('invalid_inventory_item','کالای انتخاب‌شده دیگر فعال نیست.',409);
            $baseUnit=(string)$item['base_unit'];
            $delta=InventoryService::majorToBase($data['quantity_major']??'',$baseUnit);
            if($delta<1)throw new SupplyException('invalid_quantity','مقدار موردنیاز را وارد کن.',422);
            $existing=$this->openNeedForItemTx($itemId,$department);
            if($existing){
                $newRequested=(int)$existing['requested_quantity_base']+$delta;
                $this->pdo->prepare(
                    "UPDATE inventory_supply_needs SET requested_quantity_base=?,note=COALESCE(?,note),updated_by_user_id=?,last_outcome=NULL WHERE id=?"
                )->execute([$newRequested,$note!==''?$note:null,$actorUserId,(int)$existing['id']]);
                $this->audit('supply.need_deferred_added','inventory_supply_need',(int)$existing['id'],$actorUserId,[
                    'added_quantity_base'=>$delta,'requested_quantity_base'=>$newRequested,
                ]);
                return (int)$existing['id'];
            }
            return $this->insertNeedTx([
                'inventory_item_id'=>$itemId,'item_name_snapshot'=>(string)$item['name'],'base_unit'=>$baseUnit,
                'requested_quantity_base'=>$delta,'department'=>$department,'source'=>'staff','note'=>$note,
            ],$actorUserId,'supply.need_deferred_created');
        }

        $name=self::truncate(trim((string)($data['free_name']??'')),160);
        $baseUnit=InventoryService::normalizeBaseUnit((string)($data['base_unit']??'count'));
        if($name==='')throw new SupplyException('name_required','نام مورد خارج از فهرست را وارد کن.',422);
        $delta=InventoryService::majorToBase($data['quantity_major']??'',$baseUnit);
        if($delta<1)throw new SupplyException('invalid_quantity','مقدار موردنیاز را وارد کن.',422);
        $existing=$this->openFreeNeedTx($name,$baseUnit,$department);
        if($existing){
            if((string)$existing['base_unit']!==$baseUnit)throw new SupplyStateConflict('unit_changed','این مورد یک نیاز باز با واحد دیگری دارد.',409);
            $newRequested=(int)$existing['requested_quantity_base']+$delta;
            $this->pdo->prepare(
                "UPDATE inventory_supply_needs SET requested_quantity_base=?,note=COALESCE(?,note),updated_by_user_id=?,last_outcome=NULL WHERE id=?"
            )->execute([$newRequested,$note!==''?$note:null,$actorUserId,(int)$existing['id']]);
            $this->audit('supply.need_deferred_added','inventory_supply_need',(int)$existing['id'],$actorUserId,[
                'added_quantity_base'=>$delta,'requested_quantity_base'=>$newRequested,
            ]);
            return (int)$existing['id'];
        }
        return $this->insertNeedTx([
            'inventory_item_id'=>null,'item_name_snapshot'=>$name,'base_unit'=>$baseUnit,
            'requested_quantity_base'=>$delta,'department'=>$department,'source'=>'staff','note'=>$note,
        ],$actorUserId,'supply.need_deferred_created');
    }

    public function prepare(string $groupKey,?int $expectedUncommitted,array $user): array
    {
        return $this->transactionalBuyer(fn(array $actor):array =>
            $this->prepareTx($groupKey,$expectedUncommitted,(int)$actor['id']),$user);
    }

    public function returnPreparing(string $groupKey,string $outcome,?int $expectedPreparing,array $user): array
    {
        return $this->transactionalBuyer(fn(array $actor):array =>
            $this->returnPreparingTx($groupKey,$outcome,$expectedPreparing,(int)$actor['id']),$user);
    }

    public function cancelUncommitted(string $groupKey,?int $expectedUncommitted,array $user): array
    {
        return $this->transactionalBuyer(fn(array $actor):array =>
            $this->cancelUncommittedTx($groupKey,$expectedUncommitted,(int)$actor['id']),$user);
    }

    public function receive(string $groupKey,array $data,array $user): array
    {
        return $this->transactionalBuyer(fn(array $actor):array =>
            $this->receiveTx($groupKey,$data,(int)$actor['id']),$user);
    }

    public function receiveBatch(array $lines,string $batchToken,array $user): array
    {
        return $this->transactionalBuyer(fn(array $actor):array =>
            $this->receiveBatchTx($lines,$batchToken,(int)$actor['id']),$user);
    }

    public function completeReceiptCost(int $receiptId,mixed $totalCost,array $user): array
    {
        return $this->transactionalBuyer(fn(array $actor):array =>
            $this->completeReceiptCostTx($receiptId,$totalCost,(int)$actor['id']),$user);
    }

    /** Correct an entered amount without rewriting the original receipt movement. */
    public function correctReceiptCost(array $data,array $user): array
    {
        return $this->transactionalBuyer(function(array $actor) use ($data): array {
            $this->inventory->assertActor($actor,'inventory_manage');
            $this->inventory->assertActor($actor,'inventory_cost_view');
            $this->requireReadyTx();
            $receiptId=(int)($data['receipt_id']??0);$reason=trim((string)($data['reason']??''));
            if($reason===''||mb_strlen($reason)>1000)throw new SupplyException('correction_reason_required','دلیل اصلاح مبلغ را بنویس.',422);
            $requestId=trim((string)($data['request_id']??''));
            if($requestId===''||strlen($requestId)>96)throw new SupplyException('request_id_required','شناسه اصلاح معتبر نیست.',422);
            $q=$this->pdo->prepare('SELECT r.*,m.movement_type,m.department movement_department FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id WHERE r.id=? FOR UPDATE');
            $q->execute([$receiptId]);$r=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($r))throw new SupplyException('receipt_not_found','رسید خرید پیدا نشد.',404);
            if($r['total_cost']===null)throw new SupplyStateConflict('receipt_cost_missing','برای این رسید ابتدا مبلغ را تکمیل کن.',409);
            $old=(int)$r['total_cost'];$expected=InventoryService::moneyValue($data['expected_total_cost']??'');
            if($old!==$expected)throw new SupplyStateConflict('receipt_cost_state_changed','مبلغ رسید هم‌زمان تغییر کرده است؛ مبلغ تازه را مرور کن.',409);
            if($r['movement_type']!=='purchase_receive')throw new SupplyException('invalid_receipt_movement','حرکت متصل به رسید معتبر نیست.',409);
            $reversal=$this->pdo->prepare('SELECT id FROM inventory_movements WHERE reversal_of_id=? LIMIT 1');$reversal->execute([(int)$r['movement_id']]);
            if($reversal->fetchColumn()!==false)throw new SupplyStateConflict('receipt_reversed','رسید برگشت‌خورده قابل اصلاح نیست.',409);
            $cost=InventoryService::moneyValue($data['total_cost']??'');
            if($cost===$old)throw new SupplyException('cost_unchanged','مبلغ جدید با مبلغ قبلی یکسان است.',422);
            $movement=$this->inventory->recordMovementTx([
                'item_id'=>(int)$r['inventory_item_id'],'movement_type'=>'cost_adjustment','quantity_base'=>0,
                'department'=>$r['movement_department'],'total_cost_delta'=>$cost-$old,'cost_status'=>'known',
                'correction_of_id'=>(int)$r['movement_id'],'source_type'=>'supply_receipt_cost_correction','source_id'=>$requestId,
                'idempotency_key'=>'supply:receipt-cost-correction:'.$requestId,
                'metadata'=>['receipt_id'=>$receiptId,'previous_total_cost'=>$old,'corrected_total_cost'=>$cost,'reason'=>$reason],
                'note'=>'اصلاح مبلغ رسید #'.$receiptId.' — '.$reason,'actor_user_id'=>(int)$actor['id'],
            ]);
            $this->pdo->prepare('UPDATE inventory_supply_receipts SET total_cost=? WHERE id=? AND total_cost=?')->execute([$cost,$receiptId,$old]);
            // Price events remain historical facts; workspace joins suppress obsolete comparisons.
            $this->audit('supply.receipt_cost_corrected','inventory_supply_receipt',$receiptId,(int)$actor['id'],[
                'previous_total_cost'=>$old,'corrected_total_cost'=>$cost,'reason'=>$reason,'cost_adjustment_movement_id'=>$movement,
            ]);
            return ['receipt_id'=>$receiptId,'total_cost'=>$cost,'cost_adjustment_movement_id'=>$movement,'balance'=>$this->inventory->balanceTx((int)$r['inventory_item_id'])];
        },$user);
    }


    /** A purchase is a fact, not a stock receipt. Delivery is a separate fact. */
    public function recordPurchase(array $data,array $user): array
    {
        return $this->transactionalBuyer(function(array $actor)use($data):array{
            $this->requireDemandReadyTx();
            $snapshot=['module_state'=>['supply_enabled'=>true,'inventory_runtime_ready'=>true],
                'inventory'=>['items'=>$this->pdo->query('SELECT * FROM inventory_items')->fetchAll(PDO::FETCH_ASSOC),'purchase_units'=>$this->pdo->query('SELECT * FROM inventory_purchase_units')->fetchAll(PDO::FETCH_ASSOC)],
                'supply'=>['groups'=>$this->purchaseGroups(),'purchases'=>$this->purchases()]];
            try{\Sokna\Shared\DailySupply::validate($snapshot,$data);$v=\Sokna\Shared\DailySupply::describe($snapshot,$data);}catch(\DomainException $e){throw new SupplyStateConflict('purchase_state_changed',$e->getMessage(),409);}
            if(isset($data['_quantity_base'])&&((int)$data['_quantity_base']!==$v['quantity_base']||($data['_base_unit']??'')!==$v['base_unit']))throw new SupplyStateConflict('purchase_unit_changed','واحد خرید از زمان ثبت تغییر کرده است؛ مقدار واقعی را بررسی کن.',409);
            $stored=$data;
            $frozen=$v['item_id']>0?$this->inventory->resolveOperationQuantityTx($v['item_id'],(int)($data['purchase_unit_id']??0),$data['unit_count']??'',$data['actual_major_quantity']??''):['quantity_base'=>$v['quantity_base'],'purchase_unit_id'=>null,'purchase_unit_name_snapshot'=>null,'purchase_unit_count'=>null,'conversion_base_quantity_snapshot'=>null,'conversion_mode'=>'base'];
            if((int)$frozen['quantity_base']!==$v['quantity_base'])throw new SupplyStateConflict('purchase_unit_changed','تبدیل واحد خرید هم‌زمان تغییر کرد؛ مقدار واقعی را مرور کن.',409);
            $stored['_purchase_quantity_snapshot']=$frozen;
            $id=(string)$data['request_id'];$at=$this->inventory->normalizeOccurredAt((string)($data['occurred_at']??date('Y-m-d H:i:s')));
            $cost=trim((string)($data['total_cost']??''))===''?null:InventoryService::moneyValue($data['total_cost']);
            $this->pdo->prepare('INSERT INTO inventory_supply_purchases(purchase_id,group_key,item_id,item_name,base_unit,quantity_base,total_cost,supplier,note,payload_json,actor_user_id,purchased_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $id,($data['group_key']??'')?:null,$v['item_id']?:null,$v['item_name'],$v['base_unit'],$v['quantity_base'],$cost,self::truncate((string)($data['supplier']??''),160),self::truncate((string)($data['note']??''),500),json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$actor['id'],$at]);
            $this->audit('supply.purchase_recorded','inventory_supply_purchase',$id,(int)$actor['id'],['quantity_base'=>$v['quantity_base'],'delivered'=>false]);
            $result=['purchase_id'=>$id,'delivered'=>false];
            if(!empty($data['delivered_to_cafe']))$result=$this->deliverPurchase(['purchase_id'=>$id,'occurred_at'=>$at,'request_id'=>$id],$actor);
            return $result;
        },$user);
    }

    public function deliverPurchase(array $data,array $user): array
    {
        return $this->transactionalBuyer(function(array $actor)use($data):array{
            $id=(string)($data['purchase_id']??'');$q=$this->pdo->prepare('SELECT * FROM inventory_supply_purchases WHERE purchase_id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p)throw new SupplyStateConflict('purchase_not_found','خرید پیدا نشد.',409);
            if($p['cancelled_at']!==null)throw new SupplyStateConflict('purchase_cancelled','این ثبت خرید لغو شده است.',409);
            if($p['delivered_at']!==null)return ['purchase_id'=>$id,'receipt_id'=>(int)$p['receipt_id'],'delivered'=>true,'duplicate'=>true];
            $payload=json_decode($p['payload_json'],true,512,JSON_THROW_ON_ERROR);
            $frozen=$payload['_purchase_quantity_snapshot']??null;
            if(is_array($frozen)&&(int)($frozen['purchase_unit_id']??0)>0){$exists=$this->pdo->prepare('SELECT id FROM inventory_purchase_units WHERE id=? FOR UPDATE');$exists->execute([(int)$frozen['purchase_unit_id']]);if($exists->fetchColumn()===false)$frozen['purchase_unit_id']=null;}
            // Use the quantity observed at purchase, not a later edited pack conversion.
            $payload['purchase_unit_id']=0;$payload['unit_count']=(string)($p['base_unit']==='count'?(int)$p['quantity_base']:(int)$p['quantity_base']/1000);$payload['actual_major_quantity']='';
            $payload['total_cost']=$p['total_cost']===null?'':(string)$p['total_cost'];
            $payload['request_token']=substr(hash('sha256','purchase-delivery:'.$id),0,32);
            $payload['occurred_at']=$this->inventory->normalizeOccurredAt((string)($data['occurred_at']??date('Y-m-d H:i:s')));
            if($payload['occurred_at']<(string)$p['purchased_at'])throw new SupplyStateConflict('delivery_before_purchase','زمان تحویل نمی‌تواند پیش از خرید باشد.',409);
            unset($payload['expected_preparing_quantity_base']);
            $result=!empty($p['group_key'])?$this->receiveTx($p['group_key'],$payload,(int)$actor['id'],$frozen):$this->receiveDirectTx($payload,(int)$actor['id'],$frozen);
            $this->pdo->prepare('UPDATE inventory_supply_purchases SET delivered_at=?,receipt_id=? WHERE purchase_id=?')->execute([$payload['occurred_at'],(int)$result['receipt_id'],$id]);
            $this->audit('supply.purchase_delivered','inventory_supply_purchase',$id,(int)$actor['id'],['receipt_id'=>(int)$result['receipt_id']]);
            return ['purchase_id'=>$id,'receipt_id'=>(int)$result['receipt_id'],'delivered'=>true];
        },$user);
    }

    public function cancelPurchase(array $data,array $user): array
    {
        return $this->transactionalBuyer(function(array $actor)use($data):array{
            $id=(string)($data['purchase_id']??'');$reason=trim((string)($data['reason']??''));if($reason==='')throw new SupplyException('reason_required','دلیل اصلاح ثبت خرید را بنویس.',422);
            $q=$this->pdo->prepare('SELECT delivered_at,cancelled_at FROM inventory_supply_purchases WHERE purchase_id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p||$p['delivered_at']!==null)throw new SupplyStateConflict('purchase_already_delivered','خرید تحویل‌شده با این روش قابل لغو نیست؛ رسید واقعی را در Local بررسی کن.',409);
            if($p['cancelled_at']!==null)return ['purchase_id'=>$id,'cancelled'=>true,'duplicate'=>true];
            $at=$this->inventory->normalizeOccurredAt((string)($data['occurred_at']??date('Y-m-d H:i:s')));
            $this->pdo->prepare('UPDATE inventory_supply_purchases SET cancelled_at=?,cancellation_reason=? WHERE purchase_id=?')->execute([$at,self::truncate($reason,500),$id]);
            $this->audit('supply.purchase_cancelled','inventory_supply_purchase',$id,(int)$actor['id'],['reason'=>$reason]);return ['purchase_id'=>$id,'cancelled'=>true];
        },$user);
    }

    public function purchases(): array
    {
        return $this->pdo->query("SELECT p.purchase_id,p.group_key,p.item_id,p.item_name,p.base_unit,p.quantity_base,COALESCE(r.total_cost,p.total_cost) total_cost,p.supplier,p.note,p.purchased_at,p.delivered_at,p.cancelled_at,p.cancellation_reason,p.receipt_id,u.display_name actor_name FROM inventory_supply_purchases p LEFT JOIN users u ON u.id=p.actor_user_id LEFT JOIN inventory_supply_receipts r ON r.id=p.receipt_id WHERE (p.delivered_at IS NULL AND p.cancelled_at IS NULL) OR p.purchase_id IN (SELECT purchase_id FROM (SELECT purchase_id FROM inventory_supply_purchases WHERE delivered_at IS NOT NULL OR cancelled_at IS NOT NULL ORDER BY COALESCE(delivered_at,cancelled_at) DESC LIMIT 40) recent) ORDER BY p.purchased_at DESC,p.purchase_id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function purchasePricePolicy(): array
    {
        return $this->purchasePricePolicyFromSettings();
    }

    public function savePurchasePricePolicy(array $data,array $user): array
    {
        return $this->transactionalBuyer(function(array $actor) use ($data): array {
            $enabled=array_key_exists('enabled',$data)&&in_array(strtolower(trim((string)$data['enabled'])),['1','true','yes','on'],true);
            $threshold=self::percentValue($data['threshold_percent']??null,0.1,1000,'آستانه اعلان تغییر قیمت');
            $high=self::percentValue($data['high_threshold_percent']??null,$threshold,1000,'آستانه اعلان با اولویت بالا');
            $save=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach([
                'inventory.purchase_price_notifications.enabled'=>$enabled?'1':'0',
                'inventory.purchase_price_notifications.threshold_percent'=>(string)$threshold,
                'inventory.purchase_price_notifications.high_threshold_percent'=>(string)$high,
            ] as $key=>$value)$save->execute([$key,$value]);
            $this->audit('supply.purchase_price_notification_policy_updated','settings',null,(int)$actor['id'],['enabled'=>$enabled,'threshold_percent'=>$threshold,'high_threshold_percent'=>$high]);
            return ['enabled'=>$enabled,'configured'=>true,'threshold_percent'=>$threshold,'high_threshold_percent'=>$high];
        },$user);
    }

    public function completeReceiptCostTx(int $receiptId,mixed $totalCost,int $actorUserId): array
    {
        $this->requireTx();$this->requireReadyTx();
        if($receiptId<1)throw new SupplyException('receipt_not_found','رسید خرید پیدا نشد.',404);
        $stmt=$this->pdo->prepare(
            'SELECT r.*,m.movement_type,m.department movement_department,m.occurred_at movement_occurred_at,i.name item_name,i.base_unit '.
            'FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id '.
            'JOIN inventory_items i ON i.id=r.inventory_item_id WHERE r.id=? FOR UPDATE'
        );
        $stmt->execute([$receiptId]);$receipt=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($receipt))throw new SupplyException('receipt_not_found','رسید خرید پیدا نشد.',404);
        if($receipt['total_cost']!==null)throw new SupplyStateConflict('receipt_cost_already_set','مبلغ این رسید قبلاً ثبت شده است؛ برای جلوگیری از بازنویسی تاریخچه، صفحه را تازه کن.',409,['current_total_cost'=>(int)$receipt['total_cost']]);
        if((string)$receipt['movement_type']!=='purchase_receive')throw new SupplyException('invalid_receipt_movement','حرکت انبار متصل به این رسید معتبر نیست.',409);
        $cost=InventoryService::moneyValue($totalCost);
        $movementId=$this->inventory->recordMovementTx([
            'item_id'=>(int)$receipt['inventory_item_id'],'movement_type'=>'cost_adjustment','quantity_base'=>0,
            'department'=>(string)($receipt['movement_department']??'shared'),'total_cost_delta'=>$cost,'cost_status'=>'known',
            'source_type'=>'supply_receipt_cost_completion','source_id'=>(string)$receiptId,
            'correction_of_id'=>(int)$receipt['movement_id'],'idempotency_key'=>'supply:receipt-cost:'.$receiptId,
            'metadata'=>['receipt_id'=>$receiptId,'original_total_cost'=>null,'completed_total_cost'=>$cost,'receipt_received_at'=>(string)$receipt['received_at']],
            'note'=>'تکمیل مبلغ رسید خرید #'.$receiptId,'actor_user_id'=>$actorUserId,
        ]);
        $updated=$this->pdo->prepare('UPDATE inventory_supply_receipts SET total_cost=? WHERE id=? AND total_cost IS NULL');
        $updated->execute([$cost,$receiptId]);
        if($updated->rowCount()!==1)throw new SupplyStateConflict('receipt_cost_state_changed','مبلغ این رسید هم‌زمان تغییر کرده است؛ صفحه را تازه کن.',409);
        $priceChange=$this->capturePurchasePriceEventTx($receiptId,$actorUserId);
        $this->audit('supply.receipt_cost_completed','inventory_supply_receipt',$receiptId,$actorUserId,[
            'inventory_item_id'=>(int)$receipt['inventory_item_id'],'item_name'=>(string)$receipt['item_name'],
            'received_quantity_base'=>(int)$receipt['received_quantity_base'],'total_cost'=>$cost,
            'receipt_movement_id'=>(int)$receipt['movement_id'],'cost_adjustment_movement_id'=>$movementId,
            'purchase_price_event_id'=>$priceChange['id']??null,
        ]);
        $balance=$this->inventory->balanceTx((int)$receipt['inventory_item_id']);
        return ['receipt_id'=>$receiptId,'total_cost'=>$cost,'cost_adjustment_movement_id'=>$movementId,'balance'=>$balance,'purchase_price_change'=>$priceChange];
    }

    /** Both hosts record a new need as an addition; replay is owned by the command receipt. */
    public function upsertNeedTx(array $data,int $actorUserId): int
    {
        return $this->addNeedDeferredTx($data,$actorUserId);
    }

    public function prepareTx(string $groupKey,?int $expectedUncommitted,int $actorUserId): array
    {
        $this->requireTx();$this->requireDemandReadyTx();
        $rows=$this->groupRowsTx($groupKey,false);
        if(!$rows)throw new SupplyStateConflict('need_closed','این نیاز دیگر باز نیست.',409);
        $current=array_sum(array_map(fn(array $r):int=>$this->uncommitted($r),$rows));
        if($expectedUncommitted!==null&&$current!==$expectedUncommitted)
            throw new SupplyStateConflict('supply_state_changed','مقدار این نیاز تغییر کرده است؛ صفحه را تازه کن و دوباره بررسی کن.',409,['current_quantity_base'=>$current]);
        $moved=0;$ids=[];
        foreach($rows as $row){
            $qty=$this->uncommitted($row);if($qty<1)continue;
            $newPreparing=(int)$row['preparing_quantity_base']+$qty;
            $this->pdo->prepare(
                "UPDATE inventory_supply_needs SET preparing_quantity_base=?,preparing_by_user_id=?,preparing_at=COALESCE(preparing_at,NOW()),last_outcome=NULL,updated_by_user_id=? WHERE id=? AND status='open'"
            )->execute([$newPreparing,$actorUserId,$actorUserId,(int)$row['id']]);
            $moved+=$qty;$ids[]=(int)$row['id'];
        }
        if($moved<1)throw new SupplyStateConflict('nothing_to_prepare','نیاز جدیدی برای شروع تهیه وجود ندارد.',409);
        $this->audit('supply.preparing_started','inventory_supply_group',null,$actorUserId,['group_key'=>$groupKey,'need_ids'=>$ids,'quantity_base'=>$moved]);
        return ['group_key'=>$groupKey,'quantity_base'=>$moved,'need_ids'=>$ids];
    }

    public function returnPreparingTx(string $groupKey,string $outcome,?int $expectedPreparing,int $actorUserId): array
    {
        $this->requireTx();$this->requireDemandReadyTx();
        if(!in_array($outcome,['returned','unavailable'],true))throw new SupplyException('invalid_outcome','وضعیت خرید معتبر نیست.',422);
        $rows=$this->groupRowsTx($groupKey,true);
        if(!$rows)throw new SupplyStateConflict('not_preparing','این قلم دیگر در حال خرید نیست.',409);
        $current=array_sum(array_map(static fn(array $r):int=>(int)$r['preparing_quantity_base'],$rows));
        if($expectedPreparing!==null&&$current!==$expectedPreparing)
            throw new SupplyStateConflict('supply_state_changed','مقدار در حال خرید تغییر کرده است؛ صفحه را تازه کن و دوباره بررسی کن.',409,['current_preparing_quantity_base'=>$current]);
        $returned=0;$ids=[];
        foreach($rows as $row){
            $qty=(int)$row['preparing_quantity_base'];if($qty<1)continue;
            $this->pdo->prepare(
                "UPDATE inventory_supply_needs SET preparing_quantity_base=0,preparing_by_user_id=NULL,preparing_at=NULL,last_outcome=?,updated_by_user_id=? WHERE id=? AND status='open'"
            )->execute([$outcome,$actorUserId,(int)$row['id']]);
            $returned+=$qty;$ids[]=(int)$row['id'];
        }
        $this->audit($outcome==='unavailable'?'supply.preparing_unavailable':'supply.preparing_returned','inventory_supply_group',null,$actorUserId,[
            'group_key'=>$groupKey,'need_ids'=>$ids,'quantity_base'=>$returned,
        ]);
        return ['group_key'=>$groupKey,'quantity_base'=>$returned,'need_ids'=>$ids];
    }

    public function cancelUncommittedTx(string $groupKey,?int $expectedUncommitted,int $actorUserId): array
    {
        $this->requireTx();$this->requireDemandReadyTx();
        $rows=$this->groupRowsTx($groupKey,false);
        if(!$rows)throw new SupplyStateConflict('need_closed','این نیاز دیگر باز نیست.',409);
        $current=array_sum(array_map(fn(array $r):int=>$this->uncommitted($r),$rows));
        if($expectedUncommitted!==null&&$current!==$expectedUncommitted)
            throw new SupplyStateConflict('supply_state_changed','مقدار نیاز تغییر کرده است؛ صفحه را تازه کن و دوباره بررسی کن.',409,['current_quantity_base'=>$current]);
        $cancelled=0;$ids=[];
        foreach($rows as $row){
            $uncommitted=$this->uncommitted($row);if($uncommitted<1)continue;
            $fulfilled=(int)$row['fulfilled_quantity_base'];$preparing=(int)$row['preparing_quantity_base'];
            $newRequested=$fulfilled+$preparing;$status=$preparing>0?'open':'closed';
            $this->pdo->prepare(
                "UPDATE inventory_supply_needs SET requested_quantity_base=?,status=?,open_item_guard=CASE WHEN ?='closed' THEN NULL ELSE open_item_guard END,last_outcome='cancelled',updated_by_user_id=?,closed_by_user_id=CASE WHEN ?='closed' THEN ? ELSE NULL END,closed_at=CASE WHEN ?='closed' THEN NOW() ELSE NULL END WHERE id=? AND status='open'"
            )->execute([$newRequested,$status,$status,$actorUserId,$status,$actorUserId,$status,(int)$row['id']]);
            $cancelled+=$uncommitted;$ids[]=(int)$row['id'];
        }
        if($cancelled<1)throw new SupplyStateConflict('nothing_to_cancel','نیاز جدیدی برای لغو وجود ندارد.',409);
        $this->audit('supply.uncommitted_cancelled','inventory_supply_group',null,$actorUserId,[
            'group_key'=>$groupKey,'need_ids'=>$ids,'quantity_base'=>$cancelled,
        ]);
        return ['group_key'=>$groupKey,'quantity_base'=>$cancelled,'need_ids'=>$ids];
    }

    public function receiveTx(string $groupKey,array $data,int $actorUserId,?array $purchaseQuantitySnapshot=null): array
    {
        $this->requireTx();$this->requireReadyTx();
        $requestToken=trim((string)($data['request_token']??''));
        if(!preg_match('/^[a-f0-9]{32}$/',$requestToken))
            throw new SupplyException('invalid_request_token','فرم خرید منقضی شده؛ صفحه را تازه کن.',422);

        $dup=$this->pdo->prepare(
            'SELECT r.*,i.base_unit FROM inventory_supply_receipts r JOIN inventory_items i ON i.id=r.inventory_item_id WHERE r.request_token=? LIMIT 1 FOR UPDATE'
        );
        $dup->execute([$requestToken]);$existing=$dup->fetch(PDO::FETCH_ASSOC);
        if(is_array($existing))return $this->duplicateReceiptStateTx($existing);

        $rows=$this->groupRowsTx($groupKey,true);
        if(!$rows)throw new SupplyStateConflict('not_preparing','این قلم دیگر در حال خرید نیست یا قبلاً تحویل شده است.',409);

        $anchor=$rows[0];$itemId=(int)($anchor['inventory_item_id']??0);$targetId=max(0,(int)($data['target_item_id']??0));
        if($itemId<1){
            $before=array_sum(array_map(static fn(array $r):int=>(int)$r['preparing_quantity_base'],$rows));
            if(isset($data['expected_preparing_quantity_base'])&&(int)$data['expected_preparing_quantity_base']!==$before)
                throw new SupplyStateConflict('supply_receipt_state_changed','نیاز خرید تغییر کرده؛ مقدار تازه را بررسی کن.',409);
            if($targetId>0){
                $target=$this->inventory->itemTx($targetId);
                if($target===null||(int)$target['active']!==1)throw new SupplyException('invalid_target_item','کالای مقصد معتبر نیست.',422);
                if((string)$target['base_unit']!==(string)$anchor['base_unit'])throw new SupplyException('unit_mismatch','واحد کالای مقصد با نیاز هم‌خوان نیست.',422);
                $itemId=$targetId;
            }else{
                $itemId=$this->inventory->createUnreviewedItemTx((string)$anchor['item_name_snapshot'],(string)$anchor['base_unit'],count($rows)>1?'shared':(string)$anchor['department'],$actorUserId,'خرید و تأمین');
                $target=$this->inventory->itemTx($itemId);
            }
            foreach($rows as $need){
                $merged=$this->mergeOpenNeedIntoTargetTx($need,$itemId,$actorUserId);
                if((int)$merged['id']===(int)$need['id']){
                    $guard=$this->needGuard((string)$need['department'],$itemId,(string)$target['name'],(string)$target['base_unit']);
                    $this->pdo->prepare('UPDATE inventory_supply_needs SET inventory_item_id=?,item_name_snapshot=?,base_unit=?,open_item_guard=?,updated_by_user_id=? WHERE id=?')
                        ->execute([$itemId,(string)$target['name'],(string)$target['base_unit'],$guard,$actorUserId,(int)$need['id']]);
                }
            }
            $groupKey='item:'.$itemId;$rows=$this->groupRowsTx($groupKey,true);$anchor=$rows[0];
            // Original free-group version was checked before merging. Include any existing
            // target needs in the current allocation, without changing the user's received quantity.
            $data['expected_preparing_quantity_base']=array_sum(array_map(static fn(array $r):int=>(int)$r['preparing_quantity_base'],$rows));
        }

        $item=$this->inventory->itemTx($itemId);
        if($item===null||(int)$item['active']!==1)throw new SupplyException('invalid_inventory_item','کالای انبار معتبر نیست.',409);
        $baseUnit=(string)$item['base_unit'];
        foreach($rows as $row)if((string)$row['base_unit']!==$baseUnit)throw new SupplyException('unit_mismatch','واحد نیازهای تجمیع‌شده هم‌خوان نیست.',409);

        $preparedTotal=array_sum(array_map(static fn(array $r):int=>(int)$r['preparing_quantity_base'],$rows));
        if($preparedTotal<1)throw new SupplyStateConflict('not_preparing','مقداری برای تحویل در حال خرید نیست.',409);
        $expected=array_key_exists('expected_preparing_quantity_base',$data)?(int)$data['expected_preparing_quantity_base']:null;
        if($expected!==null&&$expected!==$preparedTotal)
            throw new SupplyStateConflict('supply_receipt_state_changed','مقدار در حال خرید از زمان بازکردن فرم تغییر کرده است؛ صفحه را تازه کن و دوباره بررسی کن.',409,['current_preparing_quantity_base'=>$preparedTotal]);

        $resolved=$purchaseQuantitySnapshot??$this->inventory->resolveOperationQuantityTx(
            $itemId,(int)($data['purchase_unit_id']??0),$data['unit_count']??'',$data['actual_major_quantity']??''
        );
        $received=(int)$resolved['quantity_base'];
        // Real purchasing can legitimately differ from the quantity marked as preparing.
        // Record the variance; never reject a receipt merely because it is over/under plan.
        $planVariance=$received-$preparedTotal;
        $costRaw=trim((string)($data['total_cost']??''));
        $totalCost=$costRaw===''?null:InventoryService::moneyValue($costRaw);
        $supplier=self::truncate(trim((string)($data['supplier']??'')),160);
        $note=self::truncate(trim((string)($data['note']??'')),500);
        $occurredAt=trim((string)($data['occurred_at']??''));
        $occurredAt=$occurredAt!==''?$this->inventory->normalizeOccurredAt($occurredAt):null;
        $departments=array_values(array_unique(array_map(static fn(array $r):string=>(string)$r['department'],$rows)));
        $movementDepartment=count($departments)===1?$departments[0]:'shared';

        $movementId=$this->inventory->recordMovementTx(array_merge($resolved,[
            'item_id'=>$itemId,'movement_type'=>'purchase_receive','quantity_base'=>$received,
            'department'=>$movementDepartment,'total_cost_delta'=>$totalCost,'cost_status'=>$totalCost===null?'unknown':'known',
            'source_type'=>'inventory_supply_group','source_id'=>$groupKey,
            'idempotency_key'=>'supply:receive:'.$requestToken,
            'metadata'=>[
                'supplier'=>$supplier?:null,'prepared_quantity_base'=>$preparedTotal,
                'purchase_plan_variance_base'=>$planVariance,'over_plan'=>$planVariance>0,'under_plan'=>$planVariance<0,
                'need_ids'=>array_map(static fn(array $r):int=>(int)$r['id'],$rows),
            ],
            'note'=>$note?:null,'actor_user_id'=>$actorUserId,'occurred_at'=>$occurredAt,
        ]));

        $toAllocate=min($received,$preparedTotal);$allocations=[];$uncommittedAfter=0;$preparedAfter=0;$totalUnmetAfter=0;
        foreach($rows as $row){
            $prepared=(int)$row['preparing_quantity_base'];$allocated=min($prepared,$toAllocate);$toAllocate-=$allocated;
            $newPreparing=$prepared-$allocated;$newFulfilled=(int)$row['fulfilled_quantity_base']+$allocated;
            $remaining=max(0,(int)$row['requested_quantity_base']-$newFulfilled);
            $uncommitted=max(0,$remaining-$newPreparing);
            $status=($remaining===0&&$newPreparing===0)?'closed':'open';
            $outcome=$status==='closed'?'received':($allocated>0?'partial':(string)($row['last_outcome']??''));
            $this->pdo->prepare(
                "UPDATE inventory_supply_needs SET fulfilled_quantity_base=?,preparing_quantity_base=?,
                 preparing_by_user_id=CASE WHEN ?>0 THEN preparing_by_user_id ELSE NULL END,
                 preparing_at=CASE WHEN ?>0 THEN preparing_at ELSE NULL END,status=?,
                 open_item_guard=CASE WHEN ?='closed' THEN NULL ELSE open_item_guard END,last_outcome=?,updated_by_user_id=?,
                 closed_by_user_id=CASE WHEN ?='closed' THEN ? ELSE NULL END,
                 closed_at=CASE WHEN ?='closed' THEN NOW() ELSE NULL END WHERE id=?"
            )->execute([
                $newFulfilled,$newPreparing,$newPreparing,$newPreparing,$status,$status,$outcome?:null,$actorUserId,
                $status,$actorUserId,$status,(int)$row['id'],
            ]);
            if($allocated>0)$allocations[]=['need_id'=>(int)$row['id'],'quantity_base'=>$allocated];
            $uncommittedAfter+=$uncommitted;$preparedAfter+=$newPreparing;$totalUnmetAfter+=$remaining;
        }

        $anchorNeedId=(int)$anchor['id'];
        $receipt=$this->pdo->prepare(
            'INSERT INTO inventory_supply_receipts(supply_need_id,inventory_item_id,requested_quantity_snapshot,received_quantity_base,remaining_quantity_after,'.
            'purchase_unit_id,purchase_unit_name_snapshot,purchase_unit_count,conversion_base_quantity_snapshot,total_cost,supplier,note,request_token,movement_id,actor_user_id,received_at) '.
            'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,COALESCE(?,NOW()))'
        );
        $receipt->execute([
            $anchorNeedId,$itemId,$preparedTotal,$received,$preparedAfter,
            $resolved['purchase_unit_id'],$resolved['purchase_unit_name_snapshot'],$resolved['purchase_unit_count'],$resolved['conversion_base_quantity_snapshot'],
            $totalCost,$supplier?:null,$note?:null,$requestToken,$movementId,$actorUserId,$occurredAt,
        ]);
        $receiptId=(int)$this->pdo->lastInsertId();
        if($allocations){
            $alloc=$this->pdo->prepare(
                'INSERT INTO inventory_supply_receipt_allocations(receipt_id,supply_need_id,allocated_quantity_base) VALUES(?,?,?)'
            );
            foreach($allocations as $row)$alloc->execute([$receiptId,$row['need_id'],$row['quantity_base']]);
        }
        $priceChange=$totalCost===null?null:$this->capturePurchasePriceEventTx($receiptId,$actorUserId);
        $this->audit('supply.preparing_received','inventory_supply_receipt',$receiptId,$actorUserId,[
            'group_key'=>$groupKey,'inventory_item_id'=>$itemId,'prepared_quantity_base'=>$preparedTotal,
            'received_quantity_base'=>$received,'purchase_plan_variance_base'=>$planVariance,
            'over_plan'=>$planVariance>0,'under_plan'=>$planVariance<0,'preparing_remaining_base'=>$preparedAfter,
            'uncommitted_remaining_base'=>$uncommittedAfter,'movement_id'=>$movementId,'allocations'=>$allocations,
            'purchase_price_event_id'=>$priceChange['id']??null,
        ]);
        return [
            'item_id'=>$itemId,'movement_id'=>$movementId,'receipt_id'=>$receiptId,'received_quantity_base'=>$received,
            'purchase_plan_variance_base'=>$planVariance,'preparing_remaining_base'=>$preparedAfter,'uncommitted_remaining_base'=>$uncommittedAfter,
            'total_unmet_base'=>$totalUnmetAfter,'base_unit'=>$baseUnit,'duplicate'=>false,'purchase_price_change'=>$priceChange,
        ];
    }

    public function receiveBatchTx(array $lines,string $batchToken,int $actorUserId): array
    {
        $this->requireTx();$this->requireReadyTx();
        $batchToken=trim($batchToken);
        if(!preg_match('/^[a-f0-9]{32}$/',$batchToken))throw new SupplyException('invalid_batch_token','فرم ثبت گروهی منقضی شده؛ صفحه را تازه کن.',422);
        $lines=array_values($lines);
        if(count($lines)<2||count($lines)>50)throw new SupplyException('invalid_batch','برای ثبت گروهی، حداقل دو و حداکثر پنجاه قلم انتخاب کن.',422);

        $prepared=[];$seen=[];$tokens=[];
        foreach($lines as $i=>$line){
            if(!is_array($line))throw new SupplyException('invalid_batch','اطلاعات یکی از اقلام خرید ناقص است.',422);
            $groupKey=trim((string)($line['group_key']??''));
            if(isset($seen[$groupKey]))throw new SupplyException('duplicate_batch_line','یک قلم خرید بیش از یک‌بار در ثبت گروهی آمده است.',422);
            $seen[$groupKey]=true;$group=$this->parseGroupKey($groupKey);
            if($group['type']!=='item')throw new SupplyException('free_batch_forbidden','اقلام خارج از فهرست باید ابتدا جداگانه به کالای انبار متصل شوند.',422);
            $token=substr(hash('sha256',$batchToken.'|'.$i.'|'.$groupKey),0,32);
            $tokens[]=$token;$data=$line;$data['request_token']=$token;
            $prepared[]=['group_key'=>$groupKey,'data'=>$data,'request_token'=>$token];
        }

        $ph=implode(',',array_fill(0,count($tokens),'?'));
        $dup=$this->pdo->prepare("SELECT request_token FROM inventory_supply_receipts WHERE request_token IN ($ph) FOR UPDATE");
        $dup->execute($tokens);$existing=$dup->fetchAll(PDO::FETCH_COLUMN);
        if($existing){
            if(count($existing)!==count($tokens))
                throw new SupplyStateConflict('partial_batch_duplicate','بخشی از ثبت گروهی قبلاً ذخیره شده است؛ برای جلوگیری از ثبت دوباره، وضعیت خرید را تازه کن.',409);
            $results=[];
            foreach($prepared as $entry)$results[]=$this->receiveTx($entry['group_key'],$entry['data'],$actorUserId);
            return ['batch_token'=>$batchToken,'results'=>$results,'duplicate'=>true];
        }

        // Lock and validate every group before the first Inventory movement.
        foreach($prepared as $entry){
            $rows=$this->groupRowsTx($entry['group_key'],true);
            if(!$rows)throw new SupplyStateConflict('not_preparing','یکی از اقلام دیگر در حال خرید نیست؛ صفحه را تازه کن.',409);
            $itemId=(int)($rows[0]['inventory_item_id']??0);
            $item=$this->inventory->itemTx($itemId);
            if($item===null||(int)$item['active']!==1)throw new SupplyException('invalid_inventory_item','یکی از کالاهای انتخاب‌شده دیگر فعال نیست.',409);
            $preparedTotal=array_sum(array_map(static fn(array $r):int=>(int)$r['preparing_quantity_base'],$rows));
            $expected=(int)($entry['data']['expected_preparing_quantity_base']??-1);
            if($expected<1||$expected!==$preparedTotal)
                throw new SupplyStateConflict('supply_receipt_state_changed','مقدار یکی از اقلام از زمان بازشدن فرم تغییر کرده است؛ صفحه را تازه کن.',409);
            foreach($rows as $row)if((string)$row['base_unit']!==(string)$item['base_unit'])
                throw new SupplyException('unit_mismatch','واحد یکی از نیازهای خرید هم‌خوان نیست.',409);
            $this->inventory->resolveOperationQuantityTx(
                $itemId,(int)($entry['data']['purchase_unit_id']??0),
                $entry['data']['unit_count']??'',$entry['data']['actual_major_quantity']??''
            );
            $costRaw=trim((string)($entry['data']['total_cost']??''));if($costRaw!=='')InventoryService::moneyValue($costRaw);
            $at=trim((string)($entry['data']['occurred_at']??''));if($at!=='')$this->inventory->normalizeOccurredAt($at);
        }

        $results=[];
        foreach($prepared as $entry)$results[]=$this->receiveTx($entry['group_key'],$entry['data'],$actorUserId);
        $this->audit('supply.batch_received','inventory_supply_batch',null,$actorUserId,[
            'batch_token'=>$batchToken,'line_count'=>count($results),
            'receipt_ids'=>array_values(array_filter(array_map(static fn(array $r):int=>(int)($r['receipt_id']??0),$results))),
            'movement_ids'=>array_values(array_map(static fn(array $r):int=>(int)($r['movement_id']??0),$results)),
        ]);
        return ['batch_token'=>$batchToken,'results'=>$results,'duplicate'=>false];
    }

    public function purchaseGroups(): array
    {
        $rows=$this->pdo->query(
            "SELECT n.*,i.name inventory_name,i.default_department,i.active inventory_active,
                    COALESCE(b.quantity_base,0) quantity_base,u.display_name requester_name,pu.display_name preparing_user_name
             FROM inventory_supply_needs n
             LEFT JOIN inventory_items i ON i.id=n.inventory_item_id
             LEFT JOIN inventory_balances b ON b.inventory_item_id=n.inventory_item_id
             LEFT JOIN users u ON u.id=n.created_by_user_id
             LEFT JOIN users pu ON pu.id=n.preparing_by_user_id
             WHERE n.status='open'
             ORDER BY COALESCE(n.preparing_at,n.updated_at),n.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $groups=[];
        foreach($rows as $row){
            $key=$this->groupKeyForNeed($row);
            if(!isset($groups[$key]))$groups[$key]=[
                'group_key'=>$key,'item_id'=>(int)($row['inventory_item_id']??0),
                'name'=>(string)$row['item_name_snapshot'],'base_unit'=>(string)$row['base_unit'],
                'inventory_active'=>$row['inventory_item_id']===null?null:(int)($row['inventory_active']??0),
                'quantity_base'=>(int)($row['quantity_base']??0),
                'uncommitted_quantity_base'=>0,'preparing_quantity_base'=>0,'remaining_quantity_base'=>0,
                'need_ids'=>[],'departments'=>[],'department_quantities'=>[],'preparing_users'=>[],
            ];
            $groups[$key]['uncommitted_quantity_base']+=$this->uncommitted($row);
            $groups[$key]['preparing_quantity_base']+=(int)$row['preparing_quantity_base'];
            $groups[$key]['remaining_quantity_base']+=$this->remaining($row);
            $groups[$key]['need_ids'][]=(int)$row['id'];
            $groups[$key]['departments'][]=(string)$row['department'];
            $department=(string)$row['department'];
            $groups[$key]['department_quantities'][$department]=($groups[$key]['department_quantities'][$department]??0)+$this->remaining($row);
            if((string)($row['preparing_user_name']??'')!=='')$groups[$key]['preparing_users'][]=(string)$row['preparing_user_name'];
        }
        foreach($groups as &$group){$group['departments']=array_values(array_unique($group['departments']));$group['preparing_users']=array_values(array_unique($group['preparing_users']));}
        unset($group);
        return array_values($groups);
    }

    private function insertNeedTx(array $data,int $actorUserId,string $auditAction): int
    {
        $guard=$this->needGuard(
            (string)$data['department'],(int)($data['inventory_item_id']??0),
            (string)$data['item_name_snapshot'],(string)$data['base_unit']
        );
        $stmt=$this->pdo->prepare(
            "INSERT INTO inventory_supply_needs(inventory_item_id,item_name_snapshot,base_unit,requested_quantity_base,fulfilled_quantity_base,
             preparing_quantity_base,department,source,status,note,created_by_user_id,updated_by_user_id,open_item_guard)
             VALUES(?,?,?,?,0,0,?,?,'open',?,?,?,?)"
        );
        try{
            $stmt->execute([
                $data['inventory_item_id'],$data['item_name_snapshot'],$data['base_unit'],$data['requested_quantity_base'],
                $data['department'],$data['source'],$data['note']!==''?$data['note']:null,$actorUserId,$actorUserId,$guard,
            ]);
        }catch(PDOException $e){
            if((string)$e->getCode()==='23000')throw new SupplyStateConflict('open_need_conflict','یک نیاز باز هم‌زمان برای این قلم ایجاد شده است؛ دوباره بارگذاری کن.',409);
            throw $e;
        }
        $id=(int)$this->pdo->lastInsertId();
        $this->audit($auditAction,'inventory_supply_need',$id,$actorUserId,[
            'inventory_item_id'=>$data['inventory_item_id'],'item_name'=>$data['item_name_snapshot'],
            'department'=>$data['department'],'requested_quantity_base'=>$data['requested_quantity_base'],'source'=>$data['source'],
        ]);
        return $id;
    }

    private function mergeOpenNeedIntoTargetTx(array $need,int $targetItemId,int $actorUserId): array
    {
        $needId=(int)$need['id'];$department=(string)$need['department'];
        $existing=$this->openNeedForItemTx($targetItemId,$department);
        if(!$existing||(int)$existing['id']===$needId)return $need;
        $remaining=$this->remaining($need);$sourcePreparing=(int)$need['preparing_quantity_base'];
        if($remaining<1){
            $this->pdo->prepare(
                "UPDATE inventory_supply_needs SET status='closed',open_item_guard=NULL,preparing_quantity_base=0,preparing_by_user_id=NULL,
                 preparing_at=NULL,last_outcome='merged',closed_by_user_id=?,closed_at=NOW(),updated_by_user_id=? WHERE id=?"
            )->execute([$actorUserId,$actorUserId,$needId]);
            return $existing;
        }
        $newRequested=(int)$existing['requested_quantity_base']+$remaining;
        $newPreparing=(int)$existing['preparing_quantity_base']+$sourcePreparing;
        $note=trim((string)($existing['note']??''));if($note==='')$note=trim((string)($need['note']??''));
        $preparingBy=(int)($existing['preparing_by_user_id']??0)?: (int)($need['preparing_by_user_id']??0);
        $preparingAt=(string)($existing['preparing_at']??'')?: (string)($need['preparing_at']??'');
        $this->pdo->prepare(
            'UPDATE inventory_supply_needs SET requested_quantity_base=?,preparing_quantity_base=?,preparing_by_user_id=?,preparing_at=?,note=?,updated_by_user_id=?,last_outcome=NULL WHERE id=?'
        )->execute([$newRequested,$newPreparing,$preparingBy?:null,$preparingAt?:null,$note?:null,$actorUserId,(int)$existing['id']]);
        $this->pdo->prepare(
            "UPDATE inventory_supply_needs SET status='cancelled',open_item_guard=NULL,preparing_quantity_base=0,preparing_by_user_id=NULL,
             preparing_at=NULL,last_outcome='merged',closed_by_user_id=?,closed_at=NOW(),updated_by_user_id=? WHERE id=?"
        )->execute([$actorUserId,$actorUserId,$needId]);
        $this->audit('supply.need_merged','inventory_supply_need',$needId,$actorUserId,[
            'target_need_id'=>(int)$existing['id'],'target_inventory_item_id'=>$targetItemId,
            'merged_remaining_quantity_base'=>$remaining,'merged_preparing_quantity_base'=>$sourcePreparing,
        ]);
        $existing['requested_quantity_base']=$newRequested;$existing['preparing_quantity_base']=$newPreparing;
        $existing['preparing_by_user_id']=$preparingBy?:null;$existing['preparing_at']=$preparingAt?:null;$existing['note']=$note?:null;
        return $existing;
    }

    private function duplicateReceiptStateTx(array $existing): array
    {
        $state=$this->pdo->prepare(
            "SELECT requested_quantity_base,fulfilled_quantity_base,preparing_quantity_base
             FROM inventory_supply_needs WHERE inventory_item_id=? AND status='open' FOR UPDATE"
        );
        $state->execute([(int)$existing['inventory_item_id']]);
        $prepared=0;$uncommitted=0;$unmet=0;
        foreach($state->fetchAll(PDO::FETCH_ASSOC) as $row){
            $remaining=max(0,(int)$row['requested_quantity_base']-(int)$row['fulfilled_quantity_base']);
            $p=min($remaining,max(0,(int)$row['preparing_quantity_base']));
            $prepared+=$p;$uncommitted+=max(0,$remaining-$p);$unmet+=$remaining;
        }
        $event=$this->purchasePriceEventByReceiptTx((int)$existing['id']);
        return [
            'item_id'=>(int)$existing['inventory_item_id'],'movement_id'=>(int)$existing['movement_id'],
            'receipt_id'=>(int)$existing['id'],'received_quantity_base'=>(int)$existing['received_quantity_base'],
            'preparing_remaining_base'=>$prepared,'uncommitted_remaining_base'=>$uncommitted,'total_unmet_base'=>$unmet,
            'base_unit'=>(string)$existing['base_unit'],'duplicate'=>true,'purchase_price_change'=>$event,
        ];
    }

    private function openNeedForItemTx(int $itemId,string $department): ?array
    {
        $stmt=$this->pdo->prepare(
            "SELECT * FROM inventory_supply_needs WHERE inventory_item_id=? AND department=? AND status='open' ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$itemId,$department]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function openFreeNeedTx(string $name,string $baseUnit,string $department): ?array
    {
        $stmt=$this->pdo->prepare(
            "SELECT * FROM inventory_supply_needs WHERE inventory_item_id IS NULL AND department=? AND status='open'
             AND base_unit=? AND LOWER(TRIM(item_name_snapshot))=? ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$department,$baseUnit,self::lower($name)]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function groupRowsTx(string $groupKey,bool $preparingOnly): array
    {
        $group=$this->parseGroupKey($groupKey);
        if($group['type']==='item'){
            $sql="SELECT * FROM inventory_supply_needs WHERE inventory_item_id=? AND status='open'".
                ($preparingOnly?' AND preparing_quantity_base>0':'').
                " ORDER BY COALESCE(preparing_at,updated_at),id FOR UPDATE";
            $stmt=$this->pdo->prepare($sql);$stmt->execute([$group['id']]);
        }else{
            $sql="SELECT * FROM inventory_supply_needs WHERE inventory_item_id IS NULL AND status='open' AND base_unit=? AND LOWER(TRIM(item_name_snapshot))=?".
                ($preparingOnly?' AND preparing_quantity_base>0':'').
                " ORDER BY COALESCE(preparing_at,updated_at),id FOR UPDATE";
            $stmt=$this->pdo->prepare($sql);$stmt->execute([$group['base_unit'],$group['name']]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function parseGroupKey(string $key): array
    {
        $key=trim($key);
        if(preg_match('/^item:(\\d+)$/',$key,$m)&&(int)$m[1]>0)return ['type'=>'item','id'=>(int)$m[1]];
        if(preg_match('/^free:(g|ml|count):([A-Za-z0-9_-]+)$/',$key,$m)){
            $padding=str_repeat('=',(4-strlen($m[2])%4)%4);
            $decoded=base64_decode(strtr($m[2],'-_','+/').$padding,true);
            if($decoded===false||trim($decoded)==='')throw new SupplyException('invalid_group','قلم خرید معتبر نیست؛ صفحه را تازه کن.',422);
            return ['type'=>'free','base_unit'=>$m[1],'name'=>self::lower(trim($decoded))];
        }
        throw new SupplyException('invalid_group','قلم خرید معتبر نیست؛ صفحه را تازه کن.',422);
    }

    private function groupKeyForNeed(array $need): string
    {
        $id=(int)($need['inventory_item_id']??0);
        if($id>0)return 'item:'.$id;
        $unit=InventoryService::normalizeBaseUnit((string)($need['base_unit']??'count'));
        $name=self::lower(trim((string)($need['item_name_snapshot']??'')));
        return 'free:'.$unit.':'.rtrim(strtr(base64_encode($name),'+/','-_'),'=');
    }

    private function needGuard(string $department,int $itemId,string $name,string $baseUnit): string
    {
        $department=InventoryService::normalizeDepartment($department)??'shared';
        if($itemId>0)return $department.':i:'.$itemId;
        return $department.':n:'.self::truncate(trim($name),160).':'.InventoryService::normalizeBaseUnit($baseUnit);
    }

    private function remaining(array $need): int
    {
        return max(0,(int)$need['requested_quantity_base']-(int)$need['fulfilled_quantity_base']);
    }

    private function uncommitted(array $need): int
    {
        return max(0,$this->remaining($need)-(int)$need['preparing_quantity_base']);
    }

    private function capturePurchasePriceEventTx(int $receiptId,int $actorUserId): ?array
    {
        $this->requireTx();
        $existing=$this->purchasePriceEventByReceiptTx($receiptId);if($existing!==null)return $existing;
        $q=$this->pdo->prepare(
            'SELECT r.*,m.occurred_at purchase_occurred_at,i.name item_name,i.category category_key,i.base_unit,c.name category_name ' .
            'FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id ' .
            'JOIN inventory_items i ON i.id=r.inventory_item_id JOIN inventory_categories c ON c.category_key=i.category WHERE r.id=? FOR UPDATE'
        );
        $q->execute([$receiptId]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($receipt)||$receipt['total_cost']===null||(int)$receipt['received_quantity_base']<1)return null;
        $itemId=(int)$receipt['inventory_item_id'];$movementId=(int)$receipt['movement_id'];$quantity=(int)$receipt['received_quantity_base'];$totalCost=(int)$receipt['total_cost'];
        $unitCost=$totalCost/max(1,$quantity);
        $before=$this->inventory->projectionBeforeMovementTx($itemId,$movementId);
        $after=$this->inventory->projectionThroughMovementTx($itemId,$movementId);
        $avgBefore=$before['average_unit_cost']===null?null:(float)$before['average_unit_cost'];
        $avgAfter=$after['average_unit_cost']===null?null:(float)$after['average_unit_cost'];
        $lastStmt=$this->pdo->prepare(
            "SELECT r.id receipt_id,r.movement_id,r.received_at,r.total_cost/r.received_quantity_base unit_cost,m.occurred_at
             FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id
             WHERE r.inventory_item_id=? AND r.id<>? AND r.total_cost IS NOT NULL AND r.received_quantity_base>0
               AND (m.occurred_at<? OR (m.occurred_at=? AND m.id<?))
               AND NOT EXISTS(SELECT 1 FROM inventory_movements rv WHERE rv.reversal_of_id=m.id)
             ORDER BY m.occurred_at DESC,m.id DESC LIMIT 1 FOR UPDATE"
        );
        $at=(string)$receipt['purchase_occurred_at'];$lastStmt->execute([$itemId,$receiptId,$at,$at,$movementId]);$last=$lastStmt->fetch(PDO::FETCH_ASSOC);$last=is_array($last)?$last:null;
        $lastCost=$last===null?null:(float)$last['unit_cost'];
        $deltaLast=$lastCost!==null?$unitCost-$lastCost:null;$pctLast=$lastCost!==null&&$lastCost>0?($deltaLast/$lastCost)*100:null;
        $deltaAvg=$avgBefore!==null?$unitCost-$avgBefore:null;$pctAvg=$avgBefore!==null&&$avgBefore>0?($deltaAvg/$avgBefore)*100:null;
        $basis=$pctLast??$pctAvg;$direction='baseline';
        if($basis!==null){if(abs($basis)<0.01)$direction='unchanged';else $direction=$basis>0?'increase':'decrease';}
        $mode=(string)($receipt['conversion_base_quantity_snapshot']===null&&$receipt['purchase_unit_id']!==null?'actual':'normalized');
        $status=$lastCost!==null&&$avgBefore!==null?'ready_'.$mode:($lastCost!==null?'last_only_'.$mode:($avgBefore!==null?'average_only_'.$mode:'baseline_'.$mode));
        $policy=$this->purchasePricePolicyFromSettings();$maxPct=max(abs((float)($pctLast??0)),abs((float)($pctAvg??0)));$level='history_only';
        if($policy['enabled']&&$policy['configured']&&$basis!==null&&$maxPct>=(float)$policy['threshold_percent'])$level=$maxPct>=(float)$policy['high_threshold_percent']?'high':'standard';
        $insert=$this->pdo->prepare(
            'INSERT INTO inventory_purchase_price_events(receipt_id,inventory_item_id,item_name_snapshot,category_key_snapshot,category_name_snapshot,movement_id,supplier,purchase_unit_id,purchase_unit_name_snapshot,purchase_unit_count,base_unit,received_quantity_base,raw_total_cost,normalized_unit_cost,last_receipt_id,last_movement_id,last_unit_cost,last_purchase_at,weighted_average_before,weighted_average_after,delta_last_amount,delta_last_percent,delta_average_amount,delta_average_percent,impact_vs_last,impact_vs_average,direction,comparison_status,notification_level,purchase_occurred_at,actor_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $receiptId,$itemId,(string)$receipt['item_name'],(string)$receipt['category_key'],(string)$receipt['category_name'],$movementId,$receipt['supplier']?:null,$receipt['purchase_unit_id'],$receipt['purchase_unit_name_snapshot'],$receipt['purchase_unit_count'],(string)$receipt['base_unit'],$quantity,$totalCost,$unitCost,
            $last['receipt_id']??null,$last['movement_id']??null,$lastCost,$last['occurred_at']??null,$avgBefore,$avgAfter,$deltaLast,$pctLast,$deltaAvg,$pctAvg,
            $deltaLast===null?null:(int)round($deltaLast*$quantity),$deltaAvg===null?null:(int)round($deltaAvg*$quantity),$direction,$status,$level,$at,$actorUserId,
        ]);
        $id=(int)$this->pdo->lastInsertId();
        if($level!=='history_only'){
            $payload=[
                'price_event_id'=>$id,'receipt_id'=>$receiptId,'inventory_item_id'=>$itemId,'item_name'=>(string)$receipt['item_name'],'base_unit'=>(string)$receipt['base_unit'],
                'new_unit_cost'=>$unitCost,'last_unit_cost'=>$lastCost,'weighted_average_before'=>$avgBefore,'delta_last_percent'=>$pctLast,'delta_average_percent'=>$pctAvg,
                'direction'=>$direction,'notification_level'=>$level,'purchase_occurred_at'=>$at,'supplier'=>$receipt['supplier']?:null,
            ];
            $event=$this->pdo->prepare('INSERT IGNORE INTO notification_domain_events(event_key,event_type,entity_type,entity_id,occurred_at,payload_json) VALUES(?,?,?,?,UTC_TIMESTAMP(),?)');
            $event->execute(['inventory.purchase_price_changed.'.$id,'inventory.purchase_price_changed','inventory_purchase_price_event',$id,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        }
        $this->audit('supply.purchase_price_change_captured','inventory_purchase_price_event',$id,$actorUserId,[
            'receipt_id'=>$receiptId,'inventory_item_id'=>$itemId,'normalized_unit_cost'=>$unitCost,'last_unit_cost'=>$lastCost,'weighted_average_before'=>$avgBefore,
            'delta_last_percent'=>$pctLast,'delta_average_percent'=>$pctAvg,'direction'=>$direction,'comparison_status'=>$status,'notification_level'=>$level,
        ]);
        return $this->purchasePriceEventByReceiptTx($receiptId);
    }

    private function purchasePriceEventByReceiptTx(int $receiptId): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM inventory_purchase_price_events WHERE receipt_id=? LIMIT 1');$q->execute([$receiptId]);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    private function purchasePricePolicyFromSettings(): array
    {
        $keys=['inventory.purchase_price_notifications.enabled','inventory.purchase_price_notifications.threshold_percent','inventory.purchase_price_notifications.high_threshold_percent'];
        $ph=implode(',',array_fill(0,count($keys),'?'));$q=$this->pdo->prepare("SELECT setting_key,setting_value FROM settings WHERE setting_key IN($ph)");$q->execute($keys);$rows=$q->fetchAll(PDO::FETCH_KEY_PAIR)?:[];
        $enabled=isset($rows[$keys[0]])&&in_array(strtolower(trim((string)$rows[$keys[0]])),['1','true','yes','on'],true);
        $threshold=is_numeric($rows[$keys[1]]??null)?max(.1,(float)$rows[$keys[1]]):null;$high=is_numeric($rows[$keys[2]]??null)?(float)$rows[$keys[2]]:null;
        $configured=$threshold!==null&&$high!==null&&$high>=$threshold;
        return ['enabled'=>$enabled&&$configured,'configured'=>$configured,'threshold_percent'=>$threshold,'high_threshold_percent'=>$high];
    }

    private function requireDemandReadyTx(): void
    {
        $this->requireTx();
        $q=$this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='module.supply.enabled' LIMIT 1 FOR UPDATE");$q->execute();$raw=$q->fetchColumn();
        if($raw!==false&&$raw!==null&&!in_array(strtolower(trim((string)$raw)),['1','true','yes','on'],true))
            throw new SupplyException('supply_disabled','ثبت نیاز خرید غیرفعال است.',409);
    }

    public function receiveDirect(array $data,array $user): array
    {
        return $this->transactionalBuyer(fn(array $actor):array=>$this->receiveDirectTx($data,(int)$actor['id']),$user);
    }

    /** A purchase without a prior need is still a canonical purchase receipt. */
    public function receiveDirectTx(array $data,int $actorUserId,?array $purchaseQuantitySnapshot=null): array
    {
        $this->requireTx();$this->requireReadyTx();
        $token=trim((string)($data['request_token']??''));
        if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new SupplyException('invalid_request_token','شناسه ثبت خرید معتبر نیست.',422);
        $dup=$this->pdo->prepare('SELECT r.*,i.base_unit FROM inventory_supply_receipts r JOIN inventory_items i ON i.id=r.inventory_item_id WHERE r.request_token=? FOR UPDATE');$dup->execute([$token]);$existing=$dup->fetch(PDO::FETCH_ASSOC);
        if(is_array($existing))return $this->duplicateReceiptStateTx($existing);
        $itemId=(int)($data['item_id']??0);$item=$this->inventory->itemTx($itemId);
        if($item===null||(int)$item['active']!==1)throw new SupplyException('invalid_inventory_item','کالای فعال را انتخاب کن.',422);
        $resolved=$purchaseQuantitySnapshot??$this->inventory->resolveOperationQuantityTx($itemId,(int)($data['purchase_unit_id']??0),$data['unit_count']??'',$data['actual_major_quantity']??'');
        $quantity=(int)$resolved['quantity_base'];$raw=trim((string)($data['total_cost']??''));$cost=$raw===''?null:InventoryService::moneyValue($raw);
        $supplier=self::truncate(trim((string)($data['supplier']??'')),160);$note=self::truncate(trim((string)($data['note']??'')),500);
        $at=trim((string)($data['occurred_at']??''));$at=$at!==''?$this->inventory->normalizeOccurredAt($at):null;
        $movement=$this->inventory->recordMovementTx(array_merge($resolved,[
            'item_id'=>$itemId,'movement_type'=>'purchase_receive','quantity_base'=>$quantity,
            'department'=>InventoryService::normalizeDepartment((string)($data['department']??$item['default_department'])),
            'total_cost_delta'=>$cost,'cost_status'=>$cost===null?'unknown':'known','source_type'=>'direct_purchase','source_id'=>$token,
            'idempotency_key'=>'supply:receive:'.$token,'actor_user_id'=>$actorUserId,'occurred_at'=>$at,'note'=>$note?:null,
            'metadata'=>['entry_mode'=>'direct_purchase','supplier'=>$supplier?:null],
        ]));
        $q=$this->pdo->prepare('INSERT INTO inventory_supply_receipts(supply_need_id,inventory_item_id,requested_quantity_snapshot,received_quantity_base,remaining_quantity_after,purchase_unit_id,purchase_unit_name_snapshot,purchase_unit_count,conversion_base_quantity_snapshot,total_cost,supplier,note,request_token,movement_id,actor_user_id,received_at) VALUES(NULL,?,?,?,0,?,?,?,?,?,?,?,?,?,?,COALESCE(?,NOW()))');
        $q->execute([$itemId,$quantity,$quantity,$resolved['purchase_unit_id'],$resolved['purchase_unit_name_snapshot'],$resolved['purchase_unit_count'],$resolved['conversion_base_quantity_snapshot'],$cost,$supplier?:null,$note?:null,$token,$movement,$actorUserId,$at]);
        $receipt=(int)$this->pdo->lastInsertId();$price=$cost===null?null:$this->capturePurchasePriceEventTx($receipt,$actorUserId);
        $this->audit('supply.direct_purchase_received','inventory_supply_receipt',$receipt,$actorUserId,['inventory_item_id'=>$itemId,'movement_id'=>$movement,'received_quantity_base'=>$quantity,'total_cost'=>$cost]);
        return ['receipt_id'=>$receipt,'movement_id'=>$movement,'item_id'=>$itemId,'received_quantity_base'=>$quantity,'duplicate'=>false,'purchase_price_change'=>$price];
    }

    private function requireReadyTx(): void
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='module.supply.enabled' LIMIT 1 FOR UPDATE");
        $stmt->execute();$raw=$stmt->fetchColumn();
        $enabled=$raw===false||$raw===null?true:in_array(strtolower(trim((string)$raw)),['1','true','yes','on'],true);
        if(!$enabled||!$this->inventory->runtimeReadyTx())
            throw new SupplyException('supply_not_ready','خرید و تأمین در حال حاضر برای عملیات آماده نیست.',409);
    }

    private function transactionalBuyer(callable $fn,array $user): array
    {
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $actor=$this->access->assertBuyer($user);
            $result=$fn($actor);
            if($ownsTransaction)$this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function audit(string $action,string $entityType,int|string|null $entityId,int $actorId,array $details): void
    {
        $display=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
        $display->execute([$actorId]);$name=$display->fetchColumn();
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)'
        )->execute([$actorId,$name!==false?$name:null,$action,$entityType,$entityId===null?null:(string)$entityId,$json?:'{}']);
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Supply canonical operation requires an open transaction.');
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    private static function percentValue(mixed $value,float $min,float $max,string $label): float
    {
        $raw=str_replace([',','٪','%',' '],['.','','',''],trim((string)$value));
        if($raw===''||!is_numeric($raw))throw new SupplyException('invalid_percent',$label.' معتبر نیست.',422);
        $number=(float)$raw;if($number<$min||$number>$max)throw new SupplyException('invalid_percent',$label.' باید بین '.$min.' و '.$max.' درصد باشد.',422);
        return round($number,2);
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
