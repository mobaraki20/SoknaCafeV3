<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
$core=require dirname(__DIR__).'/_app.php';LocalPage::requireAdmin($core);$id=trim((string)($_GET['id']??''));try{$path=$core->recoveryWorkspace()->path($id);}catch(Throwable){http_response_code(404);echo 'فایل پشتیبان پیدا نشد.';exit;}header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.basename($path).'"');header('Content-Length: '.filesize($path));header('Cache-Control: no-store');readfile($path);
