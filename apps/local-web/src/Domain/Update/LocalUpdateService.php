<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Update;
use Sokna\Local\Core\Observability;
use Sokna\Local\Core\Migrations;
use Sokna\Local\Core\MigrationExecutionException;
use Throwable;
use ZipArchive;

final class LocalUpdateService
{
    private const FORMAT='sokna-component-package-v1';
    private const STATE_FORMAT='sokna-local-update-state-v1';
    private const BINDING_NAME='.sokna-installation.json';
    private const BINDING_FORMAT='sokna-local-installation-binding-v1';
    private const MAX_FILES=12000;
    private const MAX_EXPANDED=536870912;
    public function __construct(
        private readonly Observability $observability,
        private readonly Migrations $migrations,
        private readonly string $packageRoot,
        private readonly string $localWebRoot,
        private readonly string $compatibilityFile,
        private readonly string $trustFile,
    ){}
    public function snapshot(): array
    {
        $s=$this->state();$staged=(array)($s['staged']??[]);$allHistory=(array)($s['history']??[]);$history=array_slice(array_reverse($allHistory),0,30);$lastFailure=is_array($s['last_failure']??null)?$s['last_failure']:$this->legacyFailureFromHistory($allHistory);
        return [
            'current_version'=>$this->currentVersion(),'active_version'=>(string)($s['active_version']??$this->currentVersion()),
            'previous_version'=>(string)($s['previous_version']??''),'lkg_recovery_id'=>(string)($s['lkg_recovery_id']??''),
            'staged'=>$staged,'history'=>$history,'last_failure'=>$lastFailure,'signature_policy'=>(string)($this->trust()['signature_policy']??'unknown'),
            'update_protocol'=>4,'legacy_local_blocked'=>LegacySlotUpdateGuard::isSlotManaged($this->localWebRoot),'activation_journal'=>$s['activation_journal']??null,'managed_tree_sha256'=>$this->managedTreeDigest(),'package_modes'=>['legacy_full_bridge','delta_v2'],
            'stable_recovery'=>['path'=>'/local-recovery.php','ready'=>is_file($this->localWebRoot.'/public/local-recovery.php'),'token_configured'=>is_file($this->tokenFile())],
            'rollback'=>$this->rollbackSafety($s),
            'recovery_mode'=>$this->recoveryMode(),
        ];
    }


    public function stageUploadedZip(string $zipPath,int $actorId=0): array
    {
        LegacySlotUpdateGuard::requireLegacyLayout($this->localWebRoot);
        $meta=$this->verifyZip($zipPath);$manifest=(array)$meta['manifest'];$version=(string)$manifest['version'];$this->assertCompatible($manifest);$dbPreflight=$this->assertMigrationLineage($manifest);$hash=(string)hash_file('sha256',$zipPath);
        $target=$this->stagePackageDir($version,$hash);
        if(is_dir($target)){
            $existing=$this->readJson($target.'/package.json');
            if(!hash_equals((string)($existing['package_sha256']??''),$hash))throw new LocalUpdateException('staged_integrity_failed','هویت Stage با بسته انتخاب‌شده یکسان نیست.',409,['version'=>$version]);
        }else{
            $tmp=$target.'.staging-'.bin2hex(random_bytes(4));$this->removeTree($tmp);$this->mkdir($tmp.'/payload');
            $this->extractPayload($zipPath,$tmp.'/payload',(array)$manifest['files']);
            if(!@copy($zipPath,$tmp.'/package.zip')){$this->removeTree($tmp);throw new LocalUpdateException('stage_failed','کپی بسته به Stage کامل نشد.',500,['version'=>$version]);}
            $this->writeJson($tmp.'/manifest.json',$manifest);
            $this->writeJson($tmp.'/package.json',['package_sha256'=>$hash,'signature_status'=>$meta['signature_status'],'version'=>$version,'staged_at'=>gmdate('c')]);
            $this->verifyDirectory($tmp.'/payload',(array)$manifest['files']);
            $this->mkdir(dirname($target));
            if(!@rename($tmp,$target)){ $this->removeTree($tmp); throw new LocalUpdateException('stage_failed','Stage بسته کامل نشد.',500,['version'=>$version]); }
        }
        $s=$this->state();$s['staged']=[
            'version'=>$version,'package_sha256'=>$hash,'source_commit'=>(string)$manifest['source_commit'],
            'signature_status'=>$meta['signature_status'],'database_preflight'=>$dbPreflight,'package_mode'=>$this->packageMode($manifest),
            'stage_dir'=>$this->relativeLifecyclePath($target),'status'=>'ready','staged_at'=>gmdate('c')
        ];
        $this->event($s,'stage',$version,$actorId,['package_sha256'=>$hash,'stage_dir'=>$s['staged']['stage_dir']]);$this->save($s);
        $this->observability->logEvent('warning','update.local_staged',['version'=>$version,'actor_user_id'=>$actorId,'package_sha256'=>$hash,'signature_status'=>$meta['signature_status']]);
        $this->pruneStageStore($target);
        return $s['staged'];
    }
    public function activateStaged(int $actorId=0): array
    {
        LegacySlotUpdateGuard::requireLegacyLayout($this->localWebRoot);
        $this->reconcileInterruptedActivation($actorId);
        $s=$this->state();$staged=(array)($s['staged']??[]);$version=(string)($staged['version']??'');if($version==='')throw new LocalUpdateException('nothing_staged','بسته‌ای برای فعال‌سازی آماده نیست.',409);
        $dir=$this->resolveStagedDir($staged);$manifest=$this->readJson($dir.'/manifest.json');$this->assertCompatible($manifest);$this->verifyDirectory($dir.'/payload',(array)($manifest['files']??[]));
        if($version===$this->currentVersion() && !$this->recoveryMode()['required'])throw new LocalUpdateException('same_version_requires_repair','برای نسخه فعلی از Repair استفاده کن.',409,['version'=>$version]);
        $this->assertMigrationLineage($manifest);$old=$this->currentVersion();$applied=[];$phase='prepare';$recovery=null;$appliedBefore=$this->migrations->appliedVersions();
        $tx=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        $s['activation_journal']=['transaction_id'=>$tx,'version'=>$version,'previous_version'=>$old,'phase'=>$phase,'started_at'=>gmdate('c'),'package_sha256'=>(string)($staged['package_sha256']??''),'applied_before'=>$appliedBefore];$this->save($s);
        try{
            $recovery=$this->createRecoveryPoint($actorId,'before_activate_'.$version,$manifest);
            $phase='deploy';$this->updateJournalPhase($tx,$phase,['recovery_id'=>(string)$recovery['id']]);
            $this->deployPayload($dir.'/payload',$manifest);
            $phase='migrations';$this->updateJournalPhase($tx,$phase);$applied=$this->migrateAndVerify();
            $phase='health';$this->updateJournalPhase($tx,$phase,['migrations'=>$applied]);$this->healthCheck();
        }catch(Throwable $e){
            $dbState=$this->databaseFailureState($appliedBefore,$e,$phase);
            $failure=$this->failureRecord($e,$phase,$version,$old,$dbState['completed_migrations'],(string)($recovery['id']??''));$failure['transaction_id']=$tx;$failure['database_state']=$dbState;
            $rollback=['ok'=>false,'attempted'=>false,'recovery_id'=>(string)($recovery['id']??''),'restored_version'=>$old];
            if(is_array($recovery)&&!empty($recovery['id'])&&$phase==='deploy'){
                $rollback['attempted']=true;
                try{$meta=$this->restoreRecoveryPoint((string)$recovery['id']);$rollback['ok']=true;$rollback['restored_version']=(string)($meta['version']??$old);$this->refreshInstallationBinding();}
                catch(Throwable $rollbackError){$rollback['error']=$this->failureCause($rollbackError);}
            }elseif(in_array($phase,['migrations','health'],true)){
                // MariaDB DDL is not transactionally reversible in the general case. Once the
                // migration phase starts, restoring old Web files can strand a newer/partial DB
                // behind an older updater. Keep the deployed target Web tree and enter a bounded
                // browser recovery mode instead. A corrective delta can then be uploaded from the
                // normal Update Center without CMD/PowerShell/SQL.
                $rollback['reason']='forward_database_state_may_exist';$rollback['recovery_required']=true;$rollback['retained_web']=true;$rollback['ok']=null;
                $this->markUpgradeRecoveryRequired($version,$old,$phase,$tx,(string)($recovery['id']??''),$dbState);
            }
            $failure['rollback']=$rollback;$s=$this->state();$s['last_failure']=$failure;$s['activation_journal']=null;
            if(in_array($phase,['migrations','health'],true)){$s['previous_version']=$old;$s['active_version']=$version;$s['recovery_mode']=['required'=>true,'target_version'=>$version,'previous_version'=>$old,'phase'=>$phase,'transaction_id'=>$tx,'recovery_id'=>(string)($recovery['id']??'')];}
            if((string)(($s['staged']??[])['version']??'')===$version){$s['staged']['status']=in_array($phase,['migrations','health'],true)?'recovery_required':'failed';$s['staged']['failed_at']=gmdate('c');$s['staged']['failure_support_id']=$failure['support_id'];}
            $this->event($s,'activate_failed',$version,$actorId,['result'=>'failed','failure'=>$failure]);
            if($rollback['attempted'])$this->event($s,'rollback_auto',$version,$actorId,['result'=>$rollback['ok']?'success':'failed','failure_support_id'=>$failure['support_id'],'recovery_id'=>(string)($recovery['id']??'')]);
            if(!empty($rollback['retained_web']))$this->event($s,'recovery_mode_entered',$version,$actorId,['result'=>'success','failure_support_id'=>$failure['support_id'],'transaction_id'=>$tx,'previous_version'=>$old]);
            $this->save($s);$this->observability->logEvent('error','update.local_activation_failed',$failure,$this->observability->correlationId());
            if($phase==='prepare')throw new LocalUpdateException('recovery_preflight_failed','به‌روزرسانی پیش از هر تغییری متوقف شد: '.$failure['cause_message'],500,['failure'=>$failure]);
            if(!empty($rollback['retained_web']))throw new LocalUpdateException('activation_failed_recovery_mode','نصب در مرحله تغییرات دیتابیس متوقف شد. فایل‌های نسخه جدید برای بازیابی امن نگه داشته شده‌اند و سامانه فقط در حالت نگهداری است؛ بسته اصلاحی بعدی را از همین صفحه به‌روزرسانی نصب کن.',500,['failure'=>$failure,'recovery_mode'=>$this->recoveryMode()]);
            if($rollback['attempted']&&!$rollback['ok'])throw new LocalUpdateException('activation_failed_rollback_failed','نصب کامل نشد و بازگشت خودکار هم کامل نشد. از صفحه بازیابی استفاده کن.',500,['failure'=>$failure]);
            throw new LocalUpdateException('activation_failed',$this->failureMessage($failure),500,['failure'=>$failure]);
        }
        $s=$this->state();$s['previous_version']=$old;$s['active_version']=$version;$s['lkg_recovery_id']=(string)$recovery['id'];$s['staged']=null;$s['last_failure']=null;$s['activation_journal']=null;$s['recovery_mode']=null;
        $this->event($s,'activate',$version,$actorId,['previous'=>$old,'recovery_id'=>$recovery['id'],'migrations'=>$applied,'transaction_id'=>$tx]);$this->save($s);
        $this->removeTree($dir);$this->pruneRecoveryPoints((string)$recovery['id']);$this->pruneStageStore('');
        $this->clearUpgradeRecoveryRequired();
        $this->observability->logEvent('warning','update.local_activated',['version'=>$version,'previous'=>$old,'actor_user_id'=>$actorId,'recovery_id'=>$recovery['id'],'transaction_id'=>$tx]);
        return $this->lifecycleReceipt('activate',$version,$old,(string)$recovery['id'],$tx,$applied);
    }
    public function repairStaged(int $actorId=0): array
    {
        LegacySlotUpdateGuard::requireLegacyLayout($this->localWebRoot);
        $this->reconcileInterruptedActivation($actorId);
        $s=$this->state();$version=(string)(($s['staged']??[])['version']??'');if($version===''||$version!==$this->currentVersion())throw new LocalUpdateException('repair_requires_same_version','Repair فقط برای بسته stage‌شده همان نسخه فعال مجاز است.',409);
        $staged=(array)($s['staged']??[]);$dir=$this->resolveStagedDir($staged);$manifest=$this->readJson($dir.'/manifest.json');$this->verifyDirectory($dir.'/payload',(array)$manifest['files']);$this->assertMigrationLineage($manifest);if($this->packageMode($manifest)==='delta_v2')throw new LocalUpdateException('delta_repair_unsupported','Repair برای بسته delta مجاز نیست؛ از بسته اصلاحی نسخه بعدی استفاده کن.',409);
        $old=$this->currentVersion();$recovery=$this->createRecoveryPoint($actorId,'before_repair_'.$version,$manifest);$applied=[];$phase='deploy';$appliedBefore=$this->migrations->appliedVersions();
        try{$this->deployPayload($dir.'/payload',$manifest);$phase='migrations';$applied=$this->migrateAndVerify();$phase='health';$this->healthCheck();}
        catch(Throwable $e){
            $dbState=$this->databaseFailureState($appliedBefore,$e,$phase);$failure=$this->failureRecord($e,$phase,$version,$old,$dbState['completed_migrations'],(string)$recovery['id'],'repair');$failure['database_state']=$dbState;
            $rollback=['ok'=>false,'attempted'=>false,'recovery_id'=>(string)$recovery['id'],'restored_version'=>$old];
            if($phase==='deploy'){
                $rollback['attempted']=true;try{$meta=$this->restoreRecoveryPoint((string)$recovery['id']);$rollback['ok']=true;$rollback['restored_version']=(string)($meta['version']??$old);$this->refreshInstallationBinding();}catch(Throwable $rollbackError){$rollback['error']=$this->failureCause($rollbackError);}
            }else{
                $rollback['reason']='forward_database_state_may_exist';$rollback['recovery_required']=true;$rollback['retained_web']=true;$rollback['ok']=null;$this->markUpgradeRecoveryRequired($version,$old,$phase,'',(string)$recovery['id'],$dbState);
            }
            $failure['rollback']=$rollback;$s=$this->state();$s['last_failure']=$failure;if(!empty($rollback['retained_web']))$s['recovery_mode']=['required'=>true,'target_version'=>$version,'previous_version'=>$old,'phase'=>$phase,'transaction_id'=>'','recovery_id'=>(string)$recovery['id']];$this->event($s,'repair_failed',$version,$actorId,['result'=>'failed','failure'=>$failure]);$this->save($s);
            if(!empty($rollback['retained_web']))throw new LocalUpdateException('repair_failed_recovery_mode','ترمیم در مرحله تغییرات دیتابیس متوقف شد. فایل‌های فعلی برای بازیابی امن نگه داشته شده‌اند؛ بسته اصلاحی بعدی را از Update Center نصب کن.',500,['failure'=>$failure,'recovery_mode'=>$this->recoveryMode()]);
            if($rollback['attempted']&&!$rollback['ok'])throw new LocalUpdateException('repair_failed_rollback_failed','ترمیم کامل نشد و بازگشت خودکار هم کامل نشد.',500,['failure'=>$failure]);
            throw new LocalUpdateException('repair_failed',$this->failureMessage($failure,'ترمیم'),500,['failure'=>$failure]);
        }
        $s=$this->state();$s['staged']=null;$s['lkg_recovery_id']=(string)$recovery['id'];$s['last_failure']=null;$s['recovery_mode']=null;$this->event($s,'repair',$version,$actorId,['recovery_id'=>$recovery['id'],'migrations'=>$applied]);$this->save($s);$this->removeTree($dir);$this->pruneRecoveryPoints((string)$recovery['id']);$this->pruneStageStore('');$this->clearUpgradeRecoveryRequired();$this->observability->logEvent('warning','update.local_repaired',['version'=>$version,'actor_user_id'=>$actorId]);return $this->lifecycleReceipt('repair',$version,$old,(string)$recovery['id'],'',$applied);
    }
    public function discardStaged(int $actorId=0): array
    {
        LegacySlotUpdateGuard::requireLegacyLayout($this->localWebRoot);
        $s=$this->state();$staged=(array)($s['staged']??[]);$version=trim((string)($staged['version']??''));if($version==='')throw new LocalUpdateException('nothing_staged','بسته‌ای برای کنار گذاشتن آماده نیست.',409);
        if(($this->recoveryMode()['required']??false)===true)throw new LocalUpdateException('staged_evidence_required','سامانه در حالت بازیابی به‌روزرسانی است و Stage فعلی بخشی از شواهد بازیابی است؛ آن را حذف نکن و بسته اصلاحی نسخه بعدی را Stage کن.',409,['version'=>$version]);
        $last=is_array($s['last_failure']??null)?$s['last_failure']:null;$forward=is_array($last)?array_values((array)($last['forward_migrations']??[])):[];
        if(is_array($last)&&(string)($last['target_version']??$last['version']??'')===$version&&$forward!==[])throw new LocalUpdateException('staged_evidence_required','این Stage مربوط به نصب ناموفقی است که migration اجرا کرده است و فعلاً برای تطبیق امن تاریخچه نگه داشته می‌شود. ابتدا بستهٔ سازگار بعدی را نصب کن.',409,['version'=>$version,'forward_migrations'=>$forward]);
        $dir=$this->resolveStagedDir($staged);$this->removeTree($dir);$s['staged']=null;$this->event($s,'stage_discard',$version,$actorId);$this->save($s);$this->pruneStageStore('');$this->observability->logEvent('warning','update.local_stage_discarded',['version'=>$version,'actor_user_id'=>$actorId]);return $this->snapshot();
    }


