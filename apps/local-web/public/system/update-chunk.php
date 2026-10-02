<?php
declare(strict_types=1);
use Sokna\Local\Domain\Update\LocalUpdateException;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=WebAction::requireAny($core,[]);$actorId=(int)($user['id']??0);$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
try{
    if($method==='POST'){
        $data=WebAction::requireMutation();$action=(string)($data['action']??'');
        if($action==='init')WebAction::json(['success'=>true,'upload'=>$core->localUpdateUploads()->init((string)($data['name']??''),(int)($data['size']??0),$actorId)]);
        if($action==='finalize')WebAction::json(['success'=>true]+$core->localUpdateUploads()->finalize((string)($data['id']??''),$actorId));
        if($action==='abort')WebAction::json(['success'=>true]+$core->localUpdateUploads()->abort((string)($data['id']??''),$actorId));
        WebAction::json(['success'=>false,'code'=>'invalid_action','message'=>'عملیات بارگذاری معتبر نیست.'],422);
    }
    $action=(string)($_GET['action']??'');
    if($method==='PUT'&&$action==='chunk'){
        WebAction::requireCsrfHeader();$id=(string)($_GET['id']??'');$offsetRaw=(string)($_GET['offset']??'');if(!ctype_digit($offsetRaw))WebAction::json(['success'=>false,'code'=>'invalid_offset','message'=>'موقعیت قطعه بارگذاری معتبر نیست.'],422);$stream=fopen('php://input','rb');if(!is_resource($stream))throw new LocalUpdateException('upload_interrupted','خواندن قطعه بارگذاری ممکن نشد.',422);$bytes=stream_get_contents($stream,\Sokna\Local\Domain\Update\LocalUpdateUploadService::CHUNK_BYTES+1);fclose($stream);if(!is_string($bytes))throw new LocalUpdateException('upload_interrupted','خواندن قطعه بارگذاری ممکن نشد.',422);WebAction::json(['success'=>true,'upload'=>$core->localUpdateUploads()->append($id,(int)$offsetRaw,$bytes,$actorId)]);
    }
    WebAction::json(['success'=>false,'code'=>'method_not_allowed','message'=>'روش درخواست معتبر نیست.'],405);
}catch(LocalUpdateException $e){WebAction::knownFailure($e);}catch(Throwable $e){error_log('local update chunk: '.$e->getMessage());WebAction::json(['success'=>false,'code'=>'upload_failed','message'=>'بارگذاری بسته به‌روزرسانی کامل نشد.'],500);}
