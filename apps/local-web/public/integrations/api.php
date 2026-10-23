<?php
declare(strict_types=1);
use Sokna\Local\Domain\Integrations\IntegrationException;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\Local\Domain\Printing\PrintException;
use Sokna\Local\Domain\System\WindowsServicesPairingException;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'مدیریت اتصال‌ها فقط برای مدیر فعال است.'],403);$actorId=(int)($user['id']??0);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $errors=[];$guard=$core->snapshotGuard();
        $accommodation=$guard->capture('connections','accommodation',['enabled'=>false,'base_url'=>'','configured'=>false,'credential_saved'=>false],'وضعیت اتصال اقامتگاه فعلاً در دسترس نیست.',fn():array=>$core->integrationSettings()->accommodationSnapshot());
        $public=$guard->capture('connections','public_edge',['status'=>'unavailable','base_url'=>'','last_sync_at'=>'','version'=>''],'وضعیت وب عمومی فعلاً در دسترس نیست.',fn():array=>$core->systemDiagnostics()->publicConnectionStatus());
        $lanStaff=$guard->capture('connections','lan_staff',['enabled'=>false,'configured'=>false,'base_url'=>'','source'=>'config','staff_policy'=>'lan_preferred_public_fallback','guest_policy'=>'public_first'],'وضعیت مسیر LAN کارکنان فعلاً در دسترس نیست.',fn():array=>$core->integrationSettings()->lanStaffSnapshot());
        $originTransition=$guard->capture('connections','public_origin_transition',['active'=>false],'وضعیت انتقال آدرس Public فعلاً در دسترس نیست.',fn():array=>$core->publicOriginRebind()->transitionStatus());
        $windows=$guard->capture('connections','windows_services',['state'=>'unavailable','active'=>false],'وضعیت Windows Services فعلاً در دسترس نیست.',fn():array=>$core->windowsServicesPairing()->status());
        $runtime=$guard->capture('connections','windows_runtime',['status'=>'unavailable','runtime_version'=>'','contract_version'=>1],'وضعیت Windows Runtime فعلاً در دسترس نیست.',function()use($core):array{$health=$core->systemDiagnostics()->healthComponent('runtime');return (array)(($health['components']??[])['runtime']??[]);});
        $agents=$guard->capture('connections','print_agents',[],'فهرست Print Agentها فعلاً در دسترس نیست.',function()use($core):array{$snap=$core->printManagement()->snapshot();return array_values((array)($snap['agents']??[]));});
        foreach(['accommodation'=>$accommodation,'public_edge'=>$public,'lan_staff'=>$lanStaff,'public_origin_transition'=>$originTransition,'windows_services'=>$windows,'windows_runtime'=>$runtime,'print_agents'=>$agents] as $key=>$section)if($section['error']!==null)$errors[$key]=$section['error'];
        WebAction::json(['success'=>true,'snapshot'=>['accommodation'=>$accommodation['value'],'public_edge'=>$public['value'],'lan_staff'=>$lanStaff['value'],'public_origin_transition'=>$originTransition['value'],'windows_services'=>$windows['value'],'windows_runtime'=>$runtime['value'],'print_agents'=>$agents['value'],'errors'=>$errors]]);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='accommodation_config_save')WebAction::json(['success'=>true,'result'=>$core->integrationSettings()->saveAccommodation($data)]);
    if($action==='accommodation_test'){
        $result=$core->accommodationTransport()->testConnection();
        if(empty($result['success']))throw new IntegrationException((string)($result['code']??'accommodation_test_failed'),(string)($result['message']??'آزمایش اتصال اقامتگاه انجام نشد.'),502);
        WebAction::json(['success'=>true,'result'=>$result]);
    }
    if($action==='public_pair')WebAction::json(['success'=>true,'pairing'=>$core->publicReenrollment()->initialPair((string)($data['base_url']??''),(string)($data['pairing_code']??''),(string)($data['display_name']??''),$actorId)]);
    if($action==='public_sync')WebAction::json(['success'=>true,'public_sync'=>$core->publicEdgePublisher()->syncAll()]);
    if($action==='public_probe'){
        $heartbeat=$core->publicEdgePublisher()->syncHeartbeat(2);
        $roundtrip=$core->publicEdgeRelay()->diagnosticProbe(2);
        $status=$core->systemDiagnostics()->publicConnectionStatus(true);
        WebAction::json(['success'=>true,'probe'=>['heartbeat'=>$heartbeat,'roundtrip'=>$roundtrip,'status'=>$status]]);
    }
    if($action==='public_reenroll')WebAction::json(['success'=>true,'reenrollment'=>$core->publicReenrollment()->complete((string)($data['base_url']??''),(string)($data['enrollment_code']??''),(string)($data['display_name']??''),$actorId)]);
    if($action==='public_origin_rebind_preflight')WebAction::json(['success'=>true,'origin_rebind'=>$core->publicOriginRebind()->preflight((string)($data['base_url']??''))]);
    if($action==='public_origin_rebind_execute')WebAction::json(['success'=>true,'origin_rebind'=>$core->publicOriginRebind()->execute((string)($data['base_url']??''),$actorId)]);
    if($action==='public_origin_transition_status')WebAction::json(['success'=>true,'origin_transition'=>$core->publicOriginRebind()->transitionStatus()]);
    if($action==='public_origin_transition_close')WebAction::json(['success'=>true,'origin_transition'=>$core->publicOriginRebind()->closeTransition((string)($data['rebind_id']??''),$actorId)]);
    if($action==='lan_staff_preflight')WebAction::json(['success'=>true,'lan_staff_preflight'=>$core->integrationSettings()->preflightLanStaff($data,$actorId)]);
    if($action==='lan_staff_config_save')WebAction::json(['success'=>true,'lan_staff'=>$core->integrationSettings()->saveLanStaff($data,$actorId)]);
    if($action==='windows_services_pairing_create')WebAction::json(['success'=>true,'windows_services'=>$core->windowsServicesPairing()->create($user,(string)($data['display_name']??''),!empty($data['allow_additional']))]);
    if($action==='windows_services_print_token_rotation_create')WebAction::json(['success'=>true,'windows_services'=>$core->windowsServicesPairing()->createRotation($user,(int)($data['agent_id']??0),(string)($data['display_name']??''))]);
    if($action==='windows_services_pairing_cancel')WebAction::json(['success'=>true,'windows_services'=>$core->windowsServicesPairing()->cancelActive($user)]);
    if($action==='windows_services_agent_retire')WebAction::json(['success'=>true,'windows_services'=>$core->windowsServicesPairing()->retireConnection($user,(int)($data['agent_id']??0),(string)($data['reason']??'operator_removed'))]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(IntegrationException|PublicEdgeSyncException|WindowsServicesPairingException|PrintException $e){WebAction::knownFailure($e);}catch(Throwable $e){WebAction::unexpected($core,$e,'connections.web_action_failed','عملیات اتصال‌ها کامل نشد.');}
