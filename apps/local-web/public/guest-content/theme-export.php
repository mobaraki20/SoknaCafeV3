<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\Domain\GuestContent\GuestContentException;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);
try{
    $id=(int)($_GET['id']??0);if($id<1)throw new GuestContentException('theme_package_invalid','بسته تم معتبر نیست.',422);
    $result=$core->guestContent()->exportThemePackage($id,$user);
    header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.str_replace('"','',(string)$result['filename']).'"');header('Cache-Control: no-store');echo (string)$result['content'];
}catch(GuestContentException $e){http_response_code($e->httpStatus);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
