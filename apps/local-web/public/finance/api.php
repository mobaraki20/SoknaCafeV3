<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,['cashier_accounts']);
$respondAccommodation=static function(array $result,string $fallback):void{
    if(($result['success']??true)===false){
        $ambiguous=(bool)($result['ambiguous']??false);$posted=(bool)($result['posted']??false);$voided=(bool)($result['voided']??false);
        $message=trim((string)($result['message']??''));if($message==='')$message=$fallback;
        if($ambiguous)$message='نتیجه عملیات اقامتگاه قطعی نیست؛ دوباره آن را ثبت نکنید و وضعیت انتقال را در بخش مالی بررسی کنید.';
        $code=$posted?'local_finalize_pending':($voided?'local_reversal_pending':($ambiguous?'accommodation_result_uncertain':'accommodation_remote_failed'));
        WebAction::json(['success'=>false,'code'=>$code,'message'=>$message,'result'=>$result],($posted||$voided||$ambiguous)?409:502);
    }
    WebAction::json(['success'=>true,'result'=>$result]);
};
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot'){
            $snap=$core->financeWorkspace()->snapshot();
            $accommodation=$core->snapshotGuard()->capture(
                'finance','accommodation',
                ['enabled'=>false,'endpoint'=>'','recent_transfers'=>[]],
                'اطلاعات انتقال اقامتگاه فعلاً در دسترس نیست.',
                fn():array=>(array)($core->integrationWorkspace()->snapshot()['accommodation']??[])
            );
            $snap['accommodation']=$accommodation['value'];
            if($accommodation['error']!==null)$snap['errors']['accommodation']=$accommodation['error'];
            WebAction::json(['success'=>true,'snapshot'=>$snap]);
        }
        if($action==='account')WebAction::json(['success'=>true,'account'=>$core->settlements()->account((int)($_GET['session_id']??0))]);
        if($action==='settlement')WebAction::json(['success'=>true,'settlement'=>$core->financeWorkspace()->settlementDetail((int)($_GET['id']??0))]);
        if($action==='reservation_search')WebAction::json(['success'=>true,'rows'=>$core->accommodation()->searchReservations((string)($_GET['q']??''),$user)]);
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='review')WebAction::json(['success'=>true,'review'=>$core->settlements()->review($data,$user)]);
    if($action==='receipt_reprint')WebAction::json(['success'=>true,'result'=>$core->settlements()->reprintReceipt((int)($data['settlement_id']??0),(string)($data['request_id']??''),$user)]);
    if($action==='discount_preview')WebAction::json(['success'=>true,'result'=>$core->settlements()->previewDiscount((int)($data['session_id']??0),isset($data['discount_type'])?(string)$data['discount_type']:null,(int)($data['discount_value']??0),$user)]);
    if($action==='discount')WebAction::json(['success'=>true,'result'=>$core->settlements()->setDiscount((int)($data['session_id']??0),isset($data['discount_type'])?(string)$data['discount_type']:null,(int)($data['discount_value']??0),$user)]);
    if($action==='settle')WebAction::json(['success'=>true,'result'=>$core->settlements()->settle($data,$user)]);
    if($action==='settlement_recovery_required')WebAction::json(['success'=>true,'result'=>$core->settlements()->markRecoveryRequired($data,$user)]);
    if($action==='followup_detach')WebAction::json(['success'=>true,'result'=>$core->settlements()->detachForFollowup($data,$user)]);
    if($action==='followup_destination')WebAction::json(['success'=>true,'result'=>$core->settlements()->setFollowupDestination((int)($data['session_id']??0),(string)($data['destination']??''),$user)]);
    if($action==='reverse')WebAction::json(['success'=>true,'result'=>$core->settlements()->reverse((int)($data['settlement_id']??0),(string)($data['reason']??''),(string)($data['request_id']??''),$user,false)]);
    if($action==='accommodation_prepare'){
        $reservation=['reservation_code'=>(string)($data['reservation_code']??''),'guest_name'=>(string)($data['guest_name']??''),'room_name'=>(string)($data['room_name']??''),'phone_hint'=>(string)($data['phone_hint']??'')];
        $expected=['expected_session_id'=>(int)($data['expected_session_id']??0),'expected_remaining_total'=>(int)($data['expected_remaining_total']??-1),'expected_signature'=>(string)($data['expected_signature']??'')];
        WebAction::json(['success'=>true,'result'=>$core->accommodation()->prepare((int)($data['session_id']??0),$reservation,$expected,$user)]);
    }
    if($action==='accommodation_charge')$respondAccommodation($core->accommodation()->attemptCharge((int)($data['transfer_id']??0),$user),'ثبت هزینه در اقامتگاه کامل نشد.');
    if($action==='accommodation_finalize')$respondAccommodation($core->accommodation()->finalizeLocal((int)($data['transfer_id']??0),$user),'تکمیل تسویه محلی انجام نشد.');
    if($action==='accommodation_void')$respondAccommodation($core->accommodation()->attemptVoid((int)($data['transfer_id']??0),(string)($data['reason']??''),$user),'برگشت انتقال اقامتگاه کامل نشد.');
    if($action==='accommodation_reversal_finalize')$respondAccommodation($core->accommodation()->finalizeLocalReversal((int)($data['transfer_id']??0),(string)($data['reason']??'تکمیل برگشت محلی'),$user),'تکمیل برگشت محلی انجام نشد.');
    if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'این عملیات فقط برای مدیر فعال است.'],403);
    if($action==='tax_enabled')WebAction::json(['success'=>true,'result'=>$core->tax()->setEnabled((bool)($data['enabled']??false),$user)]);
    if($action==='tax_rate')WebAction::json(['success'=>true,'result'=>$core->tax()->createRateVersion($data,$user)]);
    if($action==='tax_policy')WebAction::json(['success'=>true,'result'=>$core->tax()->createItemPolicyVersion($data,$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);WebAction::unexpected($core,$e,'finance.web_action_failed','عملیات مالی کامل نشد. دوباره تلاش کنید.');}
