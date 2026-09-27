<?php
declare(strict_types=1);
use Sokna\Local\Setup\BrowserSetupService;
$root=dirname(__DIR__,3);
require_once dirname(__DIR__).'/src/Setup/SetupException.php';
require_once dirname(__DIR__).'/src/Setup/BrowserSetupService.php';
$setup=new BrowserSetupService($root,dirname(__DIR__));
if(!$setup->status()['installed']){header('Location: /setup/');exit;}
$configFile=$root.DIRECTORY_SEPARATOR.'config.php';$config=require $configFile;if(!is_array($config)){http_response_code(500);exit;}
require_once dirname(__DIR__).'/bootstrap.php';$core=sokna_local_bootstrap($config);$core->startSession('/');header('X-Sokna-Runtime: php-ready');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
return $core;
