<?php
declare(strict_types=1);
use Sokna\Local\Domain\Update\LocalUpdateException;
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new LocalUpdateException('method_not_allowed','روش درخواست معتبر نیست.',405);
    $expected=(string)($_SESSION['sokna_csrf_token']??'');$candidate=trim((string)($_POST['csrf_token']??''));if($expected===''||$candidate===''||!hash_equals($expected,$candidate))throw new LocalUpdateException('csrf_expired','نشست صفحه منقضی شده؛ صفحه را تازه کنید.',419);
    $file=$_FILES['package']??null;if(!is_array($file)||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new LocalUpdateException('upload_failed','آپلود بسته کامل نشد.',422);
    $size=(int)($file['size']??0);if($size<100||$size>268435456)throw new LocalUpdateException('package_size','حجم بسته update معتبر نیست.',413);
    $id=bin2hex(random_bytes(16));$dst=$core->localUpdates()->incomingDir().'/'.$id.'.zip';$tmp=(string)($file['tmp_name']??'');if(!is_uploaded_file($tmp)||!move_uploaded_file($tmp,$dst))throw new LocalUpdateException('upload_failed','ذخیره بسته update انجام نشد.',500);@chmod($dst,0600);
    try{$staged=$core->localUpdates()->stageUploadedZip($dst,(int)($user['id']??0));}finally{@unlink($dst);}
    echo json_encode(['success'=>true,'staged'=>$staged],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(LocalUpdateException $e){http_response_code($e->httpStatus);echo json_encode(['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $e){error_log('update upload: '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'code'=>'upload_failed','message'=>'آپلود بسته update انجام نشد.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
