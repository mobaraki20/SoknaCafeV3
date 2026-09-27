<?php
declare(strict_types=1);

use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,[
    'staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports',
]);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $action=(string)($_GET['action']??'snapshot');
        if($action==='snapshot')WebAction::json($core->staffConsumptionWorkspace()->snapshot($user));
        if($action==='history')WebAction::json($core->staffConsumptionWorkspace()->history(isset($_GET['personnel_id'])?(int)$_GET['personnel_id']:null,$user,(int)($_GET['limit']??100)));
        if($action==='account')WebAction::json(['success'=>true,'account'=>$core->staffAccountService()->account((int)($_GET['personnel_id']??0),$user,(int)($_GET['limit']??100))]);
        if($action==='report')WebAction::json($core->staffConsumptionReports()->report($_GET,$user));
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
    }

    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='quote_self')WebAction::json($core->staffConsumptionWorkspace()->quoteSelf($data,$user));
    if($action==='quote_proxy')WebAction::json($core->staffConsumptionWorkspace()->quoteForPersonnel((int)($data['personnel_id']??0),$data,$user));
    if($action==='post_self')WebAction::json($core->staffConsumptionPosting()->postSelf($data,$user));
    if($action==='post_proxy')WebAction::json($core->staffConsumptionPosting()->postForPersonnel((int)($data['personnel_id']??0),$data,$user));
    if($action==='policy_save')WebAction::json(['success'=>true,'result'=>$core->staffBenefitManagement()->savePolicy($data,$user)]);
    if($action==='rule_save')WebAction::json(['success'=>true,'result'=>$core->staffBenefitManagement()->saveRule($data,$user)]);
    if($action==='profile_save')WebAction::json(['success'=>true,'result'=>$core->staffBenefitManagement()->assignProfile($data,$user)]);
    if($action==='override_save')WebAction::json(['success'=>true,'result'=>$core->staffBenefitManagement()->saveOverride($data,$user)]);
    if($action==='account_payment')WebAction::json(['success'=>true,'entry'=>$core->staffAccountService()->payment($data,$user)]);
    if($action==='account_waiver')WebAction::json(['success'=>true,'entry'=>$core->staffAccountService()->waiver($data,$user)]);
    if($action==='account_reverse')WebAction::json(['success'=>true,'entry'=>$core->staffAccountService()->reverse($data,$user)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(\Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('staff consumption web action: '.$e->getMessage());
    WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'عملیات مصرف پرسنل انجام نشد.'],500);
}
