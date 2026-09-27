<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['preparation','shift_supervision']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')WebAction::json($core->preparation()->feed($user));
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='claim')WebAction::json($core->preparation()->claim($data,$user));
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('preparation web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات انجام نشد؛ دوباره تلاش کنید.'],500);
}
