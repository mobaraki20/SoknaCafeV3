<?php
declare(strict_types=1);
$root=dirname(__DIR__,3);$configFile=$root.DIRECTORY_SEPARATOR.'config.php';if(!is_file($configFile)){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');echo 'SOKNA setup required';exit;}$config=require $configFile;if(!is_array($config)){http_response_code(500);exit;}
require_once dirname(__DIR__).'/bootstrap.php';$core=sokna_local_bootstrap($config);$core->startSession('/');header('X-Sokna-Runtime: php-ready');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
return $core;
