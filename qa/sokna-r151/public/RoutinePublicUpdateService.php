<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Update;

use Sokna\PublicEdge\Emergency\PublicUpdateException;
use Throwable;
use ZipArchive;

/**
 * Routine Local-driven Public Edge updater.
 *
 * Emergency v1 remains stable/break-glass. This service owns routine delta
 * delivery only and deliberately lives outside src/Emergency so a legacy
 * full-snapshot bridge can install/replace it without mutating the stable
 * emergency engine.
 */
final class RoutinePublicUpdateService
{
    private const FORMAT='sokna-public-delta-v2';
    private const STATE_FORMAT='sokna-public-routine-update-state-v2';
    private const MAX_FILES=12000;
    private const MAX_EXPANDED=536870912;
    private const MAX_PACKAGE=67108864;
    private const MAX_CHUNK=524288;
    private const STABLE_PREFIXES=['emergency.php','src/Emergency/','resources/update-trust-v1.json'];
    private const RECOVERY_OWNER_PREFIXES=['recovery.php','src/Recovery/'];
    /** @var null|callable */ private $health;
    /** @var null|callable */ private $migrate;
    /** @var null|callable */ private $migrationPending;
    /** @var null|callable Source-only hook; untrusted until independent stable owner is provisioned. */ private $ownerHook;

    public function __construct(
        private readonly string $componentRoot,
        private readonly string $storageRoot,
        private readonly string $trustFile,
        ?callable $health=null,
        ?callable $migrate=null,
        ?callable $ownerHook=null,
        ?callable $migrationPending=null,
    ){$this->health=$health;$this->migrate=$migrate;$this->ownerHook=$ownerHook;$this->migrationPending=$migrationPending;}

    /** Advertised only by Public owners that strictly enforce the multi-baseline contract. */
    public function supportsStrictMultiBaselineV1(): bool { return true; }

    public function snapshot(): array
    {
        $s=$this->state();$managed=$this->managedTreeDigest();$expected=trim((string)($s['active_managed_tree_sha256']??''));
        $diskVersion=$this->currentVersion();
        $emergencyState=$this->readJson(rtrim($this->storageRoot,"\\/").'/emergency/update/state.json');
        $emergencyVersion=trim((string)($emergencyState['active_version']??''));
        $externalPending=$emergencyVersion!==''&&hash_equals($emergencyVersion,$diskVersion)
            &&(!hash_equals((string)($s['active_version']??''),$diskVersion)||$expected===''||!hash_equals($expected,$managed));
        return [
            'protocol'=>2,
            'format'=>self::FORMAT,
            'current_version'=>$this->currentVersion(),
            'managed_tree_sha256'=>$managed,
            'expected_managed_tree_sha256'=>$expected,
            'managed_tree_matches_expected'=>$expected===''?null:hash_equals($expected,$managed),
            'active_version'=>(string)($s['active_version']??$this->currentVersion()),
            'previous_version'=>(string)($s['previous_version']??''),
            'lkg_recovery_id'=>(string)($s['lkg_recovery_id']??''),
            'staged'=>$s['staged']??null,
            'pending_verification'=>$s['pending_verification']??null,
            'activation_journal'=>$s['activation_journal']??null,
            'db_forward_fence'=>$s['db_forward_fence']??null,
            'interrupted_activation'=>is_array($s['activation_journal']??null)&&!is_array($s['pending_verification']??null),
            'history'=>array_slice(array_reverse((array)($s['history']??[])),0,30),
            'upload'=>['max_package_bytes'=>self::MAX_PACKAGE,'max_chunk_bytes'=>self::MAX_CHUNK,'resumable'=>true],
            'lifecycle'=>['version'=>3,'recovery'=>'differential-preimage','stage_identity'=>'version+package_sha256','bounded_io_retries'=>true,'stable_recovery_owner'=>'/recovery.php'],
            'unified_package_format'=>'sokna-component-package-v1',
            'unified_full_v1_supported'=>true,
            'emergency_state_reconciliation_pending'=>$externalPending,
            'emergency_active_version'=>$emergencyVersion,
            'signature_policy'=>'not_required_for_operator_upload',
        ];
    }

