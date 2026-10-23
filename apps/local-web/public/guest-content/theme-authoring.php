<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
$core=require dirname(__DIR__).'/_app.php';LocalPage::requireAdmin($core);
$type=trim((string)($_GET['file']??'contract'));
if($type==='starter'){
    $path=dirname(__DIR__,2).'/resources/guest-themes/sokna-editorial/theme.json';
    $name='sokna-theme-v2-starter.soknatheme';$mime='application/json; charset=utf-8';
}elseif($type==='schema'){
    $path=dirname(__DIR__,2).'/resources/GUEST_THEME_V2_SCHEMA.json';
    $name='SOKNA_GUEST_THEME_V2_SCHEMA.json';$mime='application/schema+json; charset=utf-8';
}else{
    $path=dirname(__DIR__,2).'/resources/GUEST_THEME_ENGINE_V2.json';
    $name='SOKNA_GUEST_THEME_ENGINE_V2.json';$mime='application/json; charset=utf-8';
}
if(!is_file($path)){http_response_code(404);exit('not found');}
header('Content-Type: '.$mime);header('Content-Disposition: attachment; filename="'.$name.'"');header('Cache-Control: no-store');readfile($path);
