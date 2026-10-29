<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use PDO;
use Sokna\Local\Core\Config;
use Sokna\Local\Core\Migrations;
use Sokna\Local\Core\Observability;
use Sokna\Local\Core\TimeAuthority;
use Sokna\Local\Domain\Orders\BusinessClock;
use Sokna\Local\Domain\Printing\PrintManagementService;
use Sokna\Local\Domain\Printing\PrintService;
use Sokna\Local\Domain\Notifications\NotificationService;
use Sokna\Local\Setup\BrowserSetupService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\Local\Runtime\RuntimeEvidence;
use Sokna\Local\Runtime\RuntimeHealthClient;
use Throwable;

final class SystemDiagnosticsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Observability $observability,
        private readonly Migrations $migrations,
        private readonly PrintManagementService $printing,
        private readonly SupportBundleWriter $bundles,
        private readonly string $packageRoot,
        private readonly string $localWebRoot,
        private readonly string $versionFile,
        private readonly ?PublicEdgeSyncClient $publicClient=null,
        private readonly ?RuntimeHealthClient $runtimeHealthClient=null,
        private readonly ?TimeAuthority $timeAuthority=null,
        private readonly ?BusinessClock $businessClock=null,
        private readonly ?NotificationService $notifications=null,
        private readonly ?MaintenanceSupervisorClient $maintenanceClient=null,
    ) {}

    public function snapshot(bool $activePublicProbe=false,bool $includeRecentLogs=true): array
    {
        $local=$this->localStatus();$database=$this->databaseStatus();$runtime=$this->runtimeStatus();$print=$this->printStatus();$public=$this->publicStatus($activePublicProbe);$time=$this->timeStatus();
        $checks=$this->allChecks($local,$database,$time,$runtime,$print,$public);
        $generated=$this->timeAuthority?->nowUtcIso() ?? gmdate('c');
        $components=['local_web'=>$local,'database'=>$database,'time'=>$time,'runtime'=>$runtime,'print_agent'=>$print,'public_edge'=>$public];
        $model=$this->healthModel($components,$checks,$generated);
        return [
            'generated_at'=>$generated,'summary'=>$model['summary'],'health_groups'=>$model['groups'],'issues'=>$model['issues'],
            'checks'=>$checks,'components'=>$components,'recent_logs'=>$includeRecentLogs?$this->mergedLogs($public):[],
        ];
    }

    public function healthComponent(string $key): array
    {
        $generated=$this->timeAuthority?->nowUtcIso() ?? gmdate('c');$components=[];$checks=[];
        if($key==='core'){
            $local=$this->localStatus();$database=$this->databaseStatus();$time=$this->timeStatus();
            $components=['local_web'=>$local,'database'=>$database,'time'=>$time];
            $checks=array_values(array_filter($this->allChecks($local,$database,$time,null,null,null),static fn(array $c): bool=>in_array($c['id'],['setup_lock','data_dir','pdo_mysql','migrations','database_version','clock_alignment','php_zip','update_chunk_transport','stable_recovery'],true)));
        }elseif($key==='runtime'){
            $runtime=$this->runtimeStatus();$components=['runtime'=>$runtime];
            $checks=[$this->check('runtime_delivery',$this->runtimeOperational($runtime),'Windows Runtime scheduler and Local worker execution healthy','warning')];
        }elseif($key==='printing'){
            $print=$this->printStatus();$components=['print_agent'=>$print];
            $checks=[
                $this->check('print_required_destinations',(int)($print['connected_agents']??0)>0&&(int)($print['ready_printer_queues']??0)>0&&(int)($print['ready_destinations']??0)>0&&(int)($print['required_unready']??0)===0,'Print service, Windows queue and required destinations ready','warning'),
                $this->check('print_agent_version_compatibility',(int)($print['incompatible_agents']??0)===0,'Print Agent version compatible','warning'),
            ];
        }elseif($key==='public'){
            $public=$this->publicStatus(false);$components=['public_edge'=>$public];
            $checks=[$this->check('public_edge_connectivity',(string)($public['status']??'')==='ok','Public Edge live sync and heartbeat','warning')];
        }else throw new \InvalidArgumentException('unknown_health_component');
        $model=$this->healthModel($components,$checks,$generated);
        return ['generated_at'=>$generated,'summary'=>$model['summary'],'health_groups'=>$model['groups'],'issues'=>$model['issues'],'checks'=>$checks,'components'=>$components];
    }

    public function maintain(): array
    {
        // Maintenance must never block a Runtime worker on WAN diagnostics. Cached sync state
        // plus Runtime receipts are sufficient to raise/clear the health notification.
        $snapshot=$this->snapshot(false,false);$issues=array_values(array_filter((array)($snapshot['issues']??[]),static fn(array $r): bool=>(string)($r['severity']??'')==='action_required'));
        $labels=array_map(static fn(array $r): string=>(string)($r['label']??''),array_slice($issues,0,3));$more=max(0,count($issues)-count($labels));
        $body=$issues===[]?'سلامت سامانه به وضعیت عادی برگشت.':'نیازمند اقدام: '.implode('، ',$labels).($more>0?' و '.$more.' مورد دیگر':'').'.';
        $notification=$this->notifications?->syncRoleCondition('system.health.action_required',$issues!==[],'سلامت سامانه نیاز به رسیدگی دارد',$body,'/system/?tab=health','admin',['issue_ids'=>array_values(array_map(static fn(array $r): string=>(string)($r['id']??''),$issues))]);
        return ['ok'=>true,'checked_at'=>(string)$snapshot['generated_at'],'status'=>(string)($snapshot['summary']['status']??'unknown'),'action_required'=>count($issues),'notification'=>$notification];
    }

    private function allChecks(?array $local,?array $database,?array $time,?array $runtime,?array $print,?array $public): array
    {
        $local??=[];$database??=[];$time??=[];$runtime??=[];$print??=[];$public??=[];
        return [
            $this->check('setup_lock',(bool)($local['setup']['installed']??false),'Setup lock','critical'),
            $this->check('data_dir',(bool)($local['data_dir_writable']??false),'Data directory writable','critical'),
            $this->check('pdo_mysql',in_array('mysql',PDO::getAvailableDrivers(),true),'PDO MySQL','critical'),
            $this->check('migrations',(int)($database['pending_migrations']??1)===0,'Schema migrations current','critical'),
            $this->check('database_version',(bool)($database['mariadb_11_4']??false),'MariaDB 11.4.x','critical'),
            $this->check('clock_alignment',($time['aligned']??null)===true,'Server/database clock alignment','warning'),
            $this->check('runtime_delivery',$this->runtimeOperational($runtime),'Windows Runtime scheduler and Local worker execution healthy','warning'),
            $this->check('print_required_destinations',(int)($print['connected_agents']??0)>0&&(int)($print['ready_printer_queues']??0)>0&&(int)($print['ready_destinations']??0)>0&&(int)($print['required_unready']??0)===0,'Print service, Windows queue and required destinations ready','warning'),
            $this->check('print_agent_version_compatibility',(int)($print['incompatible_agents']??0)===0,'Print Agent version compatible','warning'),
            $this->check('public_edge_connectivity',(string)($public['status']??'')==='ok','Public Edge live sync and heartbeat','warning'),
            $this->check('php_zip',class_exists(\ZipArchive::class),'PHP ZIP for Local updater','warning'),
            $this->check('update_chunk_transport',is_file($this->localWebRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'system'.DIRECTORY_SEPARATOR.'update-chunk.php'),'Chunked Local update transport','warning'),
            $this->check('stable_recovery',is_file($this->localWebRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'local-recovery.php'),'Stable Local recovery entrypoint','warning'),
        ];
    }

    private function healthModel(array $components,array $checks,string $generated): array
    {
        $groups=[];$issues=[];
        $add=function(string $id,string $label,string $status,string $impact,string $actionLabel,string $actionUrl,string $lastSuccess='',string $code='') use (&$groups,&$issues,$generated): void {
            $groups[]=['id'=>$id,'label'=>$label,'status'=>$status,'impact'=>$impact,'action_label'=>$actionLabel,'action_url'=>$actionUrl,'last_success'=>$lastSuccess,'last_checked'=>$generated];
            if(in_array($status,['action_required','degraded'],true))$issues[]=['id'=>$id,'group'=>$id,'severity'=>$status,'label'=>$label,'impact'=>$impact,'action_label'=>$actionLabel,'action_url'=>$actionUrl,'last_success'=>$lastSuccess,'last_checked'=>$generated,'technical_code'=>$code];
        };
        if(isset($components['local_web'])||isset($components['database'])){
            $lw=(array)($components['local_web']??[]);$db=(array)($components['database']??[]);$ok=(string)($lw['status']??'')==='ok'&&(string)($db['status']??'')==='ok';
            $add('local_db','سامانه محلی و دیتابیس',$ok?'operational':'action_required',$ok?'ثبت سفارش و اطلاعات محلی آماده‌اند.':'کار روزانه، ثبت سفارش یا تسویه ممکن است متوقف شود.','بررسی جزئیات','/system/?tab=health','',$ok?'':'local_or_database_unhealthy');
        }
        if(isset($components['runtime'])){$rt=(array)$components['runtime'];$ok=$this->runtimeOperational($rt);$add('windows_runtime','سرویس‌های ویندوز',$ok?'operational':'action_required',$ok?'چرخه Runtime و triggerهای Local تازه‌اند.':'همگام‌سازی، کارهای زمان‌بندی‌شده یا چاپ ممکن است به‌موقع اجرا نشوند.','بررسی اتصال سرویس‌ها','/integrations/?tab=windows',(string)($rt['scheduler']['last_cycle_at']??''),$ok?'':(string)($rt['probe']['error_code']??'runtime_not_recent'));}
        if(isset($components['public_edge'])){$pe=(array)$components['public_edge'];$ok=(string)($pe['status']??'')==='ok';$add('public_guest','وب عمومی و مسیر مهمان',$ok?'operational':'action_required',$ok?'منو/سفارش مهمان و heartbeat وب عمومی آماده‌اند.':'منوی مهمان، سفارش آنلاین یا همگام‌سازی Public ممکن است به‌روز نباشد.','بررسی اتصال وب عمومی','/integrations/?tab=public',(string)($pe['last_sync_at']??''),$ok?'':((string)($pe['probe_error']??'')?:'public_edge_unhealthy'));}
        if(isset($components['print_agent'])){$pa=(array)$components['print_agent'];$expected=(int)($pa['active_agents']??0)>0||count(array_filter((array)($pa['destinations']??[]),static fn($d): bool=>is_array($d)&&(int)($d['active']??0)===1))>0;$ready=(string)($pa['status']??'')==='ok';$required=(int)($pa['required_unready']??0)>0;$status=!$expected?'not_configured':($ready?'operational':($required?'action_required':'degraded'));$add('printing','آمادگی چاپ',$status,!$expected?'چاپ هنوز برای این نصب پیکربندی نشده است.':($ready?'مسیرهای چاپ آماده‌اند.':'برخی چاپ‌ها ممکن است در صف بمانند یا نیاز به رسیدگی داشته باشند.'),'باز کردن چاپ','/printing/','',$ready?'':'printing_not_ready');}
        if(isset($components['time'])){$tm=(array)$components['time'];$aligned=$tm['aligned']??null;$ok=$aligned===true;$impact=$ok?'زمان فنی، روز کاری و روز بانکی هم‌راستا هستند.':($aligned===false?'اختلاف ساعت می‌تواند گزارش، همگام‌سازی یا ترتیب رخدادها را مبهم کند.':'هم‌راستایی ساعت دیتابیس هنوز قابل تأیید نیست؛ تا دریافت شواهد معتبر سالم فرض نمی‌شود.');$code=$ok?'':($aligned===false?'clock_misaligned':'clock_alignment_unverified');$add('canonical_time','زمان سامانه',$ok?'operational':'degraded',$impact,'بررسی زمان','/system/?tab=health','',$code);}
        $recoveryIds=['php_zip','update_chunk_transport','stable_recovery'];$recovery=array_values(array_filter($checks,static fn(array $c): bool=>in_array((string)$c['id'],$recoveryIds,true)));if($recovery!==[]){$ok=count(array_filter($recovery,static fn(array $c): bool=>!$c['ok']))===0;$add('recovery','به‌روزرسانی و بازیابی',$ok?'operational':'degraded',$ok?'مسیر به‌روزرسانی و بازیابی اضطراری آماده است.':'بخشی از مسیر به‌روزرسانی یا بازیابی آماده نیست.','باز کردن به‌روزرسانی','/system/?tab=updates','',$ok?'':'recovery_readiness');}
        $byId=[];foreach($groups as $group)$byId[(string)($group['id']??'')]=$group;
        $pickStatus=static function(array $ids) use ($byId): string {
            $states=[];foreach($ids as $id)if(isset($byId[$id]))$states[]=(string)($byId[$id]['status']??'checking');
            if($states===[])return 'checking';
            if(in_array('action_required',$states,true))return 'action_required';
            if(in_array('degraded',$states,true)||in_array('not_configured',$states,true))return 'degraded';
            return 'operational';
        };
        $serviceStatus=$pickStatus(['local_db']);
        $dependencyStatus=$pickStatus(['windows_runtime','canonical_time','recovery']);
        $workflowStatus=$pickStatus(['public_guest','printing']);
        $coreBlocked=$serviceStatus==='action_required';
        $hasLimited=count(array_filter($groups,static fn(array $g): bool=>in_array((string)($g['status']??''),['action_required','degraded','not_configured'],true)))>0;
        $status=$coreBlocked?'action_required':($hasLimited?'degraded':'operational');
        $dimensions=[
            ['id'=>'service_availability','label'=>'دردسترس‌بودن سامانه','status'=>$serviceStatus,'detail'=>$serviceStatus==='operational'?'هسته محلی و دیتابیس برای کار روزانه در دسترس‌اند.':'هسته محلی یا دیتابیس نیاز به رسیدگی دارد.'],
            ['id'=>'dependency_readiness','label'=>'آمادگی وابستگی‌ها','status'=>$dependencyStatus,'detail'=>$dependencyStatus==='operational'?'سرویس‌های دستگاه، زمان و مسیر بازیابی آماده‌اند.':'یک یا چند وابستگی سامانه هنوز کاملاً آماده نیست.'],
            ['id'=>'workflow_actionability','label'=>'قابل‌اجرا بودن گردش‌کارها','status'=>$workflowStatus,'detail'=>$workflowStatus==='operational'?'گردش‌کارهای وب عمومی و چاپ آماده‌اند.':'حداقل یک گردش‌کار جانبی محدود یا نیازمند اقدام است.'],
        ];
        return ['summary'=>['status'=>$status,'dimensions'=>$dimensions,'action_required_count'=>count(array_filter($issues,static fn(array $i): bool=>$i['severity']==='action_required')),'degraded_count'=>count(array_filter($issues,static fn(array $i): bool=>$i['severity']==='degraded'))],'groups'=>$groups,'issues'=>$issues];
    }

    public function publicConnectionStatus(bool $activeProbe=false): array{return $this->publicStatus($activeProbe);}

    public function createSupportBundle(array $freshPublicUpdateEvidence=[]): array
    {
        $started=microtime(true);
        if($freshPublicUpdateEvidence===[])$freshPublicUpdateEvidence=$this->freshPublicUpdateEvidence(3);
        try{
            // The bundle is local-first and outage-safe. Public evidence is optional metadata;
            // a WAN/update failure can never prevent the bundle itself from being written.
            $snapshot=$this->snapshot(false,false);
            $snapshot['fresh_public_update']=$freshPublicUpdateEvidence;
            $snapshot['diagnostics_profile']=[
                'mode'=>'support_local_first',
                'public_update_probe'=>(string)($freshPublicUpdateEvidence['status']??'not_attempted'),
                'public_update_probe_elapsed_ms'=>(int)($freshPublicUpdateEvidence['elapsed_ms']??0),
                'cached_public_probe_age_seconds'=>$snapshot['components']['public_edge']['probe_cache_age_seconds']??null,
                'cached_update_control_age_seconds'=>$snapshot['components']['public_edge']['update_control_cache_age_seconds']??null,
                'snapshot_elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
            ];
        }
        catch(Throwable $e){
            $snapshot=[
                'generated_at'=>$this->timeAuthority?->nowUtcIso() ?? gmdate('c'),
                'summary'=>['status'=>'partial','label'=>'diagnostics_partial'],
                'issues'=>[['id'=>'support_snapshot_partial','severity'=>'degraded','label'=>'بخشی از وضعیت سامانه هنگام ساخت بسته پشتیبانی قابل خواندن نبود.','technical_code'=>'support_snapshot_partial']],
                'components'=>[],
                'fresh_public_update'=>$freshPublicUpdateEvidence,
                'bundle_snapshot_error'=>[
                    'class'=>get_class($e),
                    'fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20),
                ],
            ];
            $snapshot['diagnostics_profile']=[
                'mode'=>'support_partial_local_first',
                'public_update_probe'=>(string)($freshPublicUpdateEvidence['status']??'not_attempted'),
                'public_update_probe_elapsed_ms'=>(int)($freshPublicUpdateEvidence['elapsed_ms']??0),
                'snapshot_elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
            ];
        }
        // Signed Windows read-only evidence is always collected with the same
        // Local support download. A missing Supervisor must not break export.
        $snapshot['maintenance_update_diagnostics']=$this->maintenanceClient===null
            ? ['format'=>'sokna-maintenance-local-support-index-v1','evidence_available'=>false,'error_code'=>'MAINTENANCE_CLIENT_NOT_CONFIGURED']
            : MaintenanceSupportEvidence::capture($this->maintenanceClient);
        return $this->bundles->create($snapshot);
    }

    /** A bounded optional probe. Never throws outside this method. */
    private function freshPublicUpdateEvidence(int $timeoutSeconds=3): array
    {
        $started=microtime(true);$captured=gmdate('c');
        if($this->publicClient===null||!$this->publicClient->configured())return ['status'=>'not_configured','captured_at'=>$captured,'elapsed_ms'=>0];
        try{
            $response=$this->publicClient->post('/api/v1/local/update/status',[],max(1,min(3,$timeoutSeconds)));
            $body=is_array($response['body']??null)?(array)$response['body']:[];
            return [
                'status'=>'fresh','captured_at'=>$captured,'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
                'update'=>$body['update']??[],'routine_update'=>$body['routine_update']??[],'routine_reconcile'=>$body['routine_reconcile']??[],
                'version'=>(string)($body['version']??''),'emergency_ready'=>(bool)($body['emergency_ready']??false),
            ];
        }catch(PublicEdgeSyncException $e){
            return ['status'=>'failed','captured_at'=>$captured,'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'http_status'=>$e->httpStatus];
        }catch(Throwable $e){
            return ['status'=>'failed','captured_at'=>$captured,'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'error_code'=>'public_update_probe_failed','fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20)];
        }
    }
    public function supportBundlePath(string $id): string{return $this->bundles->resolve($id);}

    private function mergedLogs(array $public): array
    {
        $local=$this->bundles->recentLogs(80);foreach($local as &$r)$r['source']=$r['source']??'local';unset($r);$remote=[];foreach(array_slice((array)($public['recent_logs']??[]),0,40) as $r)if(is_array($r))$remote[]=['ts'=>(string)($r['at']??''),'level'=>'info','event'=>'public.'.(string)($r['action']??'event'),'correlation_id'=>'','source'=>'public','context'=>['installation_id'=>$r['installation_id']??null,'actor_hint'=>$r['actor_hint']??null]];return array_slice(array_merge($remote,$local),0,100);
    }

    private function localStatus(): array
    {
        $version=$this->readTrim($this->versionFile);$setup=(new BrowserSetupService($this->packageRoot,$this->localWebRoot))->status();
        $free=@disk_free_space($this->observability->dataRoot());$installation=(string)$this->config->get('installation.id','');
        return [
            'status'=>($setup['installed']??false)&&is_writable($this->observability->dataRoot())?'ok':'attention','version'=>$version!==''?$version:'unknown','php_version'=>PHP_VERSION,
            'setup'=>$setup,'data_dir_writable'=>is_dir($this->observability->dataRoot())&&is_writable($this->observability->dataRoot()),'disk_free_mb'=>$free===false?null:(int)floor($free/1048576),'upload_limit_bytes'=>$this->effectiveUploadLimitBytes(),
            'installation_hint'=>$installation===''?'':substr(hash('sha256',$installation),0,12),'update_transport'=>'chunked-v1',
        ];
    }

    private function databaseStatus(): array
    {
        try{
            $version=(string)$this->pdo->query('SELECT VERSION()')->fetchColumn();$database=(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $catalog=array_keys($this->migrations->catalog());$applied=$this->migrations->appliedVersions();$pending=array_values(array_diff($catalog,$applied));
            return ['status'=>$pending===[]&&str_starts_with($version,'11.4.')?'ok':'attention','server_version'=>$version,'database'=>$database,'mariadb_11_4'=>str_starts_with($version,'11.4.'),'applied_migrations'=>count($applied),'catalog_migrations'=>count($catalog),'pending_migrations'=>count($pending),'pending_versions'=>array_slice($pending,0,20)];
        }catch(Throwable $e){return ['status'=>'error','server_version'=>'','database'=>'','mariadb_11_4'=>false,'applied_migrations'=>0,'catalog_migrations'=>0,'pending_migrations'=>-1,'pending_versions'=>[],'error'=>'database_unavailable'];}
    }

    private function timeStatus(): array
    {
        $time=$this->timeAuthority ?? new TimeAuthority('Asia/Tehran');
        $row=$time->diagnostics($this->pdo);
        $business=$this->businessClock?->assignment() ?? [];
        $row['business_date']=(string)($business['business_date']??'');
        $row['business_cutoff']=(string)($business['cutoff']??'');
        $row['business_shift_key']=(string)($business['shift_key']??'');
        $row['business_shift_label']=(string)($business['shift_label']??'');
        $aligned=$row['aligned']??null;$row['alignment_evidence']=$aligned===true?'verified':($aligned===false?'misaligned':'unavailable');$row['status']=$aligned===true?'ok':($aligned===false?'attention':'warning');
        return $row;
    }

    private function runtimeOperational(array $runtime): bool
    {
        $scheduler=is_array($runtime['scheduler']??null)?$runtime['scheduler']:[];
        $probe=is_array($runtime['probe']??null)?$runtime['probe']:[];
        $execution=is_array($runtime['execution']??null)?$runtime['execution']:[];
        return ($probe['status']??'')==='ok'
            &&($scheduler['healthy']??false)===true
            &&($scheduler['fresh']??false)===true
            &&!in_array((string)($execution['state']??'not_seen'),['failed','stalled'],true)
            &&($runtime['unresolved_failure']??true)===false
            &&(int)($runtime['age_seconds']??PHP_INT_MAX)<=max(30,(int)($runtime['fresh_seconds']??180));
    }

    private function runtimeStatus(): array
    {
        $receipts=RuntimeEvidence::snapshot($this->pdo);
        $probe=$this->runtimeHealthClient?->probe() ?? ['status'=>'not_configured','http_status'=>0,'error_code'=>'runtime_health_client_missing','health'=>null];
        $runtime=RuntimeEvidence::combine($receipts,$probe);
        $runtime['contract_version']=1;
        $runtime['note']='Scheduler delivery, Local worker execution and Public business transport are separate health dimensions; accepted delivery is not worker completion.';
        return $runtime;
    }

    private function printStatus(): array
    {
        try{
            $snap=$this->printing->snapshot();$agents=(array)($snap['agents']??[]);$destinations=(array)($snap['destinations']??[]);$queue=(array)($snap['server_queue']??[]);
            $active=0;$stale=0;$incompatible=0;$unreadyDestinations=0;$requiredUnready=0;$connected=0;$windowsBacklog=0;$reportedPrinterQueues=0;$readyPrinterQueues=0;$activeDestinations=0;$readyDestinations=0;$agentVersions=[];
            foreach($agents as $a){
                if((int)($a['active']??0)!==1)continue;$active++;
                if(($a['connection_status']??'')==='connected'){$connected++;$reportedVersion=trim((string)($a['agent_version']??''));if($reportedVersion!=='')$agentVersions[$reportedVersion]=true;}else $stale++;
                if(!empty($a['agent_version'])&&empty($a['version_compatible']))$incompatible++;
                $windowsBacklog+=max(0,(int)($a['local_backlog_count']??0));
                $reportedPrinterQueues+=max(0,(int)($a['printer_count']??count((array)($a['printers']??[]))));
                $readyPrinterQueues+=max(0,(int)($a['ready_printer_count']??count(array_filter((array)($a['printers']??[]),static fn($p): bool=>is_array($p)&&!empty($p['ready'])))));
            }
            foreach($destinations as $d){
                if((int)($d['active']??0)!==1)continue;$activeDestinations++;
                if(!empty($d['operational']))$readyDestinations++;else{$unreadyDestinations++;if((int)($d['required_for_operation']??0)===1)$requiredUnready++;}
            }
            $serverBacklog=max(0,(int)($queue['backlog_count']??0));$attention=max(0,(int)($queue['attention_count']??0));$stalled=max(0,(int)($queue['stalled_unclaimed_count']??0));
            $status=$stale>0||$incompatible>0||($active>0&&$readyPrinterQueues===0)||($active>0&&$readyDestinations===0)||$unreadyDestinations>0||$attention>0||$stalled>0?'attention':'ok';
            return [
                'status'=>$status,'protocol_version'=>PrintService::PROTOCOL_VERSION,'minimum_agent_version'=>PrintService::MINIMUM_AGENT_VERSION,'recommended_agent_version'=>PrintService::RECOMMENDED_AGENT_VERSION,'agent_versions'=>array_keys($agentVersions),'agent_version'=>count($agentVersions)===1?(string)array_key_first($agentVersions):'',
                'active_agents'=>$active,'connected_agents'=>$connected,'stale_agents'=>$stale,'incompatible_agents'=>$incompatible,
                'required_unready'=>$requiredUnready,'unready_destinations'=>$unreadyDestinations,'active_destinations'=>$activeDestinations,'ready_destinations'=>$readyDestinations,'reported_printer_queues'=>$reportedPrinterQueues,'ready_printer_queues'=>$readyPrinterQueues,
                'local_backlog_count'=>$windowsBacklog,'server_backlog_count'=>$serverBacklog,'server_attention_count'=>$attention,'stalled_unclaimed_count'=>$stalled,
                'agents'=>$agents,'destinations'=>$destinations,'recent_jobs'=>(array)($snap['recent_jobs']??[]),'server_queue'=>$queue,
            ];
        }catch(Throwable){return ['status'=>'unavailable','protocol_version'=>PrintService::PROTOCOL_VERSION,'minimum_agent_version'=>PrintService::MINIMUM_AGENT_VERSION,'recommended_agent_version'=>PrintService::RECOMMENDED_AGENT_VERSION,'agent_versions'=>[],'agent_version'=>'','active_agents'=>0,'connected_agents'=>0,'stale_agents'=>0,'incompatible_agents'=>0,'required_unready'=>0,'unready_destinations'=>0,'active_destinations'=>0,'ready_destinations'=>0,'reported_printer_queues'=>0,'ready_printer_queues'=>0,'local_backlog_count'=>0,'server_backlog_count'=>0,'server_attention_count'=>0,'stalled_unclaimed_count'=>0,'agents'=>[],'destinations'=>[],'recent_jobs'=>[],'server_queue'=>[]];}
    }

    private function publicStatus(bool $activeProbe=false): array
    {
        $baseUrl=$this->publicClient?->publicBaseUrl() ?: rtrim(trim((string)$this->config->get('public.base_url','')),'/');$base=$this->publicClient?->safeOrigin() ?: $this->safeOrigin($baseUrl);
        $rows=[];
        try{$rows=$this->pdo->query('SELECT channel,status,last_http_status,detail_json,last_attempt_at,last_success_at FROM public_sync_state ORDER BY channel')->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){}
        $transportV2=false;$projectionV2=['pending_events'=>0,'oldest_pending_age_seconds'=>null,'failed_models'=>0,'models'=>[]];
        try{
            $this->pdo->query('SELECT 1 FROM public_projection_model_delivery LIMIT 1');$transportV2=true;
            $pending=(int)$this->pdo->query('SELECT COUNT(*) FROM public_projection_outbox WHERE delivered_at IS NULL')->fetchColumn();
            $oldest=(string)($this->pdo->query('SELECT MIN(created_at) FROM public_projection_outbox WHERE delivered_at IS NULL')->fetchColumn()?:'');
            $modelRows=$this->pdo->query('SELECT model_key,ack_sequence,source_version,last_attempt_at,last_success_at,last_error_code FROM public_projection_model_delivery ORDER BY model_key')->fetchAll(PDO::FETCH_ASSOC)?:[];
            $failedModels=0;foreach($modelRows as $mr)if(trim((string)($mr['last_error_code']??''))!=='')$failedModels++;
            $projectionV2=['pending_events'=>$pending,'oldest_pending_age_seconds'=>$this->age($oldest,'Asia/Tehran'),'failed_models'=>$failedModels,'models'=>$modelRows];
        }catch(Throwable){}
        $latest='';$errors=0;
        foreach($rows as &$row){
            $channel=(string)($row['channel']??'');
            $superseded=$transportV2&&in_array($channel,['read_models','runtime_transport'],true);
            $row['superseded']=$superseded;
            if(!$superseded&&(string)($row['status']??'')==='error')$errors++;
            $detail=json_decode((string)($row['detail_json']??''),true);$row['detail']=is_array($detail)?$detail:[];unset($row['detail_json']);
            $success=(string)($row['last_success_at']??'');
            $attempt=(string)($row['last_attempt_at']??'');
            $row['success_age_seconds']=$this->age($success);
            $row['attempt_age_seconds']=$this->age($attempt);
            if($success!==''&&($latest===''||$success>$latest))$latest=$success;
        }unset($row);
        $rows=\Sokna\Shared\IranTime::transport($rows,'UTC');
        $lastSyncAge=$this->age($latest);
        $configured=$base!==''&&trim((string)$this->config->get('public.shared_secret',''))!=='';
        $live=null;$liveError='';$diag=[];$connectivity=[];$probeCache=$this->loadPublicProbeCache();$probeCacheAge=null;
        if($probeCache!==[]){$diag=(array)($probeCache['diagnostics']??[]);$live=(array)($diag['health']??[]);$connectivity=(array)($diag['connectivity']??[]);$probeCacheAge=$this->age((string)($probeCache['cached_at']??''));}
        $updateControlCache=$this->loadPublicUpdateControlCache();$updateControl=(array)($updateControlCache['status']??[]);$updateControlAge=$this->age((string)($updateControlCache['cached_at']??''));
        $probeAt=(int)(strtotime((string)($probeCache['cached_at']??''))?:0);$controlAt=(int)(strtotime((string)($updateControlCache['cached_at']??''))?:0);$preferUpdateControl=$controlAt>=$probeAt&&$updateControl!==[];
        $updateSnapshot=$preferUpdateControl?(array)($updateControl['update']??[]):(array)($diag['update']??($live['update']??[]));
        $routineUpdateSnapshot=$preferUpdateControl?(array)($updateControl['routine_update']??[]):(array)($diag['routine_update']??($live['routine_update']??[]));
        $updateProtocols=$preferUpdateControl?(array)($updateControl['protocols']??[]):(array)($diag['protocols']??[]);
        // Version identity follows the newest authoritative control state after an update commit.
        // A passive page must not keep showing the older probe-cache version until another WAN probe.
        $effectiveVersion=trim((string)($live['version']??''));
        if($preferUpdateControl){
            $controlVersion=trim((string)($routineUpdateSnapshot['active_version']??$routineUpdateSnapshot['current_version']??''));
            if($controlVersion!=='')$effectiveVersion=$controlVersion;
        }
        if($activeProbe&&$configured&&$this->publicClient!==null){
            try{
                // Explicit diagnostic probes are bounded and single-shot. A WAN outage must not
                // trigger a second fallback wait after the signed diagnostics request times out.
                $diag=$this->publicClient->diagnostics(2)['body'];
                $live=(array)($diag['health']??[]);
                $connectivity=(array)($diag['connectivity']??[]);
                $this->savePublicProbeCache($diag);$probeCacheAge=0;
            }catch(PublicEdgeSyncException $e){
                $liveError=$e->errorCode;
                if($e->errorCode!=='public_unreachable'){
                    try{$health=$this->publicClient->health(2)['body'];if(is_array($health))$live=$health;}catch(Throwable){}
                }
            }catch(Throwable){$liveError='public_probe_failed';}
        }
        $remoteFresh=($connectivity['local_fresh']??null);
        $remoteRuntime=(string)($connectivity['runtime_status']??'');
        $heartbeatAge=$this->age((string)($connectivity['last_seen_at']??''));
        $relayDiagnostics=is_array($diag['relay']??null)?$diag['relay']:[];
        $relayAutomatic=$this->automaticPublicRelayEvidence();
        $relayTriggerReceipts=$this->recentPublicRelayTriggerReceipts();
        $oldestQueuedAge=$this->age((string)($relayDiagnostics['oldest_queued_at']??''));
        $relayQueueStalled=(int)($relayDiagnostics['queued']??0)>0&&$oldestQueuedAge!==null&&$oldestQueuedAge>45;
        $automaticRelayOk=(string)($relayAutomatic['status']??'')==='ok';
        $staleSync=$lastSyncAge!==null&&$lastSyncAge>180;
        $projectionBacklogStale=$transportV2&&(int)($projectionV2['pending_events']??0)>0&&($projectionV2['oldest_pending_age_seconds']??null)!==null&&(int)$projectionV2['oldest_pending_age_seconds']>120;
        $projectionModelFailed=$transportV2&&(int)($projectionV2['failed_models']??0)>0;
        $healthyLive=is_array($live)&&($live['ok']??false)===true;
        if(!$configured)$status='not_configured';
        elseif($activeProbe){
            $status=!$healthyLive||$liveError!==''||$errors>0||$staleSync||$projectionBacklogStale||$projectionModelFailed||$remoteFresh===false||!$automaticRelayOk||$relayQueueStalled
                ?'attention'
                :($latest!==''?'ok':'configured_unprobed');
        }else{
            // Passive product/readiness views use persisted sync evidence only. This keeps Local
            // responsive when WAN/Public is unavailable; explicit "آزمایش ارتباط" owns live I/O.
            $status=$errors>0||$staleSync||$projectionBacklogStale||$projectionModelFailed||!$automaticRelayOk||$relayQueueStalled
                ?'attention'
                :($latest!==''?'ok':'configured_unprobed');
        }
        return [
            'status'=>$status,
            // A diagnostic snapshot never needs Public URL credentials, path, query or fragment.
            // Public Web clients continue to use the configured endpoint independently.
            'base_url'=>$base,
            'base_origin'=>$base,
            'productization'=>'G3.3_LIVE_HEALTH_EMERGENCY',
            'last_sync_at'=>$latest,
            'last_sync_age_seconds'=>$lastSyncAge,
            'sync_channels'=>$rows,
            'sync_error_count'=>$errors,
            'stale_sync'=>$staleSync,
            'transport_v2'=>$transportV2,
            'projection_v2'=>$projectionV2,
            'projection_backlog_stale'=>$projectionBacklogStale,
            'live'=>$live,
            'version'=>$effectiveVersion,
            'update'=>$updateSnapshot,
            'routine_update'=>$routineUpdateSnapshot,
            'update_protocol'=>(int)($live['update_protocol']??(($updateProtocols['routine_delta_v2']??false)?2:1)),
            'update_protocols'=>$updateProtocols,
            'update_control_cache_age_seconds'=>$updateControlAge,
            'emergency_ready'=>(bool)($live['emergency_ready']??false),
            'connectivity'=>$connectivity,
            'edge_heartbeat_age_seconds'=>$heartbeatAge,
            'edge_local_fresh'=>$remoteFresh,
            'edge_runtime_status'=>$remoteRuntime,
            'relay'=>$relayDiagnostics,
            'relay_oldest_queued_age_seconds'=>$oldestQueuedAge,
            'relay_queue_stalled'=>$relayQueueStalled,
            'automatic_relay'=>$relayAutomatic,
            'relay_trigger_receipts'=>$relayTriggerReceipts,
            'recent_logs'=>(array)($diag['recent_logs']??[]),
            'probe_error'=>$liveError,'probe_mode'=>$activeProbe?'active':'passive','probe_cache_age_seconds'=>$probeCacheAge,
            'note'=>'Public health is multi-dimensional: realtime exchange, durable projection backlog, guest publication and live Public health are evaluated independently. Legacy monolithic read-model errors are ignored once transport v2 is active.',
        ];
    }

    private function loadPublicUpdateControlCache(): array
    {
        try{return $this->observability->readJsonFile($this->observability->runtimeDir().DIRECTORY_SEPARATOR.'public-update-control-cache.json');}
        catch(Throwable){return [];}
    }

    private function loadPublicProbeCache(): array
    {
        try{return $this->observability->readJsonFile($this->observability->runtimeDir().DIRECTORY_SEPARATOR.'public-diagnostics-cache.json');}
        catch(Throwable){return [];}
    }

    private function savePublicProbeCache(array $diag): void
    {
        $safe=[
            'health'=>(array)($diag['health']??[]),'connectivity'=>(array)($diag['connectivity']??[]),'relay'=>(array)($diag['relay']??[]),
            'update'=>(array)($diag['update']??[]),'routine_update'=>(array)($diag['routine_update']??[]),'protocols'=>(array)($diag['protocols']??[]),
            'recent_logs'=>array_slice((array)($diag['recent_logs']??[]),0,40),
        ];
        try{$this->observability->atomicJsonWrite($this->observability->runtimeDir().DIRECTORY_SEPARATOR.'public-diagnostics-cache.json',['format'=>'sokna-public-diagnostics-cache-v1','cached_at'=>gmdate('c'),'diagnostics'=>$this->observability->redact($safe)]);}catch(Throwable){}
    }

    private function automaticPublicRelayEvidence(): array
    {
        try{
            $q=$this->pdo->query(
                "SELECT trigger_key,state,result_json,correlation_id,accepted_at FROM runtime_trigger_receipts " .
                "WHERE trigger_key IN ('public.relay_sync','inventory.order_events','public.projection_sync') " .
                "ORDER BY accepted_at DESC,id DESC LIMIT 24"
            );
            $rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        }catch(Throwable){
            return ['status'=>'unavailable','trigger_key'=>'','age_seconds'=>null,'correlation_id'=>'','error_code'=>'runtime_trigger_evidence_unavailable'];
        }
        if($rows===[])return ['status'=>'not_seen','trigger_key'=>'','age_seconds'=>null,'correlation_id'=>'','error_code'=>'automatic_relay_not_seen'];

        // Runtime writes the receipt as "accepted" before the handler finishes. Treating that
        // short in-flight state as a failure made the Connections page flap red/green every
        // few seconds even while the previous relay cycle had completed successfully.
        $candidates=[];
        foreach($rows as $row){
            $state=(string)($row['state']??'');
            if(!in_array($state,['completed','failed'],true))continue;
            $candidate=$this->automaticRelayEvidenceCandidate($row);
            if($candidate!==null)$candidates[]=$candidate;
        }
        if($candidates===[]){
            $row=$rows[0];
            return [
                'status'=>'pending','trigger_key'=>(string)($row['trigger_key']??''),
                'receipt_state'=>(string)($row['state']??''),'accepted_at'=>(string)($row['accepted_at']??''),
                'age_seconds'=>$this->ageRuntimeReceipt((string)($row['accepted_at']??'')),'correlation_id'=>(string)($row['correlation_id']??''),
                'error_code'=>'automatic_relay_in_progress',
            ];
        }

        // Dedicated public.relay_sync is canonical. A fresh success there must not be
        // overwritten by a newer compatibility/projection receipt. If the dedicated cycle
        // is not healthy, any other fresh successful relay-capable trigger is valid recovery
        // evidence. This preserves compatibility with already-running older Runtime catalogs.
        foreach($candidates as $candidate)
            if(($candidate['trigger_key']??'')==='public.relay_sync'&&($candidate['status']??'')==='ok')return $candidate;
        foreach($candidates as $candidate)
            if(($candidate['status']??'')==='ok')return $candidate;
        foreach($candidates as $candidate)
            if(($candidate['trigger_key']??'')==='public.relay_sync')return $candidate;
        return $candidates[0];
    }

    /** @return array<string,mixed>|null */
    private function automaticRelayEvidenceCandidate(array $row): ?array
    {
        $key=(string)($row['trigger_key']??'');$state=(string)($row['state']??'');$accepted=(string)($row['accepted_at']??'');$age=$this->ageRuntimeReceipt($accepted);
        $result=json_decode((string)($row['result_json']??''),true);if(!is_array($result))$result=[];
        $relay=[];$heartbeat=[];$compat=[];$attempted=true;$error='';
        if($key==='inventory.order_events'){
            $compat=is_array($result['public_compat']??null)?$result['public_compat']:[];
            $attempted=($compat['attempted']??false)===true;
            if(!$attempted)return null;
            $relay=is_array($compat['relay']??null)?$compat['relay']:[];
            $heartbeat=is_array($compat['heartbeat']??null)?$compat['heartbeat']:[];
            $success=($compat['success']??false)===true&&($relay['success']??false)===true;
            $freshLimit=45;$error=(string)($compat['error_code']??'');
        }elseif($key==='public.projection_sync'){
            $relay=is_array($result['relay']??null)?$result['relay']:[];
            $success=$state==='completed'&&($relay['success']??false)===true;
            $freshLimit=75;$error=(string)($result['error_code']??($state==='failed'?'runtime_handler_failed':''));
        }else{
            $relay=is_array($result['relay']??null)?$result['relay']:[];
            $heartbeat=is_array($result['heartbeat']??null)?$result['heartbeat']:[];
            $success=$state==='completed'&&($relay['success']??false)===true;
            $freshLimit=25;$error=(string)($result['error_code']??($state==='failed'?'runtime_handler_failed':''));
        }
        $fresh=$age!==null&&$age<=$freshLimit;
        $status=$success&&$fresh?'ok':($success?'stale':'failed');
        return [
            'status'=>$status,'trigger_key'=>$key,'receipt_state'=>$state,'accepted_at'=>$accepted,'age_seconds'=>$age,'fresh_seconds'=>$freshLimit,
            'correlation_id'=>(string)($row['correlation_id']??''),'attempted'=>$attempted,'relay'=>$relay,'heartbeat'=>$heartbeat,
            'error_code'=>$error!==''?$error:($status==='stale'?'automatic_relay_stale':($success?'':'automatic_relay_failed')),
            'exception_class'=>(string)($result['exception_class']??($compat['exception_class']??'')),
            'exception_detail_code'=>(string)($result['exception_detail_code']??''),
            'exception_fingerprint'=>(string)($result['exception_fingerprint']??($compat['exception_fingerprint']??'')),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function recentPublicRelayTriggerReceipts(int $limit=20): array
    {
        $limit=max(1,min(50,$limit));
        try{
            $q=$this->pdo->query(
                "SELECT trigger_key,state,result_json,correlation_id,accepted_at FROM runtime_trigger_receipts " .
                "WHERE trigger_key IN ('public.relay_sync','inventory.order_events','public.projection_sync') " .
                "ORDER BY accepted_at DESC,id DESC LIMIT {$limit}"
            );
            $rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        }catch(Throwable){return [];}
        $out=[];
        foreach($rows as $row){
            $result=json_decode((string)($row['result_json']??''),true);if(!is_array($result))$result=[];
            $compat=is_array($result['public_compat']??null)?$result['public_compat']:[];
            $error=(string)($result['error_code']??($compat['error_code']??''));
            $out[]=[
                'trigger_key'=>(string)($row['trigger_key']??''),'state'=>(string)($row['state']??''),
                'accepted_at'=>(string)($row['accepted_at']??''),'age_seconds'=>$this->ageRuntimeReceipt((string)($row['accepted_at']??'')),
                'correlation_id'=>(string)($row['correlation_id']??''),'error_code'=>$error,
                'exception_class'=>(string)($result['exception_class']??($compat['exception_class']??'')),
                'exception_detail_code'=>(string)($result['exception_detail_code']??''),
                'exception_fingerprint'=>(string)($result['exception_fingerprint']??($compat['exception_fingerprint']??'')),
            ];
        }
        return $out;
    }

    private function safeOrigin(string $value): string
    {
        $value=trim($value);if($value==='')return '';$parts=parse_url($value);if(!is_array($parts))return '';$scheme=strtolower((string)($parts['scheme']??''));$host=(string)($parts['host']??'');if(!in_array($scheme,['http','https'],true)||$host==='')return '';$port=isset($parts['port'])?':'.(int)$parts['port']:'';return $scheme.'://'.$host.$port;
    }
    private function effectiveUploadLimitBytes(): int
    {
        $upload=$this->iniBytes((string)ini_get('upload_max_filesize'));$post=$this->iniBytes((string)ini_get('post_max_size'));
        if($upload<=0)return max(0,$post);if($post<=0)return $upload;return min($upload,$post);
    }
    private function iniBytes(string $value): int
    {
        $value=trim($value);if($value==='')return 0;$last=strtolower(substr($value,-1));$n=(float)$value;
        return (int)round($n*match($last){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1});
    }
    private function readTrim(string $path): string{$v=@file_get_contents($path);return is_string($v)?trim($v):'';}
    /** Runtime receipt DATETIME uses the Local PDO session timezone, not UTC. */
    private function ageRuntimeReceipt(string $value): ?int
    {
        $value=trim($value);
        if($value==='')return null;
        // PHP timezone is explicitly configured from app.timezone by Bootstrap.
        $ts=strtotime($value);
        if($ts===false)return null;
        return max(0,time()-$ts);
    }

    private function age(string $value,string $basis='UTC'): ?int
    {
        $value=trim($value);if($value==='')return null;
        // MariaDB DATETIME values in this product are written with UTC_TIMESTAMP() but carry no
        // timezone marker. The PHP process runs in Asia/Tehran, so strtotime() alone makes them
        // appear ~3.5 hours stale. Treat bare SQL DATETIME as UTC; preserve explicit offsets/Z.
        if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)){
            try{$ts=(new \DateTimeImmutable($value,new \DateTimeZone($basis)))->getTimestamp();}catch(\Throwable){return null;}
        }else{$ts=strtotime($value);if($ts===false)return null;}
        return max(0,time()-$ts);
    }
    private function check(string $id,bool $ok,string $label,string $severity): array{return ['id'=>$id,'ok'=>$ok,'label'=>$label,'severity'=>$severity];}
}