    public function rollback(int $actorId=0): array
    {
        LegacySlotUpdateGuard::requireLegacyLayout($this->localWebRoot);
        $s=$this->state();$id=(string)($s['lkg_recovery_id']??'');if($id==='')throw new LocalUpdateException('no_recovery_point','نقطه بازگشت معتبری وجود ندارد.',409);
        $safety=$this->rollbackSafety($s);if(($safety['allowed']??false)!==true)throw new LocalUpdateException('rollback_forward_migrations_unsafe','بازگشت کد به نسخه قبلی پس از اجرای migrationهای رو‌به‌جلو ایمن نیست. از نسخه اصلاحی بعدی استفاده کن.',409,['rollback'=>$safety]);
        $from=$this->currentVersion();$meta=$this->restoreRecoveryPoint($id);$to=(string)($meta['version']??'unknown');$s=$this->state();$s['active_version']=$to;$s['previous_version']=$from;$this->event($s,'rollback',$to,$actorId,['from'=>$from,'recovery_id'=>$id]);$this->save($s);$this->observability->logEvent('critical','update.local_rollback',['from'=>$from,'to'=>$to,'actor_user_id'=>$actorId,'recovery_id'=>$id]);return $this->lifecycleReceipt('rollback',$to,$from,$id,'',[]);
    }


    private function rollbackSafety(array $state): array
    {
        $mode=$this->recoveryMode();if(($mode['required']??false)===true)return ['allowed'=>false,'reason'=>'forward_database_state_may_exist','migrations'=>array_values((array)(($mode['database_state']??[])['completed_migrations']??[])),'recovery_id'=>(string)($mode['recovery_id']??'')];
        $id=trim((string)($state['lkg_recovery_id']??''));if($id==='')return ['allowed'=>false,'reason'=>'no_recovery_point','migrations'=>[]];
        foreach(array_reverse((array)($state['history']??[])) as $entry){
            if(!is_array($entry)||(string)($entry['action']??'')!=='activate'||(string)($entry['recovery_id']??'')!==$id)continue;
            $migrations=array_values(array_filter((array)($entry['migrations']??[]),static fn($v):bool=>is_string($v)&&$v!==''));
            if($migrations!==[])return ['allowed'=>false,'reason'=>'forward_migrations_applied','migrations'=>$migrations,'recovery_id'=>$id];
            return ['allowed'=>true,'reason'=>'code_only_activation','migrations'=>[],'recovery_id'=>$id];
        }
        return ['allowed'=>true,'reason'=>'legacy_recovery_unknown_migrations','migrations'=>[],'recovery_id'=>$id];
    }

