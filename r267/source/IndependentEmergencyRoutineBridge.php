<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Update;

use Sokna\PublicEdge\Emergency\PublicUpdateException;
use Throwable;

/**
 * Independent Emergency HTTP controller adapter for the EXISTING Routine v2
 * state machine. No separate ZIP, DB migration engine, recovery ledger or
 * filesystem writer is introduced. The protected five-file Emergency owner
 * and the historical Emergency state stay untouched.
 *
 * This adapter is only safe behind Emergency password + CSRF + HTTPS controls.
 */
final class IndependentEmergencyRoutineBridge
{
    /** @param callable():list<string> $pendingMigrations
     *  @param callable():array<string,mixed> $freshHealthReadback
     */
    public function __construct(
        private readonly RoutinePublicUpdateService $routine,
        private readonly string $storageRoot,
        private readonly mixed $pendingMigrations,
        private readonly mixed $freshHealthReadback,
    ) {
        if (!is_callable($pendingMigrations) || !is_callable($freshHealthReadback)) {
            throw new PublicUpdateException('emergency_forward_provider_missing',
                'Routine and database verification providers are required.', 503);
        }
    }

    public function snapshot(): array
    {
        $snapshot = $this->routine->snapshot();
        $snapshot['channel'] = 'emergency_independent_routine_v2';
        $snapshot['historical_emergency_active_version'] = $snapshot['emergency_active_version'] ?? '';
        $snapshot['forward_only_protected'] = is_array($snapshot['db_forward_fence'] ?? null);
        $snapshot['emergency_schema_migration_contract']='append_only_exact_sha_v1';
        $snapshot['emergency_migration_commit_requires_fresh_readback']=true;
        return $snapshot;
    }

    /** Stage the SAME immutable operator ZIP used by Local -> Public. */
    public function stageUploadedZip(string $path, string $actor='emergency'): array
    {
        $this->requireUnambiguousRoutineState(false);
        $staged=$this->routine->stageUploadedDeltaZip($path, 'emergency:'.$actor, true);
        // Prevent the previous failure mode: report schema compatibility
        // during Stage, not only after the operator presses Activate.
        $this->stagedMigrationPlan(false);
        return $staged;
    }

    /**
     * The original 35IC bridge rejected every staged schema migration.  Only a
     * byte-pinned, append-only plan may cross this gate; Routine remains the
     * sole writer/DDL runner. Commit is always a distinct authenticated POST.
     */
    public function activateStaged(string $actor='emergency'): array
    {
        $this->requireUnambiguousRoutineState(true);
        $this->requireNoPendingMigration();
        $this->stagedMigrationPlan(false);
        $snapshot=$this->routine->activateStaged('emergency:'.$actor);
        if(!is_array($snapshot['pending_verification']??null))
            throw new PublicUpdateException('emergency_activation_not_pending',
                'Target was not left in verifiable state.', 503);
        return $snapshot;
    }

    /** In a LATER authenticated request, validate actual target and DB before Commit. */
    public function commitPending(string $actor='emergency'): array
    {
        $snapshot=$this->routine->snapshot();
        $p=$snapshot['pending_verification']??null;
        if(!is_array($p) || !is_string($p['version']??null) || $p['version']==='')
            throw new PublicUpdateException('nothing_pending',
                'No independently verifiable Routine activation is pending.', 409);
        if(!hash_equals((string)$p['version'],(string)$snapshot['current_version']) ||
           !hash_equals((string)($p['target_tree_sha256']??''),
               (string)($snapshot['managed_tree_sha256']??'')))
            throw new PublicUpdateException('post_activate_identity_mismatch',
                'Target code and exact managed-tree readback differ.',409);
        $plan=$this->stagedMigrationPlan(true);
        $pending=$this->pendingVersions();
        $expected=array_keys($plan);sort($expected,SORT_STRING);
        $pendingSorted=$pending;sort($pendingSorted,SORT_STRING);
        $fence=$snapshot['db_forward_fence']??null;
        if($plan===[] && $pending!==[])
            throw new PublicUpdateException('emergency_unexpected_pending_migration',
                'A code-only stage must not find pending schema changes.',409);
        if($plan!==[]){
            // A resumed migration may have already committed part of the
            // catalog. Only a fence for this very activation permits a subset.
            $sameFence=is_array($fence)
                &&hash_equals((string)($p['transaction_id']??''),(string)($fence['transaction_id']??''))
                &&hash_equals((string)$p['version'],(string)($fence['schema_owner_version']??''));
            if(!$sameFence && $pendingSorted!==$expected)
                throw new PublicUpdateException('emergency_schema_plan_mismatch',
                    'The database pending migration plan differs from the immutable staged ZIP.',409);
            if($sameFence && array_diff($pendingSorted,$expected)!==[])
                throw new PublicUpdateException('emergency_schema_resume_mismatch',
                    'Forward recovery has pending migrations outside its approved package.',409);
        }
        // Routine itself durably fences the database BEFORE the first DDL.
        // For a schema change the new Routine's post-migration readback is
        // executed INSIDE its lifecycle lock, BEFORE it publishes Commit.
        if($plan!==[]){
            $result=$this->routine->commitActivation('emergency:'.$actor,
                function(string $target): array {
                    $health=($this->freshHealthReadback)();
                    if(!is_array($health)||($health['ok']??null)!==true||
                        ($health['database']??null)!=='ready'||
                        ($health['emergency_ready']??null)!==true||
                        (int)($health['pending_migrations']??-1)!==0||
                        (int)($health['applied_migrations']??-1)!==(int)($health['catalog_migrations']??-2)||
                        !hash_equals($target,(string)($health['version']??'')))
                        throw new PublicUpdateException('emergency_target_health_not_qualified',
                            'Target Public/DB readback is incomplete after schema change; keep forward-only recovery.',503);
                    return $health;
                });
        }else{
            $health=($this->freshHealthReadback)();
            if(!is_array($health)||($health['ok']??null)!==true||
                ($health['database']??null)!=='ready'||
                ($health['emergency_ready']??null)!==true||
                (int)($health['pending_migrations']??-1)!==0||
                !hash_equals((string)$p['version'],(string)($health['version']??'')))
                throw new PublicUpdateException('emergency_target_health_not_qualified',
                    'Fresh target Public health, identity and migration readback are required.',503);
            $result=$this->routine->commitActivation('emergency:'.$actor);
        }
        return $result;
    }

