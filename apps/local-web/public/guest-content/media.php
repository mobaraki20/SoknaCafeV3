<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$key=trim((string)($_GET['key']??''));$variant=(string)($_GET['variant']??'card');$variant=$variant==='master'?'master':'card';$file=$core->guestContent()->localPreview($key,$user,$variant);if($file===null){http_response_code(404);exit;}header('Content-Type: '.$file['mime']);header('Cache-Control: private,max-age=300');header('X-Content-Type-Options: nosniff');readfile($file['path']);
