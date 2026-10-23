<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);if((string)($user['role']??'')!=='admin')WebAction::json(['success'=>false,'code'=>'forbidden','message'=>'مدیریت چاپ فقط برای مدیر فعال است.'],403);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $readAction=(string)($_GET['action']??'');
        if($readAction==='print_template_details')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->details((int)($_GET['package_id']??0),$user)]);
        $guard=$core->snapshotGuard();$printing=$guard->capture('printing','workspace',['agents'=>[],'destinations'=>[],'recent_jobs'=>[],'templates'=>['packages'=>[]]],'وضعیت چاپ فعلاً در دسترس نیست.',fn():array=>$core->printManagement()->snapshot());
        $snapshot=$printing['value'];$snapshot['quick_preferences']=$core->orderStaffActions()->printQuickPreferences($user);WebAction::json(['success'=>true,'snapshot'=>$snapshot,'error'=>$printing['error']]);
    }
    $data=WebAction::requireMutation();$action=(string)($data['action']??'');
    if($action==='print_quick_preferences_update')WebAction::json($core->orderStaffActions()->updatePrintQuickPreferences($data,$user));
    if($action==='print_agent_create')WebAction::json(['success'=>false,'code'=>'agent_onboarding_moved','message'=>'ساخت Print Agent مستقل غیرفعال است. افزودن یا اتصال دوباره دستگاه فقط از «اتصال‌ها ← سرویس‌های ویندوز» انجام می‌شود.'],409);
    if($action==='print_destination_configure')WebAction::json(['success'=>true,'result'=>$core->printing()->configureDestination((string)($data['destination_key']??''),$data,$user)]);
    if($action==='print_template_import')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->import((string)($data['package_json']??''),$user)]);
    if($action==='print_template_version_save')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->saveVersion((int)($data['base_package_id']??0),(array)($data['package']??[]),$user)]);
    if($action==='print_template_standard_install')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->installStandard((string)($data['document_kind']??''),$user)]);
    if($action==='print_template_rollback')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->rollback((int)($data['package_id']??0),$user)]);
    if($action==='print_template_activate')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->activate((int)($data['package_id']??0),$user)]);
    if($action==='print_template_preview')WebAction::json(['success'=>true,'result'=>$core->printTemplates()->preview((int)($data['package_id']??0),(string)($data['destination_key']??''),$user,(string)($data['output_format']??'pdf'))]);
    if($action==='pdf_test_bridge')WebAction::json(['success'=>true,'result'=>$core->printing()->pdfTestBridge($user,(int)($data['agent_id']??0))]);
    if($action==='print_test')WebAction::json(['success'=>true,'result'=>$core->printing()->enqueueTest((string)($data['destination_key']??''),(string)($data['request_id']??''),$user,isset($data['package_id'])?(int)$data['package_id']:null)]);
    if($action==='print_resolve')WebAction::json(['success'=>true,'result'=>$core->printing()->resolveAmbiguous((int)($data['job_id']??0),(string)($data['resolution']??''),(string)($data['reason']??''),$user)]);
    if($action==='print_job_control')WebAction::json(['success'=>true,'result'=>$core->printing()->controlJob((int)($data['job_id']??0),(string)($data['job_action']??''),(string)($data['reason']??''),$user,isset($data['destination_key'])?(string)$data['destination_key']:null)]);
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات معتبر نیست.'],422);
}catch(Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);WebAction::unexpected($core,$e,'printing.web_action_failed','عملیات چاپ کامل نشد.');}
