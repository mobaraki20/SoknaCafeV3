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

    public function saveRecipe(int $menuItemId,array $components,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->inventory->assertActor($user,'inventory_manage');
            $normalized=$this->normalizeComponents($components);
            $menuLock=$this->pdo->prepare('SELECT id FROM items WHERE id=? AND active=1 FOR UPDATE');
            $menuLock->execute([$menuItemId]);
            if($menuLock->fetchColumn()===false)throw new InventoryException('menu_item_not_found','آیتم منو برای دستور مصرف پیدا نشد.',404);
            $this->validateComponentsTx($normalized);

            $current=$this->activeRecipeTx($menuItemId,true);
            $currentNormalized=[];
            foreach((array)($current['components']??[]) as $component)
                $currentNormalized[(int)$component['inventory_item_id']]=(int)$component['quantity_base'];
            ksort($currentNormalized);
            if($normalized===$currentNormalized){
                $this->pdo->commit();
                return ['recipe_id'=>$current?(int)$current['id']:null,'changed'=>false];
            }

            if($current){
                $this->pdo->prepare("UPDATE inventory_recipe_versions SET status='retired',retired_at=NOW() WHERE id=? AND status='active'")
                    ->execute([(int)$current['id']]);
            }
            if($normalized===[]){
                $this->audit('inventory.recipe_removed','menu_item',$menuItemId,(int)$actor['id'],['previous_recipe_id'=>$current['id']??null]);
                $this->pdo->commit();
                return ['recipe_id'=>null,'changed'=>true];
            }

            $versionStmt=$this->pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM inventory_recipe_versions WHERE menu_item_id=?');
            $versionStmt->execute([$menuItemId]);$version=(int)$versionStmt->fetchColumn();
            $this->pdo->prepare("INSERT INTO inventory_recipe_versions(menu_item_id,version_no,status,created_by_user_id) VALUES(?,?,'active',?)")
                ->execute([$menuItemId,$version,(int)$actor['id']]);
            $recipeId=(int)$this->pdo->lastInsertId();
            $insert=$this->pdo->prepare('INSERT INTO inventory_recipe_components(recipe_version_id,inventory_item_id,quantity_base) VALUES(?,?,?)');
            foreach($normalized as $inventoryItemId=>$quantity)$insert->execute([$recipeId,$inventoryItemId,$quantity]);

            $this->audit('inventory.recipe_version_created','menu_item',$menuItemId,(int)$actor['id'],[
                'recipe_id'=>$recipeId,'version_no'=>$version,'components'=>$normalized,
            ]);
            $this->pdo->commit();
            return ['recipe_id'=>$recipeId,'changed'=>true,'version_no'=>$version];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
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
            $lines[(string)$orderItemId]=[
                'order_item_id'=>$orderItemId,'menu_item_id'=>(int)($row['menu_item_id']??0),
                'recipe_version_id'=>$recipeVersionId?:null,'recipe_version_no'=>(int)($row['recipe_version_no']??0)?:null,
                'department'=>(string)($row['department']??'shared'),'known_cost_amount'=>$lineKnown,
                'estimated_cost_amount'=>$lineEstimated,'unknown_component_count'=>$lineUnknown,'cost_status'=>$lineStatus,
                'components'=>$components,
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
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $orderItem){
            if(self::areaForStation((string)($orderItem['preparation_station']??'cold_bar'))===null)continue;
            $menuItemId=(int)$orderItem['item_id'];
            $recipe=$this->activeRecipeTx($menuItemId,false);
            $components=[];
            foreach((array)($recipe['components']??[]) as $component){
                $components[]=[
                    'recipe_component_id'=>(int)$component['id'],
                    'inventory_item_id'=>(int)$component['inventory_item_id'],
                    'quantity_base'=>(int)$component['quantity_base'],
                    'unit_cost_snapshot'=>$component['average_unit_cost']===null?null:(float)$component['average_unit_cost'],
                    'cost_status'=>(string)($component['balance_cost_status']??'unknown'),
                ];
            }
            $snapshot[]=[
                'order_item_id'=>(int)$orderItem['id'],
                'menu_item_id'=>$menuItemId,
                'order_quantity'=>max(0,(int)$orderItem['quantity']),
                'department'=>self::areaForStation((string)$orderItem['preparation_station'])??'shared',
                'recipe_version_id'=>$recipe?(int)$recipe['id']:null,
                'recipe_version_no'=>$recipe?(int)$recipe['version_no']:null,
                'components'=>$components,
            ];
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
            if((string)$event['event_type']!=='accounted')
                throw new InventoryException('unsupported_order_event','این رخداد سفارش هنوز در Inventory V3 پشتیبانی نمی‌شود.',409);

            $this->applyAccountedTx(
                (int)$event['order_id'],
                (int)($event['actor_user_id']??0),
                is_array($payload['recipe_snapshot']??null)?$payload['recipe_snapshot']:[],
                (string)$event['created_at']
            );
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
                    ],
                    'actor_user_id'=>$actorUserId,
                ]);
                $created++;
            }
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
            $normalized[$id]=$qty;
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
