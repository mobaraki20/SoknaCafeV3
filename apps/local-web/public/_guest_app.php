<?php
declare(strict_types=1);

use Sokna\Local\Setup\BrowserSetupService;
use Sokna\Local\UI\LocalUrl;

$local=dirname(__DIR__);$root=dirname($local);
require_once dirname(__DIR__).'/src/UI/LocalUrl.php';
require_once dirname(__DIR__).'/src/Setup/SetupException.php';
require_once dirname(__DIR__).'/src/Setup/BrowserSetupService.php';
$setup=new BrowserSetupService($root,$local);
if(!$setup->status()['installed']){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');echo 'سامانه محلی هنوز آماده نیست.';exit;}
$configFile=$root.DIRECTORY_SEPARATOR.'config.php';$config=require $configFile;if(!is_array($config)){http_response_code(500);exit;}
$dataDir=rtrim(trim((string)($config['app']['data_dir']??'')),"\\/");
$recoveryMarker=$dataDir!==''?$dataDir.DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'recovery-required.json':'';
if($recoveryMarker!==''&&is_file($recoveryMarker)){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');echo 'سامانه محلی در حالت بازیابی است؛ ثبت سفارش مهمان موقتاً متوقف است.';exit;}
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/src/Domain/GuestLan/GuestLanRuntimeService.php';
require_once dirname(__DIR__).'/src/Domain/GuestLan/GuestLanPageRenderer.php';
require_once dirname(__DIR__).'/src/Domain/GuestLan/GuestLanThemeCssService.php';
require_once dirname(__DIR__).'/src/Domain/GuestLan/GuestLanGatewayService.php';
$core=sokna_local_bootstrap($config);
header('X-Sokna-Build: '.trim((string)@file_get_contents(dirname(__DIR__).'/VERSION.txt')));
header('X-Sokna-Guest-Transport: local_lan');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
return $core;
