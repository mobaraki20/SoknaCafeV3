<?php
declare(strict_types=1);
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
try{if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')WebAction::json(['success'=>false,'code'=>'method_not_allowed','message'=>'روش معتبر نیست.'],405);WebAction::json(['success'=>true,'report'=>$core->reporting()->report((string)($_GET['from']??''),(string)($_GET['to']??''))]);}catch(Throwable $e){if(property_exists($e,'httpStatus'))WebAction::knownFailure($e);error_log('reports api: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'internal_error','message'=>'گزارش ساخته نشد.'],500);}
