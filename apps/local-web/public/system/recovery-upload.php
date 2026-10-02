<?php
declare(strict_types=1);
use Sokna\Local\Domain\Recovery\RecoveryException;
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RecoveryException('method_not_allowed','روش درخواست معتبر نیست.',405);
    $expected=(string)($_SESSION['sokna_csrf_token']??'');$candidate=trim((string)($_POST['csrf_token']??''));if($expected===''||$candidate===''||!hash_equals($expected,$candidate))throw new RecoveryException('csrf_expired','نشست صفحه منقضی شده؛ صفحه را تازه کنید.',419);
    $file=$_FILES['backup']??null;$uploadError=is_array($file)?(int)($file['error']??UPLOAD_ERR_NO_FILE):UPLOAD_ERR_NO_FILE;if($uploadError!==UPLOAD_ERR_OK){$problem=WebAction::uploadProblem($uploadError,'فایل بازیابی');throw new RecoveryException($problem['code'],$problem['message'],$problem['status']);}$size=(int)($file['size']??0);if($size<32||$size>536870912)throw new RecoveryException('backup_size','حجم فایل بازیابی معتبر نیست.',413);
    $tmp=(string)($file['tmp_name']??'');if(!is_uploaded_file($tmp))throw new RecoveryException('upload_failed','فایل آپلود معتبر نیست.',422);$scratch=$core->recoveryWorkspace()->incomingDir().'/'.bin2hex(random_bytes(12)).'.tmp';if(!move_uploaded_file($tmp,$scratch))throw new RecoveryException('upload_failed','ذخیره فایل بازیابی انجام نشد.',500);$meta=$core->recoveryWorkspace()->registerIncoming($scratch,(string)($file['name']??'backup.skbf'));@unlink($scratch);echo json_encode(['success'=>true,'backup'=>$meta],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(RecoveryException $e){http_response_code($e->httpStatus);echo json_encode(['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $e){error_log('recovery upload: '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'code'=>'upload_failed','message'=>'آپلود فایل بازیابی انجام نشد.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
