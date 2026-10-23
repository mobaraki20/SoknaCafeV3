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
// If an update reached the migration/health phase and then failed, the deployed
// target Web tree is intentionally retained. Business routes are quarantined,
// but login + Update Center stay browser-accessible so recovery never requires
// CMD/PowerShell/SQL or rebinding an older Web tree.
$dataDir=rtrim(trim((string)($config['app']['data_dir']??'')),"\\/");
$recoveryMarker=$dataDir!==''?$dataDir.DIRECTORY_SEPARATOR.'lifecycle'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR.'recovery-required.json':'';
if($recoveryMarker!==''&&is_file($recoveryMarker)){
    $requestPath=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
    $base=rtrim(LocalUrl::basePath(),'/');if($base!==''&&$base!=='/'&&str_starts_with($requestPath,$base))$requestPath=substr($requestPath,strlen($base))?:'/';
    $allowed=$requestPath==='/login.php'||$requestPath==='/logout.php'||$requestPath==='/local-update-runner.php'||$requestPath==='/local-recovery.php'||$requestPath==='/brand-asset.php'||$requestPath==='/manifest.php'||$requestPath==='/scds.php'||str_starts_with($requestPath,'/system/');
    if(!$allowed){header('Location: '.LocalUrl::path('/system/?tab=updates&recovery=1'));exit;}
}
require_once dirname(__DIR__).'/bootstrap.php';$core=sokna_local_bootstrap($config);
// The token-authenticated Runtime entrypoint has no browser identity. A shared
// HttpClient cookie must not serialize independent machine triggers on PHP's
// session file lock. Match the actual script file; never trust a request header,
// query parameter or URL to opt a browser route out of session authentication.
$runtimeScript=realpath(__DIR__.'/internal/runtime/v1/trigger.php');
$requestScript=realpath((string)($_SERVER['SCRIPT_FILENAME']??''));
if($runtimeScript===false||$requestScript===false||$requestScript!==$runtimeScript)$core->startSession();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');header('X-Sokna-Build: '.trim((string)@file_get_contents(dirname(__DIR__).'/VERSION.txt')));header('X-Sokna-Runtime: php-ready');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
return $core;
