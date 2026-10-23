<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')WebAction::json(['success'=>false,'code'=>'method_not_allowed','message'=>'روش معتبر نیست.'],405);
    $action=(string)($_GET['action']??'report');
    if($action==='activity'){
        WebAction::requireAny($core,['shift_supervision']);
        $actorRaw=trim((string)($_GET['actor_id']??''));$actorId=$actorRaw!==''?(int)$actorRaw:null;
        WebAction::json(['success'=>true,'activity'=>$core->reporting()->activityReport((string)($_GET['from']??''),(string)($_GET['to']??''),$actorId,(string)($_GET['family']??''),(string)($_GET['q']??''),(int)($_GET['limit']??40),(int)($_GET['offset']??0))]);
    }
    WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
    WebAction::json(['success'=>true,'report'=>$core->reporting()->report((string)($_GET['from']??''),(string)($_GET['to']??''))]);
}catch(Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('reports api: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'گزارش ساخته نشد.'],500);}
