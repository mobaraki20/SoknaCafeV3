<?php
declare(strict_types=1);
use Sokna\Local\Setup\BrowserSetupService;
use Sokna\Local\UI\LocalUrl;
$local=dirname(__DIR__);$root=dirname($local);
require_once dirname(__DIR__).'/src/Core/Session.php';
require_once dirname(__DIR__).'/src/UI/LocalUrl.php';
require_once dirname(__DIR__).'/src/Setup/SetupException.php';
require_once dirname(__DIR__).'/src/Setup/BrowserSetupService.php';
$setup=new BrowserSetupService($root,$local);
if(!$setup->status()['installed']){header('Location: '.LocalUrl::path('/setup/'));exit;}
$configFile=$root.DIRECTORY_SEPARATOR.'config.php';$config=require $configFile;if(!is_array($config)){http_response_code(500);exit;}
require_once dirname(__DIR__).'/bootstrap.php';$core=sokna_local_bootstrap($config);$core->startSession();header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');header('X-Sokna-Build: '.trim((string)@file_get_contents(dirname(__DIR__).'/VERSION.txt')));header('X-Sokna-Runtime: php-ready');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
return $core;
