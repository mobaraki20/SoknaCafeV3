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
        private readonly ?PublicEdgeSyncClient $publicClient=null,
    ) {}

    public function snapshot(): array
    {
        $local=$this->localStatus();$database=$this->databaseStatus();$runtime=$this->runtimeStatus();$print=$this->printStatus();$public=$this->publicStatus();
        $checks=[
            $this->check('setup_lock',(bool)($local['setup']['installed']??false),'Setup lock','critical'),
            $this->check('data_dir',(bool)($local['data_dir_writable']??false),'Data directory writable','critical'),
            $this->check('pdo_mysql',in_array('mysql',PDO::getAvailableDrivers(),true),'PDO MySQL','critical'),
            $this->check('migrations',(int)($database['pending_migrations']??1)===0,'Schema migrations current','critical'),
            $this->check('database_version',(bool)($database['mariadb_11_4']??false),'MariaDB 11.4.x','critical'),
            $this->check('print_required_destinations',(int)($print['required_unready']??0)===0,'Required print destinations ready','warning'),
            $this->check('php_zip',class_exists(\ZipArchive::class),'PHP ZIP for Local updater','warning'),
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
        $version=$this->readTrim($this->packageRoot.DIRECTORY_SEPARATOR.'VERSION.txt');$setup=(new BrowserSetupService($this->packageRoot,$this->localWebRoot))->status();
        $free=@disk_free_space($this->observability->dataRoot());$installation=(string)$this->config->get('installation.id','');
        return [
            'status'=>($setup['installed']??false)&&is_writable($this->observability->dataRoot())?'ok':'attention','version'=>$version!==''?$version:'unknown','php_version'=>PHP_VERSION,
            'setup'=>$setup,'data_dir_writable'=>is_dir($this->observability->dataRoot())&&is_writable($this->observability->dataRoot()),'disk_free_mb'=>$free===false?null:(int)floor($free/1048576),
            'installation_hint'=>$installation===''?'':substr(hash('sha256',$installation),0,12),
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
        try{
            $rows=$this->pdo->query("SELECT runtime_instance_id,MAX(accepted_at) last_seen,SUM(state='failed') failed_count,COUNT(*) receipt_count FROM runtime_trigger_receipts GROUP BY runtime_instance_id ORDER BY last_seen DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as &$row){$row['failed_count']=(int)$row['failed_count'];$row['receipt_count']=(int)$row['receipt_count'];$row['age_seconds']=$this->age((string)$row['last_seen']);}unset($row);
            $latest=$rows[0]??null;$state=$latest===null?'not_seen':(((int)($latest['age_seconds']??999999)<=3600)?'observed_recently':'observed_stale');
            return ['status'=>$state,'contract_version'=>1,'instances'=>$rows,'note'=>'Runtime health is evidence-based from Local trigger receipts; no business payload is accepted.'];
        }catch(Throwable){return ['status'=>'unavailable','contract_version'=>1,'instances'=>[]];}
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
        $base=$this->safeOrigin((string)$this->config->get('public.base_url',''));$rows=[];
        try{$rows=$this->pdo->query('SELECT channel,status,last_http_status,last_attempt_at,last_success_at FROM public_sync_state ORDER BY channel')->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){}
        $latest='';$errors=0;foreach($rows as $r){if((string)($r['status']??'')==='error')$errors++;$v=(string)($r['last_success_at']??'');if($v!==''&&($latest===''||$v>$latest))$latest=$v;}
        $configured=$base!==''&&trim((string)$this->config->get('public.shared_secret',''))!=='';$live=null;$liveError='';
        $diag=[];if($configured&&$this->publicClient!==null){try{$diag=$this->publicClient->diagnostics()['body'];$live=(array)($diag['health']??[]);}catch(PublicEdgeSyncException $e){$liveError=$e->errorCode;try{$live=$this->publicClient->health()['body'];}catch(Throwable){}}catch(Throwable){$liveError='public_probe_failed';}}
        $status=!$configured?'not_configured':(is_array($live)&&($live['ok']??false)?'ok':($liveError!==''?'attention':($errors>0?'attention':($latest!==''?'observed_recently':'configured_unprobed'))));
        return ['status'=>$status,'base_origin'=>$base,'productization'=>'G3.3_LIVE_HEALTH_EMERGENCY','last_sync_at'=>$latest,'sync_channels'=>$rows,'sync_error_count'=>$errors,'live'=>$live,'version'=>(string)($live['version']??''),'update'=>(array)($diag['update']??($live['update']??[])),'emergency_ready'=>(bool)($live['emergency_ready']??false),'recent_logs'=>(array)($diag['recent_logs']??[]),'probe_error'=>$liveError,'note'=>'Public live health, bounded emergency logs and Public-owned lifecycle are projected into Local.'];
    }

    private function safeOrigin(string $value): string
    {
        $value=trim($value);if($value==='')return '';$parts=parse_url($value);if(!is_array($parts))return '';$scheme=strtolower((string)($parts['scheme']??''));$host=(string)($parts['host']??'');if(!in_array($scheme,['http','https'],true)||$host==='')return '';$port=isset($parts['port'])?':'.(int)$parts['port']:'';return $scheme.'://'.$host.$port;
    }
    private function readTrim(string $path): string{$v=@file_get_contents($path);return is_string($v)?trim($v):'';}
    private function age(string $value): ?int{$ts=strtotime($value);return $ts===false?null:max(0,time()-$ts);}
    private function check(string $id,bool $ok,string $label,string $severity): array{return ['id'=>$id,'ok'=>$ok,'label'=>$label,'severity'=>$severity];}
}
