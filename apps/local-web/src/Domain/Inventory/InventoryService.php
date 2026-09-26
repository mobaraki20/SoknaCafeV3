<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Inventory;

use PDO;
use PDOException;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class InventoryService
{
    private const MOVEMENT_TYPES=[
        'opening_balance','purchase_receive','recipe_consumption','waste','count_adjustment',
        'purchase_return','quantity_correction','cost_adjustment','reversal',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
    ) {}

    public function assertActor(array $user,string $capability): array
    {
        $id=(int)($user['id']??0);
        if($id<1)throw new InventoryException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($id);
        if($fresh===null)throw new InventoryException('forbidden','حساب کاربری فعال نیست.',403);
        if(!$this->capabilities->has($capability,$fresh))
            throw new InventoryException('forbidden','دسترسی این عملیات انبار برای حساب شما فعال نیست.',403);
        return $fresh;
    }

    public function configuredTx(): bool
    {
        $this->requireTx();
        return $this->settingBoolTx('module.inventory.enabled',true);
    }

    public function runtimeReadyTx(): bool
    {
        $this->requireTx();
        return $this->configuredTx()
            && $this->settingBoolTx('inventory_initialized',false)
            && !$this->settingBoolTx('inventory_reconciliation_required',false);
    }

    public function recordMovement(array $data,array $user,string $capability='inventory_operations'): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertActor($user,$capability);
            $id=$this->recordMovementTx($data+['actor_user_id'=>(int)$actor['id']]);
            $balance=$this->balanceTx((int)$data['item_id']);
            $this->pdo->commit();
            return ['movement_id'=>$id,'balance'=>$balance];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function recordMovementTx(array $data): int
    {
        $this->requireTx();
        $itemId=(int)($data['item_id']??0);
        $type=trim((string)($data['movement_type']??''));
        $quantity=(int)($data['quantity_base']??0);
        if($itemId<1||!in_array($type,self::MOVEMENT_TYPES,true))
            throw new InventoryException('invalid_movement','حرکت انبار معتبر نیست.',422);
        if($type!=='cost_adjustment'&&$quantity===0)
            throw new InventoryException('invalid_quantity','مقدار حرکت انبار نمی‌تواند صفر باشد.',422);

        $countMovement=in_array($type,['opening_balance','count_adjustment'],true);
        if($countMovement){
            if(!$this->configuredTx())throw new InventoryException('inventory_disabled','انبار در حال حاضر غیرفعال است.',409);
        }elseif(!$this->runtimeReadyTx()){
            throw new InventoryException('inventory_not_ready','انبار برای ثبت عملیات روزانه آماده نیست.',409);
        }

        $item=$this->itemTx($itemId);
        if($item===null)throw new InventoryException('item_not_found','کالای انبار پیدا نشد.',404);
        $baseUnit=self::normalizeBaseUnit((string)$item['base_unit']);
        $balance=$this->balanceTx($itemId);
        $oldQty=(int)$balance['quantity_base'];
        $oldAvg=$balance['average_unit_cost']===null?null:(float)$balance['average_unit_cost'];
        $oldCostStatus=(string)($balance['cost_status']??'unknown');
        $newQty=$oldQty+$quantity;

        $occurredAt=trim((string)($data['occurred_at']??''));
        if($occurredAt!=='')$occurredAt=$this->normalizeOccurredAt($occurredAt);
        $requiresChronologicalRebuild=in_array($type,['quantity_correction','cost_adjustment'],true);
        $costBasisAverage=$oldAvg;
        $costBasisStatus=$oldCostStatus;
        if($occurredAt!==''){
            $latest=$this->pdo->prepare('SELECT occurred_at FROM inventory_movements WHERE inventory_item_id=? ORDER BY occurred_at DESC,id DESC LIMIT 1');
            $latest->execute([$itemId]);
            $latestOccurred=(string)($latest->fetchColumn()?:'');
            if($latestOccurred!==''&&$occurredAt<$latestOccurred){
                $requiresChronologicalRebuild=true;
                $historical=$this->projectionReplayTx($itemId,$occurredAt);
                $costBasisAverage=$historical['average_unit_cost']===null?null:(float)$historical['average_unit_cost'];
                $costBasisStatus=(string)$historical['cost_status'];
            }
        }

        $explicitUnit=array_key_exists('unit_cost_snapshot',$data)&&$data['unit_cost_snapshot']!==null
            ?max(0.0,(float)$data['unit_cost_snapshot']):null;
        $explicitTotal=array_key_exists('total_cost_delta',$data)&&$data['total_cost_delta']!==null
            ?(int)$data['total_cost_delta']:null;
        $lockCostBasis=!empty($data['lock_cost_basis']);
        $unitCost=$explicitUnit;
        if($unitCost===null&&$explicitTotal!==null&&$quantity!==0)$unitCost=abs($explicitTotal/$quantity);
        if($unitCost===null&&!$lockCostBasis&&$costBasisAverage!==null)$unitCost=$costBasisAverage;

        $movementCostStatus=(string)($data['cost_status']??(
            $explicitTotal!==null?'known':($unitCost!==null?($costBasisStatus==='known'?'known':'estimated'):'unknown')
        ));
        if(!in_array($movementCostStatus,['known','estimated','unknown'],true))$movementCostStatus='unknown';
        $totalCostDelta=$explicitTotal;
        if($totalCostDelta===null&&$unitCost!==null&&$movementCostStatus!=='unknown')
            $totalCostDelta=(int)round($quantity*$unitCost);

        $newAvg=$oldAvg;
        $newCostStatus=$oldCostStatus;
        $revalueBalance=!empty($data['revalue_balance']);
        $costBearingInbound=$quantity>0&&in_array($type,['purchase_receive','opening_balance'],true);

        if($type==='cost_adjustment'){
            if($explicitTotal===null)throw new InventoryException('cost_required','مبلغ اصلاح هزینه مشخص نیست.',422);
        }elseif($costBearingInbound){
            if($explicitTotal!==null){
                $receiptUnit=abs($explicitTotal/max(1,$quantity));
                $unitCost=$receiptUnit;
                if($oldQty>0&&$oldAvg!==null){
                    $newAvg=(($oldQty*$oldAvg)+$explicitTotal)/max(1,$newQty);
                    $newCostStatus=$oldCostStatus==='known'?'known':'partial';
                }elseif($oldQty>0){
                    $newAvg=$receiptUnit;
                    $newCostStatus='partial';
                }elseif($newQty>0){
                    $newAvg=$receiptUnit;
                    $newCostStatus='known';
                }
            }else{
                $newCostStatus=$oldAvg!==null?'partial':'unknown';
            }
        }elseif($revalueBalance&&$newQty>0&&$totalCostDelta!==null){
            $oldValue=$oldAvg!==null?$oldQty*$oldAvg:0.0;
            $newValue=$oldValue+$totalCostDelta;
            if($newValue>=0)$newAvg=$newValue/$newQty;
        }elseif($newQty<=0&&$oldAvg===null){
            $newCostStatus='unknown';
        }

        $department=self::normalizeDepartment((string)($data['department']??''),true);
        $idempotencyKey=trim((string)($data['idempotency_key']??''))?:null;
        if($idempotencyKey!==null){
            if(strlen($idempotencyKey)>190)throw new InventoryException('idempotency_key_too_long','شناسه تکرارنشدن حرکت انبار بیش از حد بلند است.',422);
            $dup=$this->pdo->prepare('SELECT id FROM inventory_movements WHERE idempotency_key=? LIMIT 1');
            $dup->execute([$idempotencyKey]);
            $existing=(int)($dup->fetchColumn()?:0);
            if($existing>0)return $existing;
        }

        $metadata=is_array($data['metadata']??null)?$data['metadata']:[];
        $metadataJson=$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
        if($metadataJson===false)throw new InventoryException('invalid_metadata','اطلاعات تکمیلی حرکت انبار معتبر نیست.',422);
        $stmt=$this->pdo->prepare(
            'INSERT INTO inventory_movements(inventory_item_id,movement_type,quantity_base,base_unit,department,purchase_unit_id,'.
            'purchase_unit_name_snapshot,purchase_unit_count,conversion_base_quantity_snapshot,unit_cost_snapshot,total_cost_delta,cost_status,'.
            'source_type,source_id,correction_of_id,reversal_of_id,idempotency_key,metadata_json,note,actor_user_id,occurred_at) '.
            'VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,COALESCE(?,NOW()))'
        );
        try{
            $stmt->execute([
                $itemId,$type,$quantity,$baseUnit,$department,
                !empty($data['purchase_unit_id'])?(int)$data['purchase_unit_id']:null,
                self::truncate(trim((string)($data['purchase_unit_name_snapshot']??'')),160)?:null,
                array_key_exists('purchase_unit_count',$data)&&$data['purchase_unit_count']!==null?(float)$data['purchase_unit_count']:null,
                array_key_exists('conversion_base_quantity_snapshot',$data)&&$data['conversion_base_quantity_snapshot']!==null?(int)$data['conversion_base_quantity_snapshot']:null,
                $unitCost,$totalCostDelta,$movementCostStatus,
                self::truncate(trim((string)($data['source_type']??'')),50)?:null,
                array_key_exists('source_id',$data)&&$data['source_id']!==null?self::truncate((string)$data['source_id'],100):null,
                !empty($data['correction_of_id'])?(int)$data['correction_of_id']:null,
                !empty($data['reversal_of_id'])?(int)$data['reversal_of_id']:null,
                $idempotencyKey,$metadataJson,self::truncate(trim((string)($data['note']??'')),500)?:null,
                !empty($data['actor_user_id'])?(int)$data['actor_user_id']:null,
                $occurredAt!==''?$occurredAt:null,
            ]);
        }catch(PDOException $e){
            if($idempotencyKey!==null&&(string)$e->getCode()==='23000'){
                $dup=$this->pdo->prepare('SELECT id FROM inventory_movements WHERE idempotency_key=? LIMIT 1');
                $dup->execute([$idempotencyKey]);
                $existing=(int)($dup->fetchColumn()?:0);
                if($existing>0)return $existing;
            }
            throw $e;
        }
        $movementId=(int)$this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE inventory_balances SET quantity_base=?,average_unit_cost=?,cost_status=?,updated_at=NOW(6) WHERE inventory_item_id=?')
            ->execute([$newQty,$newAvg,$newCostStatus,$itemId]);
        if($requiresChronologicalRebuild){
            $projection=$this->rebuildProjectionTx($itemId);
            $newQty=(int)$projection['quantity_base'];
            $newAvg=$projection['average_unit_cost']===null?null:(float)$projection['average_unit_cost'];
            $newCostStatus=(string)$projection['cost_status'];
        }

        $this->audit('inventory.movement_created','inventory_movement',$movementId,(int)($data['actor_user_id']??0),[
            'item_id'=>$itemId,'movement_type'=>$type,'quantity_delta'=>$quantity,
            'balance_before'=>$oldQty,'balance_after'=>$newQty,'department'=>$department,
            'cost_status'=>$movementCostStatus,'total_cost_delta'=>$totalCostDelta,
            'source_type'=>$data['source_type']??null,'source_id'=>$data['source_id']??null,
        ]);
        return $movementId;
    }

    public function itemTx(int $itemId): ?array
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare(
            'SELECT i.*,b.quantity_base,b.average_unit_cost,b.cost_status,b.updated_at balance_updated_at '.
            'FROM inventory_items i LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id WHERE i.id=? FOR UPDATE'
        );
        $stmt->execute([$itemId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function balanceTx(int $itemId): array
    {
        $this->requireTx();
        $this->pdo->prepare("INSERT IGNORE INTO inventory_balances(inventory_item_id,quantity_base,average_unit_cost,cost_status) VALUES(?,0,NULL,'unknown')")
            ->execute([$itemId]);
        $stmt=$this->pdo->prepare('SELECT * FROM inventory_balances WHERE inventory_item_id=? FOR UPDATE');
        $stmt->execute([$itemId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new InventoryException('balance_unavailable','موجودی کالا قابل خواندن نیست.',500);
        return $row;
    }

    public function createUnreviewedItemTx(
        string $name,
        string $baseUnit,
        string $department,
        int $actorUserId,
        string $sourceLabel='external_workflow'
    ): int {
        $this->requireTx();
        $name=self::truncate(trim($name),160);
        if($name==='')throw new InventoryException('name_required','نام کالای جدید مشخص نیست.',422);
        $base=self::normalizeBaseUnit($baseUnit);
        $dept=self::normalizeDepartment($department)??'shared';
        $source=self::truncate(trim($sourceLabel),80)?:'external_workflow';
        $code='INV-'.strtoupper(bin2hex(random_bytes(5)));
        $note='این کالا از فرایند «'.$source.'» ساخته شده است؛ دسته، واحد، حد هشدار و واحد خرید را بررسی کن.';
        $stmt=$this->pdo->prepare(
            "INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id)
             VALUES(?,?,'ingredient',?,?,0,'needs_review',?,1,?)"
        );
        $stmt->execute([$code,$name,$base,$dept,$note,$actorUserId>0?$actorUserId:null]);
        $itemId=(int)$this->pdo->lastInsertId();
        $this->balanceTx($itemId);
        $this->audit('inventory.item_created_from_supply','inventory_item',$itemId,$actorUserId,[
            'name'=>$name,'base_unit'=>$base,'department'=>$dept,'review_status'=>'needs_review','source'=>$source,
        ]);
        return $itemId;
    }

    public function createItem(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertActor($user,'inventory_manage');
            $name=self::truncate(trim((string)($data['name']??'')),160);
            if($name==='')throw new InventoryException('name_required','نام کالا لازم است.',422);
            $category=trim((string)($data['category']??'ingredient'))?:'ingredient';
            $categoryStmt=$this->pdo->prepare('SELECT active FROM inventory_categories WHERE category_key=? FOR UPDATE');
            $categoryStmt->execute([$category]);
            if((int)($categoryStmt->fetchColumn()?:0)!==1)throw new InventoryException('invalid_category','دسته انبار معتبر نیست.',422);
            $base=self::normalizeBaseUnit((string)($data['base_unit']??'count'));
            $department=self::normalizeDepartment((string)($data['default_department']??'shared'))??'shared';
            $code=trim((string)($data['item_code']??''))?:'INV-'.strtoupper(bin2hex(random_bytes(5)));
            $review=(string)($data['review_status']??'ready')==='needs_review'?'needs_review':'ready';
            $stmt=$this->pdo->prepare(
                'INSERT INTO inventory_items(item_code,name,category,base_unit,default_department,warning_threshold,review_status,review_note,active,created_by_user_id) '.
                'VALUES(?,?,?,?,?,?,?,?,1,?)'
            );
            $stmt->execute([
                self::truncate($code,80),$name,$category,$base,$department,max(0,(int)($data['warning_threshold']??0)),
                $review,self::truncate(trim((string)($data['review_note']??'')),500)?:null,(int)$actor['id'],
            ]);
            $id=(int)$this->pdo->lastInsertId();
            $this->balanceTx($id);
            $this->audit('inventory.item_created','inventory_item',$id,(int)$actor['id'],['name'=>$name,'category'=>$category]);
            $this->pdo->commit();
            return ['id'=>$id,'item_code'=>$code];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function projectionReplayTx(int $itemId,?string $throughOccurredAt=null): array
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare('SELECT * FROM inventory_movements WHERE inventory_item_id=? ORDER BY occurred_at,id FOR UPDATE');
        $stmt->execute([$itemId]);
        return self::projectionReplayRows($stmt->fetchAll(PDO::FETCH_ASSOC),$throughOccurredAt);
    }

    public function rebuildProjectionTx(int $itemId): array
    {
        $projection=$this->projectionReplayTx($itemId);
        $this->balanceTx($itemId);
        $this->pdo->prepare('UPDATE inventory_balances SET quantity_base=?,average_unit_cost=?,cost_status=?,updated_at=NOW(6) WHERE inventory_item_id=?')
            ->execute([(int)$projection['quantity_base'],$projection['average_unit_cost'],(string)$projection['cost_status'],$itemId]);
        return $projection;
    }

    public static function projectionReplayRows(array $rows,?string $throughOccurredAt=null): array
    {
        $quantityCorrections=[];$costCorrections=[];
        foreach($rows as $row){
            $target=(int)($row['correction_of_id']??0);
            if($target<1)continue;
            if((string)$row['movement_type']==='quantity_correction')
                $quantityCorrections[$target]=($quantityCorrections[$target]??0)+(int)$row['quantity_base'];
            elseif((string)$row['movement_type']==='cost_adjustment')
                $costCorrections[$target]=($costCorrections[$target]??0)+(int)($row['total_cost_delta']??0);
        }

        $quantity=0;$average=null;$costStatus='unknown';$movementUnitCosts=[];$movementCostStatuses=[];
        foreach($rows as $row){
            $type=(string)$row['movement_type'];
            if($type==='quantity_correction'||$type==='cost_adjustment')continue;
            $occurred=(string)$row['occurred_at'];
            if($throughOccurredAt!==null&&$occurred>$throughOccurredAt)continue;

            $movementId=(int)$row['id'];
            $delta=(int)$row['quantity_base']+(int)($quantityCorrections[$movementId]??0);
            $oldQuantity=$quantity;$newQuantity=$oldQuantity+$delta;
            $unitCost=null;$movementStatus='unknown';

            if($delta>0&&in_array($type,['purchase_receive','opening_balance'],true)){
                $total=$row['total_cost_delta']===null?null:(int)$row['total_cost_delta'];
                if(array_key_exists($movementId,$costCorrections))$total=($total??0)+(int)$costCorrections[$movementId];
                if($total!==null&&$total<0)throw new InventoryException('invalid_cost_correction','اصلاح هزینه، ارزش یک ورود انبار را منفی کرده است.',409);
                if($total!==null){
                    $unitCost=abs($total/max(1,$delta));
                    if($oldQuantity>0&&$average!==null){
                        $average=(($oldQuantity*$average)+$total)/max(1,$newQuantity);
                        $costStatus=$costStatus==='known'?'known':'partial';
                    }elseif($oldQuantity>0){
                        $average=$unitCost;$costStatus='partial';
                    }elseif($newQuantity>0){
                        $average=$unitCost;$costStatus='known';
                    }
                    $movementStatus='known';
                }else{
                    $movementStatus=$average!==null?'estimated':'unknown';
                    $costStatus=$average!==null?'partial':'unknown';
                    $unitCost=$average;
                }
                $quantity=$newQuantity;
            }else{
                if($type==='reversal'&&$delta>0){
                    $target=(int)($row['reversal_of_id']??0);
                    $unitCost=$target>0?($movementUnitCosts[$target]??null):null;
                    if($unitCost===null&&$row['unit_cost_snapshot']!==null)$unitCost=(float)$row['unit_cost_snapshot'];
                    if($unitCost===null)$unitCost=$average;
                    $movementStatus=$target>0?($movementCostStatuses[$target]??(string)$row['cost_status']):(string)$row['cost_status'];
                    if($newQuantity>0&&$unitCost!==null){
                        $oldValue=$average!==null?$oldQuantity*$average:0.0;
                        $newValue=$oldValue+($delta*$unitCost);
                        if($newValue>=0){
                            $average=$newValue/$newQuantity;
                            $costStatus=$costStatus==='known'&&$movementStatus==='known'?'known':'partial';
                        }
                    }
                }else{
                    $unitCost=$average;
                    $movementStatus=$unitCost===null?'unknown':($costStatus==='known'?'known':'estimated');
                }
                $quantity=$newQuantity;
                if($quantity<=0&&$average===null)$costStatus='unknown';
            }
            $movementUnitCosts[$movementId]=$unitCost;
            $movementCostStatuses[$movementId]=$movementStatus;
        }
        return [
            'quantity_base'=>$quantity,'average_unit_cost'=>$average,'cost_status'=>$costStatus,
            'movement_unit_costs'=>$movementUnitCosts,'movement_cost_statuses'=>$movementCostStatuses,
        ];
    }

    /** Resolve a physical operation quantity while snapshotting purchase-unit conversion. */
    public function resolveOperationQuantityTx(
        int $itemId,
        int $purchaseUnitId,
        mixed $unitCountInput,
        mixed $actualMajorInput=null
    ): array {
        $this->requireTx();
        $item=$this->itemTx($itemId);
        if($item===null||(int)$item['active']!==1)throw new InventoryException('item_not_found','کالای انبار معتبر نیست.',404);
        $unitCount=self::decimal($unitCountInput);
        if($unitCount<=0)throw new InventoryException('invalid_quantity','مقدار عملیات باید بیشتر از صفر باشد.',422);
        if($purchaseUnitId<1){
            $baseQty=self::majorToBase($unitCount,(string)$item['base_unit']);
            if($baseQty<1)throw new InventoryException('invalid_quantity','مقدار عملیات معتبر نیست.',422);
            return [
                'quantity_base'=>$baseQty,'purchase_unit_id'=>null,'purchase_unit_name_snapshot'=>null,
                'purchase_unit_count'=>null,'conversion_base_quantity_snapshot'=>null,'conversion_mode'=>'base',
            ];
        }
        $stmt=$this->pdo->prepare('SELECT * FROM inventory_purchase_units WHERE id=? AND inventory_item_id=? AND active=1 FOR UPDATE');
        $stmt->execute([$purchaseUnitId,$itemId]);$unit=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($unit))throw new InventoryException('invalid_purchase_unit','واحد خرید معتبر نیست.',422);
        $mode=(string)$unit['conversion_mode'];
        $conversion=null;
        if($mode==='actual_quantity'){
            $baseQty=self::majorToBase($actualMajorInput,(string)$item['base_unit']);
            if($baseQty<1)throw new InventoryException('actual_quantity_required','مقدار واقعی این ورود را وارد کن.',422);
        }else{
            $conversion=(int)($unit['base_quantity']??0);
            if($conversion<1)throw new InventoryException('conversion_incomplete','تبدیل این واحد خرید هنوز کامل نشده است.',409);
            $baseQty=(int)round($unitCount*$conversion);
        }
        if($baseQty<1)throw new InventoryException('invalid_quantity','مقدار عملیات معتبر نیست.',422);
        return [
            'quantity_base'=>$baseQty,
            'purchase_unit_id'=>(int)$unit['id'],
            'purchase_unit_name_snapshot'=>(string)$unit['name'],
            'purchase_unit_count'=>$unitCount,
            'conversion_base_quantity_snapshot'=>$conversion,
            'conversion_mode'=>$mode,
        ];
    }

    public static function moneyValue(mixed $value): int
    {
        $raw=str_replace([',','٬',' '],'',trim((string)$value));
        if($raw===''||!preg_match('/^\\d+$/',$raw)||strlen($raw)>18)
            throw new InventoryException('invalid_cost','مبلغ واردشده معتبر نیست.',422);
        return (int)$raw;
    }

    private static function decimal(mixed $value): float
    {
        $raw=str_replace([',','٬','٫',' '],['','','.',''],trim((string)$value));
        if($raw===''||!preg_match('/^(?:\\d+(?:\\.\\d+)?|\\.\\d+)$/',$raw))
            throw new InventoryException('invalid_quantity','مقدار عددی واردشده معتبر نیست.',422);
        $number=(float)$raw;
        if(!is_finite($number)||$number<0)throw new InventoryException('invalid_quantity','مقدار عددی واردشده معتبر نیست.',422);
        return $number;
    }

    public static function majorToBase(mixed $value,string $baseUnit): int
    {
        $raw=trim((string)$value);
        if($raw==='')return 0;
        $raw=str_replace([',','٬','٫',' '],['','','.',''],$raw);
        if(!preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/',$raw))
            throw new InventoryException('invalid_quantity','مقدار عددی واردشده معتبر نیست.',422);
        $number=(float)$raw;
        if(!is_finite($number)||$number<0)throw new InventoryException('invalid_quantity','مقدار عددی واردشده معتبر نیست.',422);
        $unit=self::normalizeBaseUnit($baseUnit);
        if($unit==='count'){
            if(abs($number-round($number))>0.000001)
                throw new InventoryException('invalid_quantity','مقدار کالای تعدادی باید عدد صحیح باشد.',422);
            return (int)round($number);
        }
        return (int)round($number*1000);
    }

    public static function normalizeBaseUnit(string $value): string
    {
        return in_array($value,['g','ml','count'],true)?$value:'count';
    }

    public static function normalizeDepartment(string $value,bool $allowEmpty=false): ?string
    {
        $value=trim($value);
        if($allowEmpty&&$value==='')return null;
        return in_array($value,['bar','kitchen','shared'],true)?$value:'shared';
    }

    public function normalizeOccurredAt(string $value): string
    {
        $value=trim($value);$ts=strtotime($value);
        if($value===''||$ts===false)throw new InventoryException('invalid_occurred_at','زمان عملیات معتبر نیست.',422);
        if($ts>time()+300)throw new InventoryException('invalid_occurred_at','زمان عملیات نمی‌تواند در آینده باشد.',422);
        return date('Y-m-d H:i:s',$ts);
    }

    private function settingBoolTx(string $key,bool $default): bool
    {
        $stmt=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$key]);
        $value=$stmt->fetchColumn();
        if($value===false||$value===null)return $default;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void
    {
        $display=null;
        if($actorId>0){
            $stmt=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$actorId]);$display=$stmt->fetchColumn();
        }
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)'
        )->execute([$actorId>0?$actorId:null,$display!==false?$display:null,$action,$entityType,(string)$entityId,$json?:'{}']);
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory canonical operation requires an open transaction.');
    }

    private static function truncate(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }
}