    /** @return array{upload_id:string,received_bytes:int,size_bytes:int,complete:bool} */
    public function beginUpload(string $packageSha256,int $sizeBytes,string $actor='local'): array
    {
        $sha=strtolower(trim($packageSha256));
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha))throw new PublicUpdateException('invalid_package_hash','Package SHA256 is invalid.',422);
        if($sizeBytes<100||$sizeBytes>self::MAX_PACKAGE)throw new PublicUpdateException('package_too_large','Package size is invalid or exceeds the routine update limit.',413);
        $this->cleanupUploads();
        $id=$sha;
        $metaPath=$this->uploadMeta($id);$part=$this->uploadPart($id);
        $meta=$this->readJson($metaPath);
        if($meta!==[]&&((string)($meta['sha256']??'')!==$sha||(int)($meta['size_bytes']??0)!==$sizeBytes)){
            @unlink($part);@unlink($metaPath);$meta=[];
        }
        if($meta===[]){
            $this->mkdir(dirname($part));
            if(file_put_contents($part,'',LOCK_EX)===false)throw new PublicUpdateException('upload_storage_failed','Update upload storage is unavailable.',500);
            @chmod($part,0600);
            $meta=['format'=>'sokna-public-upload-v2','upload_id'=>$id,'sha256'=>$sha,'size_bytes'=>$sizeBytes,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'actor_hint'=>substr($actor,0,120)];
            $this->writeJson($metaPath,$meta);
        }
        clearstatcache(true,$part);
        $received=is_file($part)?(int)filesize($part):0;
        if($received>$sizeBytes){@unlink($part);@unlink($metaPath);throw new PublicUpdateException('upload_state_invalid','Stored upload exceeds declared size.',409);}
        return ['upload_id'=>$id,'received_bytes'=>$received,'size_bytes'=>$sizeBytes,'complete'=>$received===$sizeBytes];
    }

    /** @return array{upload_id:string,received_bytes:int,size_bytes:int,complete:bool} */
    public function appendUploadChunk(string $uploadId,int $offset,string $chunkBase64,string $chunkSha256): array
    {
        $id=$this->safeUploadId($uploadId);$meta=$this->readJson($this->uploadMeta($id));$part=$this->uploadPart($id);
        if(($meta['format']??'')!=='sokna-public-upload-v2'||!is_file($part))throw new PublicUpdateException('upload_missing','Routine update upload was not started.',404);
        $size=(int)($meta['size_bytes']??0);$bytes=base64_decode($chunkBase64,true);
        if(!is_string($bytes)||$bytes==='')throw new PublicUpdateException('invalid_chunk','Upload chunk is invalid.',422);
        $len=strlen($bytes);if($len>self::MAX_CHUNK)throw new PublicUpdateException('chunk_too_large','Upload chunk exceeds the allowed size.',413);
        $want=strtolower(trim($chunkSha256));if(!preg_match('/^[a-f0-9]{64}$/D',$want)||!hash_equals($want,hash('sha256',$bytes)))throw new PublicUpdateException('chunk_hash_mismatch','Upload chunk hash does not match.',422);
        if($offset<0||$offset+$len>$size)throw new PublicUpdateException('chunk_range_invalid','Upload chunk range is invalid.',422);
        clearstatcache(true,$part);
        $current=(int)filesize($part);
        if($offset<$current){
            // Idempotent retry after a lost response: accept only if the exact
            // bytes already stored at this offset match this chunk.
            if($offset+$len>$current)throw new PublicUpdateException('upload_offset_mismatch','Upload must resume at the server offset.',409,['expected_offset'=>$current]);
            $fh=fopen($part,'rb');if(!is_resource($fh))throw new PublicUpdateException('upload_read_failed','Stored upload cannot be read.',500);
            fseek($fh,$offset);$existing=(string)fread($fh,$len);fclose($fh);
            if(!hash_equals($want,hash('sha256',$existing)))throw new PublicUpdateException('upload_offset_conflict','Retried chunk differs from stored bytes.',409,['expected_offset'=>$current]);
            return ['upload_id'=>$id,'received_bytes'=>$current,'size_bytes'=>$size,'complete'=>$current===$size];
        }
        if($offset!==$current)throw new PublicUpdateException('upload_offset_mismatch','Upload must resume at the server offset.',409,['expected_offset'=>$current]);
        $fh=fopen($part,'ab');if(!is_resource($fh))throw new PublicUpdateException('upload_write_failed','Stored upload cannot be extended.',500);
        $next=$offset+$len;
        try{
            if(flock($fh,LOCK_EX)!==true)throw new PublicUpdateException('upload_write_failed','Upload chunk could not be stored.',500);
            $written=fwrite($fh,$bytes);if($written!==$len||fflush($fh)!==true)throw new PublicUpdateException('upload_write_failed','Upload chunk could not be stored.',500);
            $stat=fstat($fh);$stored=is_array($stat)?(int)($stat['size']??-1):-1;
            if($stored!==$next)throw new PublicUpdateException('upload_write_size_mismatch','Stored upload size did not advance as expected.',500,['expected_offset'=>$next,'stored_size'=>$stored]);
        }finally{@flock($fh,LOCK_UN);fclose($fh);}
        clearstatcache(true,$part);
        $meta['updated_at']=gmdate('c');$this->writeJson($this->uploadMeta($id),$meta);
        return ['upload_id'=>$id,'received_bytes'=>$next,'size_bytes'=>$size,'complete'=>$next===$size];
    }

    public function finishUpload(string $uploadId,string $actor='local',?bool $requireStrictMultiBaseline=null): array
    {
        $id=$this->safeUploadId($uploadId);$meta=$this->readJson($this->uploadMeta($id));$part=$this->uploadPart($id);
        if(($meta['format']??'')!=='sokna-public-upload-v2'||!is_file($part))throw new PublicUpdateException('upload_missing','Routine update upload is missing.',404);
        $size=(int)($meta['size_bytes']??0);clearstatcache(true,$part);$received=(int)filesize($part);if($received!==$size)throw new PublicUpdateException('upload_incomplete','Routine update upload is incomplete.',409,['received_bytes'=>$received,'size_bytes'=>$size]);
        $want=(string)($meta['sha256']??'');$got=hash_file('sha256',$part);if(!is_string($got)||!hash_equals($want,$got))throw new PublicUpdateException('package_hash_mismatch','Uploaded package SHA256 does not match.',422);
        try{$staged=$this->stageUploadedDeltaZip($part,$actor,$requireStrictMultiBaseline);}finally{@unlink($part);@unlink($this->uploadMeta($id));}
        return $staged;
    }

    public function stageUploadedDeltaZip(string $zipPath,string $actor='local',?bool $requireStrictMultiBaseline=null): array
    {
        $meta=$this->verifyZip($zipPath);$m=(array)$meta['manifest'];$version=(string)$m['version'];
        $isStrict=(bool)(($m['provenance']??[])['strict_multibaseline_v1']??false);
        // An authenticated old v2 transport is still not the universal-v3 protocol.
        // Reject BEFORE publishing a stage, even when source/tree and signature match.
        if($requireStrictMultiBaseline!==null && $requireStrictMultiBaseline!==$isStrict)
            throw new PublicUpdateException('public_update_protocol_mismatch',
                'This Public update must use its declared routine transport protocol.',409);
        $this->assertBaseline($m);
        $hash=hash_file('sha256',$zipPath);$target=$this->stageDir($version,$hash);
        if(!is_dir($target)){
            $tmp=$target.'.staging-'.bin2hex(random_bytes(4));$this->removeTree($tmp);$this->mkdir($tmp.'/payload');
            $this->extractPayload($zipPath,$tmp.'/payload',(array)($m['files']??[]));
            $this->copyFileWithRetry($zipPath,$tmp.'/package.zip','stage_package','package.zip',0600);
            $this->writeJson($tmp.'/manifest.json',$m);$this->writeJson($tmp.'/package.json',['package_sha256'=>$hash,'signature_status'=>$meta['signature_status'],'staged_at'=>gmdate('c')]);
            $this->verifyDirectory($tmp.'/payload',(array)($m['files']??[]));
            $this->renameWithRetry($tmp,$target,'stage_publish',$version);
        }else{
            $old=$this->readJson($target.'/package.json');
            if(($old['package_sha256']??'')!==$hash)throw new PublicUpdateException('staged_hash_identity_mismatch','Hash-keyed routine stage identity is inconsistent.',500,['version'=>$version,'package_sha256'=>$hash]);
        }
        $s=$this->state();$s['staged']=['version'=>$version,'base_version'=>(string)(($m['base']??[])['version']??''),'base_tree_sha256'=>(string)(($m['base']??[])['managed_tree_sha256']??''),'target_tree_sha256'=>(string)(($m['target']??[])['managed_tree_sha256']??''),'package_sha256'=>$hash,'stage_key'=>$version.'/'.$hash,'signature_status'=>$meta['signature_status'],
            'strict_multibaseline_v1'=>(bool)(($m['provenance']??[])['strict_multibaseline_v1']??false),
            'staged_at'=>gmdate('c')];
        $this->event($s,'stage',$version,$actor,['package_sha256'=>$hash]);$this->save($s);return $s['staged'];
    }

    /** Apply staged delta while keeping the public maintenance gate closed. */
    public function activateStaged(string $actor='local'): array
    {
        return $this->withSharedLifecycleLock(function() use ($actor) {

        $s=$this->state();if(is_array($s['pending_verification']??null))throw new PublicUpdateException('verification_pending','A Public update is awaiting Local verification.',409);
        $journal=(array)($s['activation_journal']??[]);
        if($journal!==[]){
            $phase=(string)($journal['phase']??'');
            $rid=(string)($journal['recovery_id']??'');
            // A crash during recovery preparation cannot have mutated the live
            // component tree. Clear that stale pre-mutation journal and retry.
            if($phase==='recovery_prepare'&&$rid===''){$s['activation_journal']=null;$this->save($s);}
            else throw new PublicUpdateException('activation_recovery_required','An interrupted Public activation must be rolled back before another activation.',409,['journal'=>$journal]);
        }
        $s=$this->state();$staged=(array)($s['staged']??[]);$version=(string)($staged['version']??'');if($version==='')throw new PublicUpdateException('nothing_staged','No routine Public delta is staged.',409);
        if($version===$this->currentVersion())throw new PublicUpdateException('same_version_not_delta_upgrade','Routine delta activation requires a new target version.',409);
        $hash=(string)($staged['package_sha256']??'');$dir=$this->resolveStageDir($version,$hash);$m=$this->readJson($dir.'/manifest.json');$this->assertBaseline($m);$this->verifyDirectory($dir.'/payload',(array)($m['files']??[]));
        $old=$this->currentVersion();$targetTree=(string)(($m['target']??[])['managed_tree_sha256']??'');$tx=gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));
        $this->ownerEmit('begin',$tx,$version,'activate');
        $s=$this->state();$s['activation_journal']=['transaction_id'=>$tx,'version'=>$version,'previous_version'=>$old,'target_tree_sha256'=>$targetTree,'recovery_id'=>'','phase'=>'recovery_prepare','started_at'=>gmdate('c')];$this->save($s);
        try{
            $recovery=$this->createRecoveryPoint('before_activate_'.$version,$actor,(array)($m['files']??[]),(array)($m['deletes']??[]),$tx);
        }catch(Throwable $e){
            $s=$this->state();$s['activation_journal']=null;$this->event($s,'recovery_prepare_failed',$version,$actor,['transaction_id'=>$tx,'error_code'=>$e instanceof PublicUpdateException?$e->errorCode:'exception']);$this->save($s);
            if($e instanceof PublicUpdateException)throw $e;throw new PublicUpdateException('recovery_preflight_failed','Routine recovery preparation failed before any live mutation.',500,['transaction_id'=>$tx]);
        }
        $s=$this->state();$s['activation_journal']['recovery_id']=(string)$recovery['id'];$s['activation_journal']['phase']='apply';$s['activation_journal']['recovery_ready_at']=gmdate('c');$this->save($s);
        $this->ownerEmit('switching',$tx,$version,'activate');
        $this->beginMaintenance($version,$actor);
        try{
            $this->applyDelta($dir.'/payload',(array)($m['files']??[]),(array)($m['deletes']??[]),$tx);
            $s=$this->state();$s['activation_journal']['phase']='health';$this->save($s);
            $actual=$this->managedTreeDigest();if(!hash_equals($targetTree,$actual))throw new PublicUpdateException('target_tree_mismatch','Activated tree does not match the target manifest.',500,['expected'=>$targetTree,'actual'=>$actual,'transaction_id'=>$tx]);
            $this->healthCheck();
        }catch(Throwable $e){
            try{
                $this->restoreRecoveryPoint((string)$recovery['id']);
            }catch(Throwable $rollbackError){
                // Do not pretend recovery succeeded and do not clear the
                // maintenance gate. A stable rescue path must be able to see
                // this exact journal and retry the same recovery point.
                $s=$this->state();$s['activation_journal']['phase']='rollback_failed';$s['activation_journal']['activation_error']=$e instanceof PublicUpdateException?$e->errorCode:'exception';$s['activation_journal']['rollback_error']=$rollbackError instanceof PublicUpdateException?$rollbackError->errorCode:'exception';$s['activation_journal']['rollback_failed_at']=gmdate('c');$this->save($s);
                throw new PublicUpdateException('activation_rollback_failed','Routine Public activation failed and automatic rollback also failed; maintenance remains locked for recovery.',500,['transaction_id'=>$tx,'recovery_id'=>(string)$recovery['id'],'activation_error'=>$e instanceof PublicUpdateException?$e->errorCode:'exception','rollback_error'=>$rollbackError instanceof PublicUpdateException?$rollbackError->errorCode:'exception']);
            }
            $s=$this->state();$s['activation_journal']=null;$s['pending_verification']=null;$this->event($s,'rollback_auto',$version,$actor,['transaction_id'=>$tx,'error_code'=>$e instanceof PublicUpdateException?$e->errorCode:'exception']);$this->save($s);
            if($e instanceof PublicUpdateException)throw $e;throw new PublicUpdateException('activation_failed','Routine Public activation failed and was rolled back.',500,['transaction_id'=>$tx]);
        }
        $s=$this->state();$s['pending_verification']=['transaction_id'=>$tx,'version'=>$version,'previous_version'=>$old,'target_tree_sha256'=>$targetTree,'recovery_id'=>(string)$recovery['id'],
            'strict_multibaseline_v1'=>(bool)($staged['strict_multibaseline_v1']??false),
            'activated_at'=>gmdate('c')];$s['activation_journal']['phase']='pending_external_verification';$s['activation_journal']['applied_at']=gmdate('c');
        $this->event($s,'activate_pending_verification',$version,$actor,['transaction_id'=>$tx,'previous'=>$old,'recovery_id'=>$recovery['id']]);$this->save($s);
        $this->ownerEmit('pending_external_verification',$tx,$version,'activate');
        return $this->snapshot();
    
        });
    }

    /** Local calls this only after a fresh remote /health verifies target runtime. */
    public function commitActivation(string $actor='local'): array
    {
        return $this->withSharedLifecycleLock(function() use ($actor) {

        $this->ownerFinalCommitGate();
        $s=$this->state();$p=(array)($s['pending_verification']??[]);$version=(string)($p['version']??'');if($version==='')throw new PublicUpdateException('nothing_pending','No routine Public activation is awaiting verification.',409);
        $tree=(string)($p['target_tree_sha256']??'');if($version!==$this->currentVersion()||$tree===''||!hash_equals($tree,$this->managedTreeDigest()))throw new PublicUpdateException('post_activate_identity_mismatch','Public target identity changed before commit.',409);
        // The target code is now active, so this request is the first safe point at which
        // the target migration catalog can be executed. Keep maintenance + recovery identity
        // until migrations and internal health both succeed. Routine upgrades previously never
        // ran migrations, which allowed source/schema drift (e.g. 35bn shipped migration 0004
        // while the live database still reported only 3 applied migrations).
        // Before the first potentially irreversible MariaDB DDL, persist a
        // fail-closed forward-only fence. It survives restart and activation
        // retries; no previous-code recovery can cross this schema boundary.
        $pending=[];
        if (is_callable($this->migrate)) {
            if (!is_callable($this->migrationPending)) {
                throw new PublicUpdateException('migration_preflight_unavailable', 'Public migration inventory is required before committing an update.', 503);
            }
            $pending = ($this->migrationPending)();
            if (is_array($s['db_forward_fence']??null)) {
                // A prior irreversible DB boundary must never be rolled back, erased,
                // or replayed by this later code-only update. A verified migration
                // inventory showing *no pending DDL* permits forward-only commit;
                // the old fence remains authoritative for all future rollbacks.
                if(!is_array($pending)||!array_is_list($pending)||$pending!==[])
                    throw new PublicUpdateException('db_forward_recovery_required','A DB-forward fence exists and migration inventory is not clean; forward recovery is required.',409);
            }
            if (!is_array($pending)) {
                throw new PublicUpdateException('migration_preflight_invalid', 'Public migration inventory returned an invalid result.', 503);
            }
            $pending = array_values(array_map('strval', $pending));
            if ($pending !== []) {
                $s = $this->state();
                if (!is_array($s['db_forward_fence'] ?? null)) {
                    $s['db_forward_fence'] = [
                        'transaction_id' => (string)($p['transaction_id'] ?? ''),
                        'schema_owner_version' => $version,
                        'pending_at_boundary' => $pending,
                        'started_at' => gmdate('c'),
                        'policy' => 'forward_recovery_only',
                    ];
                }
                if (is_array($s['activation_journal'] ?? null)) {
                    $s['activation_journal']['phase'] = 'db_forward_started';
                }
                $this->save($s); // MUST complete before executing the first DDL
            }
        }
        $migrations=[];
        try{
            if(is_callable($this->migrate)&&$pending!==[]){
                $result=($this->migrate)();$migrations=is_array($result)?array_values(array_map('strval',$result)):[];
                if(($this->migrationPending)()!==[])
                    throw new PublicUpdateException('migration_incomplete','Public migration plan was not fully applied.',409);
            }
            $this->healthCheck();
        }catch(Throwable $e){
            $s=$this->state();
            if(is_array($s['activation_journal']??null)){
                $s['activation_journal']['phase']='migration_failed';
                $s['activation_journal']['migration_error']=$e instanceof PublicUpdateException?$e->errorCode:get_class($e);
                $s['activation_journal']['migration_failed_at']=gmdate('c');
            }
            $this->save($s);
            if($e instanceof PublicUpdateException)throw $e;
            throw new PublicUpdateException('migration_failed','Public database migration failed; use forward recovery, not old-code rollback.',500,['version'=>$version,'exception'=>get_class($e)]);
        }

        // Release maintenance only after target schema + internal health are proven. If lock
        // removal is transiently blocked, pending_verification stays persisted and commit is
        // safely retryable without losing recovery identity.
        $s=$this->state();
        $committedFence=$s['db_forward_fence']??null;
        $didMigrate=is_array($committedFence)&&hash_equals((string)($p['transaction_id']??''),(string)($committedFence['transaction_id']??''));
        $retainedPriorFence=is_array($committedFence)&&!$didMigrate;
        // Re-evaluate the migration plan after target health and before state commit.
        // In particular, never treat unavailable/changed DB evidence as safe.
        if($retainedPriorFence) {
            if(!is_callable($this->migrate)||!is_callable($this->migrationPending))
                throw new PublicUpdateException('migration_preflight_unavailable','A prior DB-forward fence requires real migration inventory.',503);
            $verifiedPending=($this->migrationPending)();
            if(!is_array($verifiedPending)||!array_is_list($verifiedPending)||$verifiedPending!==[])
                throw new PublicUpdateException('db_forward_recovery_required','Prior DB-forward fence remains and database verification is not clean.',409);
        }
        $s['previous_version']=(string)($p['previous_version']??'');$s['active_version']=$version;$s['active_managed_tree_sha256']=$tree;
        // Verified target and DB state: clear maintenance only now.
        $this->clearMaintenance();
        // Any DB-forward fence forbids restoring an older code recovery point.
        $s['lkg_recovery_id']=($didMigrate||$retainedPriorFence)?'':(string)($p['recovery_id']??'');
        if($didMigrate){
            $s['last_db_forward_commit']=['transaction_id'=>(string)$p['transaction_id'],'version'=>$version,'at'=>gmdate('c')];
            $s['db_forward_fence']=null;
        }
        $s['pending_verification']=null;$s['activation_journal']=null;$s['staged']=null;
        $this->event($s,'activate_commit',$version,$actor,['recovery_id'=>$s['lkg_recovery_id'],'migrations'=>$migrations,'prior_db_forward_fence_retained'=>$retainedPriorFence]);$this->save($s);$this->pruneLifecycle((string)$s['lkg_recovery_id']);return $this->snapshot();
    
        });
    }

    public function rollback(string $actor='local'): array
    {
        return $this->withSharedLifecycleLock(function() use ($actor) {

        $this->ownerRecoveryGate();
        $s = $this->state();
        if (is_array($s['db_forward_fence'] ?? null)) {
            throw new PublicUpdateException('db_forward_rollback_forbidden', 'Database schema may have advanced; recovering older application code is forbidden.', 409, ['fence' => $s['db_forward_fence']]);
        }
        $s=$this->state();$pending=(array)($s['pending_verification']??[]);$journal=(array)($s['activation_journal']??[]);$id=(string)($pending['recovery_id']??$journal['recovery_id']??$s['lkg_recovery_id']??'');if($id==='')throw new PublicUpdateException('no_recovery_point','No routine LKG recovery point exists.',409);
        $from=$this->currentVersion();$m=$this->restoreRecoveryPoint($id);$to=(string)($m['version']??'unknown');$s=$this->state();$s['active_version']=$to;$s['active_managed_tree_sha256']=(string)($m['managed_tree_sha256']??'');$s['previous_version']=$from;$s['pending_verification']=null;$s['activation_journal']=null;$s['staged']=null;$this->event($s,'rollback',$to,$actor,['from'=>$from,'recovery_id'=>$id]);$this->save($s);$this->clearMaintenance();$this->pruneLifecycle($id);return $this->snapshot();
    
        });
    }

    /**
     * Reconcile Routine state after a successful stable Emergency full-snapshot activation.
     *
     * Emergency and Routine deliberately have separate lifecycle stores. A stable Emergency
     * activation can therefore replace the mutable Public tree without mutating Routine state.
     * Reconciliation is allowed only when there is no in-progress Routine activation and the
     * stable Emergency state explicitly records the same version that is present on disk.
     * Pending DB migrations are applied before Routine state is advanced.
     */
    public function reconcileExternalEmergencyActivation(string $actor='local'): array
    {
        return $this->withSharedLifecycleLock(function() use ($actor) {

        $this->ownerFinalCommitGate();
        // Emergency and Routine are separate lifecycle owners. A full-snapshot Emergency activation
        // can replace application bytes without advancing Routine v2 state, so reconcile that
        // split state exactly once under an OS lock before Routine status is exposed again.
        $lockPath=$this->root().'/external-emergency-reconcile.lock';$this->mkdir(dirname($lockPath));
        $lock=@fopen($lockPath,'c+');if(!is_resource($lock))throw new PublicUpdateException('external_reconcile_lock_failed','Public update reconciliation lock is unavailable.',500);
        try{
            if(!@flock($lock,LOCK_EX))throw new PublicUpdateException('external_reconcile_lock_failed','Public update reconciliation lock could not be acquired.',500);
            $diskVersion=$this->currentVersion();$diskTree=$this->managedTreeDigest();$s=$this->state();
            $active=trim((string)($s['active_version']??''));$expected=trim((string)($s['active_managed_tree_sha256']??''));
            if($active===$diskVersion&&$expected!==''&&hash_equals($expected,$diskTree))return ['reconciled'=>false,'reason'=>'already_aligned','version'=>$diskVersion,'managed_tree_sha256'=>$diskTree,'migrations'=>[]];
            if(is_array($s['pending_verification']??null)||is_array($s['activation_journal']??null))return ['reconciled'=>false,'reason'=>'routine_activation_in_progress','version'=>$diskVersion,'managed_tree_sha256'=>$diskTree,'migrations'=>[]];
            $emergency=$this->readJson(rtrim($this->storageRoot,"\\/").'/emergency/update/state.json');$emergencyActive=trim((string)($emergency['active_version']??''));
            if($diskVersion===''||$diskVersion==='unknown'||$emergencyActive===''||!hash_equals($diskVersion,$emergencyActive))return ['reconciled'=>false,'reason'=>'no_matching_emergency_activation','version'=>$diskVersion,'managed_tree_sha256'=>$diskTree,'migrations'=>[]];
            // The old reconcile flow ran target migrations without setting the DB-forward
            // fence. Persist the same fence used by Routine commit BEFORE any DDL.
            // Never replay uncertain migrations under an existing forward fence.
            if(is_array($s['db_forward_fence']??null))
                throw new PublicUpdateException('db_forward_recovery_required','Public DB-forward state requires verified forward recovery, not automatic reconciliation.',409);
            $pending=[];
            if(is_callable($this->migrate)){
                if(!is_callable($this->migrationPending))
                    throw new PublicUpdateException('migration_preflight_unavailable','Migration inventory is required before Emergency reconciliation.',503);
                try{$pending=($this->migrationPending)();}
                catch(Throwable $e){throw new PublicUpdateException('migration_preflight_failed','Cannot establish the current Public migration plan.',503);}
                if(!is_array($pending)||!array_is_list($pending))
                    throw new PublicUpdateException('migration_preflight_invalid','Public migration inventory is invalid.',503);
                foreach($pending as $migration)if(!is_string($migration)||$migration==='')
                    throw new PublicUpdateException('migration_preflight_invalid','Public migration inventory contains an invalid ID.',503);
            }
            $migrations=[];
            if($pending!==[]){
                $s['db_forward_fence']=[
                    'transaction_id'=>'external_emergency_'.$diskVersion,
                    'schema_owner_version'=>$diskVersion,
                    'pending_at_boundary'=>$pending,
                    'started_at'=>gmdate('c'),
                    'policy'=>'forward_recovery_only',
                ];
                $this->save($s); // Must be durable before target migration code starts.
            }
            try{
                if($pending!==[]) {
                    $migrations=(array)call_user_func($this->migrate);
                    // Incomplete migration application must keep the forward-only fence.
                    if(($this->migrationPending)()!==[])
                        throw new PublicUpdateException('external_reconcile_migrations_incomplete','Public migrations are not fully applied.',409);
                }
                if(is_callable($this->health)&&!(bool)call_user_func($this->health))
                    throw new PublicUpdateException('external_reconcile_health_failed','Public health check failed after Emergency reconciliation.',500,['version'=>$diskVersion]);
            }
            catch(PublicUpdateException $e){throw $e;}
            catch(Throwable $e){throw new PublicUpdateException('external_reconcile_migration_failed','Public database migration failed while reconciling Emergency activation.',500,['version'=>$diskVersion,'exception'=>get_class($e)]);}
            // Re-read while still holding the lock so a lifecycle action that arrived during a slow
            // migration cannot be overwritten by the reconciliation state write.
            $latest=$this->state();if(is_array($latest['pending_verification']??null)||is_array($latest['activation_journal']??null))return ['reconciled'=>false,'reason'=>'routine_activation_in_progress','version'=>$diskVersion,'managed_tree_sha256'=>$diskTree,'migrations'=>$migrations];
            $latestActive=trim((string)($latest['active_version']??$active));$latestExpected=trim((string)($latest['active_managed_tree_sha256']??''));
            if($latestActive===$diskVersion&&$latestExpected!==''&&hash_equals($latestExpected,$diskTree))return ['reconciled'=>false,'reason'=>'already_aligned','version'=>$diskVersion,'managed_tree_sha256'=>$diskTree,'migrations'=>$migrations];
            $previous=$latestActive!==''?$latestActive:$active;$latest['previous_version']=$previous!==''&&!hash_equals($previous,$diskVersion)?$previous:(string)($latest['previous_version']??'');$latest['active_version']=$diskVersion;$latest['active_managed_tree_sha256']=$diskTree;$latest['staged']=null;$latest['pending_verification']=null;$latest['activation_journal']=null;$latest['lkg_recovery_id']='';
            if($pending!==[]){
                $fence=$latest['db_forward_fence']??null;
                if(!is_array($fence)||!hash_equals('external_emergency_'.$diskVersion,(string)($fence['transaction_id']??'')))
                    throw new PublicUpdateException('db_forward_recovery_required','External migration fence was changed before final reconciliation.',409);
                $latest['last_db_forward_commit']=['transaction_id'=>(string)$fence['transaction_id'],'version'=>$diskVersion,'at'=>gmdate('c')];
                $latest['db_forward_fence']=null;
            }
            $this->event($latest,'external_emergency_reconcile',$diskVersion,$actor,['previous'=>$previous,'managed_tree_sha256'=>$diskTree,'migrations'=>$migrations]);$this->save($latest);$this->pruneLifecycle('');
            return ['reconciled'=>true,'reason'=>'emergency_activation','version'=>$diskVersion,'managed_tree_sha256'=>$diskTree,'previous_version'=>$previous,'migrations'=>$migrations];
        }finally{if(is_resource($lock)){@flock($lock,LOCK_UN);fclose($lock);}}
    
        });
    }

    /** F1-S7E5D1B2A: an injection seam, NOT a trusted stable owner implementation. */
    private function ownerEmit(string $phase,string $transactionId,string $targetVersion,string $action): void
    {
        if($this->ownerHook===null)return; // Source-only; production adoption is NOT qualified.
        try{
            $result=($this->ownerHook)(['owner'=>'routine','phase'=>$phase,
                'transaction_id'=>$transactionId,'target_version'=>$targetVersion,'action'=>$action]);
            if($result!==true)throw new \RuntimeException('owner_event_not_acknowledged');
        }catch(Throwable $e){
            throw new PublicUpdateException('stable_owner_unavailable',
                'Trusted Public lifecycle owner did not durably acknowledge this phase.',503,
                ['phase'=>$phase,'transaction_id'=>$transactionId]);
        }
    }

    /** Cannot release the WAL/fence until a real external owner proves DB, schema and commit. */
    private function ownerFinalCommitGate(): void
    {
        if($this->ownerHook!==null)throw new PublicUpdateException('stable_owner_commit_not_qualified',
            'External lifecycle owner final-commit integration is not qualified.',503);
    }
    private function ownerRecoveryGate(): void
    {
        if($this->ownerHook!==null)throw new PublicUpdateException('stable_owner_recovery_not_qualified',
            'Recovery under the durable shared-owner WAL must be independently qualified.',503);
    }

    /** One OS lock for the existing Routine and Emergency live-tree mutation paths. */
    private function withSharedLifecycleLock(callable $work): mixed
    {
        $path=rtrim($this->storageRoot,"\\/").'/public-update-lifecycle.lock';
        $this->mkdir(dirname($path));
        $fh=@fopen($path,'c+');
        if(!is_resource($fh))throw new PublicUpdateException('shared_update_lock_unavailable','Public updater coordination lock cannot be opened.',503);
        try{
            if(!@flock($fh,LOCK_EX|LOCK_NB))throw new PublicUpdateException('shared_update_busy','Another Public update is changing the installation.',409);
            return $work();
        }finally{@flock($fh,LOCK_UN);@fclose($fh);}
    }

    public function currentVersion(): string
    {
        $f=@file_get_contents($this->componentRoot.'/VERSION.txt');return is_string($f)&&trim($f)!==''?trim($f):'unknown';
    }

    public function managedTreeDigest(): string{return $this->treeDigest($this->managedFileMap($this->componentRoot));}

    /** Admin/support evidence only: excludes persistent/runtime/source-owned paths by the same rules as the routine tree digest. */
    public function managedInventory(): array
    {
        $map=$this->managedFileMap($this->componentRoot);$rows=[];
        foreach($map as $rel=>$digest){$p=$this->componentRoot.'/'.$rel;$rows[]=['path'=>$rel,'size_bytes'=>is_file($p)?(int)filesize($p):0,'sha256'=>$digest];}
        return ['count'=>count($rows),'managed_tree_sha256'=>$this->treeDigest($map),'files'=>$rows];
    }
    public function maintenanceLockPath(): string{return $this->root().'/activation.lock';}

    private function verifyZip(string $path): array
    {
        if(!class_exists(ZipArchive::class))throw new PublicUpdateException('zip_unavailable','PHP ZipArchive is unavailable.',500);if(!is_file($path))throw new PublicUpdateException('package_missing','Routine update package is missing.',404);
        $z=new ZipArchive();if($z->open($path)!==true)throw new PublicUpdateException('invalid_zip','Invalid routine update ZIP.');
        try{
            $raw=$z->getFromName('manifest.json');if(!is_string($raw))throw new PublicUpdateException('manifest_missing','manifest.json is required.');$m=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            // Unified Public package: one FULL ZIP is consumed both by the
            // unchanged standalone Emergency owner and by Routine/Local.
            // Routine converts the authenticated full snapshot to a minimal
            // in-memory delta, then uses its existing journal, differential
            // recovery, readback and external commit. Never deploy the full
            // snapshot directly from Routine.
            if(is_array($m)&&($m['format']??'')==='sokna-component-package-v1'){
                return $this->verifyUnifiedFullZip($z,$m,$raw);
            }
            if(!is_array($m)||($m['format']??'')!==self::FORMAT||(int)($m['schema_version']??0)!==2||(string)($m['component']??'')!=='public-edge')throw new PublicUpdateException('manifest_contract','Routine Public delta manifest is invalid.');
            $version=trim((string)($m['version']??''));if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,95}$/D',$version))throw new PublicUpdateException('invalid_version','Routine target version is invalid.');
            $base=(array)($m['base']??[]);$target=(array)($m['target']??[]);foreach([['base',$base],['target',$target]] as [$label,$node]){if(!preg_match('/^[a-f0-9]{64}$/D',(string)($node['managed_tree_sha256']??'')))throw new PublicUpdateException('manifest_contract',ucfirst($label).' managed tree digest is invalid.');}
            if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,95}$/D',(string)($base['version']??'')))throw new PublicUpdateException('manifest_contract','Base version is invalid.');
            $contracts=(array)($m['contracts']??[]);if((int)($contracts['local_public_contract']??0)!==1||(int)($contracts['routine_update_protocol']??0)!==2)throw new PublicUpdateException('incompatible_contract','Routine Local/Public update contract is incompatible.',409);
            $files=(array)($m['files']??[]);$deletes=(array)($m['deletes']??[]);if(count($files)>self::MAX_FILES||count($deletes)>self::MAX_FILES)throw new PublicUpdateException('invalid_file_count','Routine delta file count is invalid.');
            $expected=[];$expanded=0;foreach($files as $e){if(!is_array($e))throw new PublicUpdateException('manifest_contract','Routine file record is invalid.');$rel=$this->safeRelative((string)($e['path']??''));$this->assertMutable($rel);$size=(int)($e['size']??-1);$hash=strtolower((string)($e['sha256']??''));if($size<0||!preg_match('/^[a-f0-9]{64}$/D',$hash)||isset($expected[$rel]))throw new PublicUpdateException('manifest_contract','Routine file record is invalid.');$mode=(int)($e['mode']??420);if(!in_array($mode,[420,493],true))throw new PublicUpdateException('manifest_contract','Routine file mode is invalid.');$expected[$rel]=[$size,$hash,$mode];$expanded+=$size;if($expanded>self::MAX_EXPANDED)throw new PublicUpdateException('package_too_large','Expanded routine delta exceeds limit.',413);}
            $seenDelete=[];foreach($deletes as $rel){$r=$this->safeRelative((string)$rel);$this->assertMutable($r);if(isset($expected[$r])||isset($seenDelete[$r]))throw new PublicUpdateException('manifest_contract','Delete path conflicts with delta payload.');$seenDelete[$r]=true;}
            $actual=[];for($i=0;$i<$z->numFiles;$i++){$name=(string)$z->getNameIndex($i);if($name==='manifest.json'||$name==='signature.json'||str_ends_with($name,'/'))continue;if(!str_starts_with($name,'payload/'))throw new PublicUpdateException('unexpected_entry','Unexpected routine ZIP entry.');$rel=$this->safeRelative(substr($name,8));if(isset($actual[$rel])||!isset($expected[$rel]))throw new PublicUpdateException('unexpected_entry','Routine ZIP entry is undeclared or duplicated.');$actual[$rel]=true;[$size,$hash]=$expected[$rel];$stream=$z->getStream($name);if(!is_resource($stream))throw new PublicUpdateException('read_failed','Routine ZIP entry cannot be read.',500);$ctx=hash_init('sha256');$read=0;while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false)break;$read+=strlen($buf);hash_update($ctx,$buf);}fclose($stream);if($read!==$size||!hash_equals($hash,hash_final($ctx)))throw new PublicUpdateException('hash_mismatch','Routine delta file hash mismatch.');}
            if(count($actual)!==count($expected))throw new PublicUpdateException('payload_set_mismatch','Routine delta payload set is incomplete.');
            return ['manifest'=>$m,'signature_status'=>$this->verifySignature($z,$raw)];
        }catch(PublicUpdateException $e){throw $e;}catch(Throwable){throw new PublicUpdateException('package_invalid','Routine Public delta package is invalid.');}finally{$z->close();}
    }

    /**
     * Full Public v1 is the common wire format for Emergency and Local.
     * Keep Emergency's v1 schema/verification intact. The Routine owner
     * computes the changed/add/delete set only after verifying EVERY full
     * snapshot member and the target identity, including unchanged files.
     */
    private function verifyUnifiedFullZip(ZipArchive $z,array $full,string $raw): array
    {
        if((int)($full['schema_version']??0)!==1||($full['component']??'')!=='public-edge')
            throw new PublicUpdateException('manifest_contract','Unified Public snapshot manifest is invalid.',422);
        $version=trim((string)($full['version']??''));
        if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/D',$version))
            throw new PublicUpdateException('invalid_version','Unified Public version is invalid.',422);
        if((int)(($full['contracts']??[])['local_public_contract']??0)!==1)
            throw new PublicUpdateException('incompatible_contract','Local/Public snapshot contract is incompatible.',409);
        $rows=(array)($full['files']??[]);
        if($rows===[]||count($rows)>self::MAX_FILES)
            throw new PublicUpdateException('invalid_file_count','Unified Public file count is invalid.',422);
        $expected=[];$expanded=0;
        foreach($rows as $entry){
            if(!is_array($entry))throw new PublicUpdateException('manifest_contract','Invalid full snapshot member.',422);
            $rel=$this->safeRelative((string)($entry['path']??''));
            // Emergency v1 rejects these paths too; never allow a package
            // through Local that Emergency would treat differently.
            if($this->stable($rel)||$this->runtimeExcluded($rel)||$this->sourceExcluded($rel))
                throw new PublicUpdateException('stable_path_forbidden','Unified ZIP contains a protected/runtime/source path.',422,['path'=>$rel]);
            $size=(int)($entry['size']??-1);$sha=strtolower((string)($entry['sha256']??''));
            if($size<0||!preg_match('/^[a-f0-9]{64}$/D',$sha)||isset($expected[$rel]))
                throw new PublicUpdateException('manifest_contract','Unified snapshot file record is invalid.',422);
            $expanded+=$size;if($expanded>self::MAX_EXPANDED)
                throw new PublicUpdateException('package_too_large','Unified snapshot exceeds expanded size limit.',413);
            $expected[$rel]=['path'=>$rel,'size'=>$size,'sha256'=>$sha,'mode'=>420];
        }
        // Stream and hash the entire package, not only paths that differ.
        $seen=[];
        for($i=0;$i<$z->numFiles;$i++){
            $name=(string)$z->getNameIndex($i);
            if($name==='manifest.json'||$name==='signature.json'||str_ends_with($name,'/'))continue;
            if(!str_starts_with($name,'payload/'))throw new PublicUpdateException('unexpected_entry','Unexpected unified ZIP entry.',422);
            $rel=$this->safeRelative(substr($name,8));
            if(!isset($expected[$rel])||isset($seen[$rel]))throw new PublicUpdateException('unexpected_entry','Undeclared or duplicate unified ZIP member.',422);
            $seen[$rel]=true;$e=$expected[$rel];$stream=$z->getStream($name);
            if(!is_resource($stream))throw new PublicUpdateException('read_failed','Unified ZIP member cannot be read.',500);
            $h=hash_init('sha256');$read=0;
            while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false)break;$read+=strlen($buf);hash_update($h,$buf);}
            fclose($stream);
            if($read!==$e['size']||!hash_equals($e['sha256'],hash_final($h)))
                throw new PublicUpdateException('hash_mismatch','Unified Public file hash mismatch.',422,['path'=>$rel]);
        }
        if(count($seen)!==count($expected))throw new PublicUpdateException('payload_set_mismatch','Unified snapshot is incomplete.',422);
        $sig=$this->verifySignature($z,$raw);
        $present=$this->managedFileMap($this->componentRoot);
        $baseVersion=$this->currentVersion();$baseTree=$this->treeDigest($present);
        // The full payload can target many hosts, but never an unknown or newer host.
        // A qualified publisher enumerates exact baselines; legacy one-baseline
        // manifests remain supported. Missing fields are NOT wildcard matches.
        $compat=(array)($full['compatibility']??[]);
        $this->assertUnifiedFullSourceCompatibility($compat,$baseVersion,$baseTree,$version);
        $delta=[];$target=[];
        foreach($expected as $rel=>$e){
            $target[$rel]=$e['sha256'];
            if(($present[$rel]??'')===$e['sha256'])continue;
            $this->assertMutable($rel); // unchanged stable recovery owner is safe
            $delta[]=$e;
        }
        $deletes=[];
        foreach($present as $rel=>$sha)if(!isset($expected[$rel])){
            $this->assertMutable($rel);
            $deletes[]=$rel;
        }
        $tree=$this->treeDigest($target);
        if(isset($full['target_managed_tree_sha256'])&&!hash_equals((string)$full['target_managed_tree_sha256'],$tree))
            throw new PublicUpdateException('target_tree_contract_mismatch','Unified target managed tree does not match its manifest.',422);
        // The normalized representation is stored only in Routine's private
        // staging area. The upload/package SHA remains that of the ORIGINAL
        // unified ZIP; the Emergency path receives those identical bytes.
        $normalized=[
            'format'=>self::FORMAT,'schema_version'=>2,'mode'=>'unified-full-v1',
            'component'=>'public-edge','version'=>$version,
            'base'=>['version'=>$baseVersion,'managed_tree_sha256'=>$baseTree],
            'target'=>['managed_tree_sha256'=>$tree],
            'contracts'=>['local_public_contract'=>1,'routine_update_protocol'=>2],
            'files'=>$delta,'deletes'=>$deletes,
            'provenance'=>['input_format'=>'sokna-component-package-v1','file_count'=>count($expected),
                'strict_multibaseline_v1'=>array_key_exists('supported_baselines',$compat)],
        ];
        $this->assertBaseline($normalized);
        return ['manifest'=>$normalized,'signature_status'=>$sig];
    }

    private function verifySignature(ZipArchive $z,string $manifestRaw): string
    {
        // Operator-managed Public ZIP: integrity is checked from the file
        // hashes and active receiver identity. Request authentication is
        // enforced by the HTTP/controller layer, independently of this ZIP.
        if($z->locateName('signature.json')!==false)
            throw new PublicUpdateException('publisher_signature_not_allowed','A publisher signature is not accepted in the keyless operator-upload format.',422);
        return 'operator_selected_hash_verified';
    }

    /**
     * Strict rollout guard: only canonical workspace build strings can be
     * compared by this older updater. Unknown versions require a separately
     * qualified foundation update; do not guess their relative order.
     */
    private static function compareWorkspaceBuilds(string $target,string $source): int
    {
        $parse=static function(string $version): array {
            if(!preg_match('/(?:^|-)workspace\.([0-9]{1,8})([a-z]{1,5})(?:-|$)/D',$version,$m))
                throw new PublicUpdateException('unverifiable_version_order','Public build order is not recognized; update refused.',409);
            $suffix=0;
            foreach(str_split($m[2]) as $c)$suffix=$suffix*26+(ord($c)-96);
            return [(int)$m[1],$suffix];
        };
        return $parse($target)<=>$parse($source);
    }

    /** Compatible with the legacy exact one-base manifest and new multi-base manifest. */
    private function assertUnifiedFullSourceCompatibility(array $compat,string $baseVersion,string $baseTree,string $targetVersion): void
    {
        if(self::compareWorkspaceBuilds($targetVersion,$baseVersion)<=0)
            throw new PublicUpdateException('downgrade_or_reinstall_forbidden','Target Public version is not newer than the installed Public version.',409);
        $multi=array_key_exists('supported_baselines',$compat);
        if($multi){
            if(array_key_exists('from_version',$compat)||array_key_exists('from_managed_tree_sha256',$compat))
                throw new PublicUpdateException('ambiguous_baseline_policy','Mixed Public baseline modes are forbidden.',422);
            $bases=$compat['supported_baselines'];
            if(!is_array($bases)||!array_is_list($bases)||count($bases)<1||count($bases)>64)
                throw new PublicUpdateException('invalid_baseline_allowlist','Expected between 1 and 64 exact Public source baselines.',422);
            $unique=[];$found=false;
            foreach($bases as $base){
                if(!is_array($base)||!is_string($base['version']??null)||!is_string($base['managed_tree_sha256']??null)||
                   !preg_match('/^[a-f0-9]{64}$/D',$base['managed_tree_sha256']))
                    throw new PublicUpdateException('invalid_baseline_allowlist','Invalid Public source baseline.',422);
                $key=$base['version']."\0".$base['managed_tree_sha256'];
                if(isset($unique[$key]))throw new PublicUpdateException('duplicate_baseline','Duplicate Public source baseline.',422);
                $unique[$key]=true;
                if(self::compareWorkspaceBuilds($targetVersion,$base['version'])<=0)
                    throw new PublicUpdateException('downgrade_in_allowlist','Public baseline allowlist cannot include equal or newer releases.',422);
                if(hash_equals($base['version'],$baseVersion)&&hash_equals($base['managed_tree_sha256'],$baseTree))$found=true;
            }
            if(!$found)throw new PublicUpdateException('base_not_qualified','Installed Public build/tree is not on the release allowlist.',409);
            return;
        }
        // Old UAT packages still have one exact source; never interpret a
        // missing/partial source declaration as permission to update any host.
        if(!is_string($compat['from_version']??null)||!is_string($compat['from_managed_tree_sha256']??null))
            throw new PublicUpdateException('missing_baseline_contract','Public source baseline must be explicitly declared.',422);
        if(!hash_equals($compat['from_version'],$baseVersion))
            throw new PublicUpdateException('base_version_mismatch','Unified package requires another starting Public version.',409);
        if(!hash_equals($compat['from_managed_tree_sha256'],$baseTree))
            throw new PublicUpdateException('base_tree_mismatch','Unified package requires another starting Public tree.',409);
    }

    private function assertBaseline(array $m): void
    {
        $base=(array)($m['base']??[]);$wantVersion=(string)($base['version']??'');$wantTree=(string)($base['managed_tree_sha256']??'');$current=$this->currentVersion();$tree=$this->managedTreeDigest();
        if(self::compareWorkspaceBuilds((string)($m['version']??''),$current)<=0)
            throw new PublicUpdateException('downgrade_or_reinstall_forbidden','Target Public version is not newer than installed Public version.',409);
        if(!hash_equals($wantVersion,$current))throw new PublicUpdateException('base_version_mismatch','Routine delta base version does not match the installed Public version.',409,['expected'=>$wantVersion,'actual'=>$current]);
        if(!hash_equals($wantTree,$tree))throw new PublicUpdateException('base_tree_mismatch','Installed Public tree does not match the delta baseline.',409,['expected'=>$wantTree,'actual'=>$tree]);
        // Prove the manifest target identity is the exact result of applying
        // changed/add/delete records to the live managed map before staging.
        $map=$this->managedFileMap($this->componentRoot);foreach((array)($m['deletes']??[]) as $rel)unset($map[(string)$rel]);foreach((array)($m['files']??[]) as $e)$map[(string)$e['path']]=(string)$e['sha256'];$wantTarget=(string)(($m['target']??[])['managed_tree_sha256']??'');$sim=$this->treeDigest($map);if(!hash_equals($wantTarget,$sim))throw new PublicUpdateException('target_tree_contract_mismatch','Delta records do not produce the declared target identity.',422,['expected'=>$wantTarget,'simulated'=>$sim]);
    }

    private function extractPayload(string $zipPath,string $target,array $files): void
    {
        $z=new ZipArchive();if($z->open($zipPath)!==true)throw new PublicUpdateException('invalid_zip','Invalid routine update ZIP.');try{foreach($files as $e){$rel=$this->safeRelative((string)$e['path']);$dst=$target.'/'.$rel;$this->mkdir(dirname($dst));$in=$z->getStream('payload/'.$rel);$out=fopen($dst,'wb');if(!is_resource($in)||!is_resource($out))throw new PublicUpdateException('extract_failed','Routine package extraction failed.',500);stream_copy_to_stream($in,$out);fclose($in);fclose($out);@chmod($dst,(int)($e['mode']??420));}}finally{$z->close();}
    }

    private function applyDelta(string $payload,array $files,array $deletes,string $transactionId=''): void
    {
        $ordered=[];$deferred=[];
        $late=['index.php'=>true,'bootstrap.php'=>true,'src/Core/Bootstrap.php'=>true,'src/Update/RoutinePublicUpdateService.php'=>true];
        foreach($files as $e){$rel=$this->safeRelative((string)$e['path']);if(isset($late[$rel]))$deferred[]=$e;else $ordered[]=$e;}
        foreach(array_merge($ordered,$deferred) as $e){
            $rel=$this->safeRelative((string)$e['path']);$src=$payload.'/'.$rel;$dst=$this->componentRoot.'/'.$rel;
            $this->replaceFileWithRetry($src,$dst,(int)($e['mode']??420),'apply_replace',$rel,$transactionId);
            clearstatcache(true,$dst);
            if(!is_file($dst)||(int)filesize($dst)!==(int)$e['size']||!hash_equals((string)$e['sha256'],(string)hash_file('sha256',$dst)))throw new PublicUpdateException('apply_integrity_failed','Routine replacement failed post-write integrity verification.',500,['file'=>$rel,'phase'=>'apply_replace','transaction_id'=>$transactionId]);
        }
        foreach($deletes as $relRaw){$rel=$this->safeRelative((string)$relRaw);$dst=$this->componentRoot.'/'.$rel;if(is_file($dst)||is_link($dst))$this->deleteFileWithRetry($dst,'apply_delete',$rel,$transactionId);}
        $this->removeEmptyDirectories($this->componentRoot);
    }

    /**
     * Differential recovery point. Only pre-images of paths touched by this
     * delta are stored. Unmanaged/runtime/stable files are never snapshotted.
     */
    private function createRecoveryPoint(string $reason,string $actor,array $files=[],array $deletes=[],string $transactionId=''): array
    {
        $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$dir=$this->root().'/recovery-points/'.$id;$this->mkdir($dir.'/payload');
        $paths=[];foreach($files as $e)$paths[$this->safeRelative((string)($e['path']??''))]=true;foreach($deletes as $rel)$paths[$this->safeRelative((string)$rel)]=true;ksort($paths,SORT_STRING);
        $entries=[];
        try{
            foreach(array_keys($paths) as $rel){
                $this->assertMutable($rel);$src=$this->componentRoot.'/'.$rel;
                if(is_file($src)){
                    $dst=$dir.'/payload/'.$rel;$this->copyFileWithRetry($src,$dst,'recovery_preimage',$rel,is_executable($src)?0755:0644,$transactionId);
                    clearstatcache(true,$dst);$hash=hash_file('sha256',$dst);if(!is_string($hash))throw new PublicUpdateException('recovery_hash_failed','Recovery pre-image hash could not be calculated.',500,['file'=>$rel,'transaction_id'=>$transactionId]);
                    $entries[]=['path'=>$rel,'existed'=>true,'size'=>(int)filesize($dst),'sha256'=>$hash,'mode'=>is_executable($src)?493:420];
                }else $entries[]=['path'=>$rel,'existed'=>false,'absent_before'=>true];
            }
            $m=['format'=>'sokna-public-routine-recovery-v3-differential','id'=>$id,'version'=>$this->currentVersion(),'managed_tree_sha256'=>$this->managedTreeDigest(),'created_at'=>gmdate('c'),'reason'=>$reason,'actor_hint'=>substr($actor,0,120),'transaction_id'=>$transactionId,'entries'=>$entries];
            $this->writeJson($dir.'/manifest.json',$m);return $m;
        }catch(Throwable $e){$this->removeTree($dir);if($e instanceof PublicUpdateException)throw $e;throw new PublicUpdateException('recovery_preflight_failed','Routine differential recovery preparation failed.',500,['transaction_id'=>$transactionId]);}
    }

    private function restoreRecoveryPoint(string $id): array
    {
        if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/D',$id))throw new PublicUpdateException('invalid_recovery_id','Routine recovery id is invalid.');
        $dir=$this->root().'/recovery-points/'.$id;$m=$this->readJson($dir.'/manifest.json');$format=(string)($m['format']??'');
        if(!in_array($format,['sokna-public-routine-recovery-v2','sokna-public-routine-recovery-v3-differential'],true))throw new PublicUpdateException('recovery_missing','Routine recovery point is invalid.',404);
        $recoveryVersion=(string)($m['version']??'');
        // One transition exception: the recovery point created while installing
        // the first stable recovery owner must be able to remove that owner if
        // H232 itself is rolled back. After H232 is committed, all later deltas
        // are forbidden from touching the owner.
        $stableOwnerTransitionRollback=$recoveryVersion==='3.0.0-g3.6-workspace.35be-updater-lifecycle-v2';
        // A direct transition from any pre-stable-owner routine updater creates
        // a legacy full-tree v2 recovery payload which naturally has no
        // recovery.php/src/Recovery. If a later committed target is rolled back,
        // restore that baseline exactly (including removing the first stable
        // owner) so the old updater can operate on its original managed tree.
        $legacyOwnerTransitionRollback=$format==='sokna-public-routine-recovery-v2'
            &&!is_file($dir.'/payload/recovery.php')&&!is_dir($dir.'/payload/src/Recovery')
            &&(is_file($this->componentRoot.'/recovery.php')||is_dir($this->componentRoot.'/src/Recovery'));
        $allowOwnerRemoval=$stableOwnerTransitionRollback||$legacyOwnerTransitionRollback;
        $this->beginMaintenance($recoveryVersion!==''?$recoveryVersion:'recovery','routine-recovery');
        try{
            if($format==='sokna-public-routine-recovery-v2'){
                $this->verifyDirectory($dir.'/payload',(array)($m['files']??[]));$this->replaceMutableTree($dir.'/payload');
                if($legacyOwnerTransitionRollback){
                    if(is_file($this->componentRoot.'/recovery.php')||is_link($this->componentRoot.'/recovery.php'))$this->deleteFileWithRetry($this->componentRoot.'/recovery.php','transition_recovery_owner_remove','recovery.php',(string)($m['transaction_id']??''));
                    if(is_dir($this->componentRoot.'/src/Recovery'))$this->removeTree($this->componentRoot.'/src/Recovery');
                }
            }else{
                foreach((array)($m['entries']??[]) as $e){
                    if(!is_array($e))throw new PublicUpdateException('recovery_manifest_invalid','Differential recovery manifest is invalid.',500);
                    $rel=$this->safeRelative((string)($e['path']??''));if(!$stableOwnerTransitionRollback||!$this->recoveryOwner($rel))$this->assertMutable($rel);$dst=$this->componentRoot.'/'.$rel;
                    if((bool)($e['existed']??false)){
                        $src=$dir.'/payload/'.$rel;
                        if(!is_file($src)||(int)filesize($src)!==(int)($e['size']??-1)||!hash_equals((string)($e['sha256']??''),(string)hash_file('sha256',$src)))throw new PublicUpdateException('recovery_integrity_failed','Differential recovery pre-image failed integrity verification.',500,['file'=>$rel]);
                        $this->replaceFileWithRetry($src,$dst,(int)($e['mode']??420),'recovery_restore',$rel,(string)($m['transaction_id']??''));
                    }elseif(is_file($dst)||is_link($dst))$this->deleteFileWithRetry($dst,'recovery_remove_new',$rel,(string)($m['transaction_id']??''));
                }
            }
            $want=(string)($m['managed_tree_sha256']??'');$actual=$this->managedTreeDigest();if($want!==''&&!hash_equals($want,$actual))throw new PublicUpdateException('recovery_tree_mismatch','Routine recovery tree identity mismatch.',500,['expected'=>$want,'actual'=>$actual]);
            $this->healthCheck($allowOwnerRemoval);
        }catch(Throwable $e){
            // Keep maintenance locked on failed recovery. Clearing the gate
            // here would expose a potentially mixed live tree.
            if($e instanceof PublicUpdateException)throw $e;throw new PublicUpdateException('recovery_failed','Routine Public recovery failed; maintenance remains locked.',500,['recovery_id'=>$id]);
        }
        $this->clearMaintenance();return $m;
    }

    /** Legacy full-tree v2 recovery compatibility only. */
    private function replaceMutableTree(string $source): void
    {
        $current=$this->managedFileMap($this->componentRoot);$incoming=$this->managedFileMap($source);
        $files=[];foreach($incoming as $rel=>$hash)$files[]=['path'=>$rel,'size'=>(int)filesize($source.'/'.$rel),'sha256'=>$hash,'mode'=>is_executable($source.'/'.$rel)?493:420];
        $deletes=array_values(array_filter(array_diff(array_keys($current),array_keys($incoming)),fn(string $rel):bool=>!$this->recoveryOwner($rel)));$files=array_values(array_filter($files,fn(array $e):bool=>!$this->recoveryOwner((string)($e['path']??''))));$this->applyDelta($source,$files,$deletes,'legacy-recovery');
    }

    private function healthCheck(bool $allowPreStableRecoveryOwner=false): void
    {
        $required=['bootstrap.php','index.php','emergency.php','setup/index.php','setup/api.php','VERSION.txt'];
        if(!$allowPreStableRecoveryOwner){$required[]='recovery.php';$required[]='src/Recovery/StableRecoveryService.php';}
        foreach($required as $rel)if(!is_file($this->componentRoot.'/'.$rel))throw new PublicUpdateException('health_failed','Critical Public file is missing.',500,['file'=>$rel]);
        if(is_callable($this->health)&&!(bool)($this->health)())throw new PublicUpdateException('health_failed','Public internal health callback failed.',500);
    }

    private function beginMaintenance(string $version,string $actor): void
    {
        $this->writeJson($this->maintenanceLockPath(),['format'=>'sokna-public-update-lock-v2','version'=>$version,'actor_hint'=>substr($actor,0,120),'started_at'=>gmdate('c')]);
    }
    private function clearMaintenance(): void
    {
        $path=$this->maintenanceLockPath();$attempts=4;
        for($i=1;$i<=$attempts;$i++){
            if(!is_file($path)&&!is_link($path))return;
            if(@unlink($path))return;
            if($i<$attempts)usleep(75000*$i);
        }
        throw new PublicUpdateException('maintenance_clear_failed','Routine maintenance lock could not be cleared.',500,$this->ioContext('maintenance_clear','activation.lock',$path,'','',$attempts));
    }

    /** @return array<string,string> */
    private function managedFileMap(string $root): array
    {
        $out=[];if(!is_dir($root))return $out;$prefixLen=strlen(rtrim($root,'/\\'))+1;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $f)if($f->isFile()){$rel=str_replace('\\','/',substr($f->getPathname(),$prefixLen));if($this->stable($rel)||$this->runtimeExcluded($rel)||$this->sourceExcluded($rel))continue;$out[$this->safeRelative($rel)]=(string)hash_file('sha256',$f->getPathname());}
        ksort($out,SORT_STRING);return $out;
    }
    private function treeDigest(array $map): string{$h=hash_init('sha256');ksort($map,SORT_STRING);foreach($map as $rel=>$digest){hash_update($h,(string)$rel);hash_update($h,"\0");hash_update($h,(string)$digest);hash_update($h,"\n");}return hash_final($h);}

    /** @deprecated Legacy utility retained for old recovery compatibility tests. */
    private function copyMutableTree(string $src,string $dst): void
    {
        $this->removeTree($dst);$this->mkdir($dst);$map=$this->managedFileMap($src);foreach(array_keys($map) as $rel){$from=$src.'/'.$rel;$to=$dst.'/'.$rel;$this->copyFileWithRetry($from,$to,'legacy_recovery_copy',$rel,is_executable($from)?0755:0644);}
    }

    private function stageDir(string $version,string $hash): string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new PublicUpdateException('invalid_package_hash','Routine package SHA256 is invalid.',422);
        return $this->root().'/staged/'.$version.'/'.$hash;
    }

    private function resolveStageDir(string $version,string $hash): string
    {
        if($hash!==''){
            $v2=$this->stageDir($version,$hash);if(is_dir($v2))return $v2;
        }
        $legacy=$this->root().'/staged/'.$version;if(is_file($legacy.'/manifest.json'))return $legacy;
        throw new PublicUpdateException('staged_package_missing','Staged routine package storage is missing.',409,['version'=>$version,'package_sha256'=>$hash]);
    }

    private function ioContext(string $phase,string $rel,string $src='',string $dst='',string $transactionId='',int $attempts=0): array
    {
        $last=error_get_last();return ['phase'=>$phase,'file'=>$rel,'source'=>$src,'destination'=>$dst,'transaction_id'=>$transactionId,'attempts'=>$attempts,'php_error'=>is_array($last)?(string)($last['message']??''):''];
    }

    private function copyFileWithRetry(string $src,string $dst,string $phase,string $rel,int $mode=0644,string $transactionId=''): void
    {
        $this->mkdir(dirname($dst));$attempts=4;
        for($i=1;$i<=$attempts;$i++){
            clearstatcache(true,$src);clearstatcache(true,$dst);
            if(is_file($src)&&@copy($src,$dst)){@chmod($dst,$mode);return;}
            if($i<$attempts)usleep(75000*$i);
        }
        throw new PublicUpdateException('copy_failed','Routine lifecycle file copy failed.',500,$this->ioContext($phase,$rel,$src,$dst,$transactionId,$attempts));
    }

    private function renameWithRetry(string $src,string $dst,string $phase,string $rel,string $transactionId=''): void
    {
        $this->mkdir(dirname($dst));$attempts=4;
        for($i=1;$i<=$attempts;$i++){
            if(@rename($src,$dst))return;
            if($i<$attempts)usleep(75000*$i);
        }
        throw new PublicUpdateException('atomic_replace_failed','Routine lifecycle atomic rename failed.',500,$this->ioContext($phase,$rel,$src,$dst,$transactionId,$attempts));
    }

    private function replaceFileWithRetry(string $src,string $dst,int $mode,string $phase,string $rel,string $transactionId=''): void
    {
        $this->mkdirPublic(dirname($dst));$tmp=$dst.'.sokna-v2-'.bin2hex(random_bytes(4));
        try{$this->copyFileWithRetry($src,$tmp,$phase.'_prepare',$rel,$mode===493?0755:0644,$transactionId);$this->renameWithRetry($tmp,$dst,$phase,$rel,$transactionId);@chmod($dst,$mode===493?0755:0644);}
        catch(Throwable $e){@unlink($tmp);throw $e;}
    }

    private function deleteFileWithRetry(string $path,string $phase,string $rel,string $transactionId=''): void
    {
        $attempts=4;
        for($i=1;$i<=$attempts;$i++){
            if(!file_exists($path)&&!is_link($path))return;
            if(@unlink($path))return;
            if($i<$attempts)usleep(75000*$i);
        }
        throw new PublicUpdateException('delete_failed','Routine lifecycle file delete failed.',500,$this->ioContext($phase,$rel,$path,'',$transactionId,$attempts));
    }

    private function pruneLifecycle(string $keepRecoveryId=''): void
    {
        // Keep a bounded recent recovery history. The current LKG is always kept.
        $rdir=$this->root().'/recovery-points';if(is_dir($rdir)){
            $dirs=array_values(array_filter(glob($rdir.'/*')?:[],'is_dir'));usort($dirs,fn($a,$b)=>(@filemtime($b)?:0)<=> (@filemtime($a)?:0));
            $kept=0;foreach($dirs as $dir){$id=basename($dir);if($id===$keepRecoveryId){$kept++;continue;}if($kept<3){$kept++;continue;}$this->removeTree($dir);}
        }
        // Stage is content-addressed; retain only a small bounded diagnostic history.
        $sroot=$this->root().'/staged';if(is_dir($sroot)){
            $leaves=[];$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sroot,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
            foreach($it as $f)if($f->isDir()&&is_file($f->getPathname().'/package.json'))$leaves[]=$f->getPathname();
            usort($leaves,fn($a,$b)=>(@filemtime($b)?:0)<=> (@filemtime($a)?:0));foreach(array_slice($leaves,5) as $dir)$this->removeTree($dir);
        }
    }
    private function hashTree(string $root): array{$out=[];if(!is_dir($root))return $out;$prefixLen=strlen(rtrim($root,'/\\'))+1;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile()){$rel=str_replace('\\','/',substr($f->getPathname(),$prefixLen));$out[]=['path'=>$rel,'size'=>$f->getSize(),'sha256'=>hash_file('sha256',$f->getPathname())];}usort($out,fn($a,$b)=>strcmp($a['path'],$b['path']));return $out;}
    private function verifyDirectory(string $root,array $files): void{$expected=[];foreach($files as $e){$rel=$this->safeRelative((string)$e['path']);$expected[$rel]=true;$p=$root.'/'.$rel;if(!is_file($p)||(int)filesize($p)!==(int)$e['size']||!hash_equals(strtolower((string)$e['sha256']),strtolower((string)hash_file('sha256',$p))))throw new PublicUpdateException('staged_integrity_failed','Routine staged file integrity failed.',422,['file'=>$rel]);}$actual=[];if(is_dir($root)){$prefixLen=strlen(rtrim($root,'/\\'))+1;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$actual[str_replace('\\','/',substr($f->getPathname(),$prefixLen))]=true;}if(count($actual)!==count($expected)||array_diff_key($actual,$expected)||array_diff_key($expected,$actual))throw new PublicUpdateException('payload_set_mismatch','Routine staged file set changed.',422);}

    private function cleanupUploads(): void{$dir=$this->root().'/incoming';if(!is_dir($dir))return;$cut=time()-86400;foreach(glob($dir.'/*.json')?:[] as $meta){if((int)@filemtime($meta)>=$cut)continue;$id=basename($meta,'.json');@unlink($meta);@unlink($dir.'/'.$id.'.part');}}
    private function uploadMeta(string $id): string{return $this->root().'/incoming/'.$id.'.json';}
    private function uploadPart(string $id): string{return $this->root().'/incoming/'.$id.'.part';}
    private function safeUploadId(string $id): string{$id=strtolower(trim($id));if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new PublicUpdateException('invalid_upload_id','Routine upload id is invalid.',422);return $id;}

    private function runtimeExcluded(string $rel): bool{return in_array($rel,['config.php','install.lock'],true)||str_starts_with($rel,'storage/');}
    private function sourceExcluded(string $rel): bool{return in_array($rel,['README.md','manifest.json'],true)||str_starts_with($rel,'Q3_P1_')||str_starts_with($rel,'tools/');}
    private function stable(string $rel): bool{foreach(self::STABLE_PREFIXES as $p)if($rel===$p||(str_ends_with($p,'/')&&str_starts_with($rel,$p)))return true;return false;}
    private function recoveryOwner(string $rel): bool{foreach(self::RECOVERY_OWNER_PREFIXES as $p)if($rel===$p||(str_ends_with($p,'/')&&str_starts_with($rel,$p)))return true;return false;}
    private function assertMutable(string $rel): void{if($this->stable($rel)||$this->recoveryOwner($rel)||$this->runtimeExcluded($rel)||$this->sourceExcluded($rel))throw new PublicUpdateException('stable_path_forbidden','Routine delta may not replace stable recovery/emergency/runtime/source-only paths.',422,['path'=>$rel]);}

    private function state(): array{$s=$this->readJson($this->root().'/state.json');if(($s['format']??'')!==self::STATE_FORMAT)$s=['format'=>self::STATE_FORMAT,'schema_version'=>2,'active_version'=>$this->currentVersion(),'active_managed_tree_sha256'=>'','previous_version'=>'','lkg_recovery_id'=>'','staged'=>null,'pending_verification'=>null,'activation_journal'=>null,'db_forward_fence'=>null,'history'=>[]];return $s;}
    private function save(array $s): void{$this->writeJson($this->root().'/state.json',$s);}
    private function event(array &$s,string $action,string $version,string $actor,array $extra=[]): void{$s['history'][]=['at'=>gmdate('c'),'action'=>$action,'version'=>$version,'actor_hint'=>substr($actor,0,120),'result'=>'success']+$extra;$s['history']=array_slice($s['history'],-100);}
    private function root(): string{$p=rtrim($this->storageRoot,"\\/").'/routine-update-v2';$this->mkdir($p);return $p;}
    private function trust(): array{return $this->readJson($this->trustFile);}
    private function readJson(string $p): array{$raw=@file_get_contents($p);if(!is_string($raw)||$raw==='')return [];try{$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}}
    private function writeJson(string $p,array $v): void
    {
        $this->mkdir(dirname($p));$raw=json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";$attempts=4;$last='';
        for($i=1;$i<=$attempts;$i++){
            $tmp=$p.'.tmp-'.bin2hex(random_bytes(4));
            try{
                if(@file_put_contents($tmp,$raw,LOCK_EX)!==strlen($raw)){ $last='write';@unlink($tmp); }
                else{ @chmod($tmp,0600);if(@rename($tmp,$p)){@chmod($p,0600);return;}$last='rename';@unlink($tmp); }
            }catch(Throwable $e){$last=$e->getMessage();@unlink($tmp);}
            if($i<$attempts)usleep(75000*$i);
        }
        throw new PublicUpdateException('state_write_failed','Routine lifecycle state write failed after bounded retries.',500,$this->ioContext('state_write',basename($p),'',$p,'',$attempts)+['last_step'=>$last]);
    }
    private function safeRelative(string $r): string{$r=str_replace('\\','/',trim($r));if($r===''||str_starts_with($r,'/')||preg_match('#(^|/)\.\.(/|$)#',$r)||str_contains($r,"\0"))throw new PublicUpdateException('unsafe_path','Unsafe routine package path.',422,['path'=>$r]);return $r;}
    private function mkdir(string $p): void{if(!is_dir($p)&&!@mkdir($p,0700,true)&&!is_dir($p))throw new PublicUpdateException('storage_unavailable','Routine lifecycle storage is unavailable.',500);}
    private function mkdirPublic(string $p): void{if(!is_dir($p)&&!@mkdir($p,0755,true)&&!is_dir($p))throw new PublicUpdateException('deploy_unavailable','Public deployment directory is unavailable.',500);@chmod($p,0755);}
    private function removeTree(string $p): void{if(!file_exists($p))return;if(is_file($p)||is_link($p)){@unlink($p);return;}$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($p);}
    private function removeEmptyDirectories(string $root): void{if(!is_dir($root))return;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)if($f->isDir()){@rmdir($f->getPathname());}}
}