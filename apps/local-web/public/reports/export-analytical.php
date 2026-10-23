<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=WebAction::requireAny($core,['shift_supervision']);
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);exit;}
    $bytes=$core->reporting()->analyticalWorkbook((string)($_GET['from']??''),(string)($_GET['to']??''));
    $from=preg_replace('/[^0-9-]/','',(string)($_GET['from']??''));$to=preg_replace('/[^0-9-]/','',(string)($_GET['to']??''));$filename='sokna-analytical-'.($from?:'from').'-'.($to?:'to').'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($bytes));header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $bytes;
}catch(Throwable $e){
    if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);
    error_log('reports analytical export: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'export_failed','message'=>'ساخت فایل تحلیلی اکسل انجام نشد.'],500);
}