    /** @param list<string> $migrations */
    private function lifecycleReceipt(string $action,string $activeVersion,string $previousVersion,string $recoveryId,string $transactionId,array $migrations): array
    {
        return [
            'format'=>'sokna-local-lifecycle-receipt-v1',
            'action'=>$action,
            'active_version'=>$activeVersion,
            'previous_version'=>$previousVersion,
            'recovery_id'=>$recoveryId,
            'transaction_id'=>$transactionId,
            'migrations'=>array_values($migrations),
            'completed_at'=>gmdate('c'),
        ];
    }

    public function rotateRecoveryCode(int $actorId=0): array
    {
        $plain=strtoupper(implode('-',str_split(bin2hex(random_bytes(12)),8)));$this->writeJson($this->tokenFile(),['format'=>'sokna-local-recovery-token-v1','hash'=>password_hash($plain,PASSWORD_DEFAULT),'rotated_at'=>gmdate('c'),'actor_user_id'=>$actorId]);@chmod($this->tokenFile(),0600);
        $this->observability->logEvent('warning','update.recovery_code_rotated',['actor_user_id'=>$actorId]);return ['recovery_code'=>$plain,'path'=>'/local-recovery.php'];
    }

    public function currentVersion(): string{$raw=$this->readJson($this->root().'/state.json');$active=trim((string)($raw['active_version']??''));if($active!=='')return $active;$v=@file_get_contents($this->packageRoot.'/VERSION.txt');return is_string($v)&&trim($v)!==''?trim($v):'unknown';}
    public function incomingPath(string $id): string{if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new LocalUpdateException('invalid_upload','شناسه فایل معتبر نیست.',422);$p=$this->root().'/incoming/'.$id.'.zip';if(!is_file($p))throw new LocalUpdateException('upload_missing','فایل آپلودشده پیدا نشد.',404);return $p;}
    public function incomingDir(): string{$p=$this->root().'/incoming';$this->mkdir($p);return $p;}

