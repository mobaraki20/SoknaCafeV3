<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Finance\SettlementService;
use Sokna\Local\Domain\Finance\SettlementStateConflict;
use Throwable;

final class AccommodationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly SettlementService $settlements,
        private readonly AccommodationTransport $transport,
    ) {}

    public function prepare(int $sessionId,array $reservation,array $expected,array $user): array
    {
        $actor=$this->assertCashier($user);
        $code=self::clip(trim((string)($reservation['reservation_code']??'')),80);
        $guest=self::clip(trim((string)($reservation['guest_name']??'')),160);
        $room=self::clip(trim((string)($reservation['room_name']??implode('، ',(array)($reservation['room_names']??[])))),240);
        $phone=self::clip(trim((string)($reservation['phone_hint']??'')),40);
        if($sessionId<1||$code===''||$guest===''||$room==='')throw new IntegrationException('reservation_incomplete','اطلاعات رزرو کامل نیست.',422);

        $this->pdo->beginTransaction();
        try{
            $existing=$this->bySessionTx($sessionId);
            if($existing!==null){
                $this->assertSamePreparedIntent($existing,$code);
                $this->pdo->commit();
                return $existing+['idempotent'=>true];
            }
            $prepared=$this->settlements->prepareExternalFullTx($sessionId,(int)$actor['id']);
            $account=$prepared['account'];
            $expectedSession=(int)($expected['expected_session_id']??0);
            $expectedTotal=(int)($expected['expected_remaining_total']??-1);
            $expectedSignature=strtolower(trim((string)($expected['expected_signature']??'')));
            if($expectedSession<1||$expectedTotal<0||!preg_match('/^[a-f0-9]{64}$/',$expectedSignature))
                throw new IntegrationStateConflict('expected_state_required','اطلاعات تأیید انتقال کامل نیست؛ حساب را دوباره باز کن.',409);
            if((int)$account['session']['id']!==$expectedSession||(int)$account['remaining_total']!==$expectedTotal||!hash_equals((string)$account['signature'],$expectedSignature))
                throw new IntegrationStateConflict('account_changed','حساب در این فاصله تغییر کرده است؛ انتقال اقامتگاه ساخته نشد.',409);
            if((int)$prepared['review']['total']<1)throw new IntegrationException('invalid_amount','مبلغ قابل انتقال معتبر نیست.',409);
            $externalOrder='CAFE-S-'.$sessionId;
            $snapshot=$prepared['snapshot'];
            $payloadHash=hash('sha256',self::canonicalJson([
                'external_order_id'=>$externalOrder,'reservation_code'=>$code,'amount'=>(int)$prepared['review']['total'],
                'currency'=>'TOMAN','invoice'=>$snapshot,
            ]));
            $stmt=$this->pdo->prepare(
                "INSERT INTO accommodation_transfers(session_id,financial_period_id,external_order_id,reservation_code,guest_name_snapshot,room_name_snapshot,
                 phone_hint_snapshot,amount,invoice_number,invoice_snapshot_json,account_signature,payload_hash,status,operator_user_id)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'pending',?)"
            );
            $stmt->execute([
                $sessionId,(int)$prepared['issued']['period']['id'],$externalOrder,$code,$guest,$room,$phone?:null,
                (int)$prepared['review']['total'],(string)$prepared['issued']['invoice_number'],self::canonicalJson($snapshot),
                (string)$account['signature'],$payloadHash,(int)$actor['id']
            ]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit('accommodation.transfer_prepared','accommodation_transfer',$id,(int)$actor['id'],[
                'session_id'=>$sessionId,'reservation_code'=>$code,'amount'=>(int)$prepared['review']['total'],'invoice_number'=>$prepared['issued']['invoice_number']
            ]);
            $row=$this->byIdTx($id);$this->pdo->commit();return ($row??[])+['idempotent'=>false];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function attemptCharge(int $transferId,array $user): array
    {
        $actor=$this->assertCashier($user);
        $this->pdo->beginTransaction();
        try{
            $transfer=$this->byIdTx($transferId);
            if($transfer===null)throw new IntegrationException('transfer_not_found','انتقال اقامتگاه پیدا نشد.',404);
            if((string)$transfer['status']==='posted'){$this->pdo->commit();return $this->finalizeLocal($transferId,$user)+['remote_idempotent'=>true];}
            if(in_array((string)$transfer['status'],['void_pending','void_failed','voided'],true))throw new IntegrationStateConflict('void_in_progress','این انتقال در فرایند برگشت است.',409);
            if(!in_array((string)$transfer['status'],['pending','failed'],true))throw new IntegrationStateConflict('invalid_transfer_state','وضعیت انتقال برای ارسال معتبر نیست.',409);
            $this->pdo->prepare("UPDATE accommodation_transfers SET status='pending',attempt_count=attempt_count+1,last_attempt_at=NOW(),last_error_code=NULL,last_error=NULL WHERE id=?")
                ->execute([$transferId]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}

        $transfer=$this->byId($transferId);$snapshot=json_decode((string)($transfer['invoice_snapshot_json']??''),true);if(!is_array($snapshot))$snapshot=[];
        $result=$this->transport->charge([
            'external_order_id'=>(string)$transfer['external_order_id'],'reservation_code'=>(string)$transfer['reservation_code'],
            'amount'=>(int)$transfer['amount'],'currency'=>'TOMAN','invoice'=>$snapshot,'payload_hash'=>(string)$transfer['payload_hash'],'requested_at'=>date(DATE_ATOM)
        ]);
        $this->pdo->beginTransaction();
        try{
            $locked=$this->byIdTx($transferId);if($locked===null)throw new IntegrationException('transfer_not_found','انتقال اقامتگاه پیدا نشد.',404);
            if(!empty($result['success'])){
                $this->pdo->prepare("UPDATE accommodation_transfers SET status='posted',remote_transaction_id=?,remote_tracking_id=?,posted_at=COALESCE(posted_at,NOW()),last_error_code=NULL,last_error=NULL,suspicious_response=0,local_finalize_pending=1 WHERE id=?")
                    ->execute([self::clip((string)($result['transaction_id']??''),120)?:null,self::clip((string)($result['tracking_id']??''),120)?:null,$transferId]);
                $this->audit('accommodation.remote_posted','accommodation_transfer',$transferId,(int)$actor['id'],['transaction_id'=>$result['transaction_id']??'','idempotent'=>(bool)($result['idempotent']??false)]);
                $this->pdo->commit();
                $local=$this->finalizeLocal($transferId,$user);
                return ['success'=>true,'posted'=>true,'remote'=>$result,'local'=>$local];
            }
            $ambiguous=(bool)($result['ambiguous']??false);$code=self::clip((string)($result['code']??'remote_error'),80);$message=self::clip((string)($result['message']??'عملیات اقامتگاه انجام نشد.'),500);
            $this->pdo->prepare('UPDATE accommodation_transfers SET status=?,last_error_code=?,last_error=?,remote_tracking_id=?,suspicious_response=? WHERE id=?')
                ->execute([$ambiguous?'pending':'failed',$code,$message,self::clip((string)($result['tracking_id']??''),120)?:null,$ambiguous?1:0,$transferId]);
            $this->audit('accommodation.remote_failed','accommodation_transfer',$transferId,(int)$actor['id'],['code'=>$code,'ambiguous'=>$ambiguous]);
            $this->pdo->commit();return ['success'=>false,'posted'=>false,'ambiguous'=>$ambiguous,'retryable'=>(bool)($result['retryable']??false),'code'=>$code,'message'=>$message];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function finalizeLocal(int $transferId,array $user): array
    {
        $transfer=$this->byId($transferId);if($transfer===null)throw new IntegrationException('transfer_not_found','انتقال اقامتگاه پیدا نشد.',404);
        if((string)$transfer['status']!=='posted')throw new IntegrationStateConflict('not_posted','ثبت هزینه در اقامتگاه هنوز قطعی نشده است.',409);
        try{
            return $this->settlements->settle([
                'session_id'=>(int)$transfer['session_id'],'destination'=>'accommodation','mode'=>'full',
                'request_id'=>'accommodation-finalize-'.$transferId,'accommodation_transfer_id'=>$transferId,
                'expected_session_id'=>(int)$transfer['session_id'],'expected_remaining_total'=>(int)$transfer['amount'],'expected_signature'=>(string)$transfer['account_signature'],
            ],$user);
        }catch(Throwable $e){
            $this->pdo->prepare('UPDATE accommodation_transfers SET local_finalize_pending=1,last_error_code=?,last_error=? WHERE id=?')
                ->execute(['local_finalize_failed',self::clip($e->getMessage(),500),$transferId]);
            return ['success'=>false,'local_finalize_pending'=>true,'message'=>$e->getMessage()];
        }
    }

    public function attemptVoid(int $transferId,string $reason,array $user): array
    {
        $actor=$this->assertCashier($user);$reason=self::clip(trim($reason),300);if($reason==='')throw new IntegrationException('reason_required','دلیل برگشت را ثبت کن.',422);
        $this->pdo->beginTransaction();
        try{
            $transfer=$this->byIdTx($transferId);if($transfer===null)throw new IntegrationException('transfer_not_found','انتقال اقامتگاه پیدا نشد.',404);
            if((string)$transfer['status']==='voided'){$this->pdo->commit();return $this->finalizeLocalReversal($transferId,$reason,$user)+['remote_idempotent'=>true];}
            if(!in_array((string)$transfer['status'],['posted','void_pending','void_failed'],true))throw new IntegrationStateConflict('not_voidable','فقط انتقال قطعی‌شده قابل برگشت است.',409);
            $this->pdo->prepare("UPDATE accommodation_transfers SET status='void_pending',void_requested_by_user_id=?,attempt_count=attempt_count+1,last_attempt_at=NOW(),last_error_code=NULL,last_error=NULL WHERE id=?")
                ->execute([(int)$actor['id'],$transferId]);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}

        $transfer=$this->byId($transferId);$result=$this->transport->void(['external_order_id'=>(string)$transfer['external_order_id'],'reason'=>$reason,'requested_at'=>date(DATE_ATOM)]);
        $this->pdo->beginTransaction();
        try{
            if(!empty($result['success'])){
                $this->pdo->prepare("UPDATE accommodation_transfers SET status='voided',remote_void_transaction_id=?,remote_original_transaction_id=?,remote_tracking_id=?,voided_by_user_id=?,voided_at=COALESCE(voided_at,NOW()),last_error_code=NULL,last_error=NULL,suspicious_response=0,local_reversal_pending=1 WHERE id=?")
                    ->execute([self::clip((string)($result['transaction_id']??''),120)?:null,self::clip((string)($result['original_transaction_id']??''),120)?:null,self::clip((string)($result['tracking_id']??''),120)?:null,(int)$actor['id'],$transferId]);
                $this->audit('accommodation.remote_voided','accommodation_transfer',$transferId,(int)$actor['id'],['reason'=>$reason,'idempotent'=>(bool)($result['idempotent']??false)]);$this->pdo->commit();
                $local=$this->finalizeLocalReversal($transferId,$reason,$user);return ['success'=>true,'voided'=>true,'remote'=>$result,'local'=>$local];
            }
            $ambiguous=(bool)($result['ambiguous']??false);$code=self::clip((string)($result['code']??'remote_error'),80);$message=self::clip((string)($result['message']??'برگشت اقامتگاه انجام نشد.'),500);
            $this->pdo->prepare('UPDATE accommodation_transfers SET status=?,last_error_code=?,last_error=?,remote_tracking_id=?,suspicious_response=? WHERE id=?')
                ->execute([$ambiguous?'void_pending':'void_failed',$code,$message,self::clip((string)($result['tracking_id']??''),120)?:null,$ambiguous?1:0,$transferId]);
            $this->audit('accommodation.remote_void_failed','accommodation_transfer',$transferId,(int)$actor['id'],['code'=>$code,'ambiguous'=>$ambiguous]);$this->pdo->commit();return ['success'=>false,'voided'=>false,'ambiguous'=>$ambiguous,'code'=>$code,'message'=>$message];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function finalizeLocalReversal(int $transferId,string $reason,array $user): array
    {
        $stmt=$this->pdo->prepare("SELECT sr.id FROM settlement_records sr WHERE sr.accommodation_transfer_id=? AND sr.status='completed' ORDER BY sr.id DESC LIMIT 1");$stmt->execute([$transferId]);$settlementId=(int)($stmt->fetchColumn()?:0);
        if($settlementId<1)return ['success'=>true,'idempotent'=>true,'no_local_settlement'=>true];
        try{return $this->settlements->reverse($settlementId,$reason,'accommodation-reverse-'.$transferId,$user,true);}
        catch(Throwable $e){$this->pdo->prepare('UPDATE accommodation_transfers SET local_reversal_pending=1,last_error_code=?,last_error=? WHERE id=?')->execute(['local_reversal_failed',self::clip($e->getMessage(),500),$transferId]);return ['success'=>false,'local_reversal_pending'=>true,'message'=>$e->getMessage()];}
    }

    public function byId(int $id): ?array{$stmt=$this->pdo->prepare('SELECT * FROM accommodation_transfers WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}
    private function byIdTx(int $id): ?array{$stmt=$this->pdo->prepare('SELECT * FROM accommodation_transfers WHERE id=? FOR UPDATE');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}
    private function bySessionTx(int $id): ?array{$stmt=$this->pdo->prepare('SELECT * FROM accommodation_transfers WHERE session_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}
    private function assertSamePreparedIntent(array $row,string $reservationCode): void{if((string)$row['reservation_code']!==$reservationCode)throw new IntegrationStateConflict('transfer_conflict','برای این حساب انتقال دیگری با رزرو متفاوت ساخته شده است.',409);}
    private function assertCashier(array $user): array{$fresh=$this->identity->findActiveById((int)($user['id']??0));if($fresh===null)throw new IntegrationException('forbidden','حساب کاربری فعال نیست.',403);if((string)($fresh['role']??'')!=='admin'&&!$this->capabilities->has('cashier_accounts',$fresh))throw new IntegrationException('forbidden','دسترسی تسویه اقامتگاه فعال نیست.',403);return $fresh;}
    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void{$name=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');$name->execute([$actorId]);$display=$name->fetchColumn();$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')->execute([$actorId,$display!==false?$display:null,$action,$entityType,(string)$entityId,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}']);}
    private static function canonicalJson(array $v): string{ksort($v);$json=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);if(!is_string($json))throw new IntegrationException('json_failed','ساخت payload انتقال انجام نشد.',500);return $json;}
    private static function clip(string $v,int $n): string{return function_exists('mb_substr')?mb_substr($v,0,$n,'UTF-8'):substr($v,0,$n);}
}
