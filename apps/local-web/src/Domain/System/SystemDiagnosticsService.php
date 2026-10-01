<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use PDO;
use Sokna\Local\Core\Config;
use Sokna\Local\Core\Migrations;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Printing\PrintManagementService;
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
    ) {}

    public function snapshot(): array
    {
        $local=$this->localStatus();$database=$this->databaseStatus();$runtime=$this->runtimeStatus();$print=$this->printStatus();$public=$this->publicStatus();
        $uploadLimit=$this->effectiveUploadLimitBytes();
        $checks=[
            $this->check('setup_lock',(bool)($local['setup']['installed']??false),'Setup lock','critical'),
            $this->check('data_dir',(bool)($local['data_dir_writable']??false),'Data directory writable','critical'),
            $this->check('pdo_mysql',in_array('mysql',PDO::getAvailableDrivers(),true),'PDO MySQL','critical'),
            $this->check('migrations',(int)($database['pending_migrations']??1)===0,'Schema migrations current','critical'),
            $this->check('database_version',(bool)($database['mariadb_11_4']??false),'MariaDB 11.4.x','critical'),
            $this->check('runtime_delivery',(string)($runtime['status']??'')==='observed_recently','Windows Runtime delivering Local triggers','warning'),
            $this->check('print_required_destinations',(int)($print['required_unready']??0)===0,'Required print destinations ready','warning'),
            $this->check('public_edge_connectivity',(string)($public['status']??'')==='ok','Public Edge live sync and heartbeat','warning'),
            $this->check('php_zip',class_exists(\ZipArchive::class),'PHP ZIP for Local updater','warning'),
            $this->check('update_chunk_transport',is_file($this->localWebRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'system'.DIRECTORY_SEPARATOR.'update-chunk.php'),'Chunked Local update transport','warning'),
            $this->check('stable_recovery',is_file($this->localWebRoot.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'local-recovery.php'),'Stable Local recovery entrypoint','warning'),
        ];
        $critical=count(array_filter($checks,static fn(array $c):bool=>$c['severity']==='critical'&&!$c['ok']));$warnings=count(array_filter($checks,static fn(array $c):bool=>$c['severity']==='warning'&&!$c['ok']));
        return [
            'generated_at'=>gmdate('c'),'summary'=>['status'=>$critical>0?'critical':($warnings>0?'warning':'ok'),'critical_count'=>$critical,'warning_count'=>$warnings],
            'checks'=>$checks,'components'=>['local_web'=>$local,'database'=>$database,'runtime'=>$runtime,'print_agent'=>$print,'public_edge'=>$public],
            'recent_logs'=>$this->mergedLogs($public),
        ];
    }

    public function createSupportBundle(): array{return $this->bundles->create($this->snapshot());}
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

    private function runtimeStatus(): array
    {
        $receipts=RuntimeEvidence::snapshot($this->pdo);
        $probe=$this->runtimeHealthClient?->probe() ?? ['status'=>'not_configured','http_status'=>0,'error_code'=>'runtime_health_client_missing','health'=>null];
        $runtime=RuntimeEvidence::combine($receipts,$probe);
        $runtime['contract_version']=1;
        $runtime['note']='Runtime is healthy only when the loopback /v1/health probe, real scheduler-cycle evidence, and fresh Local trigger receipts agree.';
        return $runtime;
    }

    private function printStatus(): array
    {
        try{
            $snap=$this->printing->snapshot();$agents=(array)($snap['agents']??[]);$destinations=(array)($snap['destinations']??[]);$active=0;$stale=0;$backlog=0;
            foreach($agents as &$a){$a['heartbeat_age_seconds']=$this->age((string)($a['last_heartbeat_at']??''));if((int)($a['active']??0)===1){$active++;if($a['heartbeat_age_seconds']===null||$a['heartbeat_age_seconds']>180)$stale++;}$backlog+=max(0,(int)($a['local_backlog_count']??0));}unset($a);
            $agentIds=[];foreach($agents as $a)if((int)($a['active']??0)===1&&($a['heartbeat_age_seconds']??999999)<=180)$agentIds[(int)$a['id']]=true;
            $requiredUnready=0;foreach($destinations as $d)if((int)($d['active']??0)===1&&(int)($d['required_for_operation']??0)===1&&!isset($agentIds[(int)($d['agent_id']??0)]))$requiredUnready++;
            return ['status'=>$requiredUnready>0||$stale>0?'attention':'ok','protocol_version'=>4,'active_agents'=>$active,'stale_agents'=>$stale,'required_unready'=>$requiredUnready,'local_backlog_count'=>$backlog,'agents'=>$agents,'destinations'=>$destinations,'recent_jobs'=>(array)($snap['recent_jobs']??[])];
        }catch(Throwable){return ['status'=>'unavailable','protocol_version'=>4,'active_agents'=>0,'stale_agents'=>0,'required_unready'=>0,'local_backlog_count'=>0,'agents'=>[],'destinations'=>[],'recent_jobs'=>[]];}
    }

    private function publicStatus(): array
    {
        $baseUrl=$this->publicClient?->publicBaseUrl() ?: rtrim(trim((string)$this->config->get('public.base_url','')),'/');$base=$this->publicClient?->safeOrigin() ?: $this->safeOrigin($baseUrl);
        $rows=[];
        try{$rows=$this->pdo->query('SELECT channel,status,last_http_status,last_attempt_at,last_success_at FROM public_sync_state ORDER BY channel')->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){}
        $latest='';$errors=0;
        foreach($rows as &$row){
            if((string)($row['status']??'')==='error')$errors++;
            $success=(string)($row['last_success_at']??'');
            $attempt=(string)($row['last_attempt_at']??'');
            $row['success_age_seconds']=$this->age($success);
            $row['attempt_age_seconds']=$this->age($attempt);
            if($success!==''&&($latest===''||$success>$latest))$latest=$success;
        }unset($row);
        $lastSyncAge=$this->age($latest);
        $configured=$base!==''&&trim((string)$this->config->get('public.shared_secret',''))!=='';
        $live=null;$liveError='';$diag=[];$connectivity=[];
        if($configured&&$this->publicClient!==null){
            try{
                $diag=$this->publicClient->diagnostics()['body'];
                $live=(array)($diag['health']??[]);
                $connectivity=(array)($diag['connectivity']??[]);
            }catch(PublicEdgeSyncException $e){
                $liveError=$e->errorCode;
                try{$live=$this->publicClient->health()['body'];}catch(Throwable){}
            }catch(Throwable){$liveError='public_probe_failed';}
        }
        $remoteFresh=($connectivity['local_fresh']??null);
        $remoteRuntime=(string)($connectivity['runtime_status']??'');
        $heartbeatAge=$this->age((string)($connectivity['last_seen_at']??''));
        $staleSync=$lastSyncAge!==null&&$lastSyncAge>180;
        $healthyLive=is_array($live)&&($live['ok']??false)===true;
        $status=!$configured?'not_configured':(
            !$healthyLive||$liveError!==''||$errors>0||$staleSync||$remoteFresh===false
                ?'attention'
                :($latest!==''?'ok':'configured_unprobed')
        );
        return [
            'status'=>$status,
            'base_url'=>$baseUrl,
            'base_origin'=>$base,
            'productization'=>'G3.3_LIVE_HEALTH_EMERGENCY',
            'last_sync_at'=>$latest,
            'last_sync_age_seconds'=>$lastSyncAge,
            'sync_channels'=>$rows,
            'sync_error_count'=>$errors,
            'stale_sync'=>$staleSync,
            'live'=>$live,
            'version'=>(string)($live['version']??''),
            'update'=>(array)($diag['update']??($live['update']??[])),
            'emergency_ready'=>(bool)($live['emergency_ready']??false),
            'connectivity'=>$connectivity,
            'edge_heartbeat_age_seconds'=>$heartbeatAge,
            'edge_local_fresh'=>$remoteFresh,
            'edge_runtime_status'=>$remoteRuntime,
            'recent_logs'=>(array)($diag['recent_logs']??[]),
            'probe_error'=>$liveError,
            'note'=>'Public health includes Local-observed sync state plus Edge-observed heartbeat/connectivity evidence.',
        ];
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
    private function age(string $value): ?int{$ts=strtotime($value);return $ts===false?null:max(0,time()-$ts);}
    private function check(string $id,bool $ok,string $label,string $severity): array{return ['id'=>$id,'ok'=>$ok,'label'=>$label,'severity'=>$severity];}
}
