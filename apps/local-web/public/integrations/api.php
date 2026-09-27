<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,['cashier_accounts']);$isAdmin=(string)($user['role']??'')==='admin';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot'){
            $integration=$core->integrationWorkspace()->snapshot();$finance=$core->financeWorkspace()->snapshot();
            WebAction::json(['success'=>true,'snapshot'=>['integrations'=>$integration,'open_accounts'=>$finance['open_accounts'],'printing'=>$isAdmin?$core->printManagement()->snapshot():null]]);
        }
        if($action==='account')WebAction::json(['success'=>true,'account'=>$core->settlements()->account((int)($_GET['session_id']??0))]);
        if($action==='reservation_search')WebAction::json(['success'=>true,'rows'=>$core->accommodation()->searchReservations((string)($_GET['q']??''),$user)]);
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='accommodation_prepare'){
        $reservation=['reservation_code'=>(string)($data['reservation_code']??''),'guest_name'=>(string)($data['guest_name']??''),'room_name'=>(string)($data['room_name']??''),'phone_hint'=>(string)($data['phone_hint']??'')];
        $expected=['expected_session_id'=>(int)($data['expected_session_id']??0),'expected_remaining_total'=>(int)($data['expected_remaining_total']??-1),'expected_signature'=>(string)($data['expected_signature']??'')];
        WebAction::json(['success'=>true,'result'=>$core->accommodation()->prepare((int)($data['session_id']??0),$reservation,$expected,$user)]);
    }
    if($action==='accommodation_charge')WebAction::json(['success'=>true,'result'=>$core->accommodation()->attemptCharge((int)($data['transfer_id']??0),$user)]);
    if($action==='accommodation_finalize')WebAction::json(['success'=>true,'result'=>$core->accommodation()->finalizeLocal((int)($data['transfer_id']??0),$user)]);
    if($action==='accommodation_void')WebAction::json(['success'=>true,'result'=>$core->accommodation()->attemptVoid((int)($data['transfer_id']??0),(string)($data['reason']??''),$user)]);
    if($action==='accommodation_reversal_finalize')WebAction::json(['success'=>true,'result'=>$core->accommodation()->finalizeLocalReversal((int)($data['transfer_id']??0),(string)($data['reason']??'تکمیل برگشت محلی'),$user)]);
    if(!$isAdmin)WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'این عملیات فقط برای مدیر فعال است.'],403);
    if($action==='print_agent_create')WebAction::json(['success'=>true,'result'=>$core->printing()->createAgent((string)($data['name']??''),$user)]);
    if($action==='print_destination_configure')WebAction::json(['success'=>true,'result'=>$core->printing()->configureDestination((string)($data['destination_key']??''),$data,$user)]);
    if($action==='print_template_import')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->import((string)($data['package_json']??''),$user)]);
    if($action==='print_template_activate')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->activate((int)($data['package_id']??0),$user)]);
    if($action==='print_template_preview')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->preview((int)($data['package_id']??0),(string)($data['destination_key']??''),$user)]);
    if($action==='print_test')WebAction::json(['success'=>true,'result'=>$core->printing()->enqueueTest((string)($data['destination_key']??''),(string)($data['request_id']??''),$user,isset($data['package_id'])?(int)$data['package_id']:null)]);
    if($action==='print_resolve')WebAction::json(['success'=>true,'result'=>$core->printing()->resolveAmbiguous((int)($data['job_id']??0),(string)($data['resolution']??''),(string)($data['reason']??''),$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('integrations web action: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات یکپارچه‌سازی انجام نشد.'],500);}
