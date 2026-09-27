<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,['cashier_accounts']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot')WebAction::json(['success'=>true,'snapshot'=>$core->financeWorkspace()->snapshot()]);
        if($action==='account')WebAction::json(['success'=>true,'account'=>$core->settlements()->account((int)($_GET['session_id']??0))]);
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='discount')WebAction::json(['success'=>true,'result'=>$core->settlements()->setDiscount((int)($data['session_id']??0),isset($data['discount_type'])?(string)$data['discount_type']:null,(int)($data['discount_value']??0),$user)]);
    if($action==='settle')WebAction::json(['success'=>true,'result'=>$core->settlements()->settle($data,$user)]);
    if($action==='reverse')WebAction::json(['success'=>true,'result'=>$core->settlements()->reverse((int)($data['settlement_id']??0),(string)($data['reason']??''),(string)($data['request_id']??''),$user,false)]);
    if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'این عملیات فقط برای مدیر فعال است.'],403);
    if($action==='tax_enabled')WebAction::json(['success'=>true,'result'=>$core->tax()->setEnabled((bool)($data['enabled']??false),$user)]);
    if($action==='tax_rate')WebAction::json(['success'=>true,'result'=>$core->tax()->createRateVersion($data,$user)]);
    if($action==='tax_policy')WebAction::json(['success'=>true,'result'=>$core->tax()->createItemPolicyVersion($data,$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('finance web action: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات مالی انجام نشد.'],500);}
