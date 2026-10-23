<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit;}
    $canAudit=(string)($user['role']??'')==='admin'||$core->auth()->hasCapability('shift_supervision',$user);
    $actorRaw=trim((string)($_GET['actor_id']??''));$actorId=$actorRaw!==''?(int)$actorRaw:null;
    $bytes=$core->reporting()->currentViewWorkbook((string)($_GET['from']??''),(string)($_GET['to']??''),$canAudit,$actorId,(string)($_GET['family']??''),(string)($_GET['q']??''));
    $from=preg_replace('/[^0-9-]/','',(string)($_GET['from']??''));$to=preg_replace('/[^0-9-]/','',(string)($_GET['to']??''));$filename='sokna-report-'.($from?:'from').'-'.($to?:'to').'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($bytes));header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $bytes;
}catch(Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('reports export: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'export_failed','message'=>'ساخت فایل اکسل انجام نشد.'],500);
}
