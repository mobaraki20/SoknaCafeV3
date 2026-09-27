<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['orders_floor']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'workspace');
        if($action==='workspace')WebAction::json($core->orderWorkspace()->staffWorkspace($user));
        if($action==='draft')WebAction::json($core->tableDrafts()->get((int)($_GET['table_id']??0),$user));
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='quick_order')WebAction::json($core->staffQuickOrders()->commit($data,$user));
    if($action==='draft_save')WebAction::json($core->tableDrafts()->save($data,$user));
    if($action==='draft_finalize')WebAction::json($core->tableDrafts()->finalize($data,$user));
    if($action==='draft_cancel')WebAction::json($core->tableDrafts()->cancel($data,$user));
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('staff web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات انجام نشد؛ دوباره تلاش کنید.'],500);
}
