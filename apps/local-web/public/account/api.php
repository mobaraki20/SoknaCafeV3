<?php
declare(strict_types=1);

use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireUser($core);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET')WebAction::json(['success'=>true,'account'=>$core->account()->snapshot($user)]);
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='profile_save')WebAction::json(['success'=>true,'result'=>$core->account()->saveProfile($data,$user)]);
    if($action==='password_change')WebAction::json(['success'=>true,'result'=>$core->account()->changePassword($data,$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('account web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'تغییرات حساب ذخیره نشد.'],500);
}
