<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use PDO;
use Sokna\Local\Core\Config;
use Sokna\Local\Core\Migrations;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Printing\PrintManagementService;
use Sokna\Local\Setup\BrowserSetupService;
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
        ];
        $critical=count(array_filter($checks,static fn(array $c):bool=>$c['severity']==='critical'&&!$c['ok']));$warnings=count(array_filter($checks,static fn(array $c):bool=>$c['severity']==='warning'&&!$c['ok']));
        return [
            'generated_at'=>gmdate('c'),'summary'=>['status'=>$critical>0?'critical':($warnings>0?'warning':'ok'),'critical_count'=>$critical,'warning_count'=>$warnings],
            'checks'=>$checks,'components'=>['local_web'=>$local,'database'=>$database,'runtime'=>$runtime,'print_agent'=>$print,'public_edge'=>$public],
            'recent_logs'=>$this->bundles->recentLogs(80),
        ];
    }

    public function createSupportBundle(): array{return $this->bundles->create($this->snapshot());}
    public function supportBundlePath(string $id): string{return $this->bundles->resolve($id);}

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
        $base=$this->safeOrigin((string)$this->config->get('public.base_url',''));
        return ['status'=>$base===''?'not_configured':'configured_unprobed','base_origin'=>$base,'productization'=>'G3_PENDING','note'=>'Public Edge active probing/update is intentionally deferred until G3 productization.'];
    }

    private function safeOrigin(string $value): string
    {
        $value=trim($value);if($value==='')return '';$parts=parse_url($value);if(!is_array($parts))return '';$scheme=strtolower((string)($parts['scheme']??''));$host=(string)($parts['host']??'');if(!in_array($scheme,['http','https'],true)||$host==='')return '';$port=isset($parts['port'])?':'.(int)$parts['port']:'';return $scheme.'://'.$host.$port;
    }
    private function readTrim(string $path): string{$v=@file_get_contents($path);return is_string($v)?trim($v):'';}
    private function age(string $value): ?int{$ts=strtotime($value);return $ts===false?null:max(0,time()-$ts);}
    private function check(string $id,bool $ok,string $label,string $severity): array{return ['id'=>$id,'ok'=>$ok,'label'=>$label,'severity'=>$severity];}
}