    /** Prior historical DB forward fence can NEVER authorize old-code rollback. */
    public function rollback(string $actor='emergency'): never
    {
        throw new PublicUpdateException('emergency_rollback_not_qualified',
            'Emergency never restores old code under an unresolved DB boundary.',409);
    }

    public function repairStaged(string $actor='emergency'): never
    {
        throw new PublicUpdateException('emergency_repair_not_qualified',
            'Independent same-version Emergency repair requires separate verification.',409);
    }

    private function requireUnambiguousRoutineState(bool $requireStaged): void
    {
        $s=$this->routine->snapshot();
        if(is_array($s['pending_verification']??null) ||
           is_array($s['activation_journal']??null) ||
           ($s['emergency_state_reconciliation_pending']??false)===true)
            throw new PublicUpdateException('routine_activation_in_progress',
                'An unresolved Routine or external Emergency operation must finish first.',409);
        if(($s['managed_tree_matches_expected']??null)!==true ||
           !hash_equals((string)($s['current_version']??''),
               (string)($s['active_version']??'')))
            throw new PublicUpdateException('routine_owner_not_reconciled',
                'Installed Public code and authoritative Routine ledger differ.',409);
        if($requireStaged&&!is_array($s['staged']??null))
            throw new PublicUpdateException('nothing_staged','No staged Routine package.',409);
    }

    private function requireNoPendingMigration(): void
    {
        try {$pending=($this->pendingMigrations)();}
        catch(Throwable) {
            throw new PublicUpdateException('migration_preflight_unavailable',
                'The Public migration inventory is not available.',503);
        }
        if(!is_array($pending) || !array_is_list($pending) || $pending!==[])
            throw new PublicUpdateException('db_forward_recovery_required',
                'Independent Emergency update cannot cross a pending DB boundary.',409);
    }

    /** @return list<string> */
    private function pendingVersions(): array
    {
        try{$pending=($this->pendingMigrations)();}
        catch(Throwable){throw new PublicUpdateException('migration_preflight_unavailable',
            'The Public schema inventory is not available.',503);}
        if(!is_array($pending)||!array_is_list($pending))
            throw new PublicUpdateException('migration_preflight_invalid',
                'The Public schema inventory is not an ordered list.',503);
        foreach($pending as $id){
            if(!is_string($id)||!preg_match('/^\d{4}_[a-z0-9_]{1,35}$/D',$id))
                throw new PublicUpdateException('migration_preflight_invalid',
                    'The Public schema inventory contains an invalid migration.',503);
        }
        if(count($pending)!==count(array_unique($pending)))
            throw new PublicUpdateException('migration_preflight_invalid',
                'Duplicate schema migrations are not accepted.',503);
        return $pending;
    }

