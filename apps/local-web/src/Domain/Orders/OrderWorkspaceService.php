<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;

final class OrderWorkspaceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly OrderCatalogService $catalog,
    ){}

    public function operatorSnapshot(array $user): array
    {
        $actor=$this->actorAny($user,['orders_floor','cashier_accounts','shift_supervision']);
        $canOrders=$this->capabilities->has('orders_floor',$actor);
        $canCashier=$this->capabilities->has('cashier_accounts',$actor);
        $canSupervise=$this->capabilities->has('shift_supervision',$actor);
        $tables=$this->tableRows();
        $orders=$canOrders?$this->attentionOrders():[];
        $calls=$canOrders?$this->activeWaiterCalls():[];
        return [
            'success'=>true,
            'permissions'=>['orders_floor'=>$canOrders,'cashier_accounts'=>$canCashier,'shift_supervision'=>$canSupervise],
            'metrics'=>[
                'attention_orders'=>count($orders),
                'active_waiter_calls'=>count($calls),
                'open_tables'=>count(array_filter($tables,static fn(array $r):bool=>(string)($r['occupancy_kind']??'free')!=='free')),
                'draft_tables'=>count(array_filter($tables,static fn(array $r):bool=>!empty($r['has_draft']))),
            ],
            'attention_orders'=>$orders,
            'waiter_calls'=>$calls,
            'tables'=>$tables,
            'item_totals'=>$this->itemTotals(),
            'generated_at'=>date(DATE_ATOM),
        ];
    }

    public function staffWorkspace(array $user): array
    {
        $actor=$this->actorAny($user,['orders_floor']);
        return [
            'success'=>true,
            'actor'=>['id'=>(int)$actor['id'],'display_name'=>(string)$actor['display_name']],
            'tables'=>$this->tableRows(),
            'catalog'=>$this->catalog->staffCatalogRows(),
            'catalog_groups'=>$this->staffMenuGroupsVisible()?$this->catalog->staffCatalogGroups():[],
            'menu_groups_visible'=>$this->staffMenuGroupsVisible(),
            'generated_at'=>date(DATE_ATOM),
        ];
    }

    private function staffMenuGroupsVisible(): bool
    {
        $q=$this->pdo->query("SELECT setting_value FROM settings WHERE setting_key='staff.order_menu_groups_visible' LIMIT 1");$v=$q->fetchColumn();if($v===false)return true;return !in_array(strtolower(trim((string)$v)),['0','false','off','no',''],true);
    }

    /** Small committed result projection; never loads full table accounts. */
    public function publicTableStates(array $tableIds): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$tableIds),static fn(int $id):bool=>$id>0)));
        return $ids===[]?[]:$this->tableRows(array_slice($ids,0,8));
    }

    private function tableRows(?array $tableIds=null): array
    {
        $sql="SELECT t.id,t.name,t.table_number,t.sort_order,t.zone_label,
            s.id session_id,s.status session_status,s.started_at,
            d.id draft_id,d.version draft_version,d.expected_session_id draft_expected_session_id,d.created_at draft_created_at,d.updated_at draft_updated_at,
            COALESCE((SELECT COUNT(*) FROM table_draft_items di WHERE di.draft_id=d.id AND di.quantity>0),0) draft_item_count,
            COALESCE((SELECT SUM(di.quantity) FROM table_draft_items di WHERE di.draft_id=d.id AND di.quantity>0),0) draft_quantity_count,
            COALESCE((SELECT SUM(di.unit_price_snapshot*di.quantity) FROM table_draft_items di WHERE di.draft_id=d.id AND di.quantity>0),0) draft_total,
            (SELECT MAX(o2.created_at) FROM orders o2 WHERE o2.session_id=s.id AND o2.status='accounted') last_order_at,
            COALESCE((SELECT COUNT(*) FROM orders o3 WHERE o3.session_id=s.id AND o3.status='accounted'),0) order_round_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) account_total,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_total,
            COALESCE((SELECT COUNT(*) FROM order_items oi JOIN orders po ON po.id=oi.order_id WHERE po.session_id=s.id AND po.status IN('pending_approval','new') AND oi.quantity>0),0) pending_item_count,
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders po ON po.id=oi.order_id WHERE po.session_id=s.id AND po.status IN('pending_approval','new') AND oi.quantity>0),0) pending_quantity_count,
            COALESCE((SELECT COUNT(*) FROM waiter_calls w WHERE w.table_id=t.id AND w.status IN('new','accepted')),0) active_waiter_count
            FROM cafe_tables t
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') AND s2.live_table_guard IS NOT NULL ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            LEFT JOIN table_drafts d ON d.table_id=t.id AND d.state='active'
            WHERE t.active=1 ORDER BY t.sort_order,t.table_number,t.id";
        if($tableIds!==null){
            $ph=implode(',',array_fill(0,count($tableIds),'?'));
            $sql=str_replace('WHERE t.active=1 ORDER BY','WHERE t.active=1 AND t.id IN ('.$ph.') ORDER BY',$sql);
            $q=$this->pdo->prepare($sql);$q->execute($tableIds);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        }else{$rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
        return array_map(static function(array $r):array{
            $sessionId=(int)($r['session_id']??0);$draftId=(int)($r['draft_id']??0);$waiterCount=(int)($r['active_waiter_count']??0);
            $pendingCount=(int)($r['pending_order_count']??0);$roundCount=(int)($r['order_round_count']??0);
            $pendingOnly=$sessionId>0&&$pendingCount>0&&$roundCount===0&&(int)($r['account_total']??0)===0&&$draftId===0;
            $occupancy=$pendingOnly?'pending_guest':($sessionId>0?'account':($draftId>0?'draft':($waiterCount>0?'waiter':'free')));
            return [
                'id'=>(int)$r['id'],'name'=>(string)$r['name'],'table_number'=>(int)$r['table_number'],'sort_order'=>(int)$r['sort_order'],'zone_label'=>(string)($r['zone_label']??''),
                'session_id'=>$sessionId,'session_status'=>(string)($r['session_status']??''),'started_at'=>(string)($r['started_at']??''),'last_order_at'=>(string)($r['last_order_at']??''),
                'has_draft'=>$draftId>0,'draft_id'=>$draftId,'draft_version'=>(int)($r['draft_version']??0),'draft_expected_session_id'=>(int)($r['draft_expected_session_id']??0),
                'draft_created_at'=>(string)($r['draft_created_at']??''),'draft_updated_at'=>(string)($r['draft_updated_at']??''),'draft_item_count'=>(int)($r['draft_item_count']??0),'draft_quantity_count'=>(int)($r['draft_quantity_count']??0),'draft_total'=>(int)($r['draft_total']??0),
                'order_round_count'=>(int)($r['order_round_count']??0),'account_total'=>(int)$r['account_total'],'pending_order_count'=>(int)$r['pending_order_count'],
                'pending_order_total'=>(int)($r['pending_order_total']??0),'pending_item_count'=>(int)($r['pending_item_count']??0),'pending_quantity_count'=>(int)($r['pending_quantity_count']??0),'active_waiter_count'=>$waiterCount,
                'occupancy_kind'=>$occupancy,'transfer_eligible'=>$sessionId<1&&$draftId<1&&$waiterCount<1,
            ];
        },$rows);
    }

    public function tableAccount(int $tableId,array $user): array
    {
        $actor=$this->actorAny($user,['orders_floor','cashier_accounts','shift_supervision']);
        $account=$this->tableAccountProjection($tableId);
        $account['permissions']=[
            'orders_floor'=>$this->capabilities->has('orders_floor',$actor),
            'cashier_accounts'=>$this->capabilities->has('cashier_accounts',$actor),
            'shift_supervision'=>$this->capabilities->has('shift_supervision',$actor),
        ];
        return $account;
    }

    /**
     * Canonical Hall read model used by the shared Local/Public Staff frontend.
     * Business authority stays Local; Public receives this bounded projection only.
     */
    public function publicHallProjection(): array
    {
        $tables=$this->tableRows();$accounts=[];
        foreach($tables as $table){
            if((int)($table['session_id']??0)<1)continue;
            $id=(int)($table['id']??0);if($id<1)continue;
            // Never publish a partial Hall model by silently dropping an account.
            // If one authoritative account cannot be projected, keep the previous Public
            // operations model and retry the outbox event instead of showing an empty pane.
            $accounts[(string)$id]=$this->tableAccountProjection($id);
        }
        $orders=$this->attentionOrders();$calls=$this->activeWaiterCalls();
        return [
            'success'=>true,
            'tables'=>$tables,
            'metrics'=>[
                'attention_orders'=>count($orders),
                'active_waiter_calls'=>count($calls),
                'open_tables'=>count(array_filter($tables,static fn(array $r):bool=>(string)($r['occupancy_kind']??'free')!=='free')),
                'draft_tables'=>count(array_filter($tables,static fn(array $r):bool=>!empty($r['has_draft']))),
            ],
            'attention_orders'=>$orders,
            'waiter_calls'=>$calls,
            'item_totals'=>$this->itemTotals(),
            'accounts'=>$accounts,
            'generated_at'=>date(DATE_ATOM),
        ];
    }

    private function tableAccountProjection(int $tableId): array
    {
        if($tableId<1)throw new OrderStaffActionException('invalid_table','میز معتبر نیست.',422);
        $stmt=$this->pdo->prepare("SELECT t.id,t.name,t.table_number,t.zone_label,s.id session_id,s.status session_status,s.started_at,
            COALESCE((SELECT COUNT(*) FROM orders o3 WHERE o3.session_id=s.id AND o3.status='accounted'),0) order_round_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) account_total,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_total,
            COALESCE((SELECT COUNT(*) FROM order_items oi JOIN orders po ON po.id=oi.order_id WHERE po.session_id=s.id AND po.status IN('pending_approval','new') AND oi.quantity>0),0) pending_item_count,
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders po ON po.id=oi.order_id WHERE po.session_id=s.id AND po.status IN('pending_approval','new') AND oi.quantity>0),0) pending_quantity_count,
            COALESCE((SELECT COUNT(*) FROM waiter_calls w WHERE w.table_id=t.id AND w.status IN('new','accepted')),0) active_waiter_count,
            (SELECT MAX(o2.created_at) FROM orders o2 WHERE o2.session_id=s.id AND o2.status='accounted') last_order_at
            FROM cafe_tables t
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') AND s2.live_table_guard IS NOT NULL ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            WHERE t.id=? AND t.active=1 LIMIT 1");
        $stmt->execute([$tableId]);$table=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($table))throw new OrderStaffActionException('table_missing','میز پیدا نشد.',404);
        $sessionId=(int)($table['session_id']??0);
        $lines=[];$rounds=[];$pending=[];$calls=[];
        if($sessionId>0){
            $lineStmt=$this->pdo->prepare("SELECT oi.id order_item_id,oi.item_id,oi.item_name,oi.unit_price,oi.quantity,oi.ordered_quantity,oi.line_total,oi.item_note,oi.fulfillment_mode,oi.preparation_station,
                o.id order_id,o.business_order_number,o.status order_status,o.total_amount order_total,o.customer_note order_note,o.created_at,ci.price current_catalog_price
                FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN items ci ON ci.id=oi.item_id
                WHERE o.session_id=? AND o.status='accounted' AND oi.quantity>0 ORDER BY o.created_at,o.id,oi.id");
            $lineStmt->execute([$sessionId]);
            $roundIndex=[];
            foreach($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $r){
                $currentCatalogPrice=array_key_exists('current_catalog_price',$r)&&$r['current_catalog_price']!==null?(int)$r['current_catalog_price']:null;
                $orderedQuantity=array_key_exists('ordered_quantity',$r)&&$r['ordered_quantity']!==null?(int)$r['ordered_quantity']:(int)$r['quantity'];
                $line=[
                    'order_item_id'=>(int)$r['order_item_id'],'item_id'=>$r['item_id']!==null?(int)$r['item_id']:null,'name'=>(string)$r['item_name'],
                    'unit_price'=>(int)$r['unit_price'],'current_price'=>$currentCatalogPrice,'price_changed'=>$currentCatalogPrice!==null&&$currentCatalogPrice!==(int)$r['unit_price'],'quantity'=>(int)$r['quantity'],'ordered_quantity'=>$orderedQuantity,'line_total'=>(int)$r['line_total'],'note'=>(string)($r['item_note']??''),
                    'fulfillment_mode'=>(string)($r['fulfillment_mode']??'dine_in'),'preparation_station'=>(string)($r['preparation_station']??''),'order_number'=>(int)$r['business_order_number'],'created_at'=>(string)$r['created_at'],
                ];
                $lines[]=$line;$orderId=(int)$r['order_id'];
                if(!isset($roundIndex[$orderId])){
                    $roundIndex[$orderId]=count($rounds);
                    $rounds[]=[
                        'round_index'=>count($rounds)+1,'order_id'=>$orderId,'order_number'=>(int)$r['business_order_number'],
                        'status'=>(string)($r['order_status']??'accounted'),'created_at'=>(string)$r['created_at'],'total_amount'=>(int)$r['order_total'],'customer_note'=>(string)($r['order_note']??''),
                        'item_count'=>0,'quantity_count'=>0,'items'=>[],
                    ];
                }
                $idx=$roundIndex[$orderId];$rounds[$idx]['item_count']++;$rounds[$idx]['quantity_count']+=(int)$r['quantity'];
                $rounds[$idx]['items'][]=[
                    'order_item_id'=>(int)$r['order_item_id'],'item_id'=>$r['item_id']!==null?(int)$r['item_id']:null,'name'=>(string)$r['item_name'],
                    'unit_price'=>(int)$r['unit_price'],'current_price'=>$currentCatalogPrice,'price_changed'=>$currentCatalogPrice!==null&&$currentCatalogPrice!==(int)$r['unit_price'],'quantity'=>(int)$r['quantity'],'ordered_quantity'=>$orderedQuantity,'line_total'=>(int)$r['line_total'],'note'=>(string)($r['item_note']??''),
                    'fulfillment_mode'=>(string)($r['fulfillment_mode']??'dine_in'),'preparation_station'=>(string)($r['preparation_station']??''),'order_id'=>$orderId,'order_number'=>(int)$r['business_order_number'],
                ];
            }
            $pendingStmt=$this->pdo->prepare("SELECT o.id,o.business_order_number,o.status,o.total_amount,o.customer_note,o.created_at,
                COUNT(oi.id) item_count,COALESCE(SUM(oi.quantity),0) quantity_count
                FROM orders o LEFT JOIN order_items oi ON oi.order_id=o.id AND oi.quantity>0
                WHERE o.session_id=? AND o.status IN('pending_approval','new')
                GROUP BY o.id,o.business_order_number,o.status,o.total_amount,o.customer_note,o.created_at ORDER BY o.created_at,o.id");
            $pendingStmt->execute([$sessionId]);
            foreach($pendingStmt->fetchAll(PDO::FETCH_ASSOC) as $r)$pending[]=[
                'id'=>(int)$r['id'],'order_number'=>(int)$r['business_order_number'],'status'=>(string)$r['status'],'total_amount'=>(int)$r['total_amount'],
                'item_count'=>(int)($r['item_count']??0),'quantity_count'=>(int)($r['quantity_count']??0),
                'note'=>(string)($r['customer_note']??''),'created_at'=>(string)$r['created_at'],
            ];
            $callStmt=$this->pdo->prepare("SELECT id,status,created_at,accepted_at FROM waiter_calls WHERE table_id=? AND session_id=? AND status IN('new','accepted') ORDER BY created_at,id");
            $callStmt->execute([$tableId,$sessionId]);
            foreach($callStmt->fetchAll(PDO::FETCH_ASSOC) as $r)$calls[]=['id'=>(int)$r['id'],'status'=>(string)$r['status'],'created_at'=>(string)$r['created_at'],'accepted_at'=>(string)($r['accepted_at']??'')];
        }
        return [
            'success'=>true,
            'table'=>[
                'id'=>(int)$table['id'],'name'=>(string)$table['name'],'table_number'=>(int)$table['table_number'],'zone_label'=>(string)($table['zone_label']??''),
                'session_id'=>$sessionId,'session_status'=>(string)($table['session_status']??''),'started_at'=>(string)($table['started_at']??''),
                'last_order_at'=>(string)($table['last_order_at']??''),'order_round_count'=>(int)($table['order_round_count']??0),'account_total'=>(int)$table['account_total'],
                'pending_order_count'=>(int)$table['pending_order_count'],'pending_order_total'=>(int)($table['pending_order_total']??0),
                'pending_item_count'=>(int)($table['pending_item_count']??0),'pending_quantity_count'=>(int)($table['pending_quantity_count']??0),'active_waiter_count'=>(int)$table['active_waiter_count'],
            ],
            'lines'=>$lines,'order_rounds'=>$rounds,'pending_orders'=>$pending,'waiter_calls'=>$calls,
            'price_changes'=>array_values(array_reduce($lines,static function(array $carry,array $line):array{
                if(empty($line['price_changed'])||($line['item_id']??null)===null)return $carry;
                $key=(string)$line['item_id'].'|'.(string)$line['unit_price'].'|'.(string)$line['current_price'];
                if(!isset($carry[$key]))$carry[$key]=['item_id'=>(int)$line['item_id'],'name'=>(string)$line['name'],'recorded_price'=>(int)$line['unit_price'],'current_price'=>(int)$line['current_price'],'quantity'=>0];
                $carry[$key]['quantity']+=(int)$line['quantity'];return $carry;
            },[])),
            'generated_at'=>date(DATE_ATOM),
        ];
    }

    private function attentionOrders(): array
    {
        $rows=$this->pdo->query("SELECT o.id,o.business_order_number,o.table_id,o.session_id,o.status,o.customer_note,o.total_amount,o.created_at,t.name table_name
            FROM orders o JOIN cafe_tables t ON t.id=o.table_id
            WHERE o.order_source='guest' AND o.status IN('pending_approval','new') ORDER BY FIELD(o.status,'pending_approval','new'),o.created_at,o.id LIMIT 150")
            ->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[])return [];
        $ids=array_map('intval',array_column($rows,'id'));$ph=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->pdo->prepare("SELECT order_id,item_name,quantity,item_note,fulfillment_mode,line_total FROM order_items WHERE order_id IN($ph) AND quantity>0 ORDER BY order_id,id");
        $stmt->execute($ids);$by=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $line)$by[(int)$line['order_id']][]=['name'=>(string)$line['item_name'],'quantity'=>(int)$line['quantity'],'note'=>(string)($line['item_note']??''),'fulfillment_mode'=>(string)$line['fulfillment_mode'],'line_total'=>(int)$line['line_total']];
        return array_map(static function(array $r) use($by):array{
            $items=$by[(int)$r['id']]??[];
            return [
                'id'=>(int)$r['id'],'order_number'=>(int)$r['business_order_number'],'table_id'=>(int)$r['table_id'],'table_name'=>(string)$r['table_name'],
                'session_id'=>(int)($r['session_id']??0),'status'=>(string)$r['status'],'note'=>(string)($r['customer_note']??''),'total_amount'=>(int)$r['total_amount'],
                'item_count'=>count($items),'quantity_count'=>array_sum(array_map(static fn(array $line):int=>(int)($line['quantity']??0),$items)),
                'created_at'=>(string)$r['created_at'],'items'=>$items,
            ];
        },$rows);
    }

    private function activeWaiterCalls(): array
    {
        $rows=$this->pdo->query("SELECT w.id,w.table_id,w.session_id,w.status,w.created_at,w.accepted_at,t.name table_name,u.display_name accepted_by
            FROM waiter_calls w JOIN cafe_tables t ON t.id=w.table_id LEFT JOIN users u ON u.id=w.accepted_by_user_id
            WHERE w.status IN('new','accepted') ORDER BY w.created_at,w.id")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'table_id'=>(int)$r['table_id'],'session_id'=>(int)($r['session_id']??0),'table_name'=>(string)$r['table_name'],
            'status'=>(string)$r['status'],'accepted_by'=>(string)($r['accepted_by']??''),'created_at'=>(string)$r['created_at'],'accepted_at'=>(string)($r['accepted_at']??''),
        ],$rows);
    }

    private function itemTotals(): array
    {
        $rows=$this->pdo->query("SELECT oi.item_name,oi.preparation_station,SUM(oi.quantity) quantity,SUM(oi.line_total) line_total
            FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN table_sessions s ON s.id=o.session_id
            WHERE s.status='active' AND o.status='accounted' AND oi.quantity>0 GROUP BY oi.item_name,oi.preparation_station ORDER BY quantity DESC,oi.item_name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function(array $r):array{
            $station=(string)($r['preparation_station']??'other');
            $area=$station==='kitchen'?'kitchen':(in_array($station,['hot_bar','cold_bar'],true)?'bar':'other');
            return ['name'=>(string)$r['item_name'],'quantity'=>(int)$r['quantity'],'line_total'=>(int)$r['line_total'],'area'=>$area];
        },$rows);
    }

    /** @param list<string> $needs */
    private function actorAny(array $user,array $needs): array
    {
        $id=(int)($user['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null)throw new OrderStaffActionException('forbidden','حساب کاربری فعال نیست.',403);
        foreach($needs as $cap)if($this->capabilities->has($cap,$fresh))return $fresh;
        throw new OrderStaffActionException('forbidden','دسترسی این بخش برای حساب شما فعال نیست.',403);
    }
}
