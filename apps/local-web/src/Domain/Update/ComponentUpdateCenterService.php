<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Update;
use Sokna\Local\Domain\System\SystemDiagnosticsService;
use Throwable;
final class ComponentUpdateCenterService
{
    public function __construct(private readonly SystemDiagnosticsService $diagnostics,private readonly LocalUpdateService $local,private readonly string $registryFile){}
    public function snapshot(): array
    {
        $health=$this->diagnostics->snapshot();$registry=$this->read($this->registryFile);$defs=(array)($registry['components']??[]);$hc=(array)($health['components']??[]);
        $map=['local-web'=>'local_web','public-edge'=>'public_edge','windows-runtime'=>'runtime','print-agent'=>'print_agent'];$rows=[];$local=$this->local->snapshot();
        foreach($defs as $id=>$def){$key=$map[$id]??'';$h=(array)($hc[$key]??[]);$compat='unknown';$operational=[];$actions=[];$current='';$previous='';$lkg='';$available='';
            if($id==='local-web'){$current=(string)($local['current_version']??'');$previous=(string)($local['previous_version']??'');$lkg=(string)($local['lkg_recovery_id']??'');$available=(string)(($local['staged']??[])['version']??'');$compat=$available!==''?'staged_validated':'current';$actions=['stage','activate','repair','rollback','stable_recovery'];$operational=['setup'=>(string)($h['setup']['state']??''),'data_dir_writable'=>(bool)($h['data_dir_writable']??false)];}
            elseif($id==='windows-runtime'){$current=(string)($h['contract_version']??'');$compat=$current==='1'?'compatible_contract':'unknown';$actions=['observe_only'];$operational=['instances'=>count((array)($h['instances']??[])),'status'=>(string)($h['status']??'')];}
            elseif($id==='print-agent'){$current=(string)($h['protocol_version']??'');$compat=$current==='4'?'compatible_contract':'unknown';$actions=['observe_only'];$operational=['active_agents'=>(int)($h['active_agents']??0),'required_unready'=>(int)($h['required_unready']??0),'backlog'=>(int)($h['local_backlog_count']??0)];}
            elseif($id==='public-edge'){$current=(string)($h['version']??'');$compat='g3_pending';$actions=['observe_only_until_g3'];$operational=['status'=>(string)($h['status']??''),'origin'=>(string)($h['base_origin']??'')];}
            $rows[]=['id'=>$id,'package_artifact'=>(string)($def['package_artifact']??''),'version_owner'=>(string)($def['version_owner']??''),'update_owner'=>(string)($def['update_owner']??''),'recovery_owner'=>(string)($def['recovery_owner']??''),'lifecycle_action'=>(string)($def['lifecycle_action']??''),'health_status'=>(string)($h['status']??'unknown'),'current_version'=>$current,'previous_version'=>$previous,'lkg'=>$lkg,'update_available'=>$available,'compatibility_state'=>$compat,'allowed_actions'=>$actions,'operational'=>$operational];}
        $history=(array)($local['history']??[]);$last=$history[0]??null;
        return ['generated_at'=>gmdate('c'),'components'=>$rows,'local'=>$local,'last_local_action'=>$last,'public_update_note'=>'Public update activation is G3-owned; Local shows status only until the Public owner contract exists.'];
    }
    private function read(string $p): array{try{$d=json_decode((string)file_get_contents($p),true,32,JSON_THROW_ON_ERROR);return is_array($d)?$d:[];}catch(Throwable){return [];}}
}
