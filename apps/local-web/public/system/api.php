<?php
declare(strict_types=1);
use Sokna\Local\Domain\Recovery\RecoveryException;
use Sokna\Local\Domain\Update\LocalUpdateException;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);$actorId=(int)($user['id']??0);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $section=trim((string)($_GET['section']??''));
        if(in_array($section,['health_core','health_runtime','health_printing','health_public'],true)){
            $key=match($section){'health_core'=>'core','health_runtime'=>'runtime','health_printing'=>'printing',default=>'public'};
            WebAction::json(['success'=>true,'health_component'=>$core->systemDiagnostics()->healthComponent($key)]);
        }
        if($section==='updates'){
            $guard=$core->snapshotGuard();$errors=[];
            $coreHealth=$guard->capture('system','updates_core',['components'=>[]],'وضعیت Local فعلاً کامل در دسترس نیست.',fn(): array=>$core->systemDiagnostics()->healthComponent('core'));
            $publicHealth=$guard->capture('system','updates_public_cached',['components'=>['public_edge'=>['status'=>'unavailable']]],'وضعیت کش‌شده Public فعلاً در دسترس نیست.',fn(): array=>$core->systemDiagnostics()->healthComponent('public'));
            $components=array_merge((array)($coreHealth['value']['components']??[]),(array)($publicHealth['value']['components']??[]));
            $publicRefresh=['status'=>'not_attempted','captured_at'=>gmdate('c'),'elapsed_ms'=>0];$probeStarted=microtime(true);
            try{
                $status=$core->publicEdgePublisher()->updateStatus(3);
                $publicRefresh=['status'=>'fresh','captured_at'=>gmdate('c'),'elapsed_ms'=>(int)round((microtime(true)-$probeStarted)*1000),'update'=>$status['update']??[],'routine_update'=>$status['routine_update']??[],'routine_reconcile'=>$status['routine_reconcile']??[],'version'=>(string)($status['version']??'')];
                $p=(array)($components['public_edge']??[]);foreach(['version','emergency_ready','update','routine_update','update_protocol'] as $key)if(array_key_exists($key,$status))$p[$key]=$status[$key];$p['live_update_status_at']=$publicRefresh['captured_at'];$components['public_edge']=$p;
            }catch(PublicEdgeSyncException $e){
                $publicRefresh=['status'=>'failed','captured_at'=>gmdate('c'),'elapsed_ms'=>(int)round((microtime(true)-$probeStarted)*1000),'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'http_status'=>$e->httpStatus];
            }catch(Throwable $e){
                $publicRefresh=['status'=>'failed','captured_at'=>gmdate('c'),'elapsed_ms'=>(int)round((microtime(true)-$probeStarted)*1000),'error_code'=>'public_update_probe_failed','fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20)];
            }
            if($coreHealth['error']!==null)$errors['core']=$coreHealth['error'];if($publicHealth['error']!==null)$errors['public_cached']=$publicHealth['error'];
            $health=['components'=>$components];
            $fallbackVersion=trim((string)@file_get_contents(dirname(__DIR__,2).'/VERSION.txt'));
            $fallbackCenter=['generated_at'=>gmdate('c'),'components'=>[],'local'=>['current_version'=>$fallbackVersion,'active_version'=>$fallbackVersion,'staged'=>[],'history'=>[],'last_failure'=>null,'recovery_mode'=>['required'=>false]],'last_local_action'=>null];
            $center=$guard->capture('system','updates_center',$fallbackCenter,'جزئیات Update Center کامل در دسترس نیست، اما نصب Local همچنان قابل انتخاب است.',fn(): array=>$core->updateCenter()->snapshot($health));
            if($center['error']!==null)$errors['update_center']=$center['error'];
            $capability=$core->maintenanceSupervisor()->updateCapabilities();
            $slotMode=($center['value']['local']['legacy_local_blocked']??true)===true;
            $verificationBound=$slotMode&&($capability['verification_ready']??false)===true;
            $panelBound=$slotMode&&($capability['ready']??false)===true&&$verificationBound;
            WebAction::json(['success'=>true,'snapshot'=>$health,'update_center'=>$center['value'],
                'maintenance_updater'=>$capability,'legacy_local_blocked'=>$slotMode,
                'maintenance_panel_bound'=>$panelBound,'maintenance_verification_bound'=>$verificationBound,'public_update_refresh'=>$publicRefresh,'nonfatal_errors'=>$errors]);
        }
        if($section==='recovery')WebAction::json(['success'=>true,'recovery'=>$core->recoveryWorkspace()->snapshot()]);
        if($section==='maintenance_operation'){
            if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'فقط مدیر اجازه بررسی عملیات نصب را دارد.'],403);
            $key=(string)($_GET['idempotency_key']??'');
            if(preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D',$key)!==1)WebAction::json(['success'=>false,'code'=>'MAINTENANCE_STATUS_REQUEST_INVALID'],422);
            $status=$core->maintenanceSupervisor()->ownedOperationStatus($key,'user:'.$actorId);
            if(($status['ok']??false)!==true)WebAction::json(['success'=>false,'code'=>(string)($status['code']??'MAINTENANCE_STATUS_UNAVAILABLE')],
                in_array(($status['code']??''),['MAINTENANCE_OPERATION_NOT_FOUND','OPERATION_NOT_FOUND'],true)?404:503);
            WebAction::json(['success'=>true,'maintenance_operation'=>$status['operation']]);
        }
        if($section==='maintenance_operations'){if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'فقط مدیر به سابقه عملیات Maintenance دسترسی دارد.'],403);WebAction::json(['success'=>true,'maintenance_operations'=>$core->maintenanceSupervisor()->updateOperations()]);}
        if($section==='maintenance_connection'){
            if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden'],403);
            WebAction::json(['success'=>true,'maintenance_connection'=>$core->maintenanceSupervisor()->connectionDiagnostics()]);
        }
        if($section==='maintenance')WebAction::json(['success'=>true,'maintenance'=>$core->maintenanceSupervisor()->snapshot()]);
        $errors=[];$guard=$core->snapshotGuard();
        $health=$guard->capture('system','health',['summary'=>['status'=>'unavailable'],'components'=>[],'checks'=>[]],'وضعیت سلامت سامانه فعلاً در دسترس نیست.',fn(): array => $core->systemDiagnostics()->snapshot());
        $updates=$guard->capture('system','updates',['local'=>[],'components'=>[]],'وضعیت به‌روزرسانی‌ها فعلاً در دسترس نیست.',fn(): array => $core->updateCenter()->snapshot($health['error']===null?$health['value']:['components'=>[]]));
        $recovery=$guard->capture('system','recovery',['backups'=>[]],'اطلاعات پشتیبان و بازیابی فعلاً در دسترس نیست.',fn(): array => $core->recoveryWorkspace()->snapshot());
        foreach(['health'=>$health,'updates'=>$updates,'recovery'=>$recovery] as $key=>$part)if($part['error']!==null)$errors[$key]=$part['error'];
        WebAction::json(['success'=>true,'snapshot'=>$health['value'],'update_center'=>$updates['value'],'recovery'=>$recovery['value'],'errors'=>$errors]);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    // Maintenance V4 is a separate privileged Windows owner. No browser HMAC,
    // no legacy in-place fallback, and no recovery-console privilege via Web.
    if($action==='maintenance_inbox_chunk'){
        if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden'],403);
        if(!\Sokna\Local\Domain\Update\LegacySlotUpdateGuard::isSlotManaged(dirname(__DIR__,2)))WebAction::json(['success'=>false,'code'=>'MAINTENANCE_R8_SLOT_REQUIRED'],409);
        $chunk=(string)($data['chunk_base64']??'');
        if(strlen($chunk)>262144 || strlen($chunk)<4)
            WebAction::json(['success'=>false,'code'=>'INBOX_CHUNK_LENGTH_INVALID'],422);
        $decoded=base64_decode($chunk,true);
        if(!is_string($decoded))WebAction::json(['success'=>false,'code'=>'INBOX_CHUNK_BASE64_INVALID'],422);
        $result=$core->maintenanceSupervisor()->uploadInboxChunk(
            (string)($data['upload_id']??''),'user:'.$actorId,
            (string)($data['sha256']??''),(int)($data['total_bytes']??0),
            (int)($data['offset']??-1),$decoded);
        if(($result['ok']??false)!==true)
            WebAction::json(['success'=>false,'code'=>(string)($result['error_code']??'INBOX_UPLOAD_FAILED'),
                'message'=>'انتقال فایل به موتور نگهداری تأیید نشد؛ عملیات نصب انجام نشده است.'],409);
        $info=(array)($result['data']??[]);
        WebAction::json(['success'=>true,'upload'=>[
            'received_bytes'=>(int)($info['received_bytes']??0),
            'complete'=>($info['complete']??false)===true,
            'package_ref'=>($info['complete']??false)===true?(string)($info['package_ref']??''):null,
            'content_sha256_verified'=>($info['content_sha256_verified']??false)===true,
            'release_signature_verified'=>false,
            'panel_update_ready'=>false,
        ]]);
    }
    if($action==='maintenance_local_preflight' || $action==='maintenance_local_stage'){
        if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden'],403);
        if(!\Sokna\Local\Domain\Update\LegacySlotUpdateGuard::isSlotManaged(dirname(__DIR__,2)))WebAction::json(['success'=>false,'code'=>'MAINTENANCE_R8_SLOT_REQUIRED'],409);
        $client=$core->maintenanceSupervisor();$cap=$client->updateCapabilities();
        // The signed preflight and Stage do not grant activation permission.
        // The final Activate endpoint independently requires ready=true and
        // exact signed Stage verification. A signed upload is not an install.
        if(($cap['verification_ready']??false)!==true)
            WebAction::json(['success'=>false,'code'=>(string)($cap['code']??'FOUNDATION_UPDATE_REQUIRED'),
                'message'=>'انتقال و اعتبارسنجی امضاشدهٔ Maintenance هنوز آماده نیست.'],409);
        $idem=(string)($data['idempotency_key']??'');
        if(preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D',$idem)!==1)
            WebAction::json(['success'=>false,'code'=>'IDEMPOTENCY_KEY_INVALID'],422);
        if($action==='maintenance_local_preflight'){
            $ref=(string)($data['package_ref']??'');
            if(preg_match('/^[a-fA-F0-9]{64}\.zip$/D',$ref)!==1)
                WebAction::json(['success'=>false,'code'=>'INBOX_CONTENT_ADDRESS_REQUIRED'],422);
            $result=$client->requestLocalUpdateOperation('preflight',
                ['operator_id'=>'user:'.$actorId,'package_ref'=>strtolower($ref)],$idem);
            $proof=(array)($result['data']['operation']??[]);
            $verification=(array)($result['data']['receipt']['verification']??[]);
            if(($result['ok']??false)!==true||($proof['state']??'')!=='verified'||
                ($verification['operator_authorized']??false)!==true||
                ($verification['signature_verified']??true)!==false||
                ($verification['artifact_integrity_verified']??false)!==true)
                WebAction::json(['success'=>false,'code'=>(string)($result['error_code']??'PACKAGE_PREFLIGHT_UNPROVEN'),
                    'message'=>'اصالت یا سازگاری بسته برای نصب تأیید نشد.'],409);
            $id=(string)($proof['operation_id']??'');
            if(preg_match('/^[a-fA-F0-9]{32}$/D',$id)!==1)
                WebAction::json(['success'=>false,'code'=>'PREFLIGHT_RECEIPT_UNPROVEN'],409);
            WebAction::json(['success'=>true,'preflight_operation_id'=>$id,'operator_preflight_verified'=>true]);
        }
        $ref=(string)($data['preflight_operation_id']??'');
        if(preg_match('/^[a-fA-F0-9]{32}$/D',$ref)!==1)
            WebAction::json(['success'=>false,'code'=>'PREFLIGHT_OPERATION_ID_INVALID'],422);
        $preflight=$client->operationById($ref);
        $pr=(array)($preflight['data']??[]);
        if(($preflight['ok']??false)!==true||($pr['operation_type']??'')!=='update.local-package-preflight'||
            ($pr['state']??'')!=='verified'||($pr['operator_id']??'')!=='user:'.$actorId||
            strtolower((string)($pr['operation_id']??''))!==strtolower($ref))
            WebAction::json(['success'=>false,'code'=>'PREFLIGHT_OPERATION_NOT_OWNED_OR_VERIFIED'],409);
        $result=$client->requestLocalUpdateOperation('stage',
            ['operator_id'=>'user:'.$actorId,'preflight_operation_id'=>$ref],$idem);
        $receipt=(array)($result['data']['operation']??[]);
        $stageEvidence=(array)($result['data']['receipt']??[]);
        if(($result['ok']??false)!==true||($receipt['state']??'')!=='staged'||
            ($stageEvidence['operator_authorized']??false)!==true||
            ($stageEvidence['signature_verified']??true)!==false||
            ($stageEvidence['artifact_integrity_verified']??false)!==true)
            WebAction::json(['success'=>false,'code'=>(string)($result['error_code']??'STAGE_UNPROVEN'),
                'message'=>'مرحله آماده‌سازی نسخه در Maintenance تأیید نشده است.'],409);
        $id=(string)($receipt['operation_id']??'');
        if(preg_match('/^[a-fA-F0-9]{32}$/D',$id)!==1)
            WebAction::json(['success'=>false,'code'=>'STAGE_RECEIPT_UNPROVEN'],409);
        WebAction::json(['success'=>true,'stage_operation_id'=>$id,'operator_stage_verified'=>true]);
    }
    if($action==='maintenance_local_activate'){
        if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'فقط مدیر اجازه نصب Local دارد.'],403);
        if(!\Sokna\Local\Domain\Update\LegacySlotUpdateGuard::isSlotManaged(dirname(__DIR__,2)))WebAction::json(['success'=>false,'code'=>'MAINTENANCE_R8_SLOT_REQUIRED'],409);
        $stageId=(string)($data['stage_operation_id']??'');
        $idem=(string)($data['idempotency_key']??'');
        if(preg_match('/^[a-fA-F0-9]{32}$/D',$stageId)!==1||
            preg_match('/^[a-zA-Z0-9._:-]{16,128}$/D',$idem)!==1)
            WebAction::json(['success'=>false,'code'=>'MAINTENANCE_REQUEST_INVALID','message'=>'شناسه Stage یا شناسه درخواست معتبر نیست.'],422);
        $client=$core->maintenanceSupervisor();
        $cap=$client->updateCapabilities();
        if(($cap['ready']??false)!==true)
            WebAction::json(['success'=>false,'code'=>(string)($cap['code']??'FOUNDATION_UPDATE_REQUIRED'),'message'=>'موتور Maintenance برای نصب از پنل تأیید نشده است.'],409);
        // The stage MUST be a currently verifiable signed Supervisor receipt,
        // not a legacy Local staged ZIP or an arbitrary browser-supplied ID.
        $ops=$client->updateOperations();
        $signedStage=$client->operationById($stageId);
        if(!\Sokna\Local\Domain\System\MaintenancePanelActivationGate::exactStageVerified($ops,$signedStage,$stageId,'user:'.$actorId))
            WebAction::json(['success'=>false,'code'=>'MAINTENANCE_STAGE_NOT_VERIFIED','message'=>'Stage معتبر و متعلق به این مدیر در سوابق امضاشده یافت نشد، یا عملیات دیگری در جریان است.'],409);
        // One explicit click: Supervisor-owned inactive candidate and slot must
        // be independently verified BEFORE the code-only cutover. HMAC here
        // authenticates the Web adapter; no publisher signature/key enrollment.
        // Derive stable idempotency keys from the already durable Activate ID.
        $candidateIdem='candidate-'.substr(hash('sha256','private|'.$idem),0,32);
        $slotIdem='slot-'.substr(hash('sha256','slot|'.$idem),0,32);
        $candidate=$client->requestLocalUpdateOperation('prepare_inactive',
            ['operator_id'=>'user:'.$actorId,'stage_operation_id'=>$stageId],$candidateIdem);
        $candidateReceipt=(array)($candidate['data']['receipt']??[]);
        if(($candidate['ok']??false)!==true||
            ($candidateReceipt['active_slot_unchanged']??false)!==true||
            ($candidateReceipt['activation_performed']??true)!==false)
            WebAction::json(['success'=>false,'code'=>(string)($candidate['error_code']??'MAINTENANCE_CANDIDATE_UNPROVEN'),
                'message'=>'نسخه غیرفعال به‌صورت مستقل تأیید نشد؛ هیچ نصب جدیدی شروع نشده است.'],409);
        $slot=$client->requestLocalUpdateOperation('prepare_slot',
            ['operator_id'=>'user:'.$actorId,'stage_operation_id'=>$stageId,
             'private_candidate_idempotency_key'=>$candidateIdem],$slotIdem);
        $slotReceipt=(array)($slot['data']['receipt']??[]);
        if(($slot['ok']??false)!==true||
            ($slotReceipt['actual_host_health_qualified']??false)!==true||
            ($slotReceipt['activation_performed']??true)!==false)
            WebAction::json(['success'=>false,'code'=>(string)($slot['error_code']??'MAINTENANCE_SLOT_UNPROVEN'),
                'message'=>'سلامت نسخه غیرفعال یا میزبان تأیید نشد؛ نصب شروع نشده است.'],409);
        $result=$client->requestLocalUpdateOperation('activate',
            ['operator_id'=>'user:'.$actorId,'stage_operation_id'=>$stageId],$idem);
        if(($result['ok']??false)!==true)
            WebAction::json(['success'=>false,'code'=>(string)($result['error_code']??'MAINTENANCE_ACTIVATION_UNPROVEN'),'message'=>'نتیجه فعال‌سازی تأیید نشده است؛ عملیات را تکرار نکن و وضعیت Maintenance را بررسی کن.','maintenance_operation'=>$result],409);
        $classification=\Sokna\Local\Domain\System\MaintenancePanelActivationGate::classifyActivationResponse(
            $result,$stageId,'user:'.$actorId);
        $receipt=(array)($result['data']['operation']??[]);
        if($classification==='pending')
            WebAction::json(['success'=>true,'accepted'=>true,'activation_committed'=>false,
                'operation_id'=>(string)$receipt['operation_id'],
                'message'=>'درخواست ثبت شد؛ نتیجه نصب باید از وضعیت پایدار عملیات خوانده شود.',
                'maintenance_operation'=>$result],202);
        if($classification!=='committed')
            WebAction::json(['success'=>false,'code'=>'MAINTENANCE_COMMIT_EVIDENCE_UNPROVEN','message'=>'Maintenance پاسخ داده، اما مدرک معتبر پذیرش یا نصب کامل نیست. وضعیت واقعی را بررسی کن.'],409);
        WebAction::json(['success'=>true,'accepted'=>false,'activation_committed'=>true,
            'operation_id'=>(string)$receipt['operation_id'],
            'maintenance_operation'=>$result]);
    }
    if($action==='support_bundle'){
        if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'بسته پشتیبانی فنی فقط برای مدیر در دسترس است.'],403);
        WebAction::json(['success'=>true,'bundle'=>$core->systemDiagnostics()->createSupportBundle()]);
    }
    if($action==='support_preflight'){
        if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'گزارش فنی و بررسی آمادگی فقط برای مدیر در دسترس است.'],403);
        $local=$core->localUpdates()->snapshot();
        $coreHealth=$core->systemDiagnostics()->healthComponent('core');
        $db=(array)(($coreHealth['components']??[])['database']??[]);
        // Do not let a flapping or down Public link suppress the independent
        // Local preflight report (the Home/Cafe support failure domain).
        $public=[];
        $publicProbeError='';
        try{$public=$core->publicEdgePublisher()->updateStatus();}
        catch(Throwable $e){$publicProbeError='public_update_probe_unavailable';}
        $publicDiagnostics=[];
        try{$publicDiagnostics=$core->publicEdgePublisher()->supportDiagnostics(10);}catch(Throwable){}
        $publicHealth=[];
        try{$publicHealth=$core->systemDiagnostics()->publicConnectionStatus(false);}
        catch(Throwable){$publicHealth=['status'=>'unavailable','probe_error'=>'public_connection_probe_unavailable'];}
        $routine=is_array($public['routine_update']??null)?(array)$public['routine_update']:[];
        $emptyState=static fn(mixed $v): bool=>$v===null||$v===[]||$v===''||$v===false;
        $localChecks=[
            'identity_available'=>preg_match('/^[a-f0-9]{64}$/D',(string)($local['managed_tree_sha256']??''))===1&&trim((string)($local['current_version']??''))!=='',
            'database_current'=>(int)($db['pending_migrations']??-1)===0&&(int)($db['applied_migrations']??-1)===(int)($db['catalog_migrations']??-2),
            'lifecycle_clear'=>$emptyState($local['staged']??null)&&$emptyState($local['activation_journal']??null)&&(($local['recovery_mode']['required']??false)!==true),
        ];
        $publicChecks=[
            'identity_available'=>preg_match('/^[a-f0-9]{64}$/D',(string)($routine['managed_tree_sha256']??''))===1&&trim((string)($routine['current_version']??$routine['active_version']??''))!=='',
            'lifecycle_clear'=>$emptyState($routine['staged']??null)&&$emptyState($routine['pending_verification']??null)&&$emptyState($routine['activation_journal']??null)&&(($routine['interrupted_activation']??false)!==true),
            'control_fresh'=>isset($publicHealth['update_control_cache_age_seconds'])&&(int)$publicHealth['update_control_cache_age_seconds']<=120,
            'committed_tree_consistent'=>($routine['managed_tree_matches_expected']??null)!==false,
        ];
        $ready=!in_array(false,$localChecks,true)&&!in_array(false,$publicChecks,true);
        WebAction::json(['success'=>true,'preflight'=>[
            'format'=>'sokna-operator-update-preflight-v1','generated_at'=>gmdate('c'),'ready_for_package_evaluation'=>$ready,
            'local'=>['current_version'=>(string)($local['current_version']??''),'managed_tree_sha256'=>(string)($local['managed_tree_sha256']??''),'managed_inventory'=>$core->localUpdates()->managedInventory(),'database'=>$db,'staged'=>$local['staged']??null,'activation_journal'=>$local['activation_journal']??null,'recovery_mode'=>$local['recovery_mode']??null,'checks'=>$localChecks],
            'public'=>['current_version'=>(string)($routine['current_version']??$routine['active_version']??''),'managed_tree_sha256'=>(string)($routine['managed_tree_sha256']??''),'previous_version'=>(string)($routine['previous_version']??''),'staged'=>$routine['staged']??null,'pending_verification'=>$routine['pending_verification']??null,'activation_journal'=>$routine['activation_journal']??null,'interrupted_activation'=>(bool)($routine['interrupted_activation']??false),'update_control_cache_age_seconds'=>$publicHealth['update_control_cache_age_seconds']??null,'health_status'=>(string)($publicHealth['status']??''),'probe_error'=>(string)($publicProbeError ?: ($publicHealth['probe_error']??'')),'managed_inventory'=>is_array($publicDiagnostics['managed_inventory']??null)?$publicDiagnostics['managed_inventory']:null,'checks'=>$publicChecks],
            'note'=>'این بررسی فقط وضعیت فعلی و پاک‌بودن lifecycle را تأیید می‌کند؛ سازگاری دقیق هر بسته هنگام Stage با version/tree همان بسته fail-closed بررسی می‌شود.'
        ]]);
    }
    if($action==='public_emergency_code_rotate')WebAction::json(['success'=>true,'emergency'=>$core->publicEdgePublisher()->rotateEmergencyCode()]);
    if($action==='public_update_status')WebAction::json(['success'=>true,'public_update'=>$core->publicEdgePublisher()->updateStatus()]);
    if($action==='public_update_activate')WebAction::json(['success'=>true,'public_update'=>$core->publicEdgePublisher()->activateUpdate()]);
    if($action==='public_update_repair')WebAction::json(['success'=>true,'public_update'=>$core->publicEdgePublisher()->repairUpdate()]);
    if($action==='public_update_rollback')WebAction::json(['success'=>true,'public_update'=>$core->publicEdgePublisher()->rollbackUpdate()]);
    if(in_array($action,['update_activate','update_repair','update_rollback'],true))WebAction::json(['success'=>false,'code'=>'MAINTENANCE_PANEL_ROUTE_REQUIRED','message'=>'عملیات نصب Local باید از مسیر تأییدشده Maintenance انجام شود. موتور قدیمی از API قابل فراخوانی نیست.'],409);
    if($action==='update_discard')WebAction::json(['success'=>true,'update_center'=>$core->localUpdates()->discardStaged($actorId)]);
    if($action==='recovery_code_rotate')WebAction::json(['success'=>true,'recovery'=>$core->localUpdates()->rotateRecoveryCode($actorId)]);
    if($action==='backup_create')WebAction::json(['success'=>true,'backup'=>$core->recoveryWorkspace()->create((string)($data['passphrase']??''),$actorId)]);
    if($action==='backup_inspect')WebAction::json(['success'=>true,'manifest'=>$core->recoveryWorkspace()->inspect((string)($data['id']??''),(string)($data['passphrase']??''))]);
    if($action==='backup_restore')WebAction::json(['success'=>true,'restore'=>$core->recoveryWorkspace()->restore((string)($data['id']??''),(string)($data['passphrase']??''),$actorId)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(LocalUpdateException|RecoveryException|PublicEdgeSyncException $e){WebAction::knownFailure($e);}catch(Throwable $e){WebAction::unexpected($core,$e,'system.web_action_failed','عملیات پشتیبانی و نگهداری کامل نشد.');}
