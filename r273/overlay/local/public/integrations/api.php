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
                if(!$client->configured()||!in_array($core->publicAuthorityState()->phase(),['active','resync_required'],true))
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
                WebAction::json(['success'=>true,'move'=>$result+[
                    'minimum_next_epoch'=>$core->publicAuthorityState()->epoch()+1
                ]]);
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
            $kind=(string)($data['move_kind']??'fresh_unpaired');
            if($kind==='existing_paired') {
                WebAction::json(['success'=>true,'move'=>[
                    'status'=>'awaiting_owner_offer','requires_owner_offer'=>true,
                    'minimum_next_epoch'=>$core->publicAuthorityState()->epoch()+1,
                    'safe_to_cutover'=>false
                ]]);
            }
            $code=$kind==='existing_paired'?(string)($data['owner_authority_code']??''):
                (string)($data['pairing_code']??'');
            $result=$replacement->start($operation,$code,$actorId,$kind,
                (int)($data['owner_next_epoch']??0));
            WebAction::json(['success'=>true,'move'=>$result]);
        }catch(\Throwable $e){
            // Remote authority might ALREADY have changed after a lost reply.
            // Never claim "Local unchanged" without reading the durable journal.
            $pending=['status'=>'unknown','active'=>false];
            try{if(isset($replacement))$pending=$replacement->status();}catch(\Throwable){}
            $phase=(string)($pending['status']??'unknown');
            $resume=$phase==='sync_pending';
            $review=$phase==='support_required'||$phase==='unknown';
            $code=$resume?'public_move_resume_required':($review?'public_move_state_requires_review':'public_move_confirmation_not_ready');
            $message=$resume
                ?'مرحلهٔ اتصال روی مقصد ممکن است انجام شده باشد. از «ادامهٔ ایمن» استفاده کنید؛ درخواست جدید نسازید.'
                :($review
                    ?'نتیجهٔ مرحلهٔ اتصال قطعی نیست. هیچ تکرار یا لغو خودکاری انجام نشد؛ وضعیت عملیات را بررسی کنید.'
                    :'تأیید انتقال کامل نشد. تا پیش‌بررسی دوباره، اتصال جدید آغاز نکنید.');
            WebAction::json(['success'=>false,'code'=>$code,'message'=>$message,
                'move'=>['status'=>$phase,'active'=>($pending['active']??false)===true],
                'next_action'=>$resume?'safe_resume':($review?'support_review':'inspect_again')],409);
        }
    }
    if($action==='public_move_existing_owner_offer_execute'){
        // This is a separate request after the owner popup. A lost response
        // must be reported as resumable/uncertain, not a generic 500.
        try {
        $localId=$core->config()->string('installation.id','');
        $operation=(string)($_SESSION['sokna_move_operation']??'');
        if($operation===''||($data['move_kind']??'')!=='existing_paired')
            throw new \RuntimeException('owner_offer_session_not_active');
        $ledger=new \Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger(
            $core->database()
        );
        if(($ledger->status($localId,$operation)['status']??'')!=='owner_approved')
            throw new \RuntimeException('local_owner_approval_required');
        $replacement=new \Sokna\Local\Domain\PublicEdge\PublicMoveSimpleReplacement(
            $core,$ledger,$localId,$core->config()->requiredString('app.data_dir')
        );
        $result=$replacement->start($operation,(string)($data['owner_authority_code']??''),
            $actorId,'existing_paired',(int)($data['owner_next_epoch']??0));
        WebAction::json(['success'=>true,'move'=>$result]);
        }catch(\Throwable $e){
            $pending=['status'=>'unknown','active'=>false];
            try{
                $localId=$core->config()->string('installation.id','');
                $replacement=new \Sokna\Local\Domain\PublicEdge\PublicMoveSimpleReplacement(
                    $core,new \Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger($core->database()),
                    $localId,$core->config()->requiredString('app.data_dir'));
                $pending=$replacement->status();
            }catch(\Throwable){}
            $phase=(string)($pending['status']??'unknown');
            $resume=$phase==='sync_pending';
            WebAction::json(['success'=>false,
                'code'=>$resume?'public_move_resume_required':'public_move_state_requires_review',
                'message'=>$resume?'مجوز مقصد ممکن است مصرف شده باشد؛ با ادامهٔ ایمن وضعیت ثبت‌شده بررسی می‌شود.':'نتیجهٔ مجوز مقصد روشن نیست؛ بدون صدور کد تازه، بررسی پشتیبانی لازم است.',
                'move'=>['status'=>$phase,'active'=>($pending['active']??false)===true],
                'next_action'=>$resume?'safe_resume':'support_review'],409);
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
            static fn(string $target):array=>$core->publicOriginRebind()->preflight($target),
            static fn(string $target):array=>$client->deploymentTargetHealth($target,6),
            static fn(string $target):array=>$client->destinationSetupStatus($target,6),
            $core->config()->requiredString('installation.id')
        );
        WebAction::json(['success'=>true,'public_move'=>$service->inspect((string)($data['base_url']??''))]);
    }
    if($action==='accommodation_config_save')WebAction::json(['success'=>true,'result'=>$core->integrationSettings()->saveAccommodation($data)]);
    if($action==='accommodation_test'){
        $result=$core->accommodationTransport()->testConnection();
        if(empty($result['success']))throw new IntegrationException((string)($result['code']??'accommodation_test_failed'),(string)($result['message']??'آزمایش اتصال اقامتگاه انجام نشد.'),502);
        WebAction::json(['success'=>true,'result'=>$result]);
    }
    if($action==='public_replacement_preflight')WebAction::json(['success'=>true,'replacement'=>$core->publicReenrollment()->replacementPreflight()]);
    if($action==='public_replacement_resume')WebAction::json(['success'=>true,'replacement'=>$core->publicReenrollment()->resumePendingRecovery($actorId)]);
    if($action==='public_replacement_execute')WebAction::json(['success'=>true,'replacement'=>$core->publicReenrollment()->recoverReplacement((string)($data['base_url']??''),(string)($data['enrollment_code']??''),(string)($data['display_name']??''),$actorId,(string)($data['confirmation']??''),(int)($data['expected_epoch']??0))]);
    if($action==='public_pair')WebAction::json(['success'=>true,'pairing'=>$core->publicReenrollment()->initialPair((string)($data['base_url']??''),(string)($data['pairing_code']??''),(string)($data['display_name']??''),$actorId)]);
    if($action==='public_sync')WebAction::json(['success'=>true,'public_sync'=>$core->publicEdgePublisher()->syncAll()]);
    if($action==='public_probe'){
        $heartbeat=$core->publicEdgePublisher()->syncHeartbeat(2);
        $roundtrip=$core->publicEdgeRelay()->diagnosticProbe(2);
        $status=$core->systemDiagnostics()->publicConnectionStatus(true);
        WebAction::json(['success'=>true,'probe'=>['heartbeat'=>$heartbeat,'roundtrip'=>$roundtrip,'status'=>$status]]);
    }
    if($action==='public_reenroll')WebAction::json(['success'=>false,'code'=>'public_reenroll_requires_recovery','message'=>'این مسیر قدیمی غیرفعال است؛ از پیش‌بررسی و بازیابی کنترل‌شده اتصال پابلیک استفاده کنید.'],409);
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
