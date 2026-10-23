<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
$core=require dirname(__DIR__).'/_app.php';
LocalPage::requireAdmin($core);
$id=trim((string)($_GET['id']??''));
try{$path=$core->systemDiagnostics()->supportBundlePath($id);}catch(Throwable){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'بسته پشتیبانی پیدا نشد.';exit;}
if(session_status()===PHP_SESSION_ACTIVE)@session_write_close();
while(ob_get_level()>0){@ob_end_clean();}
clearstatcache(true,$path);
$size=@filesize($path);
if(!is_int($size)||$size<=0){http_response_code(409);header('Content-Type: text/plain; charset=utf-8');echo 'فایل بسته پشتیبانی خالی یا هنوز آماده نیست.';exit;}
$name=basename($path);
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name));
header('Content-Length: '.(string)$size);
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
$fh=@fopen($path,'rb');
if($fh===false){http_response_code(404);exit;}
fpassthru($fh);
fclose($fh);
exit;
