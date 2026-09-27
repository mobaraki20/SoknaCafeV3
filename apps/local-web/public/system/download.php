<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
$core=require dirname(__DIR__).'/_app.php';LocalPage::requireAdmin($core);$id=trim((string)($_GET['id']??''));
try{$path=$core->systemDiagnostics()->supportBundlePath($id);}catch(Throwable){http_response_code(404);echo 'Support bundle not found.';exit;}
header('Content-Type: application/gzip');header('Content-Disposition: attachment; filename="'.basename($path).'"');header('Content-Length: '.(string)filesize($path));header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');readfile($path);
