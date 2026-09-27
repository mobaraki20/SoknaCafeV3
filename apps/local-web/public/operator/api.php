<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['orders_floor','cashier_accounts','shift_supervision']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action!=='snapshot')WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
        WebAction::json($core->orderWorkspace()->operatorSnapshot($user));
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='order_status')WebAction::json($core->orderStaffActions()->changeStatus($data,$user));
    if($action==='waiter_status')WebAction::json($core->waiterCallStaff()->changeStatus($data,$user));
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('operator web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات انجام نشد؛ دوباره تلاش کنید.'],500);
}
