<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/bootstrap.php';
require_once dirname(__DIR__,2).'/src/Setup/SetupException.php';
require_once dirname(__DIR__,2).'/src/Setup/BrowserSetupService.php';

use Sokna\Local\Setup\BrowserSetupService;
use Sokna\Local\Setup\SetupException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
session_name('sokna_setup');session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>'/setup/']);session_start();
$_SESSION['csrf']=$_SESSION['csrf']??bin2hex(random_bytes(24));
$localRoot=dirname(__DIR__,2);$service=new BrowserSetupService($localRoot,$localRoot);
$action=(string)($_GET['action']??'status');

function setup_json(array $payload,int $status=200): never { http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit; }
function setup_body(): array { $raw=file_get_contents('php://input');$v=json_decode((string)$raw,true);return is_array($v)?$v:[]; }
function setup_csrf(array $body): void { if(!hash_equals((string)($_SESSION['csrf']??''),(string)($body['csrf']??'')))throw new SetupException('csrf_invalid','نشست Setup معتبر نیست.',403); }
function setup_local_base_url(): string {
    $port=(int)($_SERVER['SERVER_PORT']??0);
    if($port<1||$port>65535)throw new SetupException('local_endpoint_invalid','پورت Local Web از وب‌سرور قابل تشخیص نیست.',500);
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    return BrowserSetupService::normalizeLocalBaseUrl($scheme.'://127.0.0.1:'.$port.'/');
}

try {
    if($action==='status'){
        $status=$service->status();
        $localBaseUrl=trim((string)($status['local_base_url']??''))!==''?(string)$status['local_base_url']:setup_local_base_url();
        setup_json(['success'=>true,'csrf'=>$_SESSION['csrf'],'setup'=>$status,'preflight'=>$service->preflight(),'local_base_url'=>$localBaseUrl]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new SetupException('method_not_allowed','روش درخواست معتبر نیست.',405);
    $body=setup_body();setup_csrf($body);
    if($action==='preflight') setup_json(['success'=>true,'preflight'=>$service->preflight((string)($body['data_dir']??''))]);
    if($action==='test_database') setup_json(['success'=>true,'database'=>$service->testDatabase((array)($body['db']??[]),(bool)($body['create_database']??false))]);
    if($action==='install_new'){ $body['local_base_url']=setup_local_base_url(); setup_json($service->installNew($body)); }
    if($action==='resume') setup_json($service->resume());
    throw new SetupException('unknown_action','عملیات Setup شناخته‌شده نیست.',404);
} catch (SetupException $e) {
    setup_json(['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details],$e->httpStatus);
} catch (Throwable) {
    setup_json(['success'=>false,'code'=>'setup_internal_error','message'=>'Setup با خطای داخلی روبه‌رو شد.'],500);
}
