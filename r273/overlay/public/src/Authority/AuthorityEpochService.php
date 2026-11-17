<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Authority;

use PDO;
use Throwable;
use Sokna\PublicEdge\Emergency\EmergencyAccessService;
use Sokna\PublicEdge\Emergency\PairingSecretStore;
use Sokna\PublicEdge\Emergency\PublicUpdateException;

final class AuthorityEpochService
{
    public function __construct(private readonly PDO $pdo, private readonly PairingSecretStore $secrets, private readonly EmergencyAccessService $access) {}

    public function fenceRestore(string $installationId,int $epoch): array
    {
        $this->validId($installationId);$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT authority_epoch,authority_state,active,revoked_at FROM installations WHERE installation_id=? FOR UPDATE');$q->execute([$installationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||!(bool)$row['active']||!empty($row['revoked_at']))throw new PublicUpdateException('installation_not_found','Installation is not active.',404);
            $current=max(1,(int)($row['authority_epoch']??1));if($epoch!==$current)throw new PublicUpdateException('authority_epoch_mismatch','Authority epoch does not match.',409);
            $state=(string)($row['authority_state']??'active');
            if($state!=='active'&&$state!=='reenroll_pending')throw new PublicUpdateException('authority_state_conflict','Authority recovery is already in progress.',409);
            $this->pdo->prepare("UPDATE installations SET remote_enabled=0,order_intake_enabled=0,authority_state='reenroll_pending',authority_epoch_required=1,authority_changed_at=UTC_TIMESTAMP(6) WHERE installation_id=?")->execute([$installationId]);
            $this->pdo->commit();$this->access->audit('authority_restore_fenced',$installationId,'signed-local',['authority_epoch'=>$current]);
            return ['status'=>200,'body'=>['ok'=>true,'installation_id'=>$installationId,'authority_epoch'=>$current,'authority_state'=>'reenroll_pending','remote_enabled'=>false,'order_intake_enabled'=>false]];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function createOffer(string $installationId,int $ttlSeconds=1800,string $actor='emergency',int $minimumNextEpoch=0,bool $ownerMove=false): array
    {
        $this->validId($installationId);$ttl=max(300,min(86400,$ttlSeconds));
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT authority_epoch,active,revoked_at FROM installations WHERE installation_id=? FOR UPDATE');
            $q->execute([$installationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||!(bool)$row['active']||!empty($row['revoked_at']))throw new PublicUpdateException('installation_not_found','Installation is not active.',404);
            if ($ownerMove) {
                // Same serialized installation scope as the forward-only epoch offer.
                // Foreign or ambiguous installs must never be implicitly adopted.
                (new \Sokna\PublicEdge\Security\PublicMoveReplacementGate($this->pdo))
                    ->requireNoUnresolvedGuestWork($installationId);
            }
            if($ownerMove){
                // An owner grant cannot silently supersede an unconsumed
                // recovery code. The operator must resume/expire that attempt.
                $q=$this->pdo->prepare("SELECT id FROM installation_authority_reenrollments WHERE installation_id=? AND status='pending' AND expires_at>=UTC_TIMESTAMP() LIMIT 1 FOR UPDATE");
                $q->execute([$installationId]);
                if($q->fetchColumn()!==false)
                    throw new PublicUpdateException('owner_move_offer_pending','A previous transfer must be resolved first.',409);
            }
            $from=max(1,(int)($row['authority_epoch']??1));
            if ($from >= 2147483600 || ($minimumNextEpoch !== 0 && ($minimumNextEpoch <= $from || $minimumNextEpoch > $from + 16))) {
                throw new PublicUpdateException('authority_target_invalid','Requested authority epoch is outside the safe recovery window.',422);
            }
            $to=max($from+1,$minimumNextEpoch);$code=strtoupper(implode('-',str_split(bin2hex(random_bytes(12)),8)));
            $this->pdo->prepare("UPDATE installation_authority_reenrollments SET status='cancelled' WHERE installation_id=? AND status='pending'")->execute([$installationId]);
            $this->pdo->prepare("INSERT INTO installation_authority_reenrollments(installation_id,from_epoch,to_epoch,code_hash,status,expires_at) VALUES(?,?,?,?, 'pending',DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))")->execute([$installationId,$from,$to,password_hash($code,PASSWORD_DEFAULT),$ttl]);
            $this->pdo->prepare("UPDATE installations SET remote_enabled=0,order_intake_enabled=0,authority_state='reenroll_pending',authority_epoch_required=1,authority_changed_at=UTC_TIMESTAMP(6) WHERE installation_id=?")->execute([$installationId]);
            $this->pdo->commit();
            $this->access->audit('authority_offer',$installationId,$actor,['from_epoch'=>$from,'to_epoch'=>$to,'ttl_seconds'=>$ttl]);
            return ['ok'=>true,'installation_id'=>$installationId,'from_epoch'=>$from,'authority_epoch'=>$to,'enrollment_code'=>$code,'expires_in_seconds'=>$ttl];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function complete(string $installationId,string $code,string $newSecret,string $displayName=''): array
    {
        $this->validId($installationId);if(trim($code)===''||strlen($newSecret)<32)throw new PublicUpdateException('reenroll_invalid','Enrollment credentials are invalid.',401);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare("SELECT * FROM installation_authority_reenrollments WHERE installation_id=? AND status='pending' AND expires_at>=UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $q->execute([$installationId]);$offer=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($offer)||!password_verify($code,(string)$offer['code_hash']))throw new PublicUpdateException('reenroll_denied','Enrollment code is invalid or expired.',401);
            $q=$this->pdo->prepare('SELECT authority_epoch,active,revoked_at FROM installations WHERE installation_id=? FOR UPDATE');$q->execute([$installationId]);$install=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($install)||!(bool)$install['active']||!empty($install['revoked_at']))throw new PublicUpdateException('installation_not_found','Installation is not active.',404);
            $current=max(1,(int)($install['authority_epoch']??1));$from=(int)$offer['from_epoch'];$to=(int)$offer['to_epoch'];
            if($current!==$from||$to<=$from||$to>$from+16)throw new PublicUpdateException('authority_epoch_conflict','Authority generation changed; create a new recovery offer.',409);
            $this->secrets->put($installationId,$newSecret);
            $name=trim($displayName);
            $sql="UPDATE installations SET authority_epoch=?,authority_state='resync_required',authority_epoch_required=1,remote_enabled=0,order_intake_enabled=0,authority_changed_at=UTC_TIMESTAMP(6)".($name!==''?',display_name=?':'')." WHERE installation_id=?";
            $params=$name!==''?[$to,$name,$installationId]:[$to,$installationId];$this->pdo->prepare($sql)->execute($params);
            $this->pdo->prepare("UPDATE realtime_requests SET state='unknown_review',error_code='authority_epoch_superseded',lease_token_hash=NULL,lease_expires_at=NULL WHERE installation_id=? AND authority_epoch<>? AND state IN ('queued','claimed')")->execute([$installationId,$to]);
            $this->pdo->prepare("UPDATE deferred_work SET state='needs_review',error_code='authority_epoch_superseded',lease_token=NULL,lease_expires_at=NULL WHERE installation_id=? AND authority_epoch<>? AND state='pending_sync'")->execute([$installationId,$to]);
            foreach(['public_sessions','auth_projections','guest_active_revisions','guest_availability_state','remote_read_models','remote_projection_events','remote_projection_model_cursors','remote_projection_cursors','installation_heartbeats'] as $table){
                $this->pdo->prepare('DELETE FROM `'.$table.'` WHERE installation_id=?')->execute([$installationId]);
            }
            $this->pdo->prepare("UPDATE installation_authority_reenrollments SET status='completed',completed_at=UTC_TIMESTAMP() WHERE id=?")->execute([(int)$offer['id']]);
            $this->pdo->commit();
            $this->access->audit('authority_reenrolled',$installationId,'reenroll',['from_epoch'=>$from,'to_epoch'=>$to]);
            return ['ok'=>true,'installation_id'=>$installationId,'authority_epoch'=>$to,'authority_state'=>'resync_required','remote_enabled'=>false,'order_intake_enabled'=>false];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function activate(string $installationId,int $epoch): array
    {
        $this->validId($installationId);$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT authority_epoch,authority_state FROM installations WHERE installation_id=? FOR UPDATE');$q->execute([$installationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new PublicUpdateException('installation_not_found','Installation is not active.',404);
            if((int)$row['authority_epoch']!==$epoch)throw new PublicUpdateException('authority_epoch_mismatch','Authority epoch does not match.',409);
            if((string)$row['authority_state']==='active'){
                // A lost HTTP acknowledgement of successful activation must
                // never strand a journaled cutover in retry mode.
                $this->pdo->commit();
                return ['status'=>200,'body'=>['ok'=>true,'installation_id'=>$installationId,
                    'authority_epoch'=>$epoch,'authority_state'=>'active','already_active'=>true]];
            }
            if((string)$row['authority_state']!=='resync_required')throw new PublicUpdateException('authority_activation_not_ready','Authority is not awaiting activation.',409);
            $hb=$this->pdo->prepare('SELECT COUNT(*) FROM installation_heartbeats WHERE installation_id=? AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 SECOND)');$hb->execute([$installationId]);
            $models=$this->pdo->prepare('SELECT COUNT(*) FROM remote_read_models WHERE installation_id=?');$models->execute([$installationId]);
            if((int)$hb->fetchColumn()<1||(int)$models->fetchColumn()<1)throw new PublicUpdateException('authority_resync_incomplete','Fresh heartbeat and read-model snapshot are required before activation.',409);
            $this->pdo->prepare("UPDATE installations SET authority_state='active',remote_enabled=1,order_intake_enabled=1,authority_changed_at=UTC_TIMESTAMP(6) WHERE installation_id=?")->execute([$installationId]);
            $this->pdo->commit();$this->access->audit('authority_activated',$installationId,'signed-local',['authority_epoch'=>$epoch]);
            return ['status'=>200,'body'=>['ok'=>true,'installation_id'=>$installationId,'authority_epoch'=>$epoch,'authority_state'=>'active']];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** Authenticated, read-only pairing proof for durable Local recovery. */
    public function status(string $installationId): array
    {
        $this->validId($installationId);
        $q=$this->pdo->prepare('SELECT installation_id,authority_epoch,authority_state,remote_enabled,order_intake_enabled FROM installations WHERE installation_id=? LIMIT 1');
        $q->execute([$installationId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new PublicUpdateException('installation_not_found','Installation not found.',404);
        return ['ok'=>true,'installation_id'=>(string)$row['installation_id'],
            'authority_epoch'=>(int)$row['authority_epoch'],
            'authority_state'=>(string)$row['authority_state'],
            'remote_enabled'=>(bool)$row['remote_enabled'],
            'order_intake_enabled'=>(bool)$row['order_intake_enabled']];
    }

    public function currentEpoch(string $installationId): int
    {
        $q=$this->pdo->prepare('SELECT authority_epoch FROM installations WHERE installation_id=? LIMIT 1');$q->execute([$installationId]);return max(1,(int)($q->fetchColumn()?:1));
    }

    private function validId(string $id): void{if(preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',trim($id))!==1)throw new PublicUpdateException('invalid_installation','Installation identity is invalid.');}
}
