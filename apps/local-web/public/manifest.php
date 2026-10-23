<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalUrl;
$core=require __DIR__.'/_app.php';$name='سکنا';$identity=$core->guestContent()->brandIdentity('draft');
try{$q=$core->database()->prepare("SELECT setting_value FROM settings WHERE setting_key='cafe.name' LIMIT 1");$q->execute();$v=trim((string)($q->fetchColumn()?:''));if($v!=='')$name=$v;}catch(Throwable){}
$version=(string)($identity['version']??'fallback');$asset=static fn(string $v):string=>LocalUrl::path('/brand-asset.php').'?variant='.rawurlencode($v).'&iv='.rawurlencode($version);
$manifest=['id'=>'./','name'=>'سامانه مدیریت کافه '.$name,'short_name'=>$name,'description'=>'سامانه مدیریت کافه '.$name,'lang'=>'fa','dir'=>'rtl','start_url'=>'./','scope'=>'./','display'=>'standalone','orientation'=>'any','background_color'=>'#f7f1e8','theme_color'=>'#0f6b66','icons'=>[['src'=>$asset('icon-192'),'sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],['src'=>$asset('icon-512'),'sizes'=>'512x512','type'=>'image/png','purpose'=>'any']]];
header('Content-Type: application/manifest+json; charset=utf-8');header('Cache-Control: no-cache');echo json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
