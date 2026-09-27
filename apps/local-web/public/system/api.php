<?php
declare(strict_types=1);
use Sokna\Local\Domain\Recovery\RecoveryException;
use Sokna\Local\Domain\Update\LocalUpdateException;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);$actorId=(int)($user['id']??0);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        WebAction::json(['success'=>true,'snapshot'=>$core->systemDiagnostics()->snapshot(),'update_center'=>$core->updateCenter()->snapshot(),'recovery'=>$core->recoveryWorkspace()->snapshot()]);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='support_bundle')WebAction::json(['success'=>true,'bundle'=>$core->systemDiagnostics()->createSupportBundle()]);
    if($action==='update_activate')WebAction::json(['success'=>true,'update_center'=>$core->localUpdates()->activateStaged($actorId)]);
    if($action==='update_repair')WebAction::json(['success'=>true,'update_center'=>$core->localUpdates()->repairStaged($actorId)]);
    if($action==='update_rollback')WebAction::json(['success'=>true,'update_center'=>$core->localUpdates()->rollback($actorId)]);
    if($action==='recovery_code_rotate')WebAction::json(['success'=>true,'recovery'=>$core->localUpdates()->rotateRecoveryCode($actorId)]);
    if($action==='backup_create')WebAction::json(['success'=>true,'backup'=>$core->recoveryWorkspace()->create((string)($data['passphrase']??''),$actorId)]);
    if($action==='backup_inspect')WebAction::json(['success'=>true,'manifest'=>$core->recoveryWorkspace()->inspect((string)($data['id']??''),(string)($data['passphrase']??''))]);
    if($action==='backup_restore')WebAction::json(['success'=>true,'restore'=>$core->recoveryWorkspace()->restore((string)($data['id']??''),(string)($data['passphrase']??''),$actorId)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(LocalUpdateException|RecoveryException $e){WebAction::knownFailure($e);}catch(Throwable $e){error_log('system control web action: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'system_control_failed','message'=>'عملیات وضعیت/بازیابی انجام نشد.'],500);}