    private function verifyZip(string $path): array
    {
        if(!class_exists(ZipArchive::class))throw new LocalUpdateException('zip_unavailable','افزونه ZIP روی PHP فعال نیست.',500);if(!is_file($path))throw new LocalUpdateException('package_missing','بسته پیدا نشد.',404);
        $z=new ZipArchive();if($z->open($path)!==true)throw new LocalUpdateException('invalid_zip','فایل ZIP معتبر نیست.',422);
        try{
            $raw=$z->getFromName('manifest.json');if(!is_string($raw))throw new LocalUpdateException('manifest_missing','manifest.json داخل بسته وجود ندارد.',422);$manifest=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($manifest)||($manifest['format']??'')!==self::FORMAT||(int)($manifest['schema_version']??0)!==1||(string)($manifest['component']??'')!=='local')throw new LocalUpdateException('manifest_contract','قرارداد بسته Local معتبر نیست.',422);
            $version=trim((string)($manifest['version']??''));if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/',$version))throw new LocalUpdateException('invalid_version','نسخه بسته معتبر نیست.',422);
            $files=(array)($manifest['files']??[]);if($files===[]||count($files)>self::MAX_FILES)throw new LocalUpdateException('invalid_file_count','تعداد فایل‌های بسته معتبر نیست.',422);
            $expected=[];$expanded=0;foreach($files as $e){if(!is_array($e))throw new LocalUpdateException('manifest_contract','رکورد فایل معتبر نیست.',422);$rel=$this->safeRelative((string)($e['path']??''));$size=(int)($e['size']??-1);$hash=strtolower((string)($e['sha256']??''));if($size<0||!preg_match('/^[a-f0-9]{64}$/',$hash))throw new LocalUpdateException('manifest_contract','هش فایل معتبر نیست.',422);if(isset($expected[$rel]))throw new LocalUpdateException('duplicate_entry','مسیر تکراری در manifest مجاز نیست.',422,['file'=>$rel]);$expected[$rel]=[$size,$hash];$expanded+=$size;if($expanded>self::MAX_EXPANDED)throw new LocalUpdateException('package_too_large','حجم بازشده بسته بیش از حد مجاز است.',413);}
            $actual=[];for($i=0;$i<$z->numFiles;$i++){$name=(string)$z->getNameIndex($i);if($name==='manifest.json'||$name==='signature.json'||str_ends_with($name,'/'))continue;if(!str_starts_with($name,'payload/'))throw new LocalUpdateException('unexpected_entry','فایل خارج از payload داخل بسته وجود دارد.',422,['entry'=>$name]);$rel=$this->safeRelative(substr($name,8));if(isset($actual[$rel]))throw new LocalUpdateException('duplicate_entry','مسیر تکراری در ZIP مجاز نیست.',422,['file'=>$rel]);$actual[$rel]=true;}
            if(array_keys($expected)!==array_keys(array_intersect_key($expected,$actual))||count($actual)!==count($expected))throw new LocalUpdateException('payload_set_mismatch','فهرست payload با manifest یکسان نیست.',422);
            foreach($expected as $rel=>[$size,$hash]){$stream=$z->getStream('payload/'.$rel);if(!is_resource($stream))throw new LocalUpdateException('missing_file','فایل اعلام‌شده در payload نیست.',422,['file'=>$rel]);$ctx=hash_init('sha256');$read=0;while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false)break;$read+=strlen($buf);hash_update($ctx,$buf);}fclose($stream);if($read!==$size||!hash_equals($hash,hash_final($ctx)))throw new LocalUpdateException('hash_mismatch','هش یکی از فایل‌های بسته معتبر نیست.',422,['file'=>$rel]);}
            $updaterContract=(int)($manifest['updater_contract']??0);if($updaterContract<2&&$version!==$this->currentVersion())throw new LocalUpdateException('upgrade_contract_missing','این بسته برای ارتقای مستقیم با updater فعلی قرارداد لازم را ندارد.',409);if($updaterContract>=2){if($this->packageMode($manifest)==='delta_v2')UpdateMigrationPolicy::assertDeltaPayloadMatchesCatalog($manifest,$files);else UpdateMigrationPolicy::assertManifestMatchesFiles($manifest,$files);}
            if($this->packageMode($manifest)==='delta_v2'){$this->assertDeltaManifest($manifest,$files);$this->assertDeltaBaseline($manifest);}
            $this->assertCompatible($manifest);$signature=$this->verifySignature($z,$raw);return ['manifest'=>$manifest,'signature_status'=>$signature];
        }catch(LocalUpdateException $e){throw $e;}catch(Throwable){throw new LocalUpdateException('package_invalid','ساختار بسته update معتبر نیست.',422);}finally{$z->close();}
    }

    private function verifySignature(ZipArchive $z,string $manifestRaw): string
    {
        $trust=$this->trust();$sigRaw=$z->getFromName('signature.json');$required=(string)($trust['signature_policy']??'')==='required';if(!is_string($sigRaw)){if($required)throw new LocalUpdateException('signature_required','امضای بسته الزامی است.',422);return 'not_present_pre_release_allowed';}
        $sig=json_decode($sigRaw,true,16,JSON_THROW_ON_ERROR);$keyId=(string)($sig['key_id']??'');$alg=(string)($sig['algorithm']??'');$b64=(string)($sig['signature_base64']??'');$keys=(array)($trust['ed25519_public_keys']??[]);$pub=base64_decode((string)($keys[$keyId]??''),true);$raw=base64_decode($b64,true);
        if($alg!=='ed25519'||!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!is_string($raw)||strlen($raw)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($raw,$manifestRaw,$pub))throw new LocalUpdateException('bad_signature','امضای بسته معتبر نیست.',422);return 'verified_ed25519';
    }

    private function extractPayload(string $zipPath,string $target,array $files): void
    { $z=new ZipArchive();$z->open($zipPath);try{foreach($files as $e){$rel=$this->safeRelative((string)$e['path']);$dst=$target.'/'.$rel;$this->mkdir(dirname($dst));$in=$z->getStream('payload/'.$rel);$out=fopen($dst,'wb');if(!is_resource($in)||!is_resource($out))throw new LocalUpdateException('extract_failed','استخراج بسته کامل نشد.',500);stream_copy_to_stream($in,$out);fclose($in);fclose($out);}}finally{$z->close();}}
    private function assertCompatible(array $manifest): void
    { $compat=$this->readJson($this->compatibilityFile);$line=(string)($compat['release_line']??'');$incomingLine=(string)(($manifest['upgrade']??[])['release_line']??'');if((int)($manifest['updater_contract']??0)>=2&&($line===''||$incomingLine!==$line))throw new LocalUpdateException('incompatible_release_line','این بسته متعلق به خط انتشار سازگار با نصب فعلی نیست.',409);$req=(array)(($compat['components']['local']??[])['requires']??[]);$contracts=(array)($manifest['contracts']??[]);foreach($req as $k=>$want){if(in_array($k,['php_version_id','pdo_mysql'],true))continue;if(!array_key_exists($k,$contracts))throw new LocalUpdateException('missing_contract','قرارداد موردنیاز بسته وجود ندارد.',422,['contract'=>$k]);$actual=$contracts[$k];if(is_int($want)&&(int)$actual!==$want)throw new LocalUpdateException('incompatible_contract','نسخه قرارداد سازگار نیست.',409,['contract'=>$k]);if(is_string($want)&&str_starts_with($want,'>=')){if((string)explode('.',(string)$actual)[0]!=='1')throw new LocalUpdateException('incompatible_contract','نسخه قرارداد سازگار نیست.',409,['contract'=>$k]);}} }

    private function assertMigrationLineage(array $manifest): array
    {
        if((int)($manifest['updater_contract']??0)<2)return ['policy'=>'legacy-same-version','applied_count'=>0,'incoming_count'=>0,'pending_count'=>0];
        try{$incoming=UpdateMigrationPolicy::catalogFromManifest($manifest);$applied=$this->migrations->appliedVersions();$stats=$this->assertInstalledMigrationLineage($applied,$incoming);return ['policy'=>UpdateMigrationPolicy::POLICY]+$stats;}
        catch(LocalUpdateException $e){throw $e;}catch(Throwable $e){throw new LocalUpdateException('database_preflight_failed','بررسی سازگاری دیتابیس قبل از نصب کامل نشد: '.$this->cleanFailureMessage($e->getMessage()),503,['cause'=>get_class($e),'cause_message'=>$this->cleanFailureMessage($e->getMessage())]);}
    }

    /** @return list<string> */

    private function assertInstalledMigrationLineage(array $applied,array $incoming): array
    {
        $recovered=[];$installedDir=$this->packageRoot.'/database/migrations';
        foreach($applied as $raw){
            $version=(string)$raw;if(!isset($incoming[$version]))throw new LocalUpdateException('schema_downgrade_unsupported','این بسته از ساختار دیتابیس نصب فعلی قدیمی‌تر است و مستقیم قابل نصب نیست.',409,['migration'=>$version]);
            $path=$installedDir.'/'.$version.'.sql';
            if(is_file($path)){
                $hash=strtolower((string)hash_file('sha256',$path));
                if(!hash_equals($incoming[$version],$hash))throw new LocalUpdateException('migration_history_changed','تاریخچه migrationهای این نصب با بسته جدید یکسان نیست؛ نصب مستقیم مجاز نیست.',409,['migration'=>$version]);
                continue;
            }
            $evidence=$this->retainedMigrationEvidence($version,$incoming[$version]);
            if($evidence===null)$evidence=$this->stagedMigrationEvidence($version,$incoming[$version]);
            if($evidence===null)throw new LocalUpdateException('migration_history_unverifiable','Migration اجراشده «'.$version.'» بعد از recovery در درخت فعال وجود ندارد و مدرک منطبق نیز پیدا نشد. نصب برای جلوگیری از تغییر تاریخچه متوقف شد.',409,['migration'=>$version]);
            $recovered[]=['migration'=>$version,'evidence'=>$evidence];
        }
        return ['applied_count'=>count($applied),'incoming_count'=>count($incoming),'pending_count'=>max(0,count($incoming)-count($applied)),'recovered_history_evidence'=>$recovered];
    }

    private function retainedMigrationEvidence(string $version,string $expectedHash): ?string
    {
        $path=$this->packageRoot.'/resources/update-recovery/migration-evidence/'.$version.'.sql';
        if(!is_file($path))return null;
        $hash=strtolower((string)hash_file('sha256',$path));
        if(!hash_equals(strtolower($expectedHash),$hash))return null;
        return 'retained-migration-evidence/'.$version;
    }

    private function stagedMigrationEvidence(string $version,string $expectedHash): ?string
    {
        $patterns=[
            $this->root().'/staged/*/payload/database/migrations/'.$version.'.sql',
            $this->root().'/staged-v2/*/*/payload/database/migrations/'.$version.'.sql',
        ];
        foreach($patterns as $pattern){
            foreach((glob($pattern)?:[]) as $path){
                if(!is_file($path))continue;$hash=strtolower((string)hash_file('sha256',$path));
                if(hash_equals($expectedHash,$hash)){
                    $relative=str_replace('\\','/',substr($path,strlen($this->root())+1));
                    return dirname(dirname(dirname($relative))).'/'.$version;
                }
            }
        }
        return null;
    }

    private function migrateAndVerify(): array
    {
        try{$applied=$this->migrations->migrate();$catalog=array_keys($this->migrations->catalog());$done=$this->migrations->appliedVersions();$pending=array_values(array_diff($catalog,$done));if($pending!==[])throw new LocalUpdateException('migration_incomplete','ساختار دیتابیس بعد از به‌روزرسانی کامل نشد.',500,['pending'=>$pending]);return $applied;}
        catch(LocalUpdateException $e){throw $e;}
        catch(MigrationExecutionException $e){
            $label=$e->migrationVersion.' / statement '.$e->statementOrdinal;
            $db=$e->databaseMessage;$suffix=$e->mysqlErrno>0?' (MySQL '.$e->mysqlErrno.')':'';
            throw new LocalUpdateException('migration_failed','Migration '.$label.' اجرا نشد'.$suffix.': '.$this->cleanFailureMessage($db),500,['migration'=>$e->migrationVersion,'statement'=>$e->statementOrdinal,'mysql_errno'=>$e->mysqlErrno,'sql_state'=>$e->sqlState,'database_message'=>$this->cleanFailureMessage($db)]);
        }
        catch(Throwable $e){throw new LocalUpdateException('migration_failed','اجرای تغییرات دیتابیس نسخه جدید کامل نشد: '.$this->cleanFailureMessage($e->getMessage()),500,['cause'=>get_class($e),'cause_message'=>$this->cleanFailureMessage($e->getMessage())]);}
    }


    private function createRecoveryPoint(int $actorId,string $reason,?array $manifest=null): array
    {
        $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$dir=$this->root().'/recovery-points/'.$id;$payload=$dir.'/payload';$this->mkdir($payload);
        $managedFiles=$this->currentManagedInventory();$managedTree=$this->managedTreeDigest();$managedSet=array_fill_keys($managedFiles,true);
        $paths=[];
        if(is_array($manifest)&&$this->packageMode($manifest)==='delta_v2'){
            foreach((array)($manifest['files']??[]) as $entry){$rel=$this->safeRelative((string)($entry['path']??''));if(!$this->stableManagedPath($rel))$paths[$rel]=true;}
            foreach((array)($manifest['deletes']??[]) as $raw){$rel=$this->safeRelative((string)$raw);if(!$this->stableManagedPath($rel))$paths[$rel]=true;}
        }else{
            foreach($managedFiles as $rel)$paths[$rel]=true;
            if($paths===[]){
                foreach(array_keys($this->relativeFileSet($this->localWebRoot)) as $rel)if($this->isCanonicalManagedPath($rel)&&!$this->stableManagedPath($rel))$paths[$rel]=true;
            }
        }
        ksort($paths,SORT_STRING);$copied=[];$absent=[];
        try{
            foreach(array_keys($paths) as $rel){
                $from=$this->localWebRoot.'/'.$rel;
                if(!is_file($from)){ $absent[]=$rel; continue; }
                $to=$payload.'/'.$rel;$this->mkdir(dirname($to));$this->copyFileReliable($from,$to,$rel,'recovery_snapshot');$copied[]=$rel;
            }
            $files=[];foreach($copied as $rel){$p=$payload.'/'.$rel;$files[]=['path'=>$rel,'size'=>(int)filesize($p),'sha256'=>(string)hash_file('sha256',$p)];}
            $meta=[
                'format'=>'sokna-local-recovery-point-v2','schema_version'=>2,'id'=>$id,'version'=>$this->currentVersion(),
                'created_at'=>gmdate('c'),'reason'=>$reason,'actor_user_id'=>$actorId,'files'=>$files,
                'affected_files'=>array_keys($paths),'absent_before'=>$absent,'managed_files'=>$managedFiles,'managed_tree_sha256'=>$managedTree,
                'mode'=>is_array($manifest)&&$this->packageMode($manifest)==='delta_v2'?'differential':'managed_snapshot'
            ];
            $this->writeJson($dir.'/manifest.json',$meta);return $meta;
        }catch(Throwable $e){
            $this->removeTree($dir);
            if($e instanceof LocalUpdateException)throw $e;
            throw new LocalUpdateException('recovery_snapshot_failed','ساخت نقطه بازگشت قبل از نصب کامل نشد.',500,['cause'=>$this->diagnosticCause($e)]);
        }
    }

    private function restoreRecoveryPoint(string $id): array
    {
        if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/',$id))throw new LocalUpdateException('invalid_recovery_id','شناسه نقطه بازگشت معتبر نیست.',422);
        $dir=$this->root().'/recovery-points/'.$id;$m=$this->readJson($dir.'/manifest.json');$format=(string)($m['format']??'');
        if($format==='sokna-local-recovery-point-v2'){
            $payload=$dir.'/payload';$this->verifyRecoveryPayload($payload,(array)($m['files']??[]));
            $copied=[];foreach((array)($m['files']??[]) as $entry){$rel=$this->safeRelative((string)($entry['path']??''));$from=$payload.'/'.$rel;$to=$this->localWebRoot.'/'.$rel;$this->mkdir(dirname($to));$this->replaceFileReliable($from,$to,$rel,'recovery_restore');$copied[$rel]=true;}
            foreach((array)($m['absent_before']??[]) as $raw){$rel=$this->safeRelative((string)$raw);if(isset($copied[$rel]))continue;$target=$this->localWebRoot.'/'.$rel;if(is_file($target)||is_link($target))$this->unlinkReliable($target,$rel,'recovery_remove_new');}
            $managedFiles=(array)($m['managed_files']??[]);$this->writeManagedInventory($managedFiles);
            $expected=strtolower(trim((string)($m['managed_tree_sha256']??'')));$actual=$this->managedTreeDigest();
            if($expected!==''&&preg_match('/^[a-f0-9]{64}$/D',$expected)&&!hash_equals($expected,$actual))throw new LocalUpdateException('recovery_tree_mismatch','بازگشت فایل‌ها انجام شد اما inventory فایل‌های مدیریت‌شده با نقطه بازیابی یکسان نیست.',500,['expected'=>$expected,'actual'=>$actual,'recovery_id'=>$id]);
            $this->healthCheck();return $m;
        }
        if($format!=='sokna-local-recovery-point-v1')throw new LocalUpdateException('recovery_missing','نقطه بازگشت معتبر نیست.',404);
        $payload=$dir.'/payload';$this->verifyDirectory($payload,(array)$m['files']);
        $managedFiles=$this->recoveryManagedInventory($m,$payload);$expected=$this->recoveryManagedTreeDigest($m,$payload,$managedFiles);
        $this->deployPayload($payload,null);$this->writeManagedInventory($managedFiles);
        $actual=$this->managedTreeDigest();if($expected!==''&&!hash_equals($expected,$actual))throw new LocalUpdateException('recovery_tree_mismatch','بازگشت فایل‌ها انجام شد اما inventory فایل‌های مدیریت‌شده با نقطه بازیابی یکسان نیست.',500,['expected'=>$expected,'actual'=>$actual,'recovery_id'=>$id]);
        $this->healthCheck();return $m;
    }
    private function deployPayload(string $source,?array $manifest=null): void
    {
        if(is_array($manifest)&&$this->packageMode($manifest)==='delta_v2'){$this->applyDeltaPayload($source,$manifest);return;}
        // The Local Web updates itself while Apache/PHP is serving files from
        // $localWebRoot. Renaming the live root works on many Unix systems but
        // fails reliably on Windows because the active directory/file handles
        // cannot be moved. Deploy in-place instead and rely on the recovery
        // point created before every activate/repair for transactional rollback.
        //
        // Stale-file removal is intentionally based only on the previous
        // managed-file inventory. On the first migration from the legacy
        // root-swap updater there is no inventory, so unknown/machine-local
        // files are preserved rather than guessed and deleted.
        if(!is_dir($source))throw new LocalUpdateException('payload_missing','Payload نسخه برای استقرار پیدا نشد.',500);

        $newFiles=$this->relativeFileSet($source);
        // The installation binding is machine-local state. It must never be
        // owned by an update payload or recovery snapshot.
        unset($newFiles[self::BINDING_NAME]);
        $managedPath=$this->root().'/managed-files.json';
        $managed=$this->readJson($managedPath);
        $oldFiles=[];
        foreach((array)($managed['files']??[]) as $rel){
            if(is_string($rel)&&$rel!==''&&!in_array($rel,['config.php','install.lock','public/local-recovery.php','public/local-update-runner.php',self::BINDING_NAME],true))$oldFiles[$rel]=true;
        }

        // Write/replace new managed files. PHP source files are no longer open
        // after parsing, so Windows permits overwriting them even though this
        // request itself is running from the same application tree.
        foreach(array_keys($newFiles) as $rel){
            $from=$source.'/'.$rel;$to=$this->localWebRoot.'/'.$rel;$this->mkdir(dirname($to));
            $this->replaceFileReliable($from,$to,$rel,'managed_apply');
        }

        // Remove only files that a previous successful in-place deployment
        // explicitly owned and that no longer exist in the new payload.
        foreach(array_keys($oldFiles) as $rel){
            if(isset($newFiles[$rel]))continue;
            $target=$this->localWebRoot.'/'.$rel;
            if(is_file($target)||is_link($target))$this->unlinkReliable($target,$rel,'managed_delete');
        }
        $this->removeEmptyDirectories($this->localWebRoot);

        $this->writeJson($managedPath,[
            'format'=>'sokna-local-managed-files-v1',
            'schema_version'=>1,
            'updated_at'=>gmdate('c'),
            'files'=>array_keys($newFiles),
        ]);
        $this->refreshInstallationBinding();
        $this->resetOpcodeCache();
    }


    private function packageMode(array $manifest): string
    {
        $contract=(int)($manifest['updater_contract']??0);$mode=(string)(($manifest['upgrade']??[])['mode']??'');
        return $contract>=3&&$mode==='delta-v2'?'delta_v2':'legacy_full_bridge';
    }

    private function assertDeltaManifest(array $manifest,array $files): void
    {
        $base=(array)($manifest['base']??[]);$target=(array)($manifest['target']??[]);
        if(trim((string)($base['version']??''))===''||!preg_match('/^[a-f0-9]{64}$/D',strtolower((string)($base['managed_tree_sha256']??'')))||!preg_match('/^[a-f0-9]{64}$/D',strtolower((string)($target['managed_tree_sha256']??''))))
            throw new LocalUpdateException('delta_contract_invalid','قرارداد baseline/target بسته delta معتبر نیست.',422);
        $paths=[];foreach($files as $e){$rel=$this->safeRelative((string)($e['path']??''));if(in_array($rel,['config.php','install.lock','public/local-recovery.php','public/local-update-runner.php',self::BINDING_NAME],true))throw new LocalUpdateException('stable_path_forbidden','بسته delta اجازه تغییر فایل پایدار/محلی را ندارد.',422,['path'=>$rel]);$paths[$rel]=true;}
        $seen=[];foreach((array)($manifest['deletes']??[]) as $raw){$rel=$this->safeRelative((string)$raw);if(isset($paths[$rel])||isset($seen[$rel])||in_array($rel,['config.php','install.lock','public/local-recovery.php','public/local-update-runner.php',self::BINDING_NAME],true))throw new LocalUpdateException('delta_delete_invalid','فهرست حذف بسته delta معتبر نیست.',422,['path'=>$rel]);$seen[$rel]=true;}
    }

    private function assertDeltaBaseline(array $manifest): void
    {
        $base=(array)($manifest['base']??[]);$wantVersion=trim((string)($base['version']??''));$wantTree=strtolower((string)($base['managed_tree_sha256']??''));
        if(!hash_equals($wantVersion,$this->currentVersion()))throw new LocalUpdateException('base_version_mismatch','این بسته delta برای نسخه نصب‌شده ساخته نشده است.',409,['expected'=>$wantVersion,'actual'=>$this->currentVersion()]);
        $actual=$this->managedTreeDigest();if($actual===''||!hash_equals($wantTree,$actual))throw new LocalUpdateException('base_tree_mismatch','فایل‌های مدیریت‌شده نصب فعلی با baseline بسته delta یکسان نیست.',409,['expected'=>$wantTree,'actual'=>$actual]);
    }

    public function managedTreeDigest(): string
    {
        $managed=$this->readJson($this->root().'/managed-files.json');$files=(array)($managed['files']??[]);if($files===[])return '';$map=[];
        foreach($files as $raw){if(!is_string($raw)||$raw==='')continue;$rel=$this->safeRelative($raw);if(in_array($rel,['config.php','install.lock','public/local-recovery.php','public/local-update-runner.php',self::BINDING_NAME],true))continue;$p=$this->localWebRoot.'/'.$rel;if(!is_file($p))return '';$map[$rel]=(string)hash_file('sha256',$p);}
        ksort($map,SORT_STRING);$h=hash_init('sha256');foreach($map as $rel=>$digest){hash_update($h,$rel);hash_update($h,"\0");hash_update($h,$digest);hash_update($h,"\n");}return hash_final($h);
    }

    /** Admin/support evidence only: paths, sizes and SHA-256 for lifecycle-managed application files. */
    public function managedInventory(): array
    {
        $rows=[];$missing=[];
        foreach($this->currentManagedInventory() as $rel){$p=$this->localWebRoot.'/'.$rel;if(!is_file($p)){$missing[]=$rel;continue;}$rows[]=['path'=>$rel,'size_bytes'=>(int)filesize($p),'sha256'=>(string)hash_file('sha256',$p)];}
        return ['count'=>count($rows),'missing'=>$missing,'managed_tree_sha256'=>$missing===[]?$this->managedTreeDigest():'','files'=>$rows];
    }


    private function applyDeltaPayload(string $source,array $manifest): void
    {
        if(!is_dir($source))throw new LocalUpdateException('payload_missing','Payload نسخه delta پیدا نشد.',500);$this->assertDeltaBaseline($manifest);
        $managedPath=$this->root().'/managed-files.json';$managed=$this->readJson($managedPath);$owned=[];foreach((array)($managed['files']??[]) as $raw)if(is_string($raw)&&$raw!=='')$owned[$this->safeRelative($raw)]=true;if($owned===[])throw new LocalUpdateException('delta_baseline_missing','نصب فعلی inventory لازم برای delta را ندارد؛ ابتدا bridge را نصب کن.',409);
        $entries=array_values((array)($manifest['files']??[]));
        $critical=['bootstrap.php','public/_app.php','public/index.php','public/system/api.php','public/update-rescue.php','src/Core/Bootstrap.php','src/Domain/Update/LocalUpdateService.php'];
        usort($entries,static function(array $a,array $b)use($critical):int{$ap=(string)($a['path']??'');$bp=(string)($b['path']??'');$ai=array_search($ap,$critical,true);$bi=array_search($bp,$critical,true);$aw=$ai===false?0:1;$bw=$bi===false?0:1;if($aw!==$bw)return $aw<=>$bw;if($aw===1&&$ai!==$bi)return $ai<=>$bi;return strcmp($ap,$bp);});
        foreach($entries as $entry){
            $rel=$this->safeRelative((string)($entry['path']??''));$from=$source.'/'.$rel;if(!is_file($from))throw new LocalUpdateException('payload_missing','فایل delta در stage پیدا نشد.',500,['file'=>$rel]);
            $to=$this->localWebRoot.'/'.$rel;$this->mkdir(dirname($to));$this->replaceFileReliable($from,$to,$rel,'delta_apply');$owned[$rel]=true;
        }
        foreach((array)($manifest['deletes']??[]) as $raw){$rel=$this->safeRelative((string)$raw);$target=$this->localWebRoot.'/'.$rel;if(is_file($target)||is_link($target))$this->unlinkReliable($target,$rel,'delta_delete');unset($owned[$rel]);}
        ksort($owned,SORT_STRING);$this->removeEmptyDirectories($this->localWebRoot);$this->writeJson($managedPath,['format'=>'sokna-local-managed-files-v1','schema_version'=>1,'updated_at'=>gmdate('c'),'files'=>array_keys($owned)]);
        $want=strtolower((string)(($manifest['target']??[])['managed_tree_sha256']??''));$actual=$this->managedTreeDigest();if($want===''||!hash_equals($want,$actual))throw new LocalUpdateException('target_tree_mismatch','هویت فایل‌های مدیریت‌شده بعد از delta با target یکسان نیست.',500,['expected'=>$want,'actual'=>$actual]);$this->refreshInstallationBinding();$this->resetOpcodeCache();
    }

    private function refreshInstallationBinding(): void
    {
        $root=dirname(rtrim($this->localWebRoot,'/\\'));
        $configPath=$root.DIRECTORY_SEPARATOR.'config.php';
        $lockPath=$root.DIRECTORY_SEPARATOR.'install.lock';
        if(!is_file($configPath)||!is_file($lockPath))return;
        try{
            $config=require $configPath;
            if(!is_array($config))return;
            $installationId=trim((string)($config['installation']['id']??''));
            if($installationId==='')return;
            $raw=@file_get_contents($lockPath);
            $lock=is_string($raw)?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;
            if(!is_array($lock)||($lock['format']??'')!=='sokna-install-lock-v3'||!hash_equals($installationId,trim((string)($lock['installation_id']??''))))return;
            $this->writeJson($this->localWebRoot.DIRECTORY_SEPARATOR.self::BINDING_NAME,[
                'format'=>self::BINDING_FORMAT,
                'installation_id'=>$installationId,
                'bound_at'=>gmdate('c'),
                'source'=>'local-update',
                'web_version'=>trim((string)@file_get_contents($this->localWebRoot.DIRECTORY_SEPARATOR.'VERSION.txt')),
            ]);
        }catch(Throwable $e){
            throw new LocalUpdateException('installation_binding_failed','اتصال نسخه جدید به نصب موجود کامل نشد و فعال‌سازی متوقف شد.',500);
        }
    }

    private function markUpgradeRecoveryRequired(string $targetVersion,string $previousVersion,string $phase,string $transactionId,string $recoveryId,array $databaseState=[]): void
    {
        // Do not unlink the installation binding here. After deploy completed the binding points
        // to the target Web tree, which is exactly the updater version that understands the
        // interrupted migration. Normal business routes are quarantined by public/_app.php while
        // /system/, login and the stable lifecycle runner remain available.
        $this->writeJson($this->root().'/recovery-required.json',[
            'format'=>'sokna-local-update-recovery-required-v2','created_at'=>gmdate('c'),
            'target_version'=>$targetVersion,'previous_version'=>$previousVersion,'phase'=>$phase,
            'transaction_id'=>$transactionId,'recovery_id'=>$recoveryId,'web_version'=>$this->webVersion(),
            'managed_tree_sha256'=>$this->managedTreeDigest(),'database_state'=>$databaseState,
        ]);
    }

    private function recoveryMode(): array
    {
        $marker=$this->readJson($this->root().'/recovery-required.json');
        if(!in_array((string)($marker['format']??''),['sokna-local-update-recovery-required-v1','sokna-local-update-recovery-required-v2'],true))return ['required'=>false];
        return ['required'=>true]+$marker;
    }

    private function webVersion(): string
    {
        $v=@file_get_contents($this->localWebRoot.'/VERSION.txt');return is_string($v)&&trim($v)!==''?trim($v):'unknown';
    }

    private function databaseFailureState(array $before,Throwable $e,string $phase): array
    {
        $after=$before;try{$after=$this->migrations->appliedVersions();}catch(Throwable){}
        $completed=array_values(array_diff($after,$before));$partial=null;
        if($e instanceof LocalUpdateException&&$e->errorCode==='migration_failed'){
            $migration=trim((string)($e->details['migration']??''));$statement=(int)($e->details['statement']??0);
            if($migration!=='')$partial=['migration'=>$migration,'statement'=>$statement,'may_have_committed_ddl'=>true];
        }
        return ['phase'=>$phase,'applied_before'=>$before,'applied_after'=>$after,'completed_migrations'=>$completed,'partial_migration'=>$partial,'database_may_be_forward'=>in_array($phase,['migrations','health'],true)];
    }

    private function clearUpgradeRecoveryRequired(): void
    {
        $path=$this->root().'/recovery-required.json';if(is_file($path))@unlink($path);
    }

    private function relativeFileSet(string $root): array
    {
        $files=[];$prefixLen=strlen(rtrim($root,'/\\'))+1;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $f)if($f->isFile()){
            $rel=str_replace('\\','/',substr($f->getPathname(),$prefixLen));
            $files[$this->safeRelative($rel)]=true;
        }
        ksort($files,SORT_STRING);return $files;
    }

    /** @return list<string> */
    private function currentManagedInventory(): array
    {
        $managed=$this->readJson($this->root().'/managed-files.json');$out=[];
        foreach((array)($managed['files']??[]) as $raw){if(!is_string($raw)||$raw==='')continue;$rel=$this->safeRelative($raw);if($this->stableManagedPath($rel))continue;$out[$rel]=true;}
        ksort($out,SORT_STRING);return array_keys($out);
    }

    /** @return list<string> */
    private function recoveryManagedInventory(array $manifest,string $payload): array
    {
        $stored=(array)($manifest['managed_files']??[]);$out=[];
        foreach($stored as $raw){if(!is_string($raw)||$raw==='')continue;$rel=$this->safeRelative($raw);if($this->stableManagedPath($rel))continue;if(is_file($payload.'/'.$rel))$out[$rel]=true;}
        if($out!==[]){ksort($out,SORT_STRING);return array_keys($out);}
        foreach(array_keys($this->relativeFileSet($payload)) as $rel){if($this->isCanonicalManagedPath($rel)&&!$this->stableManagedPath($rel))$out[$rel]=true;}
        ksort($out,SORT_STRING);return array_keys($out);
    }

    private function recoveryManagedTreeDigest(array $manifest,string $payload,array $managedFiles): string
    {
        $stored=strtolower(trim((string)($manifest['managed_tree_sha256']??'')));if(preg_match('/^[a-f0-9]{64}$/D',$stored))return $stored;
        return $this->managedTreeDigestForRoot($payload,$managedFiles);
    }

    private function managedTreeDigestForRoot(string $root,array $managedFiles): string
    {
        $map=[];foreach($managedFiles as $rel){$rel=$this->safeRelative((string)$rel);$p=$root.'/'.$rel;if(!is_file($p))return '';$map[$rel]=(string)hash_file('sha256',$p);}ksort($map,SORT_STRING);
        $h=hash_init('sha256');foreach($map as $rel=>$digest){hash_update($h,$rel);hash_update($h,"\0");hash_update($h,$digest);hash_update($h,"\n");}return hash_final($h);
    }

    private function writeManagedInventory(array $managedFiles): void
    {
        $out=[];foreach($managedFiles as $raw){$rel=$this->safeRelative((string)$raw);if($this->stableManagedPath($rel)||!is_file($this->localWebRoot.'/'.$rel))continue;$out[$rel]=true;}ksort($out,SORT_STRING);
        $this->writeJson($this->root().'/managed-files.json',['format'=>'sokna-local-managed-files-v1','schema_version'=>1,'updated_at'=>gmdate('c'),'files'=>array_keys($out)]);
    }

    private function stableManagedPath(string $rel): bool
    { return in_array($rel,['config.php','install.lock','public/local-recovery.php','public/local-update-runner.php',self::BINDING_NAME],true); }

    private function isCanonicalManagedPath(string $rel): bool
    {
        $parts=explode('/',$rel);$first=$parts[0]??'';
        if(in_array($first,['assets','database','public','resources','src'],true))return true;
        if(count($parts)===1&&in_array($rel,['README.md','VERSION.txt','bootstrap.php'],true))return true;
        return $first==='tools'&&in_array(basename($rel),['business-backup.php','provision-print-worker.php','setup-machine.php'],true);
    }
    private function reconcileInterruptedActivation(int $actorId): void
    {
        $s=$this->state();$j=is_array($s['activation_journal']??null)?$s['activation_journal']:null;if($j===null)return;
        $phase=(string)($j['phase']??'');$tx=(string)($j['transaction_id']??'');$recoveryId=(string)($j['recovery_id']??'');$previous=(string)($j['previous_version']??$this->currentVersion());$target=(string)($j['version']??'');
        if($phase==='prepare'&&$recoveryId===''){$s['activation_journal']=null;$this->event($s,'interrupted_prepare_cleared',$target,$actorId,['result'=>'success','transaction_id'=>$tx]);$this->save($s);return;}
        if($phase==='deploy'){
            if($recoveryId==='')throw new LocalUpdateException('activation_recovery_required','اجرای قبلی هنگام استقرار فایل‌ها نیمه‌کاره مانده و شناسه نقطه بازگشت در دسترس نیست.',409,['journal'=>$j]);
            try{$meta=$this->restoreRecoveryPoint($recoveryId);$this->refreshInstallationBinding();}
            catch(Throwable $e){throw new LocalUpdateException('activation_recovery_required','اجرای قبلی هنگام استقرار فایل‌ها نیمه‌کاره مانده و بازگشت خودکار آن کامل نشد.',409,['journal'=>$j,'recovery_error'=>$this->failureCause($e)]);}
            $s=$this->state();$s['active_version']=(string)($meta['version']??$previous);$s['activation_journal']=null;$this->event($s,'interrupted_deploy_recovered',(string)($meta['version']??$previous),$actorId,['result'=>'success','transaction_id'=>$tx,'target_version'=>$target,'recovery_id'=>$recoveryId]);$this->save($s);return;
        }
        if(in_array($phase,['migrations','health'],true)){
            // The target Web deployment completed before entering these phases. DB state may have
            // moved forward even when the migration never reached its final journal row. Retain
            // the target updater and enter maintenance mode; never restore older Web code here.
            $before=array_values((array)($j['applied_before']??[]));$dbState=$this->databaseFailureState($before,new LocalUpdateException('interrupted_activation','اجرای قبلی در میانه نصب قطع شد.',500),$phase);
            $this->markUpgradeRecoveryRequired($target,$previous,$phase,$tx,$recoveryId,$dbState);
            $s=$this->state();$s['previous_version']=$previous;$s['active_version']=$target;$s['activation_journal']=null;$s['recovery_mode']=['required'=>true,'target_version'=>$target,'previous_version'=>$previous,'phase'=>$phase,'transaction_id'=>$tx,'recovery_id'=>$recoveryId];
            if((string)(($s['staged']??[])['version']??'')===$target)$s['staged']['status']='recovery_required';
            $this->event($s,'interrupted_activation_recovery_mode',$target,$actorId,['result'=>'success','transaction_id'=>$tx,'previous_version'=>$previous,'recovery_id'=>$recoveryId]);$this->save($s);return;
        }
        throw new LocalUpdateException('activation_recovery_required','اجرای قبلی به‌روزرسانی در مرحله ناشناخته متوقف شده است؛ برای جلوگیری از تغییر بیشتر، ادامه خودکار انجام نشد.',409,['journal'=>$j]);
    }


    private function stagePackageDir(string $version,string $hash): string
    {
        $versionKey=substr(hash('sha256',$version),0,24);return $this->root().'/staged-v2/'.$versionKey.'/'.$hash;
    }

    private function resolveStagedDir(array $staged): string
    {
        $rel=trim((string)($staged['stage_dir']??''));
        if($rel!==''){
            $rel=$this->safeLifecycleRelative($rel);$p=$this->root().'/'.$rel;if(is_dir($p))return $p;
            throw new LocalUpdateException('staged_missing','Stage انتخاب‌شده پیدا نشد.',409,['stage_dir'=>$rel]);
        }
        $version=(string)($staged['version']??'');$legacy=$this->root().'/staged/'.$version;if(is_dir($legacy))return $legacy;
        throw new LocalUpdateException('staged_missing','Stage انتخاب‌شده پیدا نشد.',409,['version'=>$version]);
    }

    private function relativeLifecyclePath(string $path): string
    {
        $root=rtrim(str_replace('\\','/',$this->root()),'/');$p=str_replace('\\','/',$path);
        if(!str_starts_with($p,$root.'/'))throw new LocalUpdateException('unsafe_path','مسیر Stage خارج از lifecycle است.',500);
        return substr($p,strlen($root)+1);
    }

    private function safeLifecycleRelative(string $rel): string
    {
        $rel=str_replace('\\','/',trim($rel));if($rel===''||str_starts_with($rel,'/')||preg_match('#(^|/)\.\.(/|$)#',$rel)||str_contains($rel,"\0"))throw new LocalUpdateException('unsafe_path','مسیر lifecycle ناامن است.',422,['path'=>$rel]);return $rel;
    }

    private function updateJournalPhase(string $tx,string $phase,array $extra=[]): void
    {
        $s=$this->state();$j=(array)($s['activation_journal']??[]);if((string)($j['transaction_id']??'')!==$tx)return;$j['phase']=$phase;$j['updated_at']=gmdate('c');foreach($extra as $k=>$v)$j[$k]=$v;$s['activation_journal']=$j;$this->save($s);
    }

    private function diagnosticCause(Throwable $e): array
    {
        $details=$e instanceof LocalUpdateException?$e->details:[];return [
            'code'=>$e instanceof LocalUpdateException?$e->errorCode:'unclassified_exception',
            'class'=>get_class($e),'message'=>substr($e->getMessage(),0,1200),
            'details'=>$details,'fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage().'|'.json_encode($details)),0,20)
        ];
    }

    private function copyFileReliable(string $source,string $dest,string $rel,string $phase): void
    {
        $attempts=5;$last=null;
        for($i=1;$i<=$attempts;$i++){
            clearstatcache(true,$source);clearstatcache(true,$dest);$this->mkdir(dirname($dest));
            if(@copy($source,$dest)){@chmod($dest,0644);return;}
            $last=error_get_last();usleep(50000*$i);
        }
        throw new LocalUpdateException('copy_failed','کپی فایل lifecycle ناموفق بود.',500,[
            'phase'=>$phase,'file'=>$rel,'source'=>$source,'destination'=>$dest,'attempts'=>$attempts,
            'source_exists'=>is_file($source),'source_readable'=>is_readable($source),'destination_dir_writable'=>is_writable(dirname($dest)),
            'source_size'=>is_file($source)?(int)filesize($source):null,'php_error'=>substr((string)($last['message']??''),0,1000)
        ]);
    }

    private function replaceFileReliable(string $source,string $dest,string $rel,string $phase): void
    {
        $tmp=$dest.'.sokna-new-'.bin2hex(random_bytes(4));$this->copyFileReliable($source,$tmp,$rel,$phase.'_copy');
        $attempts=5;$last=null;$this->invalidateOpcodePath($dest);
        for($i=1;$i<=$attempts;$i++){
            if(is_file($dest)||is_link($dest)){
                if(!@unlink($dest)){ $last=error_get_last();usleep(60000*$i);continue; }
            }
            if(@rename($tmp,$dest)){@chmod($dest,0644);$this->invalidateOpcodePath($dest);return;}
            $last=error_get_last();usleep(60000*$i);
        }
        // Last-resort overwrite from the verified temporary file. This is less
        // atomic but still protected by the differential recovery point.
        if(@copy($tmp,$dest)){@unlink($tmp);@chmod($dest,0644);$this->invalidateOpcodePath($dest);return;}
        $last=error_get_last();@unlink($tmp);
        throw new LocalUpdateException('replace_failed','جایگزینی فایل به‌روزرسانی کامل نشد.',500,[
            'phase'=>$phase,'file'=>$rel,'destination'=>$dest,'attempts'=>$attempts,
            'destination_dir_writable'=>is_writable(dirname($dest)),'php_error'=>substr((string)($last['message']??''),0,1000)
        ]);
    }

    private function unlinkReliable(string $path,string $rel,string $phase): void
    {
        if(!file_exists($path)&&!is_link($path))return;$last=null;$this->invalidateOpcodePath($path);
        for($i=1;$i<=5;$i++){if(@unlink($path)){$this->invalidateOpcodePath($path);return;}$last=error_get_last();usleep(60000*$i);}
        throw new LocalUpdateException('delete_failed','حذف فایل مدیریت‌شده هنگام lifecycle کامل نشد.',500,['phase'=>$phase,'file'=>$rel,'path'=>$path,'php_error'=>substr((string)($last['message']??''),0,1000)]);
    }

    private function invalidateOpcodePath(string $path): void
    {
        if(strtolower(pathinfo($path,PATHINFO_EXTENSION))!=='php')return;
        if(function_exists('opcache_invalidate')){try{@opcache_invalidate($path,true);}catch(Throwable){}}
    }

    private function resetOpcodeCache(): void
    {
        if(function_exists('opcache_reset')){try{@opcache_reset();}catch(Throwable){}}
        clearstatcache();
    }

    private function verifyRecoveryPayload(string $root,array $files): void
    {
        foreach($files as $e){$rel=$this->safeRelative((string)($e['path']??''));$p=$root.'/'.$rel;if(!is_file($p)||(int)filesize($p)!==(int)($e['size']??-1)||!hash_equals(strtolower((string)($e['sha256']??'')),strtolower((string)hash_file('sha256',$p))))throw new LocalUpdateException('recovery_integrity_failed','یکپارچگی فایل‌های نقطه بازگشت معتبر نیست.',500,['file'=>$rel]);}
    }

    private function pruneRecoveryPoints(string $keepId): void
    {
        $root=$this->root().'/recovery-points';if(!is_dir($root))return;$dirs=[];
        foreach(new \DirectoryIterator($root) as $f)if($f->isDir()&&!$f->isDot())$dirs[]=$f->getPathname();
        usort($dirs,static fn($a,$b)=>(filemtime($b)?:0)<=>(filemtime($a)?:0));
        $kept=0;foreach($dirs as $dir){$id=basename($dir);if($id===$keepId||$kept<2){$kept++;continue;}$this->removeTree($dir);}
    }

    private function pruneStageStore(string $keepDir): void
    {
        $root=$this->root().'/staged-v2';if(!is_dir($root))return;$packages=[];
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f)if($f->isDir()&&is_file($f->getPathname().'/package.json'))$packages[]=$f->getPathname();
        usort($packages,static fn($a,$b)=>(filemtime($b.'/package.json')?:0)<=>(filemtime($a.'/package.json')?:0));
        $kept=0;foreach($packages as $dir){if($keepDir!==''&&str_replace('\\','/',$dir)===str_replace('\\','/',$keepDir)){$kept++;continue;}if($kept<3){$kept++;continue;}$this->removeTree($dir);}
    }

    private function removeEmptyDirectories(string $root): void
    {
        if(!is_dir($root))return;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f)if($f->isDir())@rmdir($f->getPathname());
    }
    private function healthCheck(): void
    {
        $critical=['bootstrap.php','public/_app.php','public/index.php','public/system/api.php','public/local-recovery.php','public/update-rescue.php','src/Core/Bootstrap.php','src/Domain/Update/LocalUpdateService.php'];
        foreach($critical as $rel)if(!is_file($this->localWebRoot.'/'.$rel))
            throw new LocalUpdateException('health_failed','فایل حیاتی نسخه جدید وجود ندارد.',500,['file'=>$rel]);

        // PHP_BINARY is not a reliable CLI path under Apache/mod_php on Windows:
        // it can resolve to the Apache executable. Resolve the real PHP CLI from
        // the SOKNA infrastructure root / loaded php.ini / PHP_BINDIR instead.
        $cli=$this->resolvePhpCli();
        if($cli!==null){
            foreach($critical as $rel){
                $out=[];$code=0;
                $cmd=escapeshellarg($cli).' -l '.escapeshellarg($this->localWebRoot.'/'.$rel).' 2>&1';
                exec($cmd,$out,$code);
                if($code!==0)throw new LocalUpdateException(
                    'health_failed',
                    'PHP syntax health check ناموفق بود.',
                    500,
                    ['file'=>$rel,'php_cli'=>$cli,'lint_output'=>substr(implode("\n",$out),0,1200)]
                );
            }
            $this->freshProcessHealthCheck($cli);
        }
    }

    private function freshProcessHealthCheck(string $cli): void
    {
        $packageRoot=dirname(rtrim($this->localWebRoot,'/\\'));$config=$packageRoot.DIRECTORY_SEPARATOR.'config.php';$bootstrap=$this->localWebRoot.DIRECTORY_SEPARATOR.'bootstrap.php';
        $code='$config=require '.var_export($config,true).'; if(!is_array($config)) throw new RuntimeException("config_invalid"); require '.var_export($bootstrap,true).'; $core=sokna_local_bootstrap($config); $core->systemDiagnostics(); $core->localUpdates()->snapshot(); $core->publicEdgePublisher(); $core->businessBackup(); $core->publicReenrollment(); echo trim((string)file_get_contents('.var_export($this->localWebRoot.DIRECTORY_SEPARATOR.'VERSION.txt',true).'));';
        $out=[];$exit=0;$cmd=escapeshellarg($cli).' -d display_errors=1 -r '.escapeshellarg($code).' 2>&1';exec($cmd,$out,$exit);
        if($exit!==0)throw new LocalUpdateException('fresh_process_health_failed','نسخه جدید در PHP process تازه قابل راه‌اندازی نیست.',500,['php_cli'=>$cli,'output'=>substr(implode("\\n",$out),0,1600)]);
        $reported=trim(implode("\\n",$out));$expected=$this->currentVersion();if($reported===''||!str_contains($reported,$expected))throw new LocalUpdateException('fresh_process_version_mismatch','PHP process تازه نسخه هدف را تأیید نکرد.',500,['expected'=>$expected,'output'=>substr($reported,0,1000)]);
    }

    private function resolvePhpCli(): ?string
    {
        $candidates=[];
        $env=trim((string)(getenv('SOKNA_PHP_CLI')?:''));
        if($env!=='')$candidates[]=$env;

        // Supported Windows layout: <SOKNA_ROOT>\Infrastructure\PHP\php.exe
        $soknaRoot=dirname(rtrim($this->localWebRoot,'/\\'));
        $candidates[]=$soknaRoot.DIRECTORY_SEPARATOR.'Infrastructure'.DIRECTORY_SEPARATOR.'PHP'.DIRECTORY_SEPARATOR.'php.exe';
        $candidates[]=$soknaRoot.DIRECTORY_SEPARATOR.'Infrastructure'.DIRECTORY_SEPARATOR.'PHP'.DIRECTORY_SEPARATOR.'php';

        $ini=php_ini_loaded_file();
        if(is_string($ini)&&$ini!==''){
            $dir=dirname($ini);
            $candidates[]=$dir.DIRECTORY_SEPARATOR.'php.exe';
            $candidates[]=$dir.DIRECTORY_SEPARATOR.'php';
        }
        if(defined('PHP_BINDIR')&&is_string(PHP_BINDIR)&&PHP_BINDIR!==''){
            $candidates[]=PHP_BINDIR.DIRECTORY_SEPARATOR.'php.exe';
            $candidates[]=PHP_BINDIR.DIRECTORY_SEPARATOR.'php';
        }

        // Only trust PHP_BINARY when it actually names a PHP executable.
        if(defined('PHP_BINARY')&&is_string(PHP_BINARY)&&PHP_BINARY!==''){
            $base=strtolower(basename(PHP_BINARY));
            if(in_array($base,['php','php.exe','php-cgi','php-cgi.exe'],true))$candidates[]=PHP_BINARY;
        }

        $seen=[];
        foreach($candidates as $candidate){
            $candidate=trim((string)$candidate);
            if($candidate===''||isset($seen[strtolower($candidate)]))continue;
            $seen[strtolower($candidate)]=true;
            if(is_file($candidate))return $candidate;
        }
        return null;
    }

    private function verifyDirectory(string $root,array $files): void
    { $expected=[];foreach($files as $e){$rel=$this->safeRelative((string)($e['path']??''));$expected[$rel]=true;$p=$root.'/'.$rel;if(!is_file($p)||(int)filesize($p)!==(int)$e['size']||!hash_equals(strtolower((string)$e['sha256']),strtolower((string)hash_file('sha256',$p))))throw new LocalUpdateException('staged_integrity_failed','یکپارچگی Stage معتبر نیست.',422,['file'=>$rel]);}$actual=[];$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$actual[str_replace('\\','/',substr($f->getPathname(),strlen($root)+1))]=true;if(count($actual)!==count($expected)||array_diff_key($actual,$expected)||array_diff_key($expected,$actual))throw new LocalUpdateException('payload_set_mismatch','مجموعه فایل‌های Stage تغییر کرده است.',422); }
    private function hashTree(string $root): array{$out=[];$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile()){$rel=str_replace('\\','/',substr($f->getPathname(),strlen($root)+1));$out[]=['path'=>$rel,'size'=>$f->getSize(),'sha256'=>hash_file('sha256',$f->getPathname())];}usort($out,fn($a,$b)=>strcmp($a['path'],$b['path']));return $out;}
    private function copyTree(string $src,string $dst): void{$this->removeTree($dst);$this->mkdir($dst);$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);foreach($it as $f){$rel=substr($f->getPathname(),strlen($src)+1);$to=$dst.'/'.$rel;if($f->isDir())$this->mkdir($to);elseif($f->isFile()){ $this->mkdir(dirname($to));if(!copy($f->getPathname(),$to))throw new LocalUpdateException('copy_failed','کپی فایل lifecycle ناموفق بود.',500);}}}
    private function removeTree(string $p): void{if(!file_exists($p))return;if(is_file($p)||is_link($p)){@unlink($p);return;}$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($p);}
    private function root(): string{$p=$this->observability->dataRoot().'/lifecycle/local';$this->mkdir($p);return $p;}
    private function tokenFile(): string{return $this->root().'/recovery-token.json';}
    private function state(): array{$s=$this->readJson($this->root().'/state.json');if(($s['format']??'')!==self::STATE_FORMAT)$s=['format'=>self::STATE_FORMAT,'schema_version'=>1,'active_version'=>$this->currentVersion(),'previous_version'=>'','lkg_recovery_id'=>'','staged'=>null,'activation_journal'=>null,'last_failure'=>null,'history'=>[]];if(!array_key_exists('activation_journal',$s))$s['activation_journal']=null;if(!array_key_exists('last_failure',$s))$s['last_failure']=null;if(!array_key_exists('recovery_mode',$s))$s['recovery_mode']=null;return $s;}
    private function save(array $s): void{$this->writeJson($this->root().'/state.json',$s);}
    private function event(array &$s,string $action,string $version,int $actorId,array $extra=[]): void{$result=trim((string)($extra['result']??'success'));unset($extra['result']);$s['history'][]=['at'=>gmdate('c'),'action'=>$action,'version'=>$version,'actor_user_id'=>$actorId,'result'=>$result!==''?$result:'success','correlation_id'=>$this->observability->correlationId()]+$extra;$s['history']=array_slice($s['history'],-100);}
    private function legacyFailureFromHistory(array $history): ?array
    {
        $rollbackIndex=-1;$rollback=null;$lastActivate=-1;
        foreach($history as $i=>$event){if(!is_array($event))continue;$action=(string)($event['action']??'');if($action==='rollback_auto'){$rollbackIndex=(int)$i;$rollback=$event;}if($action==='activate')$lastActivate=(int)$i;}
        if($rollbackIndex<0||$lastActivate>$rollbackIndex||!is_array($rollback))return null;
        return ['at'=>(string)($rollback['at']??''),'operation'=>'activate','target_version'=>(string)($rollback['version']??''),'from_version'=>$this->currentVersion(),'failure_stage'=>'unknown','failure_stage_label'=>'نامشخص در updater قدیمی','cause_code'=>'legacy_failure_not_recorded','cause_message'=>'این تلاش با updater قدیمی انجام شده و علت اصلی شکست ذخیره نشده است. از تلاش بعدی جزئیات کامل ثبت می‌شود.','cause_details'=>['legacy_error'=>(string)($rollback['error']??'activation_failed')],'forward_migrations'=>array_values((array)($rollback['forward_migrations']??[])),'recovery_id'=>'','support_id'=>(string)substr((string)($rollback['correlation_id']??''),0,12),'rollback'=>['ok'=>true,'restored_version'=>$this->currentVersion()]];
    }


    private function failureRecord(Throwable $e,string $stage,string $target,string $from,array $applied,string $recoveryId,string $operation='activate'): array
    {
        $cause=$this->failureCause($e);$support=substr($this->observability->correlationId(),0,12);
        return ['at'=>gmdate('c'),'operation'=>$operation,'target_version'=>$target,'from_version'=>$from,'failure_stage'=>$stage,'failure_stage_label'=>$this->failureStageLabel($stage),'cause_code'=>$cause['code'],'cause_message'=>$cause['message'],'cause_details'=>$cause['details'],'forward_migrations'=>array_values($applied),'recovery_id'=>$recoveryId,'support_id'=>$support];
    }

    private function failureCause(Throwable $e): array
    {
        if($e instanceof LocalUpdateException)return ['code'=>$e->errorCode,'message'=>$this->cleanFailureMessage($e->getMessage()),'details'=>$e->details];
        return ['code'=>'internal_failure','message'=>$this->cleanFailureMessage($e->getMessage())?:'خطای داخلی بدون پیام رخ داد.','details'=>['exception'=>get_class($e)]];
    }

    private function failureStageLabel(string $stage): string
    { return match($stage){'prepare'=>'آماده‌سازی نقطه بازگشت','deploy'=>'استقرار فایل‌ها','migrations'=>'تغییر ساختار دیتابیس','health'=>'بررسی سلامت نسخه جدید',default=>'مرحله نامشخص'}; }

    private function failureMessage(array $failure,string $operation='نصب'): string
    {
        $stage=(string)($failure['failure_stage_label']??'مرحله نامشخص');$cause=(string)($failure['cause_message']??'علت نامشخص');$rollback=(array)($failure['rollback']??[]);
        $tail=!empty($rollback['ok'])?' آخرین وضعیت سالم با موفقیت برگردانده شد.':' بازگشت خودکار نیز کامل نشد.';
        return $operation.' در مرحله «'.$stage.'» متوقف شد: '.$cause.$tail;
    }

    private function cleanFailureMessage(string $message): string
    { $message=trim((string)preg_replace('/\s+/u',' ',$message));return substr($message,0,1200); }

    private function readJson(string $p): array{$raw=@file_get_contents($p);if(!is_string($raw)||$raw==='')return [];try{$d=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($d)?$d:[];}catch(Throwable){return [];}}
    private function writeJson(string $p,array $v): void{$this->mkdir(dirname($p));$tmp=$p.'.tmp-'.bin2hex(random_bytes(4));$json=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$p)){@unlink($tmp);throw new LocalUpdateException('state_write_failed','ثبت وضعیت lifecycle ناموفق بود.',500);} @chmod($p,0600);}
    private function safeRelative(string $r): string{$r=str_replace('\\','/',trim($r));if($r===''||str_starts_with($r,'/')||preg_match('#(^|/)\.\.(/|$)#',$r)||str_contains($r,"\0"))throw new LocalUpdateException('unsafe_path','مسیر فایل بسته ناامن است.',422,['path'=>$r]);return $r;}
    private function mkdir(string $p): void{if(!is_dir($p)&&!@mkdir($p,0700,true)&&!is_dir($p))throw new LocalUpdateException('storage_unavailable','فضای lifecycle قابل نوشتن نیست.',500);}
    private function trust(): array{return $this->readJson($this->trustFile);}
}
