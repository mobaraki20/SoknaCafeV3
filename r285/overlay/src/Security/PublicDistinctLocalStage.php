<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use PDO;
use RuntimeException;
use Throwable;

/**
 * R285, source-exact Public 35ik: safe first phase of distinct-installation
 * transfer. Internal service only; NO unauthenticated HTTP route. A Public
 * owner receipt from the target instance and an HMAC-verified OLD Local
 * offline-fence receipt must both be present before changing any row.
 *
 * This intentionally does NOT activate new Local or guest order intake.
 */
final class PublicDistinctLocalStage
{
    private const HANDOFF_FORMAT='sokna-local-machine-offline-fence-v1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $publicInstanceId,
        private readonly string $serverKey,
    ) {
        if(strlen($serverKey)<32 || $pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)
            throw new RuntimeException('distinct_move_internal_configuration_invalid');
    }

    public function reserve(
        string $oldId,string $newId,string $ownerOperationId,
        array $offlineSourceReceipt,string $handoffSecret,
        string $verifiedBusinessSha,string $verifiedMediaSha
    ):array {
        foreach([$oldId,$newId] as $id) {
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D',$id))
                throw new RuntimeException('distinct_move_installation_id_invalid');
        }
        if(hash_equals($oldId,$newId))
            throw new RuntimeException('distinct_move_same_machine_not_supported');
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$ownerOperationId))
            throw new RuntimeException('distinct_move_operation_invalid');
        foreach([$verifiedBusinessSha,$verifiedMediaSha] as $sha)
            if(!preg_match('/^[a-f0-9]{64}$/D',$sha))
                throw new RuntimeException('distinct_move_backup_sha_invalid');
        $this->verifyOfflineReceipt($offlineSourceReceipt,$handoffSecret,$oldId,
            $verifiedBusinessSha,$verifiedMediaSha);
        $enrollmentCode=$this->codeFor($oldId,$newId,$ownerOperationId);
        if($this->pdo->inTransaction()) throw new RuntimeException('distinct_move_nested_transaction_not_allowed');
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT installation_id,active,authority_epoch,authority_state,revoked_at FROM installations WHERE installation_id=? FOR UPDATE');
            $q->execute([$oldId]);$old=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($old)||(int)$old['active']!==1||!empty($old['revoked_at']))
                throw new RuntimeException('distinct_move_source_not_active');
            $q->execute([$newId]);$new=$q->fetch(PDO::FETCH_ASSOC);
            if($new!==false) throw new RuntimeException('distinct_move_target_already_exists');

            $approved=$this->pdo->prepare(
                'SELECT public_instance_id,local_installation_id,expires_epoch FROM public_move_owner_receipts WHERE operation_id=? FOR UPDATE'
            );$approved->execute([$ownerOperationId]);$owner=$approved->fetch(PDO::FETCH_ASSOC);
            if(!is_array($owner) ||
                !hash_equals((string)$owner['public_instance_id'],$this->publicInstanceId) ||
                !hash_equals((string)$owner['local_installation_id'],$newId) ||
                (int)$owner['expires_epoch']<=time())
                throw new RuntimeException('distinct_move_target_owner_approval_missing');

            // One and only one operation can reserve this old Local. The
            // installation row FOR UPDATE makes concurrent reservations serial.
            $q=$this->pdo->prepare(
                "SELECT id,new_installation_id FROM installation_takeovers
                 WHERE old_installation_id=? AND status='pending' AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE"
            );$q->execute([$oldId]);
            if($q->fetch(PDO::FETCH_ASSOC)!==false)
                throw new RuntimeException('distinct_move_pending_operation_exists');

            // Never discard or double-deliver any QR or remote staff request.
            (new PublicMoveReplacementGate($this->pdo))->requireNoUnresolvedGuestWork($oldId);
            $next=(int)$old['authority_epoch']+1;
            if($next<=1||$next>=2147483600)
                throw new RuntimeException('distinct_move_epoch_outside_range');

            // A sealed source must never serve requests during staging; all
            // Public signed calls from the old machine except idempotent fence
            // are rejected by SignedLocalRequestVerifier authorityCheck.
            $u=$this->pdo->prepare(
                "UPDATE installations SET remote_enabled=0,order_intake_enabled=0,
                  authority_state='reenroll_pending',authority_epoch_required=1,
                  public_move_intake_paused=1,authority_changed_at=UTC_TIMESTAMP(6)
                  WHERE installation_id=? AND active=1 AND revoked_at IS NULL"
            );
            $u->execute([$oldId]);
            if($u->rowCount()!==1)
                throw new RuntimeException('distinct_move_old_fence_race');

            // RESERVATION ONLY: new installation has no keys, no fresh session,
            // no read-model, no QR intake and no Public default binding.
            $ins=$this->pdo->prepare(
                "INSERT INTO installations(installation_id,display_name,active,
                  remote_enabled,order_intake_enabled,authority_epoch,
                  authority_state,authority_epoch_required,public_move_intake_paused)
                  VALUES(?,NULL,0,0,0,?,'reenroll_pending',1,1)"
            );
            $ins->execute([$newId,$next]);
            $takeover=$this->pdo->prepare(
                "INSERT INTO installation_takeovers(old_installation_id,new_installation_id,
                  code_hash,status,expires_at) VALUES(?,?,?,'pending',
                  DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))"
            );
            $takeover->execute([$oldId,$newId,password_hash($enrollmentCode,PASSWORD_DEFAULT)]);
            $reservationId=(int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return ['ok'=>true,'phase'=>'old_fenced_new_reserved',
                'old_installation_id'=>$oldId,'new_installation_id'=>$newId,
                'new_authority_epoch'=>$next,'reservation_id'=>$reservationId,
                'enrollment_code'=>$enrollmentCode,'target_active'=>false,
                'target_remote_enabled'=>false,'target_guest_intake_enabled'=>false,
                'old_local_remote_fenced'=>true,'public_default_binding_changed'=>false,
                'source_owner_handoff_verified'=>true,'safe_to_cutover'=>false];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** Bind the R284 machine HMAC receipt to actual, separately checked
     * encrypted backup hashes, not browser declarations about being 'fenced'.
     */
    private function verifyOfflineReceipt(
        array $receipt,string $secret,string $oldId,string $businessSha,string $mediaSha
    ):void {
        if(strlen($secret)<32||!is_string($receipt['mac']??null))
            throw new RuntimeException('distinct_move_source_handoff_auth_missing');
        $mac=$receipt['mac'];unset($receipt['mac']);
        if(!preg_match('/^[a-f0-9]{64}$/D',$mac))
            throw new RuntimeException('distinct_move_source_handoff_invalid');
        $canonical=json_encode($receipt,JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|
            JSON_THROW_ON_ERROR)."\n";
        if(!hash_equals(hash_hmac('sha256',$canonical,$secret),$mac))
            throw new RuntimeException('distinct_move_source_handoff_bad_signature');
        if(($receipt['format']??'')!==self::HANDOFF_FORMAT ||
            ($receipt['source_installation_id']??'')!==$oldId ||
            ($receipt['business_backup_sha256']??'')!==$businessSha ||
            ($receipt['media_archive_sha256']??'')!==$mediaSha ||
            ($receipt['source_http_fenced']??null)!==true ||
            ($receipt['source_windows_workers_stopped']??null)!==true ||
            ($receipt['target_business_activated']??null)!==false)
            throw new RuntimeException('distinct_move_source_handoff_unverified');
    }

    private function codeFor(string $old,string $new,string $operation):string
    {
        $raw=strtoupper(substr(hash_hmac('sha256',
            implode("\n",['sokna-distinct-public-stage-v1',$this->publicInstanceId,
                $old,$new,$operation]),$this->serverKey),0,32));
        return implode('-',str_split($raw,8));
    }
}
