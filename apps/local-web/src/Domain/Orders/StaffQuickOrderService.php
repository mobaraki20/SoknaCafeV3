<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class StaffQuickOrderService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BusinessClock $clock,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly OrderCommitService $orders,
    ) {}

    /** Re-read the canonical Local actor before every mutation. */
    public function assertActor(array $user): array
    {
        $userId=(int)($user['id']??0);
        if($userId<1)throw new StaffQuickOrderException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($userId);
        if($fresh===null)throw new StaffQuickOrderException('forbidden','حساب کاربری فعال نیست.',403);
        if(!$this->capabilities->has('orders_floor',$fresh))
            throw new StaffQuickOrderException('forbidden','دسترسی ثبت سفارش برای این حساب فعال نیست.',403);
        return $fresh;
    }

    public function commit(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$this->commitTx($data,$user);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function commitTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Staff Quick Order requires an open transaction.');
        $actor=$this->assertActor($user);
        $userId=(int)$actor['id'];
        $tableId=(int)($data['table_id']??0);
        $expectedSessionId=max(0,(int)($data['expected_session_id']??0));
        $requestToken=$this->requestToken($data['request_token']??'');
        if($tableId<1)throw new StaffQuickOrderException('invalid_table','میز فعال پیدا نشد.',404);

        $table=$this->pdo->prepare('SELECT id,name FROM cafe_tables WHERE id=? AND active=1 FOR UPDATE');
        $table->execute([$tableId]);
        if(!$table->fetch(PDO::FETCH_ASSOC))throw new StaffQuickOrderException('invalid_table','میز فعال پیدا نشد.',404);

        $sessionStmt=$this->pdo->prepare("SELECT * FROM table_sessions WHERE table_id=? AND status IN('active','pending') ORDER BY FIELD(status,'active','pending'),id DESC LIMIT 1 FOR UPDATE");
        $sessionStmt->execute([$tableId]);
        $session=$sessionStmt->fetch(PDO::FETCH_ASSOC)?:null;
        $currentSessionId=$session?(int)$session['id']:0;

        if($expectedSessionId>0){
            if($currentSessionId!==$expectedSessionId)
                throw new StaffQuickOrderException('session_changed','حساب این میز تغییر کرده است؛ صفحه را تازه کنید.',409,['current_session_id'=>$currentSessionId]);
        }elseif($currentSessionId>0){
            throw new StaffQuickOrderException('session_changed','وضعیت میز تغییر کرده است؛ صفحه را تازه کنید.',409,['current_session_id'=>$currentSessionId]);
        }

        if($session===null){
            $business=$this->clock->assignment($data['occurred_at']??null);
            $token='S'.bin2hex(random_bytes(24));
            $insert=$this->pdo->prepare(
                "INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,started_at,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot)
                 VALUES(?,?,'active',?,?,NOW(),?,?,?,?,?)"
            );
            $insert->execute([
                $token,$tableId,$tableId,$userId,
                $business['business_date'],$business['shift_key'],$business['shift_label'],$business['cutoff'],
            ]);
            $sessionId=(int)$this->pdo->lastInsertId();
        }else{
            $sessionId=(int)$session['id'];
            if((string)$session['status']==='pending'){
                $this->pdo->prepare("UPDATE table_sessions SET status='active',live_table_guard=table_id,opened_by_user_id=COALESCE(opened_by_user_id,?) WHERE id=?")
                    ->execute([$userId,$sessionId]);
            }
        }

        $pending=$this->pdo->prepare("SELECT id FROM orders WHERE session_id=? AND status IN('pending_approval','new') ORDER BY id LIMIT 1 FOR UPDATE");
        $pending->execute([$sessionId]);
        if($pending->fetchColumn()!==false)
            throw new StaffQuickOrderException('pending_guest_order','سفارش مهمان منتظر بررسی است؛ ابتدا آن را تعیین تکلیف کنید.',409);

        try{
            $order=$this->orders->commitTx([
                'source'=>'staff',
                'actor_user_id'=>$userId,
                'table_id'=>$tableId,
                'session_id'=>$sessionId,
                'client_token'=>$requestToken,
                'status'=>'accounted',
                'customer_note'=>(string)($data['note']??''),
                'occurred_at'=>$data['occurred_at']??null,
                'items'=>$data['items']??null,
            ]);
        }catch(OrderCommitException $e){
            throw new StaffQuickOrderException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);
        }

        $order['session_id']=$sessionId;
        return $order;
    }

    private function requestToken(mixed $value): string
    {
        $token=trim((string)$value);
        if(!preg_match('/^[A-Za-z0-9-]{16,74}$/',$token))
            throw new StaffQuickOrderException('invalid_request_token','شناسه امن ثبت سفارش معتبر نیست؛ صفحه را تازه کنید.',422);
        return 'staff-'.$token;
    }
}
