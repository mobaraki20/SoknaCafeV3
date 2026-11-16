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
    if(in_array($action,['public_move_begin','public_move_confirm','public_move_cancel','public_move_status','public_move_finish'],true)){
        try {
            // This request has already passed real Local admin authentication
            // and WebAction's per-session CSRF. No browser session or Local ID.
            $localId=$core->config()->requiredString('installation.id');
            if(session_status()!==PHP_SESSION_ACTIVE)throw new \RuntimeException('local_session_unavailable');
            $userId=(int)($user['id']??0);
            if($userId<=0)throw new \RuntimeException('operator_not_authenticated');
            if(($_SESSION['sokna_move_admin_id']??null)!==$userId ||
                !is_string($_SESSION['sokna_move_token']??null) ||
                !preg_match('/^[a-f0-9]{64}$/D',(string)$_SESSION['sokna_move_token'])){
                $_SESSION['sokna_move_admin_id']=$userId;
                $_SESSION['sokna_move_token']=bin2hex(random_bytes(32));
                unset($_SESSION['sokna_move_operation']);
            }
            $trustedSessionToken=(string)$_SESSION['sokna_move_token'];
            $keys=(new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalKeyStore(
                $core->config()->requiredString('app.data_dir'),
                dirname(__DIR__)
            ))->keys();
            $ledger=new \Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger($core->database());
            $resolver=static function(string $token)use($core,$trustedSessionToken,$localId,$userId):?array{
                if(!hash_equals($trustedSessionToken,$token))return null;
                $live=$core->auth()->currentUser();
                if(!is_array($live)||($live['role']??'')!=='admin'||
                    (int)($live['id']??0)!==$userId)return null;
                return ['authenticated'=>true,'role'=>'admin','installation_id'=>$localId];
            };
            $approval=new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalApprovalService(
                $ledger,$resolver,$keys['mac'],$keys['csrf']
            );
            $flow=new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalFlow($ledger,$approval,$localId);
            $replacement=new \Sokna\Local\Domain\PublicEdge\PublicMoveSimpleReplacement(
                $core,$ledger,$localId,$core->config()->requiredString('app.data_dir')
            );
            if($action==='public_move_status'){
                $pending=$replacement->status();
                if(($pending['active']??false)===true){
                    $_SESSION['sokna_move_operation']=$pending['operation_id'];
                }
                WebAction::json(['success'=>true,'move'=>$pending]);
            }
            if($action==='public_move_begin'){
                $client=$core->publicEdgeSyncClient();
                if(!$client->configured()||$core->publicAuthorityState()->phase()!=='active')
                    throw new \RuntimeException('active_public_connection_required');
                $source=$client->publicBaseUrl();
                // Disaster recovery MUST work when the old Public is down.
                // Local's current URL is trusted from config. An empty source
                // instance ID is explicitly supported by the signed challenge.
                // Do not probe the old Public or require its host/DB to respond.
                $sourceId='';
                $result=$flow->begin((string)($data['base_url']??''),$source,$sourceId,time(),
                    (string)($_SESSION['sokna_move_operation']??''));
                $_SESSION['sokna_move_operation']=$result['operation_id'];
                WebAction::json(['success'=>true,'move'=>$result]);
            }
            $operation=(string)($_SESSION['sokna_move_operation']??'');
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$operation)){
                $pending=$replacement->status();
                $operation=($pending['active']??false)===true
                    ?(string)$pending['operation_id']:'';
            }
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$operation))
                throw new \RuntimeException('move_session_not_active');
            if($action==='public_move_finish'){
                $result=$replacement->finish($operation);
                if(($result['completed']??false)===true)unset($_SESSION['sokna_move_operation']);
                WebAction::json(['success'=>true,'move'=>$result]);
            }
            if($action==='public_move_cancel'){
                if(($replacement->status()['active']??false)===true)
                    throw new \RuntimeException('paired_replacement_must_finish_before_cancel');
                $result=$flow->cancel($operation);
                unset($_SESSION['sokna_move_operation']);
                WebAction::json(['success'=>true,'move'=>$result]);
            }
            if(!is_array($data['attestation']??null)||!is_array($data['receipt_envelope']??null))
                throw new \RuntimeException('signed_owner_receipt_required');
            $flow->confirm($operation,$trustedSessionToken,
                $data['attestation'],$data['receipt_envelope'],time());
            $result=$replacement->start($operation,(string)($data['pairing_code']??''),$actorId);
            WebAction::json(['success'=>true,'move'=>$result]);
        }catch(\Throwable $e){
            // Never disclose key paths, challenge hashes, SQL, session tokens,
            // owner credentials or remote host internals.
            WebAction::json(['success'=>false,'code'=>'public_move_confirmation_not_ready',
                'message'=>'تأیید انتقال کامل نشد. اتصال فعلی بدون تغییر است؛ وضعیت مقصد و ورود مدیر را بررسی کنید.'],409);
        }
    }
    if($action==='public_move_inspect'){
        // R258 QA: authenticated Local admin + WebAction CSRF are already enforced.
        // No browser-submitted identity proof or approval flags are accepted.
        // This action performs only signed SAME-installation preflight, no commit.
        require_once dirname(__DIR__,2).'/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
        require_once dirname(__DIR__,2).'/src/Domain/PublicEdge/PublicMoveUiInspectionService.php';
        $client=$core->publicEdgeSyncClient();
        $service=new \Sokna\Local\Domain\PublicEdge\PublicMoveUiInspectionService(
            $client->publicBaseUrl(),$core->publicAuthorityState()->phase(),
            static fn(string $target):array=>$core->publicOriginRebind()->preflight($target)
        );
        WebAction::json(['success'=>true,'public_move'=>$service->inspect((string)($data['base_url']??''))]);
    }
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
