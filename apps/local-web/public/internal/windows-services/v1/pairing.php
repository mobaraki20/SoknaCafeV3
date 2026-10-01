<?php
declare(strict_types=1);
use Sokna\Local\Domain\System\WindowsServicesPairingException;
use Sokna\Local\UI\WebAction;

$core=require dirname(__DIR__,3).'/_app.php';
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')WebAction::json(['success'=>false,'code'=>'method_not_allowed','message'=>'روش درخواست معتبر نیست.'],405);
    if((string)($_SERVER['HTTP_X_SOKNA_WINDOWS_SERVICES_PAIRING']??'')!=='1')WebAction::json(['success'=>false,'code'=>'pairing_contract_required','message'=>'قرارداد Pairing معتبر نیست.'],426);
    $raw=(string)file_get_contents('php://input');if(strlen($raw)>32768)WebAction::json(['success'=>false,'code'=>'request_too_large','message'=>'درخواست Pairing بیش از حد مجاز است.'],413);
    $data=json_decode($raw,true);if(!is_array($data))WebAction::json(['success'=>false,'code'=>'invalid_json','message'=>'ساختار درخواست Pairing معتبر نیست.'],400);
    $action=(string)($data['action']??'');$code=(string)($data['pairing_code']??'');$remote=(string)($_SERVER['REMOTE_ADDR']??'');
    $service=$core->windowsServicesPairing();
    if($action==='exchange')WebAction::json(['success'=>true]+$service->exchange($code,$remote));
    if($action==='confirm')WebAction::json(['success'=>true]+$service->confirm($code,$remote));
    if($action==='cancel')WebAction::json(['success'=>true]+$service->cancel($code,$remote));
    WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات Pairing معتبر نیست.'],422);
}catch(WindowsServicesPairingException $e){WebAction::knownFailure($e);}catch(Throwable $e){error_log('windows services pairing: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'pairing_failed','message'=>'اتصال Windows Services کامل نشد.'],500);}
