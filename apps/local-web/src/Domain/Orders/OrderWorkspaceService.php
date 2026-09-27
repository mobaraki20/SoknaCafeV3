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
            'generated_at'=>date(DATE_ATOM),
        ];
    }

    private function tableRows(): array
    {
        $sql="SELECT t.id,t.name,t.table_number,t.sort_order,
            s.id session_id,s.status session_status,s.started_at,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.session_id=s.id AND o.status='accounted'),0) account_total,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.session_id=s.id AND o.status IN('pending_approval','new')),0) pending_order_count,
            COALESCE((SELECT COUNT(*) FROM waiter_calls w WHERE w.table_id=t.id AND w.status IN('new','accepted')),0) active_waiter_count
            FROM cafe_tables t
            LEFT JOIN table_sessions s ON s.id=(SELECT s2.id FROM table_sessions s2 WHERE s2.table_id=t.id AND s2.status IN('active','pending') ORDER BY FIELD(s2.status,'active','pending'),s2.id DESC LIMIT 1)
            WHERE t.active=1 ORDER BY t.sort_order,t.table_number,t.id";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r):array=>[
            'id'=>(int)$r['id'],'name'=>(string)$r['name'],'table_number'=>(int)$r['table_number'],'sort_order'=>(int)$r['sort_order'],
            'session_id'=>(int)($r['session_id']??0),'session_status'=>(string)($r['session_status']??''),'started_at'=>(string)($r['started_at']??''),
            'account_total'=>(int)$r['account_total'],'pending_order_count'=>(int)$r['pending_order_count'],'active_waiter_count'=>(int)$r['active_waiter_count'],
        ],$rows);
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
