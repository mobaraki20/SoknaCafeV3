<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\Domain\GuestContent\GuestContentException;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new GuestContentException('method_not_allowed','روش درخواست معتبر نیست.',405);
    $expected=(string)($_SESSION['sokna_csrf_token']??'');$candidate=trim((string)($_POST['csrf_token']??''));if($expected===''||$candidate===''||!hash_equals($expected,$candidate))throw new GuestContentException('csrf_expired','نشست صفحه منقضی شده؛ صفحه را تازه کنید.',419);
    $file=$_FILES['media']??null;if(!is_array($file)||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new GuestContentException('upload_failed','آپلود تصویر کامل نشد.',422);
    $tmp=(string)($file['tmp_name']??'');if(!is_uploaded_file($tmp))throw new GuestContentException('upload_failed','فایل بارگذاری‌شده معتبر نیست.',422);
    $result=$core->guestContent()->importUpload($tmp,(string)($file['name']??'image'),(string)($_POST['alt_text']??''),$user);
    echo json_encode(['success'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(GuestContentException $e){http_response_code($e->httpStatus);echo json_encode(['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $e){error_log('guest media upload: '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'code'=>'upload_failed','message'=>'آپلود تصویر انجام نشد.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
