<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Inventory\InventoryOrderService;
use Sokna\Local\Domain\Printing\PrintService;
use Throwable;

final class OrderStaffActionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly InventoryOrderService $inventoryOrders,
        private readonly PrintService $printing,
    ){}

    public function changeStatus(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{$result=$this->changeStatusTx($data,$user);$this->pdo->commit();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function changeStatusTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Order staff action requires an open transaction.');
        $actor=$this->actor($user);$actorId=(int)$actor['id'];
        $orderId=(int)($data['order_id']??0);$target=trim((string)($data['status']??''));
        if($orderId<1||!in_array($target,['accounted','cancelled'],true))
            throw new OrderStaffActionException('invalid_action','این عملیات فقط برای تأیید یا رد سفارش منتظر است.',422);

        $probe=$this->pdo->prepare('SELECT table_id,session_id FROM orders WHERE id=? LIMIT 1');
        $probe->execute([$orderId]);$ids=$probe->fetch(PDO::FETCH_ASSOC);
        if(!is_array($ids))throw new OrderStaffActionException('not_found','سفارش پیدا نشد.',404);
        $tableId=(int)$ids['table_id'];$sessionId=(int)($ids['session_id']??0);

        $table=$this->pdo->prepare('SELECT id,name FROM cafe_tables WHERE id=? FOR UPDATE');
        $table->execute([$tableId]);$tableRow=$table->fetch(PDO::FETCH_ASSOC);
        if(!is_array($tableRow))throw new OrderStaffActionException('table_missing','میز سفارش پیدا نشد.',409);

        $session=null;
        if($sessionId>0){
            $stmt=$this->pdo->prepare('SELECT id,status,table_id FROM table_sessions WHERE id=? FOR UPDATE');
            $stmt->execute([$sessionId]);$session=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($session)||(int)$session['table_id']!==$tableId)throw new OrderStaffActionException('session_changed','نشست این سفارش تغییر کرده است.',409);
        }

        $stmt=$this->pdo->prepare('SELECT id,status,accepted_by_user_id,session_id,table_id FROM orders WHERE id=? FOR UPDATE');
        $stmt->execute([$orderId]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order))throw new OrderStaffActionException('not_found','سفارش پیدا نشد.',404);
        $old=(string)$order['status'];
        if(!in_array($old,['pending_approval','new'],true)){
            if($old===$target)return ['success'=>true,'idempotent'=>true,'order_id'=>$orderId,'status'=>$old];
            throw new OrderStaffActionException('order_closed','این سفارش دیگر منتظر تأیید نیست.',409,['current_status'=>$old]);
        }

        if($target==='accounted'){
            if($sessionId>0&&is_array($session)&&(string)$session['status']==='pending'){
                $activate=$this->pdo->prepare("UPDATE table_sessions SET status='active',live_table_guard=table_id,opened_by_user_id=COALESCE(opened_by_user_id,?) WHERE id=? AND status='pending'");
                $activate->execute([$actorId,$sessionId]);
                if($activate->rowCount()!==1)throw new OrderStaffActionException('session_changed','نشست میز هم‌زمان تغییر کرده است.',409);
            }elseif($sessionId>0&&is_array($session)&&(string)$session['status']!=='active'){
                throw new OrderStaffActionException('session_closed','نشست این میز پایان یافته است.',409);
            }
            $update=$this->pdo->prepare("UPDATE orders SET status='accounted',accepted_at=COALESCE(accepted_at,NOW()),accepted_by_user_id=COALESCE(accepted_by_user_id,?) WHERE id=? AND status=?");
            $update->execute([$actorId,$orderId,$old]);
            if($update->rowCount()!==1)throw new OrderStaffActionException('order_changed','این سفارش هم‌زمان توسط همکار دیگری بررسی شد.',409);
            $this->pdo->prepare("INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,?,'accounted',?)")->execute([$orderId,$old,$actorId]);
            $this->inventoryOrders->enqueueAccountedTx($orderId,$actorId);
            $this->printing->enqueueOrderTx($orderId,$actorId);
        }else{
            $update=$this->pdo->prepare("UPDATE orders SET status='cancelled',updated_at=NOW() WHERE id=? AND status=?");
            $update->execute([$orderId,$old]);
            if($update->rowCount()!==1)throw new OrderStaffActionException('order_changed','این سفارش هم‌زمان توسط همکار دیگری بررسی شد.',409);
            $this->pdo->prepare("INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id) VALUES(?,?,'cancelled',?)")->execute([$orderId,$old,$actorId]);
            if($sessionId>0&&is_array($session)&&(string)$session['status']==='pending'){
                $remaining=$this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE session_id=? AND status IN('pending_approval','new')");
                $remaining->execute([$sessionId]);
                if((int)$remaining->fetchColumn()===0){
                    $this->pdo->prepare("UPDATE waiter_calls SET status='cancelled',active_table_guard=NULL,cancelled_at=COALESCE(cancelled_at,NOW()),cancel_reason=COALESCE(cancel_reason,'pending_rejected'),cancelled_by_user_id=COALESCE(cancelled_by_user_id,?) WHERE session_id=? AND status IN('new','accepted')")->execute([$actorId,$sessionId]);
                    $this->pdo->prepare("UPDATE table_sessions SET status='closed',live_table_guard=NULL,ended_at=COALESCE(ended_at,NOW()) WHERE id=? AND status='pending'")->execute([$sessionId]);
                }
            }
        }

        $this->audit('order.status_changed',$orderId,$actor,['from_status'=>$old,'to_status'=>$target,'table_id'=>$tableId,'session_id'=>$sessionId]);
        return ['success'=>true,'idempotent'=>false,'order_id'=>$orderId,'status'=>$target,'table_id'=>$tableId,'table_name'=>(string)$tableRow['name']];
    }

    private function actor(array $user): array
    {
        $id=(int)($user['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null||!$this->capabilities->has('orders_floor',$fresh))
            throw new OrderStaffActionException('forbidden','دسترسی رسیدگی به سفارش برای این حساب فعال نیست.',403);
        return $fresh;
    }

    private function audit(string $action,int $entityId,array $actor,array $details): void
    {
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}';
        $this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')
            ->execute([(int)$actor['id'],(string)$actor['display_name'],$action,'order',(string)$entityId,$json]);
    }
}
