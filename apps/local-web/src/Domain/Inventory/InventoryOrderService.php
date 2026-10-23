<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Inventory;

use PDO;
use Throwable;

final class InventoryOrderService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly InventoryService $inventory,
    ) {}

    public function saveRecipe(int $menuItemId,array $components,array $user,bool $reviewed=true,bool $complete=true): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->inventory->assertActor($user,'inventory_manage');
            $normalized=$this->normalizeComponents($components);
            $menuLock=$this->pdo->prepare('SELECT id FROM items WHERE id=? FOR UPDATE');
            $menuLock->execute([$menuItemId]);
            if($menuLock->fetchColumn()===false)throw new InventoryException('menu_item_not_found','آیتم منو برای دستور مصرف پیدا نشد.',404);
            $current=$this->activeRecipeTx($menuItemId,true);
            $currentQuantities=[];$newQuantities=[];$currentState=[];$newState=[];
            foreach((array)($current['components']??[]) as $component){
                $id=(int)$component['inventory_item_id'];$qty=(int)$component['quantity_base'];
                $currentQuantities[$id]=$qty;
                $currentState[$id]=[$qty,(string)($component['quantity_status']??'known'),trim((string)($component['source_note']??''))];
            }
            foreach($normalized as $inventoryItemId=>$component){
                $id=(int)$inventoryItemId;$qty=(int)$component['quantity_base'];
                $newQuantities[$id]=$qty;
                $newState[$id]=[$qty,(string)$component['quantity_status'],trim((string)($component['source_note']??''))];
            }
            ksort($currentQuantities);ksort($newQuantities);ksort($currentState);ksort($newState);
            $contentChanged=$newState!==$currentState;
            $currentReview=(string)($current['review_status']??'ready');
            if(!in_array($currentReview,['ready','needs_review'],true))$currentReview='ready';
            $currentCompleteness=(string)($current['completeness_status']??'complete');
            if(!in_array($currentCompleteness,['complete','partial','unknown'],true))$currentCompleteness='complete';
            $targetReview=$reviewed?'ready':'needs_review';
            $targetCompleteness=$complete?'complete':'partial';

            if(!$contentChanged){
                $reviewChanged=$current&&$targetReview!==$currentReview;
                $completenessChanged=$current&&$targetCompleteness!==$currentCompleteness;
                if($reviewChanged||$completenessChanged){
                    $completenessNote=$targetCompleteness==='complete'?null:self::truncate((string)($current['completeness_note']??'رسپی هنوز از نظر پوشش مواد کامل تأیید نشده است.'),1000);
                    $this->pdo->prepare("UPDATE inventory_recipe_versions SET review_status=?,review_note=?,completeness_status=?,completeness_note=? WHERE id=? AND status='active'")
                        ->execute([$targetReview,$targetReview==='ready'?null:(string)($current['review_note']??''),$targetCompleteness,$completenessNote,(int)$current['id']]);
                    $this->audit('inventory.recipe_metadata_updated','menu_item',$menuItemId,(int)$actor['id'],[
                        'recipe_id'=>(int)$current['id'],'review_from'=>$currentReview,'review_to'=>$targetReview,'completeness_from'=>$currentCompleteness,'completeness_to'=>$targetCompleteness,
                    ]);
                }
                $this->pdo->commit();
                return ['recipe_id'=>$current?(int)$current['id']:null,'changed'=>false,'review_changed'=>(bool)$reviewChanged,'completeness_changed'=>(bool)$completenessChanged,'review_status'=>$targetReview,'completeness_status'=>$targetCompleteness];
            }
            $this->validateComponentsTx($normalized);

            if($current){
                $this->pdo->prepare("UPDATE inventory_recipe_versions SET status='retired',retired_at=NOW() WHERE id=? AND status='active'")
                    ->execute([(int)$current['id']]);
            }
            if($normalized===[]){
                $this->audit('inventory.recipe_removed','menu_item',$menuItemId,(int)$actor['id'],['previous_recipe_id'=>$current['id']??null]);
                $this->pdo->commit();
                return ['recipe_id'=>null,'changed'=>true,'review_status'=>'ready'];
            }

            $versionStmt=$this->pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM inventory_recipe_versions WHERE menu_item_id=?');
            $versionStmt->execute([$menuItemId]);$version=(int)$versionStmt->fetchColumn();
            $reviewStatus=$reviewed?'ready':'needs_review';
            $sourceRef=$current&&$currentReview==='needs_review'&&!$reviewed?'manual_edit_from_'.self::truncate((string)($current['source_ref']??'provisional'),160):'manual_edit';
            $reviewNote=$reviewStatus==='needs_review'?self::truncate((string)($current['review_note']??'رسپی هنوز نیازمند بازبینی و تکمیل دستی است.'),500):null;
            $completenessStatus=$complete?'complete':'partial';
            $completenessNote=$completenessStatus==='complete'?null:self::truncate((string)($current['completeness_note']??'رسپی هنوز از نظر پوشش مواد کامل تأیید نشده است.'),1000);
            $this->pdo->prepare("INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,review_status,completeness_status,source_ref,review_note,completeness_note,created_by_user_id) VALUES(?,?,'active',?,?,?,?,?,?)")
                ->execute([$menuItemId,$version,$reviewStatus,$completenessStatus,$sourceRef,$reviewNote,$completenessNote,(int)$actor['id']]);
            $recipeId=(int)$this->pdo->lastInsertId();
            $insert=$this->pdo->prepare('INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base,quantity_status,source_note) VALUES(?,?,?,?,?)');
            foreach($normalized as $inventoryItemId=>$component)
                $insert->execute([$recipeId,$inventoryItemId,(int)$component['quantity_base'],(string)$component['quantity_status'],$component['source_note']]);

            $this->audit('inventory.recipe_version_created','menu_item',$menuItemId,(int)$actor['id'],[
                'recipe_id'=>$recipeId,'version_no'=>$version,'review_status'=>$reviewStatus,'completeness_status'=>$completenessStatus,'components'=>$newQuantities,
            ]);
            $this->pdo->commit();
            return ['recipe_id'=>$recipeId,'changed'=>true,'version_no'=>$version,'review_status'=>$reviewStatus,'completeness_status'=>$completenessStatus];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function recipeEditorSnapshot(array $user): array
    {
        $this->inventory->assertActor($user,'inventory_manage');
        $items=$this->pdo->query(
            "SELECT i.id,i.name,i.base_unit,i.category,i.active,i.review_status,i.review_note,c.name category_name,COALESCE(b.quantity_base,0) quantity_base " .
            "FROM inventory_items i JOIN inventory_categories c ON c.category_key=i.category " .
            "LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id ORDER BY i.active DESC,c.sort_order,i.name,i.id"
        )->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($items as &$item){$item['id']=(int)$item['id'];$item['active']=(bool)$item['active'];$item['quantity_base']=(int)$item['quantity_base'];}unset($item);
        $rows=$this->pdo->query(
            "SELECT v.id recipe_id,v.menu_item_id,v.version_no,v.review_status,v.completeness_status,v.source_ref,v.review_note,v.completeness_note,c.inventory_item_id,c.quantity_base,c.quantity_status,c.source_note,i.name inventory_item_name,i.base_unit " .
            "FROM inventory_recipe_versions v JOIN inventory_recipe_components c ON c.recipe_version_id=v.id " .
            "JOIN inventory_items i ON i.id=c.inventory_item_id WHERE v.status='active' ORDER BY v.menu_item_id,c.id"
        )->fetchAll(PDO::FETCH_ASSOC)?:[];
        $recipes=[];
        foreach($rows as $row){
            $menuId=(int)$row['menu_item_id'];
            if(!isset($recipes[$menuId]))$recipes[$menuId]=[
                'recipe_id'=>(int)$row['recipe_id'],'version_no'=>(int)$row['version_no'],
                'review_status'=>(string)($row['review_status']??'ready'),'completeness_status'=>(string)($row['completeness_status']??'complete'),'source_ref'=>$row['source_ref']??null,
                'review_note'=>$row['review_note']??null,'completeness_note'=>$row['completeness_note']??null,'components'=>[],
            ];
            $recipes[$menuId]['components'][]=[
                'inventory_item_id'=>(int)$row['inventory_item_id'],'quantity_base'=>(int)$row['quantity_base'],
                'quantity_status'=>(string)($row['quantity_status']??'known'),'source_note'=>$row['source_note']??null,
                'inventory_item_name'=>(string)$row['inventory_item_name'],'base_unit'=>(string)$row['base_unit'],
            ];
        }
        return ['inventory_items'=>$items,'recipes'=>$recipes];
    }

    public function saveRecipeEditor(int $menuItemId,array $components,array $user,bool $reviewed=true,bool $complete=true): array
    {
        $normalized=[];
        foreach($components as $component){
            if(!is_array($component))throw new InventoryException('invalid_recipe','اطلاعات مواد مصرفی معتبر نیست.',422);
            $id=(int)($component['inventory_item_id']??0);
            $raw=trim((string)($component['quantity_base']??''));
            if($id<1||$raw===''||!preg_match('/^\d+$/',$raw)||(int)$raw<1)
                throw new InventoryException('invalid_recipe','مقدار مصرف هر ماده باید با واحد نمایش‌داده‌شده و بیشتر از صفر باشد.',422);
            $status=(string)($component['quantity_status']??'known');
            if(!in_array($status,['known','estimated'],true))$status='known';
            $normalized[]=[
                'inventory_item_id'=>$id,'quantity_base'=>(int)$raw,'quantity_status'=>$status,
                'source_note'=>self::truncate(trim((string)($component['source_note']??'')),500)?:null,
            ];
        }
        return $this->saveRecipe($menuItemId,$normalized,$user,$reviewed,$complete);
    }

    public function enqueueAccountedTx(int $orderId,int $actorUserId): ?int
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory order enqueue requires an open transaction.');
        try{
            if(!$this->inventory->runtimeReadyTx())return null;
            $payload=['recipe_snapshot'=>$this->orderRecipeSnapshotTx($orderId)];
            $key='accounted:order:'.$orderId.':'.hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $stmt=$this->pdo->prepare(
                "INSERT IGNORE INTO inventory_order_events(event_type,order_id,order_item_id,payload_json,idempotency_key,status,actor_user_id)
                 VALUES('accounted',?,NULL,?,?,'pending',?)"
            );
            $stmt->execute([
                $orderId,
                json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                substr($key,0,190),
                $actorUserId>0?$actorUserId:null,
            ]);
            $eventId=(int)$this->pdo->lastInsertId();
            if($eventId<1){
                $find=$this->pdo->prepare('SELECT id FROM inventory_order_events WHERE idempotency_key=? LIMIT 1');
                $find->execute([substr($key,0,190)]);
                $eventId=(int)($find->fetchColumn()?:0);
            }
            if($eventId>0)$this->attemptProcessTx($eventId);
            return $eventId>0?$eventId:null;
        }catch(Throwable $e){
            // Inventory is optional. Ordering must remain protected from secondary-module failure.
            return null;
        }
    }

    public function enqueueQuantityAdjustedTx(int $orderId,int $orderItemId,int $fromQuantity,int $toQuantity,int $inventoryQuantityDelta,int $actorUserId,string $requestId): ?int
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory quantity-adjustment enqueue requires an open transaction.');
        try{
            if($inventoryQuantityDelta===0||!$this->inventory->runtimeReadyTx())return null;
            $snapshot=null;
            foreach($this->accountedRecipeSnapshotTx($orderId) as $row){
                if(is_array($row)&&(int)($row['order_item_id']??0)===$orderItemId){$snapshot=$row;break;}
            }
            if(!is_array($snapshot)||(int)($snapshot['recipe_version_id']??0)<1)return null;
            $payload=[
                'request_id'=>$requestId,'order_item_id'=>$orderItemId,'from_quantity'=>$fromQuantity,'to_quantity'=>$toQuantity,
                'inventory_quantity_delta'=>$inventoryQuantityDelta,'recipe_snapshot'=>$snapshot,
            ];
            $key='quantity_adjusted:order-item:'.$orderItemId.':'.$requestId;
            $stmt=$this->pdo->prepare(
                "INSERT IGNORE INTO inventory_order_events(event_type,order_id,order_item_id,payload_json,idempotency_key,status,actor_user_id)
                 VALUES('quantity_adjusted',?,?,?,?, 'pending',?)"
            );
            $stmt->execute([$orderId,$orderItemId,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),substr($key,0,190),$actorUserId>0?$actorUserId:null]);
            $eventId=(int)$this->pdo->lastInsertId();
            if($eventId<1){$find=$this->pdo->prepare('SELECT id FROM inventory_order_events WHERE idempotency_key=? LIMIT 1');$find->execute([substr($key,0,190)]);$eventId=(int)($find->fetchColumn()?:0);}
            if($eventId>0)$this->attemptProcessTx($eventId);
            return $eventId>0?$eventId:null;
        }catch(Throwable){
            // Inventory remains degradable relative to order/account operations; the durable order adjustment survives.
            return null;
        }
    }

    public function processPending(int $limit=30): array
    {
        $limit=max(1,min(100,$limit));
        $ids=$this->pdo->query(
            "SELECT e.id FROM inventory_order_events e
             WHERE e.status='pending'
               AND NOT EXISTS(
                   SELECT 1 FROM inventory_order_events p
                   WHERE p.order_id=e.order_id AND p.id<e.id AND p.status<>'done'
               )
             ORDER BY e.id LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_COLUMN);
        $processed=0;$failed=0;
        foreach($ids as $id){
            $this->pdo->beginTransaction();
            try{
                if($this->attemptProcessTx((int)$id))$processed++;else $failed++;
                $this->pdo->commit();
            }catch(Throwable){
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                $failed++;
            }
        }
        return ['processed'=>$processed,'failed'=>$failed];
    }

    /** Return the exact recipe/cost snapshot captured by the durable accounted event. */
    public function accountedRecipeSnapshotTx(int $orderId): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory accounted snapshot requires an open transaction.');
        if($orderId<1)return [];
        $stmt=$this->pdo->prepare(
            "SELECT payload_json FROM inventory_order_events WHERE order_id=? AND event_type='accounted' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$orderId]);$json=$stmt->fetchColumn();
        if($json===false)return [];
        $payload=json_decode((string)$json,true);
        return is_array($payload)&&is_array($payload['recipe_snapshot']??null)?array_values($payload['recipe_snapshot']):[];
    }

    /**
     * Summarize the immutable Inventory recipe snapshot without re-reading current cost/recipe state.
     * known_cost_amount includes only components whose captured cost_status was known.
     * Estimated/unknown values remain explicit in the snapshot for reporting.
     * @return array<string,mixed>
     */
    public function costSummaryFromRecipeSnapshot(array $snapshot): array
    {
        $knownTotal=0;$estimatedTotal=0;$unknownComponents=0;$lines=[];
        foreach($snapshot as $row){
            if(!is_array($row))continue;
            $orderItemId=(int)($row['order_item_id']??0);if($orderItemId<1)continue;
            $orderQty=max(0,(int)($row['order_quantity']??0));
            $lineKnown=0;$lineEstimated=0;$lineUnknown=0;$components=[];
            foreach((array)($row['components']??[]) as $component){
                if(!is_array($component))continue;
                $perUnit=max(0,(int)($component['quantity_base']??0));
                $unit=$component['unit_cost_snapshot']===null?null:(float)$component['unit_cost_snapshot'];
                $status=(string)($component['cost_status']??'unknown');
                if(!in_array($status,['known','estimated','unknown'],true))$status='unknown';
                $quantity=$perUnit*$orderQty;$amount=$unit===null?null:(int)round($unit*$quantity);
                if($status==='known'&&$amount!==null)$lineKnown+=$amount;
                elseif($amount!==null){$lineEstimated+=$amount;if($status==='unknown')$status='estimated';}
                else $lineUnknown++;
                $components[]=[
                    'recipe_component_id'=>(int)($component['recipe_component_id']??0)?:null,
                    'inventory_item_id'=>(int)($component['inventory_item_id']??0),
                    'quantity_base_per_unit'=>$perUnit,'order_quantity'=>$orderQty,'quantity_base_total'=>$quantity,
                    'unit_cost_snapshot'=>$unit,'cost_status'=>$status,'cost_amount_snapshot'=>$amount,
                ];
            }
            $recipeVersionId=(int)($row['recipe_version_id']??0);
            $lineStatus=$lineUnknown>0?'partial':($lineEstimated>0?'estimated':($recipeVersionId>0?'known':'unknown'));
            $lineKey=(string)$orderItemId;
            if(isset($lines[$lineKey])){
                $lines[$lineKey]['known_cost_amount']+=(int)$lineKnown;$lines[$lineKey]['estimated_cost_amount']+=(int)$lineEstimated;$lines[$lineKey]['unknown_component_count']+=(int)$lineUnknown;
                $lines[$lineKey]['components']=array_merge((array)$lines[$lineKey]['components'],$components);$lines[$lineKey]['cost_status']=$lines[$lineKey]['unknown_component_count']>0?'partial':($lines[$lineKey]['estimated_cost_amount']>0?'estimated':'known');
            }else $lines[$lineKey]=[
                'order_item_id'=>$orderItemId,'menu_item_id'=>(int)($row['menu_item_id']??0),'recipe_version_id'=>$recipeVersionId?:null,'recipe_version_no'=>(int)($row['recipe_version_no']??0)?:null,
                'department'=>(string)($row['department']??'shared'),'known_cost_amount'=>$lineKnown,'estimated_cost_amount'=>$lineEstimated,'unknown_component_count'=>$lineUnknown,'cost_status'=>$lineStatus,'components'=>$components,
            ];
            $knownTotal+=$lineKnown;$estimatedTotal+=$lineEstimated;$unknownComponents+=$lineUnknown;
        }
        return [
            'snapshot_version'=>'f1.5-v1','known_cost_amount'=>$knownTotal,'estimated_cost_amount'=>$estimatedTotal,
            'unknown_component_count'=>$unknownComponents,'lines'=>$lines,
        ];
    }

    public function orderRecipeSnapshotTx(int $orderId): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory recipe snapshot requires an open transaction.');
        if($orderId<1)return [];
        $stmt=$this->pdo->prepare(
            'SELECT oi.id,oi.item_id,oi.quantity,oi.preparation_station
             FROM order_items oi WHERE oi.order_id=? AND oi.item_id IS NOT NULL ORDER BY oi.id'
        );
        $stmt->execute([$orderId]);$snapshot=[];
        $bundle=$this->pdo->prepare('SELECT component_item_id,quantity,preparation_station_snapshot,item_name_snapshot FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id');
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $orderItem){
            $bundle->execute([(int)$orderItem['id']]);$componentRows=$bundle->fetchAll(PDO::FETCH_ASSOC)?:[];
            $sources=[];
            if($componentRows!==[]){
                foreach($componentRows as $component){
                    if((int)($component['component_item_id']??0)<1)continue;
                    $sources[]=['menu_item_id'=>(int)$component['component_item_id'],'order_quantity'=>max(0,(int)$component['quantity']),'station'=>(string)($component['preparation_station_snapshot']??'none'),'bundle_component'=>true,'bundle_component_name'=>(string)($component['item_name_snapshot']??'')];
                }
            }else{
                $sources[]=['menu_item_id'=>(int)$orderItem['item_id'],'order_quantity'=>max(0,(int)$orderItem['quantity']),'station'=>(string)($orderItem['preparation_station']??'none'),'bundle_component'=>false,'bundle_component_name'=>null];
            }
            foreach($sources as $source){
                if(self::areaForStation((string)$source['station'])===null)continue;
                $menuItemId=(int)$source['menu_item_id'];$recipe=$this->activeRecipeTx($menuItemId,false);$components=[];
                foreach((array)($recipe['components']??[]) as $component){
                    $components[]=[
                        'recipe_component_id'=>(int)$component['id'],'inventory_item_id'=>(int)$component['inventory_item_id'],'quantity_base'=>(int)$component['quantity_base'],
                        'quantity_status'=>(string)($component['quantity_status']??'known'),'source_note'=>$component['source_note']??null,
                        'unit_cost_snapshot'=>$component['average_unit_cost']===null?null:(float)$component['average_unit_cost'],'cost_status'=>(string)($component['balance_cost_status']??'unknown'),
                    ];
                }
                $snapshot[]=[
                    'order_item_id'=>(int)$orderItem['id'],'menu_item_id'=>$menuItemId,'order_quantity'=>(int)$source['order_quantity'],
                    'department'=>self::areaForStation((string)$source['station'])??'shared','bundle_component'=>(bool)$source['bundle_component'],'bundle_component_name'=>$source['bundle_component_name'],
                    'recipe_version_id'=>$recipe?(int)$recipe['id']:null,'recipe_version_no'=>$recipe?(int)$recipe['version_no']:null,
                    'recipe_review_status'=>$recipe?(string)($recipe['review_status']??'ready'):null,'recipe_completeness_status'=>$recipe?(string)($recipe['completeness_status']??'complete'):null,
                    'recipe_source_ref'=>$recipe?($recipe['source_ref']??null):null,'components'=>$components,
                ];
            }
        }
        return $snapshot;
    }

    private function attemptProcessTx(int $eventId): bool
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory event processing requires an open transaction.');
        if(!$this->inventory->runtimeReadyTx())return false;

        $stmt=$this->pdo->prepare('SELECT * FROM inventory_order_events WHERE id=? FOR UPDATE');
        $stmt->execute([$eventId]);$event=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($event))return false;
        if((string)$event['status']==='done')return true;
        if((string)$event['status']!=='pending')return false;

        $previous=$this->pdo->prepare("SELECT id FROM inventory_order_events WHERE order_id=? AND id<? AND status<>'done' ORDER BY id LIMIT 1 FOR UPDATE");
        $previous->execute([(int)$event['order_id'],$eventId]);
        if($previous->fetchColumn()!==false)return false;

        $this->pdo->exec('SAVEPOINT inventory_event_apply');
        try{
            $payload=json_decode((string)($event['payload_json']??''),true);
            if(!is_array($payload))$payload=[];
            $eventType=(string)$event['event_type'];
            if($eventType==='accounted'){
                $this->applyAccountedTx(
                    (int)$event['order_id'],
                    (int)($event['actor_user_id']??0),
                    is_array($payload['recipe_snapshot']??null)?$payload['recipe_snapshot']:[],
                    (string)$event['created_at']
                );
            }elseif($eventType==='quantity_adjusted'){
                $this->applyQuantityAdjustedTx(
                    (int)$event['id'],(int)$event['order_id'],(int)($event['order_item_id']??0),
                    (int)($event['actor_user_id']??0),$payload,(string)$event['created_at']
                );
            }else{
                throw new InventoryException('unsupported_order_event','این رخداد سفارش هنوز در Inventory V3 پشتیبانی نمی‌شود.',409);
            }
            $this->pdo->prepare(
                "UPDATE inventory_order_events SET status='done',attempt_count=attempt_count+1,last_error=NULL,processed_at=NOW() WHERE id=?"
            )->execute([$eventId]);
            $this->pdo->exec('RELEASE SAVEPOINT inventory_event_apply');
            return true;
        }catch(Throwable $e){
            $this->pdo->exec('ROLLBACK TO SAVEPOINT inventory_event_apply');
            $this->pdo->exec('RELEASE SAVEPOINT inventory_event_apply');
            $this->pdo->prepare(
                "UPDATE inventory_order_events SET status=IF(attempt_count>=9,'failed','pending'),attempt_count=attempt_count+1,last_error=? WHERE id=? AND status='pending'"
            )->execute([self::truncate($e->getMessage(),500),$eventId]);
            return false;
        }
    }

    private function applyAccountedTx(int $orderId,int $actorUserId,array $snapshot,?string $occurredAt): int
    {
        $created=0;
        foreach($snapshot as $orderItem){
            if(!is_array($orderItem))continue;
            $menuItemId=(int)($orderItem['menu_item_id']??0);
            $orderItemId=(int)($orderItem['order_item_id']??0);
            $orderQuantity=max(0,(int)($orderItem['order_quantity']??0));
            $recipeVersionId=(int)($orderItem['recipe_version_id']??0);
            if($orderItemId<1||$orderQuantity<1||$recipeVersionId<1)continue;
            $department=InventoryService::normalizeDepartment((string)($orderItem['department']??'shared'))??'shared';
            foreach((array)($orderItem['components']??[]) as $component){
                if(!is_array($component))continue;
                $inventoryItemId=(int)($component['inventory_item_id']??0);
                $perUnit=max(0,(int)($component['quantity_base']??0));
                $componentId=(int)($component['recipe_component_id']??0);
                if($inventoryItemId<1||$perUnit<1)continue;
                $qty=-($perUnit*$orderQuantity);
                $componentKey=$componentId>0?'component:'.$componentId:'item:'.$inventoryItemId;
                $key='inventory:recipe:order-item:'.$orderItemId.':recipe:'.$recipeVersionId.':'.$componentKey;
                $snapshotHasCost=array_key_exists('unit_cost_snapshot',$component)||array_key_exists('cost_status',$component);
                $snapshotUnit=array_key_exists('unit_cost_snapshot',$component)&&$component['unit_cost_snapshot']!==null?(float)$component['unit_cost_snapshot']:null;
                $rawStatus=(string)($component['cost_status']??'unknown');
                $status=$rawStatus==='known'?'known':($snapshotUnit!==null?'estimated':'unknown');
                $this->inventory->recordMovementTx([
                    'item_id'=>$inventoryItemId,'movement_type'=>'recipe_consumption','quantity_base'=>$qty,
                    'department'=>$department,'source_type'=>'order_item','source_id'=>$orderItemId,
                    'idempotency_key'=>$key,'unit_cost_snapshot'=>$snapshotUnit,'cost_status'=>$status,
                    'lock_cost_basis'=>$snapshotHasCost,'occurred_at'=>$occurredAt,
                    'metadata'=>[
                        'order_id'=>$orderId,'order_item_id'=>$orderItemId,'menu_item_id'=>$menuItemId,
                        'recipe_version_id'=>$recipeVersionId,'recipe_version_no'=>(int)($orderItem['recipe_version_no']??0),
                        'recipe_component_id'=>$componentId?:null,'per_menu_item_quantity_base'=>$perUnit,'order_quantity'=>$orderQuantity,
                        'recipe_review_status'=>(string)($orderItem['recipe_review_status']??'ready'),'recipe_completeness_status'=>(string)($orderItem['recipe_completeness_status']??'complete'),'recipe_quantity_status'=>(string)($component['quantity_status']??'known'),
                    ],
                    'actor_user_id'=>$actorUserId,
                ]);
                $created++;
            }
        }
        return $created;
    }

    private function applyQuantityAdjustedTx(int $eventId,int $orderId,int $orderItemId,int $actorUserId,array $payload,?string $occurredAt): int
    {
        $delta=(int)($payload['inventory_quantity_delta']??0);$line=$payload['recipe_snapshot']??null;
        if($delta===0||!is_array($line)||$orderItemId<1)return 0;
        $recipeVersionId=(int)($line['recipe_version_id']??0);if($recipeVersionId<1)return 0;
        $menuItemId=(int)($line['menu_item_id']??0);
        $department=InventoryService::normalizeDepartment((string)($line['department']??'shared'))??'shared';$created=0;
        foreach((array)($line['components']??[]) as $component){
            if(!is_array($component))continue;
            $inventoryItemId=(int)($component['inventory_item_id']??0);$perUnit=max(0,(int)($component['quantity_base']??0));$componentId=(int)($component['recipe_component_id']??0);
            if($inventoryItemId<1||$perUnit<1)continue;
            $qty=-($perUnit*$delta);if($qty===0)continue;
            $componentKey=$componentId>0?'component:'.$componentId:'item:'.$inventoryItemId;
            $snapshotHasCost=array_key_exists('unit_cost_snapshot',$component)||array_key_exists('cost_status',$component);
            $snapshotUnit=array_key_exists('unit_cost_snapshot',$component)&&$component['unit_cost_snapshot']!==null?(float)$component['unit_cost_snapshot']:null;
            $rawStatus=(string)($component['cost_status']??'unknown');$status=$rawStatus==='known'?'known':($snapshotUnit!==null?'estimated':'unknown');
            $this->inventory->recordMovementTx([
                'item_id'=>$inventoryItemId,'movement_type'=>$delta>0?'recipe_consumption':'reversal','quantity_base'=>$qty,
                'department'=>$department,'source_type'=>'order_item_adjustment','source_id'=>(string)($payload['request_id']??$eventId),
                'idempotency_key'=>'inventory:recipe-adjust:event:'.$eventId.':'.$componentKey,
                'unit_cost_snapshot'=>$snapshotUnit,'cost_status'=>$status,'lock_cost_basis'=>$snapshotHasCost,'occurred_at'=>$occurredAt,
                'metadata'=>[
                    'inventory_order_event_id'=>$eventId,'order_id'=>$orderId,'order_item_id'=>$orderItemId,'menu_item_id'=>$menuItemId,
                    'recipe_version_id'=>$recipeVersionId,'recipe_component_id'=>$componentId?:null,'per_menu_item_quantity_base'=>$perUnit,
                    'recipe_review_status'=>(string)($line['recipe_review_status']??'ready'),'recipe_completeness_status'=>(string)($line['recipe_completeness_status']??'complete'),'recipe_quantity_status'=>(string)($component['quantity_status']??'known'),
                    'from_quantity'=>(int)($payload['from_quantity']??0),'to_quantity'=>(int)($payload['to_quantity']??0),'quantity_delta'=>$delta,
                ],'actor_user_id'=>$actorUserId,
            ]);$created++;
        }
        return $created;
    }

    private function activeRecipeTx(int $menuItemId,bool $forUpdate): ?array
    {
        $sql="SELECT * FROM inventory_recipe_versions WHERE menu_item_id=? AND status='active' ORDER BY version_no DESC,id DESC LIMIT 1";
        if($forUpdate)$sql.=' FOR UPDATE';
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$menuItemId]);$recipe=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($recipe))return null;
        $components=$this->pdo->prepare(
            "SELECT c.*,i.name inventory_item_name,i.base_unit,b.average_unit_cost,COALESCE(b.cost_status,'unknown') balance_cost_status
             FROM inventory_recipe_components c
             JOIN inventory_items i ON i.id=c.inventory_item_id
             LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id
             WHERE c.recipe_version_id=? ORDER BY c.id"
        );
        $components->execute([(int)$recipe['id']]);$recipe['components']=$components->fetchAll(PDO::FETCH_ASSOC);
        return $recipe;
    }

    private function normalizeComponents(array $components): array
    {
        $normalized=[];
        foreach($components as $component){
            if(!is_array($component))throw new InventoryException('invalid_recipe','اطلاعات مواد مصرفی معتبر نیست.',422);
            $id=(int)($component['inventory_item_id']??0);$qty=(int)($component['quantity_base']??0);
            if($id<1||$qty<1)throw new InventoryException('invalid_recipe','برای هر ماده انبار، مقدار مصرف معتبر وارد کن.',422);
            if(isset($normalized[$id]))throw new InventoryException('duplicate_recipe_item','یک ماده انبار نمی‌تواند دو بار در دستور مصرف ثبت شود.',422);
            $status=(string)($component['quantity_status']??'known');
            if(!in_array($status,['known','estimated'],true))$status='known';
            $note=self::truncate(trim((string)($component['source_note']??'')),500);
            $normalized[$id]=['quantity_base'=>$qty,'quantity_status'=>$status,'source_note'=>$note!==''?$note:null];
        }
        ksort($normalized);
        return $normalized;
    }

    private function validateComponentsTx(array $normalized): void
    {
        if($normalized===[])return;
        $ids=array_map('intval',array_keys($normalized));$ph=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->pdo->prepare("SELECT id,name,active FROM inventory_items WHERE id IN($ph) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==count($ids))throw new InventoryException('recipe_item_missing','یکی از مواد انتخاب‌شده در انبار پیدا نشد.',404);
        foreach($rows as $row)if((int)$row['active']!==1)
            throw new InventoryException('recipe_item_inactive','یکی از مواد دستور مصرف غیرفعال است.',409,['item_id'=>(int)$row['id']]);
    }

    private static function areaForStation(string $station): ?string
    {
        return match($station){
            'none'=>null,
            'kitchen'=>'kitchen',
            default=>'bar',
        };
    }

    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void
    {
        $display=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
        $display->execute([$actorId]);$name=$display->fetchColumn();
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)'
        )->execute([$actorId,$name!==false?$name:null,$action,$entityType,(string)$entityId,$json?:'{}']);
    }

    private static function truncate(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }
}
