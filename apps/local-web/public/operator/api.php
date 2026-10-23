<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['orders_floor','cashier_accounts','shift_supervision']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot'){
            $snapshot=$core->orderWorkspace()->operatorSnapshot($user);
            $snapshot['order_acceptance']=$core->orderAcceptance()->snapshot();
            $snapshot['print_quick_preferences']=$core->orderStaffActions()->printQuickPreferences($user);
            $snapshot['accommodation_enabled']=(bool)$core->config()->get('integrations.accommodation.enabled',false);
            $snapshot['can_manage_connections']=((string)($user['role']??'')==='admin');
            WebAction::json($snapshot);
        }
        if($action==='account')WebAction::json($core->orderWorkspace()->tableAccount((int)($_GET['table_id']??0),$user));
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='order_status')WebAction::json($core->orderStaffActions()->changeStatus($data,$user));
    if($action==='waiter_status')WebAction::json($core->waiterCallStaff()->changeStatus($data,$user));
    if($action==='table_move')WebAction::json($core->tableChanges()->move($data,$user));
    if($action==='order_reprint')WebAction::json($core->orderStaffActions()->reprintPreparation($data,$user));
    if($action==='order_item_adjust')WebAction::json($core->orderStaffActions()->adjustItemQuantity($data,$user));
    if($action==='print_quick_preferences_update')WebAction::json($core->orderStaffActions()->updatePrintQuickPreferences($data,$user));
    if($action==='order_acceptance_update'){
        $result=$core->orderAcceptance()->update($data,$user);
        $sync=['success'=>false,'degraded'=>false,'support_id'=>'','failed_channels'=>[]];
        try{
            $sync=$core->publicEdgePublisher()->syncAll();
        }catch(\Throwable $syncError){
            $supportId=substr($core->observability()->correlationId(),0,12);
            $core->observability()->logEvent('warning','orders.acceptance_public_sync_deferred',['exception'=>get_class($syncError)],$supportId);
            $sync=['success'=>false,'degraded'=>false,'support_id'=>$supportId,'failed_channels'=>['public']];
        }
        $publicReady=(bool)($sync['success']??false);
        WebAction::json(['success'=>true,'result'=>$result,'order_acceptance'=>$result['acceptance'],'public_sync'=>[
            'success'=>$publicReady,
            'degraded'=>(bool)($sync['degraded']??false),
            'support_id'=>(string)($sync['support_id']??''),
            'failed_channels'=>array_values((array)($sync['failed_channels']??[])),
        ],'public_sync_pending'=>!$publicReady]);
    }
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('operator web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات انجام نشد؛ دوباره تلاش کنید.'],500);
}
