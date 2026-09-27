<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Printing;

use PDO;

final class PrintManagementService
{
    public function __construct(private readonly PDO $pdo,private readonly PrintTemplatePackageService $templates) {}

    public function snapshot(): array
    {
        $agents=$this->pdo->query(
            "SELECT id,name,token_hint,active,hostname,agent_version,os_version,printers_json,health_json,bridge_protocol_version,bridge_port,bridge_pairing_id,bridge_origin,\n".
            "       local_backlog_count,local_unknown_count,sqlite_health,disk_free_mb,last_heartbeat_at,last_seen_at,last_error,created_at\n".
            "FROM print_agents ORDER BY active DESC,id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach($agents as &$agent){
            $agent['printers']=$this->decode((string)($agent['printers_json']??''));
            $agent['health']=$this->decode((string)($agent['health_json']??''));
            $agent['preview_ready']=(int)($agent['active']??0)===1&&(int)($agent['bridge_protocol_version']??0)===1&&(int)($agent['bridge_port']??0)>0&&trim((string)($agent['bridge_pairing_id']??''))!=='';
            unset($agent['printers_json'],$agent['health_json'],$agent['bridge_pairing_id']);
        }unset($agent);
        $destinations=$this->pdo->query(
            "SELECT d.destination_key,d.label,d.destination_type,d.agent_id,d.windows_queue_name,d.active,d.required_for_operation,\n".
            "       d.paper_width_mm,d.printable_width_mm,d.copies,d.layout_mode,d.updated_at,a.name agent_name\n".
            "FROM print_destinations d LEFT JOIN print_agents a ON a.id=d.agent_id ORDER BY d.destination_key"
        )->fetchAll(PDO::FETCH_ASSOC);
        $jobs=$this->pdo->query(
            "SELECT j.id,j.job_type,j.destination_key,j.status,j.blocked_reason,j.entity_type,j.entity_id,j.attempt_count,j.last_error_code,j.last_error,\n".
            "       j.resolution_state,j.created_at,j.updated_at,d.label destination_label,a.name agent_name\n".
            "FROM print_jobs j JOIN print_destinations d ON d.destination_key=j.destination_key\n".
            "LEFT JOIN print_agents a ON a.id=j.claimed_by_agent_id ORDER BY j.id DESC LIMIT 60"
        )->fetchAll(PDO::FETCH_ASSOC);
        return ['agents'=>$agents,'destinations'=>$destinations,'recent_jobs'=>$jobs,'templates'=>$this->templates->snapshot()];
    }

    private function decode(string $json): array
    {
        if(trim($json)==='')return [];
        $value=json_decode($json,true);
        return is_array($value)?$value:[];
    }
}
