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

    public function upsertNeed(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $department=InventoryService::normalizeDepartment((string)($data['department']??'shared'))??'shared';
            $actor=$this->access->assertReporter($user,$department);
            $id=$this->upsertNeedTx($data+['department'=>$department],(int)$actor['id']);
            $this->pdo->commit();
            return ['need_id'=>$id];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function addNeedDeferredTx(array $data,int $actorUserId): int
    {
        $this->requireTx();
        $this->requireReadyTx();
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

    public function upsertNeedTx(array $data,int $actorUserId): int
    {
        $this->requireTx();$this->requireReadyTx();
        $itemId=max(0,(int)($data['inventory_item_id']??0));
        $department=InventoryService::normalizeDepartment((string)($data['department']??'shared'))??'shared';
        $note=self::truncate(trim((string)($data['note']??'')),500);
        $source=in_array((string)($data['source']??'staff'),['staff','manager','low_stock'],true)
            ?(string)($data['source']??'staff'):'staff';

        if($itemId>0){
            $item=$this->inventory->itemTx($itemId);
            if($item===null||(int)$item['active']!==1)throw new SupplyException('invalid_inventory_item','کالای انتخاب‌شده دیگر فعال نیست.',409);
            $baseUnit=(string)$item['base_unit'];
            $uncommitted=InventoryService::majorToBase($data['quantity_major']??'',$baseUnit);
            if($uncommitted<1)throw new SupplyException('invalid_quantity','مقدار موردنیاز را وارد کن.',422);
            $existing=$this->openNeedForItemTx($itemId,$department);
            if($existing){
                $fulfilled=(int)$existing['fulfilled_quantity_base'];
                $preparing=(int)$existing['preparing_quantity_base'];
                $requested=$fulfilled+$preparing+$uncommitted;
                $this->pdo->prepare(
                    "UPDATE inventory_supply_needs SET requested_quantity_base=?,item_name_snapshot=?,base_unit=?,source=?,note=?,updated_by_user_id=?,last_outcome=NULL WHERE id=?"
                )->execute([$requested,(string)$item['name'],$baseUnit,$source,$note?:null,$actorUserId,(int)$existing['id']]);
                $this->audit('supply.need_updated','inventory_supply_need',(int)$existing['id'],$actorUserId,[
                    'inventory_item_id'=>$itemId,'department'=>$department,'requested_quantity_base'=>$requested,
                    'fulfilled_quantity_base'=>$fulfilled,'preparing_quantity_base'=>$preparing,'uncommitted_quantity_base'=>$uncommitted,
                ]);
                return (int)$existing['id'];
            }
            return $this->insertNeedTx([
                'inventory_item_id'=>$itemId,'item_name_snapshot'=>(string)$item['name'],'base_unit'=>$baseUnit,
                'requested_quantity_base'=>$uncommitted,'department'=>$department,'source'=>$source,'note'=>$note,
            ],$actorUserId,'supply.need_created');
        }

        $name=self::truncate(trim((string)($data['free_name']??'')),160);
        $baseUnit=InventoryService::normalizeBaseUnit((string)($data['base_unit']??'count'));
        if($name==='')throw new SupplyException('name_required','نام مورد خارج از فهرست را وارد کن.',422);
        $uncommitted=InventoryService::majorToBase($data['quantity_major']??'',$baseUnit);
        if($uncommitted<1)throw new SupplyException('invalid_quantity','مقدار موردنیاز را وارد کن.',422);
        $existing=$this->openFreeNeedTx($name,$baseUnit,$department);
        if($existing){
            $fulfilled=(int)$existing['fulfilled_quantity_base'];$preparing=(int)$existing['preparing_quantity_base'];
            $requested=$fulfilled+$preparing+$uncommitted;
            $this->pdo->prepare(
                "UPDATE inventory_supply_needs SET requested_quantity_base=?,source=?,note=?,updated_by_user_id=?,last_outcome=NULL WHERE id=?"
            )->execute([$requested,$source,$note?:null,$actorUserId,(int)$existing['id']]);
            $this->audit('supply.need_updated','inventory_supply_need',(int)$existing['id'],$actorUserId,[
                'free_name'=>$name,'department'=>$department,'requested_quantity_base'=>$requested,
                'preparing_quantity_base'=>$preparing,'uncommitted_quantity_base'=>$uncommitted,
            ]);
            return (int)$existing['id'];
        }
        return $this->insertNeedTx([
            'inventory_item_id'=>null,'item_name_snapshot'=>$name,'base_unit'=>$baseUnit,
            'requested_quantity_base'=>$uncommitted,'department'=>$department,'source'=>$source,'note'=>$note,
        ],$actorUserId,'supply.need_created');
    }

    public function prepareTx(string $groupKey,?int $expectedUncommitted,int $actorUserId): array
    {
        $this->requireTx();$this->requireReadyTx();
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
        $this->requireTx();$this->requireReadyTx();
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
        $this->requireTx();$this->requireReadyTx();
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

    public function receiveTx(string $groupKey,array $data,int $actorUserId): array
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
            if(count($rows)!==1)throw new SupplyStateConflict('free_group_ambiguous','کالای خارج از فهرست باید ابتدا به یک کالای انبار متصل شود.',409);
            $need=$anchor;$needId=(int)$need['id'];
            if($targetId>0){
                $target=$this->inventory->itemTx($targetId);
                if($target===null||(int)$target['active']!==1)throw new SupplyException('invalid_target_item','کالای مقصد معتبر نیست.',422);
                if((string)$target['base_unit']!==(string)$need['base_unit'])throw new SupplyException('unit_mismatch','واحد کالای مقصد با نیاز ثبت‌شده هم‌خوان نیست.',422);
                $merged=$this->mergeOpenNeedIntoTargetTx($need,$targetId,$actorUserId);
                $itemId=$targetId;
                if((int)$merged['id']!=$needId){
                    $groupKey='item:'.$targetId;$rows=$this->groupRowsTx($groupKey,true);
                    if(!$rows)throw new SupplyStateConflict('merged_state_changed','درخواست‌ها ادغام شدند اما مقدار در حال خرید پیدا نشد؛ صفحه را تازه کن.',409);
                    $anchor=$rows[0];
                }else{
                    $guard=$this->needGuard((string)$need['department'],$itemId,(string)$target['name'],(string)$target['base_unit']);
                    $this->pdo->prepare(
                        'UPDATE inventory_supply_needs SET inventory_item_id=?,item_name_snapshot=?,base_unit=?,open_item_guard=?,updated_by_user_id=? WHERE id=?'
                    )->execute([$itemId,(string)$target['name'],(string)$target['base_unit'],$guard,$actorUserId,$needId]);
                    $groupKey='item:'.$itemId;$rows=$this->groupRowsTx($groupKey,true);$anchor=$rows[0];
                }
            }else{
                $itemId=$this->inventory->createUnreviewedItemTx(
                    (string)$need['item_name_snapshot'],(string)$need['base_unit'],(string)$need['department'],$actorUserId,'خرید و تأمین'
                );
                $guard=$this->needGuard((string)$need['department'],$itemId,(string)$need['item_name_snapshot'],(string)$need['base_unit']);
                $this->pdo->prepare(
                    'UPDATE inventory_supply_needs SET inventory_item_id=?,open_item_guard=?,updated_by_user_id=? WHERE id=?'
                )->execute([$itemId,$guard,$actorUserId,$needId]);
                $groupKey='item:'.$itemId;$rows=$this->groupRowsTx($groupKey,true);$anchor=$rows[0];
            }
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

        $resolved=$this->inventory->resolveOperationQuantityTx(
            $itemId,(int)($data['purchase_unit_id']??0),$data['unit_count']??'',$data['actual_major_quantity']??''
        );
        $received=(int)$resolved['quantity_base'];
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
        $this->audit('supply.preparing_received','inventory_supply_receipt',$receiptId,$actorUserId,[
            'group_key'=>$groupKey,'inventory_item_id'=>$itemId,'prepared_quantity_base'=>$preparedTotal,
            'received_quantity_base'=>$received,'preparing_remaining_base'=>$preparedAfter,
            'uncommitted_remaining_base'=>$uncommittedAfter,'movement_id'=>$movementId,'allocations'=>$allocations,
        ]);
        return [
            'item_id'=>$itemId,'movement_id'=>$movementId,'receipt_id'=>$receiptId,'received_quantity_base'=>$received,
            'preparing_remaining_base'=>$preparedAfter,'uncommitted_remaining_base'=>$uncommittedAfter,
            'total_unmet_base'=>$totalUnmetAfter,'base_unit'=>$baseUnit,'duplicate'=>false,
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
                'need_ids'=>[],'departments'=>[],
            ];
            $groups[$key]['uncommitted_quantity_base']+=$this->uncommitted($row);
            $groups[$key]['preparing_quantity_base']+=(int)$row['preparing_quantity_base'];
            $groups[$key]['remaining_quantity_base']+=$this->remaining($row);
            $groups[$key]['need_ids'][]=(int)$row['id'];
            $groups[$key]['departments'][]=(string)$row['department'];
        }
        foreach($groups as &$group)$group['departments']=array_values(array_unique($group['departments']));
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
        return [
            'item_id'=>(int)$existing['inventory_item_id'],'movement_id'=>(int)$existing['movement_id'],
            'receipt_id'=>(int)$existing['id'],'received_quantity_base'=>(int)$existing['received_quantity_base'],
            'preparing_remaining_base'=>$prepared,'uncommitted_remaining_base'=>$uncommitted,'total_unmet_base'=>$unmet,
            'base_unit'=>(string)$existing['base_unit'],'duplicate'=>true,
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
        $this->pdo->beginTransaction();
        try{
            $actor=$this->access->assertBuyer($user);
            $result=$fn($actor);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
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

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
