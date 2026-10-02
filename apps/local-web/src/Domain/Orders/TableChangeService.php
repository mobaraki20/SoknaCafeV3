<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Observability;
use Throwable;

/**
 * Canonical Local owner for moving one live table account to another free table.
 *
 * A move deliberately creates a new live table_session. The source session is
 * closed with ended_reason=moved so the physical-table history remains explicit,
 * while the account's open orders, discount state, active waiter call and draft
 * continue on the destination session.
 */
final class TableChangeService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly Observability $observability,
    ) {}

    public function move(array $data,array $user): array
    {
        $actor=$this->actor($user);$actorId=(int)$actor['id'];
        $sourceTableId=(int)($data['table_id']??0);
        $targetTableId=(int)($data['target_table_id']??0);
        $expectedSessionId=(int)($data['expected_session_id']??0);
        $requestId=$this->requestId((string)($data['request_id']??''));

        if($sourceTableId<1||$targetTableId<1||$sourceTableId===$targetTableId)
            throw new TableChangeException('invalid_table_move','میز مبدأ و مقصد را درست انتخاب کن.',422);
        if($expectedSessionId<1)
            throw new TableChangeException('expected_session_required','حساب میز تغییر کرده است؛ صفحه را تازه کن و دوباره تلاش کن.',409);

        $prior=$this->idempotentResult($requestId,$actorId,$sourceTableId,$targetTableId,$expectedSessionId,false);
        if($prior!==null)return $prior;

        $this->pdo->beginTransaction();
        try{
            // Lock physical tables in deterministic order to avoid source/target deadlocks.
            $lockIds=[$sourceTableId,$targetTableId];sort($lockIds,SORT_NUMERIC);
            $tableStmt=$this->pdo->prepare('SELECT id,name,table_number FROM cafe_tables WHERE id IN(?,?) AND active=1 ORDER BY id FOR UPDATE');
            $tableStmt->execute($lockIds);$tables=[];
            foreach($tableStmt->fetchAll(PDO::FETCH_ASSOC) as $row)$tables[(int)$row['id']]=$row;
            $source=$tables[$sourceTableId]??null;$target=$tables[$targetTableId]??null;
            if(!is_array($source))throw new TableChangeException('source_table_missing','میز مبدأ فعال پیدا نشد.',404);
            if(!is_array($target))throw new TableChangeException('target_table_missing','میز مقصد فعال پیدا نشد.',404);

            $sessionStmt=$this->pdo->prepare("SELECT * FROM table_sessions WHERE id=? AND table_id=? AND status IN('active','pending') LIMIT 1 FOR UPDATE");
            $sessionStmt->execute([$expectedSessionId,$sourceTableId]);$session=$sessionStmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($session)){
                // A retry after a committed move should resolve to the original success.
                $prior=$this->idempotentResult($requestId,$actorId,$sourceTableId,$targetTableId,$expectedSessionId,true);
                if($prior!==null){$this->pdo->commit();return $prior;}
                throw new TableChangeException('session_changed','حساب میز هم‌زمان تغییر کرده است؛ صفحه را تازه کن.',409,['expected_session_id'=>$expectedSessionId]);
            }

            $targetSession=$this->pdo->prepare("SELECT id,status FROM table_sessions WHERE table_id=? AND status IN('active','pending') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $targetSession->execute([$targetTableId]);$occupied=$targetSession->fetch(PDO::FETCH_ASSOC);
            if(is_array($occupied))throw new TableChangeException('target_occupied','میز مقصد الان حساب باز دارد.',409,['target_session_id'=>(int)$occupied['id']]);

            // Drafts are Local-owned live state. A destination with a draft is not truly free.
            $draftStmt=$this->pdo->prepare("SELECT * FROM table_drafts WHERE table_id IN(?,?) AND state='active' ORDER BY table_id,id FOR UPDATE");
            $draftStmt->execute($lockIds);$sourceDraft=null;$targetDraft=null;
            foreach($draftStmt->fetchAll(PDO::FETCH_ASSOC) as $draft){
                if((int)$draft['table_id']===$sourceTableId)$sourceDraft=$draft;
                if((int)$draft['table_id']===$targetTableId)$targetDraft=$draft;
            }
            if(is_array($targetDraft))throw new TableChangeException('target_draft_active','روی میز مقصد یک سفارش در حال ثبت وجود دارد.',409,['draft_id'=>(int)$targetDraft['id']]);
            if(is_array($sourceDraft)&&(int)($sourceDraft['expected_session_id']??0)!==$expectedSessionId)
                throw new TableChangeException('draft_session_mismatch','پیش‌نویس میز با حساب فعلی هم‌خوان نیست؛ ابتدا صفحه سفارش را تازه کن.',409,['draft_id'=>(int)$sourceDraft['id'],'draft_session_id'=>(int)($sourceDraft['expected_session_id']??0)]);

            // Lock active waiter calls on both tables. A pre-existing destination call makes
            // the table operationally occupied even when it has no table_session yet.
            $callStmt=$this->pdo->prepare("SELECT id,table_id,session_id,status FROM waiter_calls WHERE table_id IN(?,?) AND status IN('new','accepted') ORDER BY table_id,id FOR UPDATE");
            $callStmt->execute($lockIds);$sourceCallIds=[];
            foreach($callStmt->fetchAll(PDO::FETCH_ASSOC) as $call){
                if((int)$call['table_id']===$targetTableId)
                    throw new TableChangeException('target_waiter_call_active','روی میز مقصد یک فراخوان فعال وجود دارد؛ ابتدا آن را تعیین تکلیف کن.',409,['call_id'=>(int)$call['id']]);
                if((int)$call['table_id']===$sourceTableId)$sourceCallIds[]=(int)$call['id'];
            }

            // Any unreversed completed settlement means itemized/partial payment has started.
            // Moving after that point would split one financial account across two sessions.
            $paidStmt=$this->pdo->prepare(
                "SELECT sr.id,sr.invoice_number,sr.destination,sr.settlement_kind FROM settlement_records sr
                 LEFT JOIN settlement_records rv ON rv.reverses_settlement_id=sr.id AND rv.status='reversal'
                 WHERE sr.session_id=? AND sr.status='completed' AND rv.id IS NULL
                 ORDER BY sr.id LIMIT 1 FOR UPDATE"
            );
            $paidStmt->execute([$expectedSessionId]);$paid=$paidStmt->fetch(PDO::FETCH_ASSOC);
            if(is_array($paid))throw new TableChangeException(
                'settlement_started','برای این حساب پرداخت ثبت شده است؛ تغییر میز تا تعیین تکلیف حساب مجاز نیست.',409,
                ['settlement_id'=>(int)$paid['id'],'invoice_number'=>(string)$paid['invoice_number']]
            );

            // Accommodation is an external financial state. Only an explicitly resolved
            // failed/voided transfer is safe to leave behind on the historical source session.
            $accStmt=$this->pdo->prepare('SELECT id,status,suspicious_response,resolved_at FROM accommodation_transfers WHERE session_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $accStmt->execute([$expectedSessionId]);$acc=$accStmt->fetch(PDO::FETCH_ASSOC);
            if(is_array($acc)){
                $resolved=!empty($acc['resolved_at']);$status=(string)$acc['status'];$suspicious=(int)($acc['suspicious_response']??0)===1;
                $safe=$resolved&&!$suspicious&&in_array($status,['failed','voided'],true);
                if(!$safe)throw new TableChangeException('accommodation_transfer_open','این حساب در فرایند انتقال اقامتگاه است؛ ابتدا همان انتقال را تعیین تکلیف کن.',409,['transfer_id'=>(int)$acc['id'],'status'=>$status]);
            }

            // Lock all live/accounted orders that belong to the account before mutation.
            $ordersStmt=$this->pdo->prepare("SELECT id,status FROM orders WHERE session_id=? AND status IN('pending_approval','new','accounted') ORDER BY id FOR UPDATE");
            $ordersStmt->execute([$expectedSessionId]);$orderRows=$ordersStmt->fetchAll(PDO::FETCH_ASSOC);

            $sourceStatus=(string)$session['status']==='pending'?'pending':'active';
            $close=$this->pdo->prepare("UPDATE table_sessions SET status='closed',live_table_guard=NULL,ended_at=NOW(),ended_reason='moved',closed_by_user_id=? WHERE id=? AND table_id=? AND status IN('active','pending')");
            $close->execute([$actorId,$expectedSessionId,$sourceTableId]);
            if($close->rowCount()!==1)throw new TableChangeException('session_changed','حساب میز هم‌زمان تغییر کرده است؛ انتقال انجام نشد.',409);

            $newToken='S'.bin2hex(random_bytes(24));
            $openedBy=(int)($session['opened_by_user_id']??0);if($openedBy<1)$openedBy=$actorId;
            $insert=$this->pdo->prepare(
                "INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,started_at,
                 business_date,business_shift_key,business_shift_label,business_cutoff_snapshot,
                 discount_type,discount_value,discount_amount,discount_by_user_id,discount_updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $insert->execute([
                $newToken,$targetTableId,$sourceStatus,$targetTableId,$openedBy,(string)$session['started_at'],
                (string)$session['business_date'],(string)$session['business_shift_key'],(string)$session['business_shift_label'],(string)$session['business_cutoff_snapshot'],
                ($session['discount_type']??null)?:null,(int)($session['discount_value']??0),(int)($session['discount_amount']??0),
                !empty($session['discount_by_user_id'])?(int)$session['discount_by_user_id']:null,($session['discount_updated_at']??null)?:null,
            ]);
            $newSessionId=(int)$this->pdo->lastInsertId();
            if($newSessionId<1)throw new TableChangeException('session_create_failed','نشست میز مقصد ساخته نشد.',500);

            $moveOrders=$this->pdo->prepare("UPDATE orders SET table_id=?,session_id=? WHERE session_id=? AND status IN('pending_approval','new','accounted')");
            $moveOrders->execute([$targetTableId,$newSessionId,$expectedSessionId]);$movedOrders=$moveOrders->rowCount();
            if($movedOrders!==count($orderRows))throw new TableChangeException('order_set_changed','مجموعه سفارش‌های حساب هم‌زمان تغییر کرد؛ انتقال متوقف شد.',409,['expected_orders'=>count($orderRows),'moved_orders'=>$movedOrders]);

            $movedDraftId=0;
            if(is_array($sourceDraft)){
                $draftId=(int)$sourceDraft['id'];$nextVersion=(int)$sourceDraft['version']+1;
                $moveDraft=$this->pdo->prepare("UPDATE table_drafts SET table_id=?,active_table_guard=?,expected_session_id=?,version=?,updated_by_user_id=? WHERE id=? AND state='active' AND table_id=?");
                $moveDraft->execute([$targetTableId,$targetTableId,$newSessionId,$nextVersion,$actorId,$draftId,$sourceTableId]);
                if($moveDraft->rowCount()!==1)throw new TableChangeException('draft_changed','پیش‌نویس سفارش هم‌زمان تغییر کرده است؛ انتقال متوقف شد.',409,['draft_id'=>$draftId]);
                $movedDraftId=$draftId;
            }

            $movedCalls=0;
            if($sourceCallIds!==[]){
                $moveCalls=$this->pdo->prepare("UPDATE waiter_calls SET table_id=?,session_id=?,active_table_guard=? WHERE table_id=? AND status IN('new','accepted')");
                $moveCalls->execute([$targetTableId,$newSessionId,$targetTableId,$sourceTableId]);$movedCalls=$moveCalls->rowCount();
                if($movedCalls!==count($sourceCallIds))throw new TableChangeException('waiter_call_changed','فراخوان میز هم‌زمان تغییر کرده است؛ انتقال متوقف شد.',409,['expected_calls'=>count($sourceCallIds),'moved_calls'=>$movedCalls]);
            }

            // The account's discount audit follows the account to the new session.
            $discountAudit=$this->pdo->prepare('UPDATE invoice_discount_audit SET session_id=? WHERE session_id=?');
            $discountAudit->execute([$newSessionId,$expectedSessionId]);

            // Preserve registered client history for the live account without deleting the
            // historical source-session rows.
            $this->pdo->prepare(
                'INSERT IGNORE INTO table_session_clients(session_id,device_token,first_seen_at,last_seen_at) '
                .'SELECT ?,device_token,first_seen_at,last_seen_at FROM table_session_clients WHERE session_id=?'
            )->execute([$newSessionId,$expectedSessionId]);

            $details=[
                'request_id'=>$requestId,
                'source_table_id'=>$sourceTableId,'source_table_name'=>(string)$source['name'],
                'target_table_id'=>$targetTableId,'target_table_name'=>(string)$target['name'],
                'source_session_id'=>$expectedSessionId,'new_session_id'=>$newSessionId,'session_status'=>$sourceStatus,
                'started_at'=>(string)$session['started_at'],'moved_order_count'=>$movedOrders,
                'moved_waiter_call_count'=>$movedCalls,'moved_draft_id'=>$movedDraftId,
            ];
            $this->audit($requestId,$actor,$details);
            $this->pdo->commit();

            $this->observability->logEvent('warning','orders.table_moved',$details+['actor_user_id'=>$actorId]);
            return $this->resultFromDetails($details,false);
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function actor(array $user): array
    {
        $id=(int)($user['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null||!$this->capabilities->has('orders_floor',$fresh))
            throw new TableChangeException('forbidden','دسترسی تغییر میز برای این حساب فعال نیست.',403);
        return $fresh;
    }

    private function audit(string $requestId,array $actor,array $details): void
    {
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)'
        )->execute([(int)$actor['id'],(string)$actor['display_name'],'table_session.moved','table_move_request',$requestId,$json]);
    }

    private function idempotentResult(string $requestId,int $actorId,int $sourceTableId,int $targetTableId,int $expectedSessionId,bool $lock): ?array
    {
        $sql="SELECT details_json FROM audit_log WHERE action='table_session.moved' AND entity_type='table_move_request' AND entity_id=? AND actor_user_id=? ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':'');
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$requestId,$actorId]);$raw=$stmt->fetchColumn();
        if(!is_string($raw)||$raw==='')return null;
        try{$details=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
        if(!is_array($details))return null;
        if((int)($details['source_table_id']??0)!==$sourceTableId||(int)($details['target_table_id']??0)!==$targetTableId||(int)($details['source_session_id']??0)!==$expectedSessionId)
            throw new TableChangeException('request_id_conflict','این شناسه درخواست قبلاً برای انتقال دیگری استفاده شده است.',409);
        return $this->resultFromDetails($details,true);
    }

    private function resultFromDetails(array $details,bool $idempotent): array
    {
        return [
            'success'=>true,'idempotent'=>$idempotent,'request_id'=>(string)($details['request_id']??''),
            'source_table_id'=>(int)($details['source_table_id']??0),'source_table_name'=>(string)($details['source_table_name']??''),
            'target_table_id'=>(int)($details['target_table_id']??0),'target_table_name'=>(string)($details['target_table_name']??''),
            'source_session_id'=>(int)($details['source_session_id']??0),'new_session_id'=>(int)($details['new_session_id']??0),
            'session_status'=>(string)($details['session_status']??''),'started_at'=>(string)($details['started_at']??''),
            'moved_order_count'=>(int)($details['moved_order_count']??0),
            'moved_waiter_call_count'=>(int)($details['moved_waiter_call_count']??0),
            'moved_draft_id'=>(int)($details['moved_draft_id']??0),
            'message'=>'مهمان از '.(string)($details['source_table_name']??'میز مبدأ').' به '.(string)($details['target_table_name']??'میز مقصد').' منتقل شد و حساب همراه او جابه‌جا شد.',
        ];
    }

    private function requestId(string $value): string
    {
        $value=trim($value);
        if($value==='')return 'move-'.bin2hex(random_bytes(12));
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D',$value))
            throw new TableChangeException('invalid_request_id','شناسه درخواست تغییر میز معتبر نیست.',422);
        return $value;
    }
}
