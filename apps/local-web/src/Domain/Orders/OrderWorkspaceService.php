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
                'open_tables'=>count(array_filter($tables,static fn(array $r):bool=>(int)$r['session_id']>0)),
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
            'catalog_groups'=>$this->catalog->staffCatalogGroups(),
            'generated_at'=>date(DATE_ATOM),
        ];
    }

    private function tableRows(): array
    {
        $sql="SELECT t.id,t.name,t.table_number,t.sort_order,t.zone_label,
            s.id session_id,s.status session_status,s.started_at,
            (SELECT MAX(o2.created_at) FROM orders o2 WHERE o2.session_id=s.id AND o2.status='accounted') last_order_at,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) account_total,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count,
            COALESCE((SELECT COUNT(*) FROM waiter_calls w WHERE w.table_id=t.id AND w.status IN('new','accepted')),0) active_waiter_count
            FROM cafe_tables t
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            WHERE t.active=1 ORDER BY t.sort_order,t.table_number,t.id";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'name'=>(string)$r['name'],'table_number'=>(int)$r['table_number'],'sort_order'=>(int)$r['sort_order'],'zone_label'=>(string)($r['zone_label']??''),
            'session_id'=>(int)($r['session_id']??0),'session_status'=>(string)($r['session_status']??''),'started_at'=>(string)($r['started_at']??''),'last_order_at'=>(string)($r['last_order_at']??''),
            'account_total'=>(int)$r['account_total'],'pending_order_count'=>(int)$r['pending_order_count'],'active_waiter_count'=>(int)$r['active_waiter_count'],
        ],$rows);
    }

    public function tableAccount(int $tableId,array $user): array
    {
        $actor=$this->actorAny($user,['orders_floor','cashier_accounts','shift_supervision']);
        if($tableId<1)throw new OrderStaffActionException('invalid_table','میز معتبر نیست.',422);
        $stmt=$this->pdo->prepare("SELECT t.id,t.name,t.table_number,t.zone_label,s.id session_id,s.status session_status,s.started_at,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) account_total,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count,
            COALESCE((SELECT COUNT(*) FROM waiter_calls w WHERE w.table_id=t.id AND w.status IN('new','accepted')),0) active_waiter_count,
            (SELECT MAX(o2.created_at) FROM orders o2 WHERE o2.session_id=s.id AND o2.status='accounted') last_order_at
            FROM cafe_tables t
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            WHERE t.id=? AND t.active=1 LIMIT 1");
        $stmt->execute([$tableId]);$table=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($table))throw new OrderStaffActionException('table_missing','میز پیدا نشد.',404);
        $sessionId=(int)($table['session_id']??0);
        $lines=[];$rounds=[];$pending=[];$calls=[];
        if($sessionId>0){
            $lineStmt=$this->pdo->prepare("SELECT oi.id order_item_id,oi.item_id,oi.item_name,oi.unit_price,oi.quantity,oi.line_total,oi.item_note,oi.fulfillment_mode,
                o.id order_id,o.business_order_number,o.total_amount order_total,o.customer_note order_note,o.created_at
                FROM order_items oi JOIN orders o ON o.id=oi.order_id
                WHERE o.session_id=? AND o.status='accounted' AND oi.quantity>0 ORDER BY o.created_at,o.id,oi.id");
            $lineStmt->execute([$sessionId]);
            $rounds=[];$roundIndex=[];
            foreach($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $r){
                $line=[
                    'order_item_id'=>(int)$r['order_item_id'],'item_id'=>$r['item_id']!==null?(int)$r['item_id']:null,'name'=>(string)$r['item_name'],
                    'unit_price'=>(int)$r['unit_price'],'quantity'=>(int)$r['quantity'],'line_total'=>(int)$r['line_total'],'note'=>(string)($r['item_note']??''),
                    'fulfillment_mode'=>(string)($r['fulfillment_mode']??'dine_in'),'order_number'=>(int)$r['business_order_number'],'created_at'=>(string)$r['created_at'],
                ];
                $lines[]=$line;
                $orderId=(int)$r['order_id'];
                if(!isset($roundIndex[$orderId])){
                    $roundIndex[$orderId]=count($rounds);
                    $rounds[]=[
                        'round_index'=>count($rounds)+1,'order_id'=>$orderId,'order_number'=>(int)$r['business_order_number'],
                        'created_at'=>(string)$r['created_at'],'total_amount'=>(int)$r['order_total'],'customer_note'=>(string)($r['order_note']??''),
                        'item_count'=>0,'quantity_count'=>0,'items'=>[],
                    ];
                }
                $idx=$roundIndex[$orderId];
                $rounds[$idx]['item_count']++;
                $rounds[$idx]['quantity_count']+=(int)$r['quantity'];
                $rounds[$idx]['items'][]=[
                    'order_item_id'=>(int)$r['order_item_id'],'item_id'=>$r['item_id']!==null?(int)$r['item_id']:null,'name'=>(string)$r['item_name'],
                    'unit_price'=>(int)$r['unit_price'],'quantity'=>(int)$r['quantity'],'line_total'=>(int)$r['line_total'],'note'=>(string)($r['item_note']??''),
                    'fulfillment_mode'=>(string)($r['fulfillment_mode']??'dine_in'),
                ];
            }
            $pendingStmt=$this->pdo->prepare("SELECT id,business_order_number,status,total_amount,customer_note,created_at FROM orders WHERE session_id=? AND status IN('pending_approval','new') ORDER BY created_at,id");
            $pendingStmt->execute([$sessionId]);
            foreach($pendingStmt->fetchAll(PDO::FETCH_ASSOC) as $r)$pending[]=[
                'id'=>(int)$r['id'],'order_number'=>(int)$r['business_order_number'],'status'=>(string)$r['status'],'total_amount'=>(int)$r['total_amount'],
                'note'=>(string)($r['customer_note']??''),'created_at'=>(string)$r['created_at'],
            ];
            $callStmt=$this->pdo->prepare("SELECT id,status,created_at,accepted_at FROM waiter_calls WHERE table_id=? AND session_id=? AND status IN('new','accepted') ORDER BY created_at,id");
            $callStmt->execute([$tableId,$sessionId]);
            foreach($callStmt->fetchAll(PDO::FETCH_ASSOC) as $r)$calls[]=['id'=>(int)$r['id'],'status'=>(string)$r['status'],'created_at'=>(string)$r['created_at'],'accepted_at'=>(string)($r['accepted_at']??'')];
        }
        return [
            'success'=>true,
            'permissions'=>[
                'orders_floor'=>$this->capabilities->has('orders_floor',$actor),
                'cashier_accounts'=>$this->capabilities->has('cashier_accounts',$actor),
                'shift_supervision'=>$this->capabilities->has('shift_supervision',$actor),
            ],
            'table'=>[
                'id'=>(int)$table['id'],'name'=>(string)$table['name'],'table_number'=>(int)$table['table_number'],'zone_label'=>(string)($table['zone_label']??''),
                'session_id'=>$sessionId,'session_status'=>(string)($table['session_status']??''),'started_at'=>(string)($table['started_at']??''),
                'last_order_at'=>(string)($table['last_order_at']??''),'account_total'=>(int)$table['account_total'],
                'pending_order_count'=>(int)$table['pending_order_count'],'active_waiter_count'=>(int)$table['active_waiter_count'],
            ],
            'lines'=>$lines,'order_rounds'=>$rounds,'pending_orders'=>$pending,'waiter_calls'=>$calls,
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
        return array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'order_number'=>(int)$r['business_order_number'],'table_id'=>(int)$r['table_id'],'table_name'=>(string)$r['table_name'],
            'session_id'=>(int)($r['session_id']??0),'status'=>(string)$r['status'],'note'=>(string)($r['customer_note']??''),'total_amount'=>(int)$r['total_amount'],
            'created_at'=>(string)$r['created_at'],'items'=>$by[(int)$r['id']]??[],
        ],$rows);
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
        $rows=$this->pdo->query("SELECT oi.item_name,SUM(oi.quantity) quantity,SUM(oi.line_total) line_total
            FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN table_sessions s ON s.id=o.session_id
            WHERE s.status='active' AND o.status='accounted' AND oi.quantity>0 GROUP BY oi.item_name ORDER BY quantity DESC,oi.item_name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r):array=>['name'=>(string)$r['item_name'],'quantity'=>(int)$r['quantity'],'line_total'=>(int)$r['line_total']],$rows);
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
