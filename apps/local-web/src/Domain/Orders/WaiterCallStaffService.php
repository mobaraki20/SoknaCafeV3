<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class WaiterCallStaffService
{
    public function __construct(private readonly PDO $pdo,private readonly IdentityRepository $identity,private readonly Capabilities $capabilities){}

    public function changeStatus(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{$result=$this->changeStatusTx($data,$user);$this->pdo->commit();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function changeStatusTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Waiter staff action requires an open transaction.');
        $actor=$this->actor($user);$actorId=(int)$actor['id'];
        $callId=(int)($data['call_id']??0);$target=trim((string)($data['status']??''));
        if($callId<1||!in_array($target,['accepted','done','cancelled'],true))
            throw new WaiterCallException('invalid_action','عملیات فراخوان معتبر نیست.',422);

        $stmt=$this->pdo->prepare('SELECT w.*,ts.status session_status FROM waiter_calls w LEFT JOIN table_sessions ts ON ts.id=w.session_id WHERE w.id=? FOR UPDATE');
        $stmt->execute([$callId]);$call=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($call))throw new WaiterCallException('not_found','فراخوان پیدا نشد.',404);

        if($call['session_id']!==null&&!in_array((string)($call['session_status']??''),['active','pending'],true)){
            $this->pdo->prepare("UPDATE waiter_calls SET status='cancelled',active_table_guard=NULL,cancelled_at=COALESCE(cancelled_at,NOW()),cancel_reason='table_closed',cancelled_by_user_id=COALESCE(cancelled_by_user_id,?) WHERE id=? AND status IN('new','accepted')")
                ->execute([$actorId,$callId]);
            $this->audit('waiter_call.cancelled',$callId,$actor,['from_status'=>(string)$call['status'],'to_status'=>'cancelled','reason'=>'table_closed']);
            return ['success'=>true,'code'=>'table_closed','message'=>'میز بسته شده و فراخوان نیز بسته شد.','call_id'=>$callId,'status'=>'cancelled','closed_session'=>true];
        }

        $current=(string)$call['status'];
        if($current===$target)return ['success'=>true,'idempotent'=>true,'call_id'=>$callId,'status'=>$current];
        if(!in_array($current,['new','accepted'],true))throw new WaiterCallException('call_closed','این فراخوان قبلاً بسته شده است.',409);

        if($target==='accepted'){
            if($current==='accepted'&&(int)($call['accepted_by_user_id']??0)!==$actorId)
                throw new WaiterCallException('already_accepted','یکی از همکاران این فراخوان را پذیرفته است.',409);
            $this->pdo->prepare("UPDATE waiter_calls SET status='accepted',accepted_by_user_id=COALESCE(accepted_by_user_id,?),accepted_at=COALESCE(accepted_at,NOW()) WHERE id=? AND status IN('new','accepted')")
                ->execute([$actorId,$callId]);
        }elseif($target==='done'){
            $this->pdo->prepare("UPDATE waiter_calls SET status='done',active_table_guard=NULL,accepted_by_user_id=COALESCE(accepted_by_user_id,?),accepted_at=COALESCE(accepted_at,NOW()),completed_at=NOW() WHERE id=? AND status IN('new','accepted')")
                ->execute([$actorId,$callId]);
        }else{
            $this->pdo->prepare("UPDATE waiter_calls SET status='cancelled',active_table_guard=NULL,cancelled_at=NOW(),cancel_reason='manual',cancelled_by_user_id=? WHERE id=? AND status IN('new','accepted')")
                ->execute([$actorId,$callId]);
        }
        $this->audit('waiter_call.'.$target,$callId,$actor,['from_status'=>$current,'to_status'=>$target]);
        return ['success'=>true,'idempotent'=>false,'call_id'=>$callId,'status'=>$target];
    }

    private function actor(array $user): array
    {
        $id=(int)($user['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null||!$this->capabilities->has('orders_floor',$fresh))throw new WaiterCallException('forbidden','دسترسی رسیدگی به فراخوان برای این حساب فعال نیست.',403);
        return $fresh;
    }

    private function audit(string $action,int $entityId,array $actor,array $details): void
    {
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}';
        $this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')
            ->execute([(int)$actor['id'],(string)$actor['display_name'],$action,'waiter_call',(string)$entityId,$json]);
    }
}
