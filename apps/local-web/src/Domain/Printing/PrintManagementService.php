<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Printing;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PrintManagementService
{
    public const HEARTBEAT_FRESH_SECONDS=180;

    public function __construct(private readonly PDO $pdo,private readonly PrintTemplatePackageService $templates) {}

    public function snapshot(): array
    {
        $agents=$this->pdo->query(
            "SELECT id,name,token_hint,active,hostname,agent_version,os_version,printers_json,health_json,bridge_protocol_version,bridge_port,bridge_pairing_id,bridge_origin,\n".
            "       local_backlog_count,local_unknown_count,sqlite_health,disk_free_mb,last_heartbeat_at,last_seen_at,last_error,created_at\n".
            "FROM print_agents ORDER BY active DESC,id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $agentMap=[];
        foreach($agents as &$agent){
            $agent['printers']=$this->normalizePrinters($this->decode((string)($agent['printers_json']??'')));
            $agent['health']=$this->decode((string)($agent['health_json']??''));
            $age=$this->age((string)($agent['last_heartbeat_at']??''));
            $active=(int)($agent['active']??0)===1;
            $version=trim((string)($agent['agent_version']??''));
            $compatible=$version!==''&&version_compare($version,PrintService::MINIMUM_AGENT_VERSION,'>=');
            $connected=$active&&$age!==null&&$age<=self::HEARTBEAT_FRESH_SECONDS;
            $agent['heartbeat_age_seconds']=$age;
            $agent['minimum_supported_version']=PrintService::MINIMUM_AGENT_VERSION;
            $agent['recommended_version']=PrintService::RECOMMENDED_AGENT_VERSION;
            $agent['version_compatible']=$compatible;
            $agent['connected']=$connected;
            $agent['connection_status']=!$active?'inactive':($age===null?'never_connected':($connected?'connected':'stale'));
            $agent['preview_ready']=$connected&&$compatible&&(int)($agent['bridge_protocol_version']??0)===1&&(int)($agent['bridge_port']??0)>0&&trim((string)($agent['bridge_pairing_id']??''))!=='';
            $agent['ready_printer_count']=count(array_filter($agent['printers'],static fn(array $p):bool=>!empty($p['ready'])));
            $agent['printer_count']=count($agent['printers']);
            $agent['operational']=$connected&&$compatible&&trim((string)($agent['sqlite_health']??''))==='ok';
            $agent['attention_code']=$this->agentAttentionCode($agent);
            unset($agent['printers_json'],$agent['health_json'],$agent['bridge_pairing_id']);
            $agentMap[(int)$agent['id']]=$agent;
        }unset($agent);

        $destinations=$this->pdo->query(
            "SELECT d.destination_key,d.label,d.destination_type,d.agent_id,d.windows_queue_name,d.fallback_agent_id,d.fallback_windows_queue_name,d.active,d.required_for_operation,\n".
            "       d.paper_width_mm,d.printable_width_mm,d.copies,d.layout_mode,d.updated_at,a.name agent_name,fa.name fallback_agent_name\n".
            "FROM print_destinations d LEFT JOIN print_agents a ON a.id=d.agent_id LEFT JOIN print_agents fa ON fa.id=d.fallback_agent_id ORDER BY d.destination_key"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach($destinations as &$destination){
            $agent=$agentMap[(int)($destination['agent_id']??0)]??null;$fallback=$agentMap[(int)($destination['fallback_agent_id']??0)]??null;
            $state=$this->destinationState($destination,is_array($agent)?$agent:null,is_array($fallback)?$fallback:null);
            $destination+=$state;
        }unset($destination);

        $jobSelect=
            "SELECT j.id,j.job_type,j.destination_key,j.status,j.blocked_reason,j.entity_type,j.entity_id,j.attempt_count,j.last_error_code,j.last_error,\n".
            "       j.resolution_state,j.claimed_by_agent_id,j.claimed_at,j.accepted_at,j.submitted_at,j.completed_at,j.created_at,j.updated_at,\n".
            "       GREATEST(0,TIMESTAMPDIFF(SECOND,j.updated_at,NOW())) age_seconds,d.label destination_label,a.name agent_name\n".
            "FROM print_jobs j JOIN print_destinations d ON d.destination_key=j.destination_key\n".
            "LEFT JOIN print_agents a ON a.id=j.claimed_by_agent_id ";
        $jobs=$this->pdo->query($jobSelect."ORDER BY j.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        $activeJobs=$this->pdo->query($jobSelect."WHERE j.status IN ('pending','reserved','claimed','started','failed','blocked','unknown','recovery_hold') ORDER BY j.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $historyJobs=$this->pdo->query($jobSelect."WHERE j.status IN ('submitted','cancelled') ORDER BY j.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);

        $counts=['pending'=>0,'reserved'=>0,'claimed'=>0,'started'=>0,'submitted'=>0,'failed'=>0,'unknown'=>0,'recovery_hold'=>0,'cancelled'=>0,'blocked'=>0];
        $rows=$this->pdo->query("SELECT status,COUNT(*) c FROM print_jobs GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){$status=(string)($row['status']??'');if(array_key_exists($status,$counts))$counts[$status]=(int)$row['c'];}
        $serverBacklog=$counts['pending']+$counts['reserved']+$counts['claimed']+$counts['started'];
        $attention=$counts['failed']+$counts['unknown']+$counts['recovery_hold']+$counts['blocked'];
        $stalled=(int)$this->pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status='pending' AND attempt_count=0 AND created_at < (NOW() - INTERVAL 60 SECOND)")->fetchColumn();

        return [
            'agents'=>$agents,
            'destinations'=>$destinations,
            'recent_jobs'=>$jobs,
            'active_jobs'=>$activeJobs,
            'history_jobs'=>$historyJobs,
            'templates'=>$this->templates->snapshot(),
            'server_queue'=>[
                'counts'=>$counts,
                'backlog_count'=>$serverBacklog,
                'attention_count'=>$attention,
                'stalled_unclaimed_count'=>$stalled,
            ],
            'heartbeat_fresh_seconds'=>self::HEARTBEAT_FRESH_SECONDS,
        ];
    }

    /** @param array<string,mixed> $agent */
    private function agentAttentionCode(array $agent): string
    {
        if((int)($agent['active']??0)!==1)return 'inactive';
        if(($agent['connection_status']??'')==='never_connected')return 'never_connected';
        if(($agent['connection_status']??'')==='stale')return 'stale';
        if(empty($agent['version_compatible']))return 'version_incompatible';
        if(trim((string)($agent['sqlite_health']??''))!=='ok')return 'queue_storage_unhealthy';
        if(trim((string)($agent['last_error']??''))!=='')return 'agent_error';
        return 'ready';
    }

    /** @param array<string,mixed> $destination @param null|array<string,mixed> $agent @param null|array<string,mixed> $fallback */
    private function destinationState(array $destination,?array $agent,?array $fallback): array
    {
        if((int)($destination['active']??0)!==1)return ['readiness_code'=>'inactive','readiness_label'=>'غیرفعال','configuration_valid'=>false,'operational'=>false,'using_fallback'=>false];
        $primary=$this->routeState($agent,trim((string)($destination['windows_queue_name']??'')));
        if(!empty($primary['operational']))return array_merge($primary,['readiness_code'=>'ready','readiness_label'=>'آماده چاپ','using_fallback'=>false,'primary_readiness'=>$primary['readiness_code']]);
        $fallbackQueue=trim((string)($destination['fallback_windows_queue_name']??''));
        $fallbackState=$fallbackQueue!==''?$this->routeState($fallback,$fallbackQueue):['readiness_code'=>'fallback_not_configured','readiness_label'=>'مسیر پشتیبان تعریف نشده','configuration_valid'=>false,'operational'=>false];
        if(!empty($fallbackState['operational']))return array_merge($fallbackState,['readiness_code'=>'fallback_ready','readiness_label'=>'آماده از مسیر پشتیبان','using_fallback'=>true,'primary_readiness'=>$primary['readiness_code'],'primary_readiness_label'=>$primary['readiness_label']]);
        return ['readiness_code'=>(string)$primary['readiness_code'],'readiness_label'=>(string)$primary['readiness_label'],'configuration_valid'=>!empty($primary['configuration_valid'])||!empty($fallbackState['configuration_valid']),'operational'=>false,'using_fallback'=>false,'primary_readiness'=>$primary['readiness_code'],'primary_readiness_label'=>$primary['readiness_label'],'fallback_readiness'=>$fallbackState['readiness_code'],'fallback_readiness_label'=>$fallbackState['readiness_label']];
    }

    /** @param null|array<string,mixed> $agent */
    private function routeState(?array $agent,string $queue): array
    {
        if($agent===null)return ['readiness_code'=>'agent_missing','readiness_label'=>'دستگاه چاپ انتخاب نشده','configuration_valid'=>false,'operational'=>false];
        $connection=(string)($agent['connection_status']??'');
        if($connection==='never_connected')return ['readiness_code'=>'agent_never_connected','readiness_label'=>'سرویس چاپ هنوز متصل نشده','configuration_valid'=>false,'operational'=>false];
        if($connection!=='connected')return ['readiness_code'=>'agent_stale','readiness_label'=>'ارتباط سرویس چاپ قدیمی است','configuration_valid'=>false,'operational'=>false];
        if(empty($agent['version_compatible']))return ['readiness_code'=>'agent_version_incompatible','readiness_label'=>'نسخه سرویس چاپ سازگار نیست','configuration_valid'=>false,'operational'=>false];
        if($queue==='')return ['readiness_code'=>'queue_missing','readiness_label'=>'چاپگر ویندوز انتخاب نشده','configuration_valid'=>false,'operational'=>false];
        $printer=$this->findPrinter((array)($agent['printers']??[]),$queue);
        if($printer===null)return ['readiness_code'=>'queue_not_reported','readiness_label'=>'این چاپگر توسط ویندوز گزارش نشده','configuration_valid'=>false,'operational'=>false];
        if(empty($printer['automation_capable']))return ['readiness_code'=>'queue_not_automation_capable','readiness_label'=>'این صف برای چاپ خودکار قابل استفاده نیست','configuration_valid'=>false,'operational'=>false,'printer'=>$printer];
        if(empty($printer['ready']))return ['readiness_code'=>(string)$printer['readiness_code'],'readiness_label'=>(string)$printer['readiness_label'],'configuration_valid'=>true,'operational'=>false,'printer'=>$printer];
        return ['readiness_code'=>'ready','readiness_label'=>'آماده چاپ','configuration_valid'=>true,'operational'=>true,'printer'=>$printer];
    }

    /** @param array<int,array<string,mixed>> $printers */
    private function findPrinter(array $printers,string $name): ?array
    {
        foreach($printers as $printer)if(strcasecmp(trim((string)($printer['name']??'')),trim($name))===0)return $printer;
        return null;
    }

    /** @param array<int,mixed> $rows @return array<int,array<string,mixed>> */
    private function normalizePrinters(array $rows): array
    {
        $out=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $automation=$this->boolValue($row['automation_capable']??false);
            $offline=$this->boolValue($row['offline']??false);$paused=$this->boolValue($row['paused']??false);$paperOut=$this->boolValue($row['paper_out']??false);$error=$this->boolValue($row['error']??false);
            $name=trim((string)($row['name']??''));$port=trim((string)($row['port']??''));
            $interactiveVirtual=!$automation&&(stripos($name,'Microsoft Print to PDF')!==false||stripos($name,'Microsoft XPS Document Writer')!==false||in_array(strtoupper($port),['PORTPROMPT:','FILE:','SHRFAX:'],true));
            [$code,$label]=!$automation?['queue_not_automation_capable',$interactiveVirtual?'صف تعاملی است؛ Windows Service نمی‌تواند پنجره Save As را مدیریت کند':'برای چاپ خودکار قابل استفاده نیست']:
                ($offline?['printer_offline','چاپگر آفلاین است']:
                ($paused?['printer_paused','صف چاپ متوقف است']:
                ($paperOut?['printer_paper_out','کاغذ چاپگر تمام شده است']:
                ($error?['printer_error','چاپگر خطا دارد']:['ready','آماده چاپ']))));
            $out[]=[
                'name'=>$name,
                'offline'=>$offline,'paused'=>$paused,'paper_out'=>$paperOut,'error'=>$error,
                'jobs'=>max(0,(int)($row['jobs']??0)),
                'driver'=>trim((string)($row['driver']??'')),
                'port'=>$port,
                'automation_capable'=>$automation,
                'ready'=>$code==='ready','readiness_code'=>$code,'readiness_label'=>$label,
            ];
        }
        return $out;
    }

    private function boolValue(mixed $value): bool
    {
        if(is_bool($value))return $value;
        if(is_int($value)||is_float($value))return (int)$value===1;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function age(string $value): ?int
    {
        $value=trim($value);if($value==='')return null;
        try{
            if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/D',$value))$ts=(new DateTimeImmutable($value,new DateTimeZone(date_default_timezone_get())))->getTimestamp();
            else $ts=(new DateTimeImmutable($value))->getTimestamp();
        }catch(\Throwable){return null;}
        return max(0,time()-$ts);
    }

    private function decode(string $json): array
    {
        if(trim($json)==='')return [];
        $value=json_decode($json,true);
        return is_array($value)?$value:[];
    }
}
