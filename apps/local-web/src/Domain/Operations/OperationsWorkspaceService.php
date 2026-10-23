<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Operations;

use PDO;
use Sokna\Local\Domain\Supply\SupplyService;
use Sokna\Local\Domain\Expenses\ExpenseService;
use Sokna\Local\Core\SnapshotGuard;

final class OperationsWorkspaceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SupplyService $supply,
        private readonly ExpenseService $expenses,
        private readonly SnapshotGuard $guard,
        private readonly \Sokna\Local\Domain\Inventory\InventoryService $inventory,
    ) {}

    public function snapshot(array $user): array
    {
        $owns=!$this->pdo->inTransaction();if($owns){$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->beginTransaction();}
        try{$cursor=(int)$this->pdo->query('SELECT `cursor` FROM operations_command_clock WHERE id=1')->fetchColumn();$s=$this->snapshotTx($user);$s['business_cursor']=$cursor;if($owns)$this->pdo->commit();return $s;}catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function snapshotTx(array $user): array
    {
        $isAdmin=(string)($user['role']??'')==='admin';$errors=[];
        $moduleState=$this->guard->capture('operations','module_state',[
            'inventory_enabled'=>true,'supply_enabled'=>true,'inventory_initialized'=>false,
            'inventory_reconciliation_required'=>false,'inventory_runtime_ready'=>false,'supply_runtime_ready'=>false,
        ],'وضعیت امکانات عملیاتی فعلاً در دسترس نیست.',fn():array=>$this->moduleState());
        if($moduleState['error']!==null)$errors['module_state']=$moduleState['error'];

        // Keep inventory master-data visible even if a secondary ledger/count/purchase-unit
        // query is temporarily unavailable. A failure in one optional subsection must not
        // blank every item selector in stock/supply workflows.
        $inventoryMaster=$this->guard->capture('operations','inventory_master',[
            'items'=>[],'categories'=>[],
        ],'فهرست کالاها و دسته‌های انبار فعلاً در دسترس نیست.',fn():array=>[
            'items'=>$this->inventoryItems(),
            'categories'=>$this->inventoryCategories(),
        ]);
        $purchaseUnits=$this->guard->capture('operations','inventory_purchase_units',[],
            'واحدهای خرید فعلاً در دسترس نیستند؛ فهرست کالاها همچنان قابل استفاده است.',fn():array=>$this->purchaseUnits());
        $movements=$this->guard->capture('operations','inventory_movements',[],
            'تاریخچه حرکت‌های انبار فعلاً در دسترس نیست.',fn():array=>$this->recentMovements());
        $openCount=$this->guard->capture('operations','inventory_count',null,
            'وضعیت شمارش باز فعلاً در دسترس نیست.',fn():?array=>$this->openCount());

        $supplyGroups=$this->guard->capture('operations','supply_groups',[],
            'نیازها و خریدهای باز فعلاً در دسترس نیستند.',fn():array=>$this->supply->purchaseGroups());
        $supplyReceipts=$this->guard->capture('operations','supply_receipts',[],
            'تحویل‌های اخیر خرید فعلاً در دسترس نیستند.',fn():array=>$this->recentReceipts());
        $purchasePricePolicy=$this->guard->capture('operations','purchase_price_policy',[
            'enabled'=>false,'configured'=>false,'threshold_percent'=>null,'high_threshold_percent'=>null,
        ],'سیاست اعلان تغییر قیمت خرید فعلاً در دسترس نیست.',fn():array=>$this->supply->purchasePricePolicy());
        $dataQuality=$this->guard->capture('operations','inventory_data_quality',[
            'missing_cost_receipt_count'=>0,'missing_cost_receipts'=>[],
        ],'صف کیفیت داده فعلاً در دسترس نیست.',fn():array=>$this->dataQuality());

        $expenses=$isAdmin?$this->guard->capture('operations','expenses',[
            'categories'=>[],'recent'=>[],
        ],'اطلاعات هزینه‌ها فعلاً در دسترس نیست.',fn():array=>[
            'categories'=>$this->expenses->categories(true),
            'recent'=>$this->recentExpenses(),
        ]):['value'=>null,'error'=>null];

        if($inventoryMaster['error']!==null){$errors['inventory_master']=$inventoryMaster['error'];$errors['inventory']=$inventoryMaster['error'];}
        if($purchaseUnits['error']!==null)$errors['inventory_purchase_units']=$purchaseUnits['error'];
        if($movements['error']!==null)$errors['inventory_movements']=$movements['error'];
        if($openCount['error']!==null)$errors['inventory_count']=$openCount['error'];
        if($supplyGroups['error']!==null){$errors['supply_groups']=$supplyGroups['error'];$errors['supply']=$supplyGroups['error'];}
        if($supplyReceipts['error']!==null)$errors['supply_receipts']=$supplyReceipts['error'];
        if($purchasePricePolicy['error']!==null)$errors['purchase_price_policy']=$purchasePricePolicy['error'];
        if($dataQuality['error']!==null)$errors['inventory_data_quality']=$dataQuality['error'];
        if($expenses['error']!==null)$errors['expenses']=$expenses['error'];

        $permissions=$this->inventory->permissions($user)+$this->supply->permissions($user);
        return \Sokna\Shared\OperationsSnapshotPolicy::apply([
            'permissions'=>$permissions,'module_state'=>$moduleState['value'],
            'inventory'=>[
                'items'=>$inventoryMaster['value']['items'],
                'categories'=>$inventoryMaster['value']['categories'],
                'purchase_units'=>$purchaseUnits['value'],
                'recent_movements'=>$movements['value'],
                'open_count'=>$openCount['value'],
            ],
            'supply'=>[
                'groups'=>$supplyGroups['value'],'purchases'=>$this->supply->purchases(),
                'recent_receipts'=>$supplyReceipts['value'],
                'purchase_price_policy'=>$purchasePricePolicy['value'],
            ],
            'data_quality'=>$dataQuality['value'],
            'expenses'=>$expenses['value'],
            'errors'=>$errors,
        ],(bool)$permissions['can_view_cost']);
    }

    public function inventoryItems(): array
    {
        $sql="SELECT i.id,i.item_code,i.name,i.category,c.name category_name,i.base_unit,i.default_department,i.warning_threshold,
                    i.review_status,i.review_note,i.active,COALESCE(b.quantity_base,0) quantity_base,b.average_unit_cost,b.cost_status,b.updated_at balance_updated_at,
                    (SELECT r.total_cost/r.received_quantity_base FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id
                     WHERE r.inventory_item_id=i.id AND r.total_cost IS NOT NULL AND r.received_quantity_base>0
                       AND NOT EXISTS(SELECT 1 FROM inventory_movements rv WHERE rv.reversal_of_id=m.id)
                     ORDER BY m.occurred_at DESC,m.id DESC LIMIT 1) last_purchase_base_unit_cost,
                    (SELECT m.occurred_at FROM inventory_supply_receipts r JOIN inventory_movements m ON m.id=r.movement_id
                     WHERE r.inventory_item_id=i.id AND r.total_cost IS NOT NULL AND r.received_quantity_base>0
                       AND NOT EXISTS(SELECT 1 FROM inventory_movements rv WHERE rv.reversal_of_id=m.id)
                     ORDER BY m.occurred_at DESC,m.id DESC LIMIT 1) last_purchase_at
             FROM inventory_items i JOIN inventory_categories c ON c.category_key=i.category
             LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id
             ORDER BY i.active DESC,i.review_status DESC,c.sort_order,i.name,i.id";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $aliases=[];
        foreach($this->pdo->query("SELECT inventory_item_id,alias FROM inventory_item_aliases WHERE active=1 ORDER BY inventory_item_id,alias")->fetchAll(PDO::FETCH_ASSOC) as $aliasRow){
            $aliases[(int)$aliasRow['inventory_item_id']][]=(string)$aliasRow['alias'];
        }
        foreach($rows as &$row)$row['aliases']=$aliases[(int)$row['id']]??[];unset($row);
        return $rows;
    }

    public function inventoryCategories(): array
    {
        return $this->pdo->query("SELECT category_key,name,active,system_category,sort_order FROM inventory_categories ORDER BY active DESC,sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function purchaseUnits(): array
    {
        $sql="SELECT pu.id,pu.inventory_item_id,pu.name,pu.conversion_mode,pu.base_quantity,pu.review_status,pu.note,pu.active,
                    i.name item_name,i.base_unit,
                    (SELECT r.total_cost FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL AND r.purchase_unit_count>0 ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_total_cost,
                    (SELECT r.purchase_unit_count FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL AND r.purchase_unit_count>0 ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_unit_count,
                    (SELECT r.received_quantity_base FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL AND r.received_quantity_base>0 ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_quantity_base,
                    (SELECT r.received_at FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id AND r.total_cost IS NOT NULL ORDER BY r.received_at DESC,r.id DESC LIMIT 1) last_receipt_at,
                    (SELECT COUNT(*) FROM inventory_supply_receipts r WHERE r.purchase_unit_id=pu.id) receipt_count
             FROM inventory_purchase_units pu JOIN inventory_items i ON i.id=pu.inventory_item_id
             ORDER BY pu.inventory_item_id,pu.active DESC,pu.name,pu.id";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){
            $row['id']=(int)$row['id'];$row['inventory_item_id']=(int)$row['inventory_item_id'];$row['base_quantity']=$row['base_quantity']===null?null:(int)$row['base_quantity'];$row['active']=(bool)$row['active'];$row['receipt_count']=(int)($row['receipt_count']??0);
            $total=$row['last_receipt_total_cost']===null?null:(float)$row['last_receipt_total_cost'];
            $count=$row['last_receipt_unit_count']===null?null:(float)$row['last_receipt_unit_count'];
            $base=$row['last_receipt_quantity_base']===null?null:(int)$row['last_receipt_quantity_base'];
            $row['last_purchase_unit_price']=$total!==null&&$count!==null&&$count>0?$total/$count:null;
            $row['last_base_unit_cost']=$total!==null&&$base!==null&&$base>0?$total/$base:null;
        }unset($row);
        return $rows;
    }

    public function recentMovements(int $limit=50): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT m.id,m.inventory_item_id,m.movement_type,m.quantity_base,m.base_unit,m.department,m.total_cost_delta,m.cost_status,m.source_type,m.metadata_json,m.note,m.occurred_at,
                    i.name item_name,u.display_name actor_name
             FROM inventory_movements m JOIN inventory_items i ON i.id=m.inventory_item_id
             LEFT JOIN users u ON u.id=m.actor_user_id ORDER BY m.occurred_at DESC,m.id DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function movementHistory(array $filters,int $limit=200): array
    {
        $limit=max(1,min(500,$limit));$where=[];$params=[];
        $itemId=(int)($filters['item_id']??0);if($itemId>0){$where[]='m.inventory_item_id=?';$params[]=$itemId;}
        $type=trim((string)($filters['movement_type']??''));
        $allowed=['opening_balance','purchase_receive','manual_receive','recipe_consumption','waste','count_adjustment','purchase_return','quantity_correction','cost_adjustment','reversal'];
        if($type!==''&&in_array($type,$allowed,true)){$where[]='m.movement_type=?';$params[]=$type;}
        $from=trim((string)($filters['from']??''));if($from!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$where[]='m.occurred_at>=?';$params[]=$from.' 00:00:00';}
        $to=trim((string)($filters['to']??''));if($to!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$where[]='m.occurred_at<?';$params[]=date('Y-m-d 00:00:00',strtotime($to.' +1 day'));}
        $sql="SELECT m.id,m.inventory_item_id,m.movement_type,m.quantity_base,m.base_unit,m.department,m.total_cost_delta,m.cost_status,m.source_type,m.metadata_json,m.note,m.occurred_at,
                    i.name item_name,u.display_name actor_name
             FROM inventory_movements m JOIN inventory_items i ON i.id=m.inventory_item_id
             LEFT JOIN users u ON u.id=m.actor_user_id";
        if($where)$sql.=' WHERE '.implode(' AND ',$where);
        $sql.=' ORDER BY m.occurred_at DESC,m.id DESC LIMIT '.$limit;
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function openCount(): ?array
    {
        $session=$this->pdo->query("SELECT * FROM inventory_count_sessions WHERE status='draft' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!is_array($session))return null;
        $stmt=$this->pdo->prepare(
            "SELECT l.id line_id,l.inventory_item_id,l.system_quantity_snapshot,l.actual_quantity,l.actual_total_cost,l.note,l.counted_at,l.updated_at version,
                    i.name item_name,i.base_unit,i.category category_key,c.name category_name
             FROM inventory_count_lines l JOIN inventory_items i ON i.id=l.inventory_item_id JOIN inventory_categories c ON c.category_key=i.category WHERE l.session_id=? ORDER BY c.sort_order,i.name,i.id"
        );
        $stmt->execute([(int)$session['id']]);
        $session['lines']=$stmt->fetchAll(PDO::FETCH_ASSOC);
        return $session;
    }

    public function recentReceipts(int $limit=40): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT r.id,r.received_quantity_base,r.remaining_quantity_after,r.purchase_unit_id,r.purchase_unit_name_snapshot,
                    r.purchase_unit_count,r.conversion_base_quantity_snapshot,r.total_cost,r.supplier,r.note,r.received_at,
                    i.name item_name,i.base_unit,u.display_name actor_name,
                    pe.id purchase_price_event_id,pe.normalized_unit_cost,pe.last_unit_cost,pe.weighted_average_before,pe.weighted_average_after,
                    pe.delta_last_percent,pe.delta_average_percent,pe.direction price_direction,pe.notification_level
             FROM inventory_supply_receipts r JOIN inventory_items i ON i.id=r.inventory_item_id
             LEFT JOIN users u ON u.id=r.actor_user_id LEFT JOIN inventory_purchase_price_events pe ON pe.receipt_id=r.id AND pe.raw_total_cost=r.total_cost
             ORDER BY r.received_at DESC,r.id DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dataQuality(int $limit=100): array
    {
        $limit=max(1,min(250,$limit));
        $count=(int)$this->pdo->query('SELECT COUNT(*) FROM inventory_supply_receipts WHERE total_cost IS NULL')->fetchColumn();
        $sql="SELECT r.id,r.inventory_item_id,r.movement_id,r.received_quantity_base,r.purchase_unit_id,r.purchase_unit_name_snapshot,
                    r.purchase_unit_count,r.conversion_base_quantity_snapshot,r.total_cost,r.supplier,r.note,r.received_at,
                    i.name item_name,i.base_unit,u.display_name actor_name
             FROM inventory_supply_receipts r JOIN inventory_items i ON i.id=r.inventory_item_id
             LEFT JOIN users u ON u.id=r.actor_user_id WHERE r.total_cost IS NULL
             ORDER BY r.received_at DESC,r.id DESC LIMIT {$limit}";
        return ['missing_cost_receipt_count'=>$count,'missing_cost_receipts'=>$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function recentExpenses(int $limit=60): array
    {
        $limit=max(1,min(100,$limit));
        $sql="SELECT e.id,e.financial_period_id,e.category_key,c.name category_name,e.amount,e.description,e.occurred_at,e.status,e.reverses_expense_id,
                    u.display_name actor_name,fp.title period_title
             FROM expenses e JOIN expense_categories c ON c.category_key=e.category_key
             JOIN financial_periods fp ON fp.id=e.financial_period_id LEFT JOIN users u ON u.id=e.actor_user_id
             ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    private function moduleState(): array
    {
        $rows=$this->pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('module.inventory.enabled','module.supply.enabled','inventory_initialized','inventory_reconciliation_required')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $bool=static function(string $key,bool $default) use ($rows): bool {
            if(!array_key_exists($key,$rows))return $default;
            return in_array(strtolower(trim((string)$rows[$key])),['1','true','yes','on'],true);
        };
        $inventoryEnabled=$bool('module.inventory.enabled',true);
        $supplyEnabled=$bool('module.supply.enabled',true);
        $initialized=$bool('inventory_initialized',false);
        $reconciliation=$bool('inventory_reconciliation_required',false);
        $inventoryReady=$inventoryEnabled&&$initialized&&!$reconciliation;
        return [
            'inventory_enabled'=>$inventoryEnabled,'supply_enabled'=>$supplyEnabled,
            'inventory_initialized'=>$initialized,'inventory_reconciliation_required'=>$reconciliation,
            'inventory_runtime_ready'=>$inventoryReady,'supply_runtime_ready'=>$supplyEnabled&&$inventoryReady,
        ];
    }

}
