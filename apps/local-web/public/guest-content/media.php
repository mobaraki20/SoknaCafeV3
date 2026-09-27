<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$key=trim((string)($_GET['key']??''));$file=$core->guestContent()->localPreview($key,$user);if($file===null){http_response_code(404);exit;}header('Content-Type: '.$file['mime']);header('Cache-Control: private,max-age=300');header('X-Content-Type-Options: nosniff');readfile($file['path']);