    /**
     * Prove that the already-staged, immutable Routine ZIP contains only new,
     * append-only SQL files, no deletes/rewrites of published migrations.
     * All other component files remain under Routine's own exact SHA guards.
     *
     * @return array<string,string> mapping version => pinned SHA256
     */
    private function stagedMigrationPlan(bool $afterActivation): array
    {
        $staged=$this->routine->snapshot()['staged']??null;
        if(!is_array($staged))throw new PublicUpdateException('nothing_staged','No staged package.',409);
        $version=(string)($staged['version']??'');
        $digest=(string)($staged['package_sha256']??'');
        if(!preg_match('/^[A-Za-z0-9._+-]{1,128}$/D',$version)||
           !preg_match('/^[a-f0-9]{64}$/D',$digest))
            throw new PublicUpdateException('staged_package_identity_invalid',
                'Cannot attest the exact staged package identity.',409);
        $dir=rtrim($this->storageRoot,'/\\').'/routine-update-v2/staged/'.$version.'/'.$digest;
        $manifestPath=$dir.'/manifest.json';
        $packagePath=$dir.'/package.zip';
        $metaPath=$dir.'/package.json';
        if(!is_file($manifestPath)||!is_file($packagePath)||!is_file($metaPath))
            throw new PublicUpdateException('staged_package_missing',
                'Exact Routine staged package is incomplete.',409);
        try{
            $manifest=json_decode((string)file_get_contents($manifestPath),true,64,JSON_THROW_ON_ERROR);
            $meta=json_decode((string)file_get_contents($metaPath),true,32,JSON_THROW_ON_ERROR);
        }catch(Throwable){$manifest=null;$meta=null;}
        if(!is_array($manifest)||!is_array($meta)||
           ($manifest['format']??null)!=='sokna-public-delta-v2'||
           ($manifest['version']??null)!==$version||
           ($meta['package_sha256']??null)!==$digest||
           !hash_equals($digest,(string)hash_file('sha256',$packagePath))||
           !is_array($manifest['files']??null)||!array_is_list($manifest['files'])||
           !is_array($manifest['deletes']??null)||!array_is_list($manifest['deletes']))
            throw new PublicUpdateException('staged_manifest_unverified',
                'The immutable staged package manifest cannot be attested.',409);
        foreach($manifest['deletes'] as $rel){
            if(!is_string($rel)||$rel===''||str_starts_with($rel,'database/migrations/'))
                throw new PublicUpdateException('emergency_schema_mutation_not_qualified',
                    'A Public emergency release cannot delete schema migrations.',409);
        }
        $plan=[];$maxExisting=0;
        foreach(glob(rtrim($this->routineRoot(),'/').'/database/migrations/*.sql')?:[] as $existing){
            if(preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/D',basename($existing),$mm))
                $maxExisting=max($maxExisting,(int)$mm[1]);
        }
        foreach($manifest['files'] as $entry){
            $rel=is_array($entry)?($entry['path']??null):null;
            if(!is_string($rel)||$rel==='')
                throw new PublicUpdateException('staged_manifest_unverified','Invalid file path.',409);
            if(!str_starts_with($rel,'database/migrations/'))continue;
            if(!preg_match('#^database/migrations/(\d{4})_([a-z0-9_]{1,35})\.sql$#D',$rel,$matches)||
               !preg_match('/^[a-f0-9]{64}$/D',(string)($entry['sha256']??'')))
                throw new PublicUpdateException('emergency_schema_mutation_not_qualified',
                    'Schema upgrades must use a numbered immutable SQL catalog.',409);
            $id=$matches[1].'_'.$matches[2];
            if(isset($plan[$id]))throw new PublicUpdateException('emergency_schema_duplicate',
                'Duplicate migration identity in staged Public package.',409);
            $stagedPath=$dir.'/payload/'.$rel;
            if(!is_file($stagedPath)||!hash_equals((string)$entry['sha256'],(string)hash_file('sha256',$stagedPath)))
                throw new PublicUpdateException('emergency_staged_sql_hash_mismatch',
                    'Staged migration bytes differ from their pinned identity.',409);
            $installed=$this->routineRoot().'/'.$rel;
            if($afterActivation){
                if(!is_file($installed)||!hash_equals((string)$entry['sha256'],(string)hash_file('sha256',$installed)))
                    throw new PublicUpdateException('emergency_target_sql_mismatch',
                        'Activated SQL differs from the exact staged package.',409);
            }else{
                if(is_file($installed))throw new PublicUpdateException('emergency_schema_rewrite_forbidden',
                    'An existing Public SQL migration must never be rewritten.',409);
                if((int)$matches[1]<=$maxExisting)
                    throw new PublicUpdateException('emergency_nonappend_migration',
                        'Public migrations must be strictly forward-only.',409);
            }
            $plan[$id]=(string)$entry['sha256'];
        }
        ksort($plan,SORT_STRING);
        if(count($plan)>64)throw new PublicUpdateException('emergency_too_many_schema_changes',
            'Public schema change exceeds one bounded release.',409);
        return $plan;
    }

    /** Standard Public component root, relative to src/Update in every install. */
    private function routineRoot(): string
    {
        return dirname(__DIR__,2);
    }
}
