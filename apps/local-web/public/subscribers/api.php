<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,['cashier_accounts']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'search');
        if($action==='search')WebAction::json(['success'=>true,'rows'=>$core->financeWorkspace()->subscriberDirectory((string)($_GET['q']??''),(string)($_GET['status']??'all'),($_GET['debt']??'0')==='1')]);
        if($action==='detail')WebAction::json(['success'=>true,'detail'=>$core->financeWorkspace()->subscriberDetail((int)($_GET['id']??0))]);
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='payment')WebAction::json(['success'=>true,'result'=>$core->subscriberAccounts()->payment($data,$user)]);
    if($action==='create')WebAction::json(['success'=>true,'result'=>$core->subscribers()->create($data,$user)]);
    if($action==='update')WebAction::json(['success'=>true,'result'=>$core->subscribers()->update((int)($data['subscriber_id']??0),$data,$user)]);
    if($action==='reverse_payment')WebAction::json(['success'=>true,'result'=>$core->subscriberAccounts()->reversePayment($data,$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('subscriber web action: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات حساب مشتری انجام نشد.'],500);}
