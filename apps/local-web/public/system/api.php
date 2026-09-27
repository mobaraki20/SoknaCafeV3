<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')WebAction::json(['success'=>true,'snapshot'=>$core->systemDiagnostics()->snapshot()]);
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='support_bundle')WebAction::json(['success'=>true,'bundle'=>$core->systemDiagnostics()->createSupportBundle()]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(Throwable $e){error_log('system diagnostics web action: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'system_diagnostics_failed','message'=>'دریافت وضعیت سیستم انجام نشد.'],500);}
