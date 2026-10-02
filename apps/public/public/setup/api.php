<?php
declare(strict_types=1);

use Sokna\PublicEdge\Setup\PublicSetupService;
use Sokna\PublicEdge\Setup\SetupException;

if(function_exists('header_remove'))header_remove('X-Powered-By');
$root=dirname(__DIR__,2);
require_once $root.'/bootstrap.php';
$configPath=trim((string)(getenv('SOKNA_PUBLIC_CONFIG')?:($root.'/config.php')));

$trustProxy=(string)(getenv('SOKNA_PUBLIC_TRUST_PROXY_HEADERS')?:'')==='1';
$forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));
$serverSecure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')
    || strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https'
    || (int)($_SERVER['SERVER_PORT']??0)===443;
$secure=$serverSecure||($trustProxy&&$forwarded==='https');
$host=(string)($_SERVER['HTTP_HOST']??'');
session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>'/setup/']);
session_start();
if(!isset($_SESSION['sokna_public_setup_csrf']))$_SESSION['sokna_public_setup_csrf']=bin2hex(random_bytes(24));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

function setup_json(array $data,int $status=200): never{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function setup_body(): array{
    $raw=(string)file_get_contents('php://input');
    if($raw==='')return [];
    try{$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}
    catch(Throwable){throw new SetupException('invalid_json','درخواست Setup معتبر نیست.',400);}
}

$service=new PublicSetupService($root,$configPath);
$action=(string)($_GET['action']??'status');

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&$action==='status'){
        $status=$service->status();
        if(($status['paired']??false)===true)unset($_SESSION['sokna_public_setup_pairing_code']);
        setup_json([
            'success'=>true,
            'csrf'=>(string)$_SESSION['sokna_public_setup_csrf'],
            'setup'=>$status,
            'preflight'=>$service->preflight((string)($status['storage_dir']??''),$secure,$host),
            'pairing_code'=>(string)($_SESSION['sokna_public_setup_pairing_code']??''),
        ]);
    }

    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')setup_json(['success'=>false,'code'=>'method_not_allowed','message'=>'Method not allowed.'],405);
    $body=setup_body();
    $csrf=(string)($body['csrf']??'');
    if($csrf===''||!hash_equals((string)$_SESSION['sokna_public_setup_csrf'],$csrf))throw new SetupException('csrf_expired','نشست Setup منقضی شده است؛ صفحه را تازه کن.',419);

    if($action==='preflight')setup_json(['success'=>true,'preflight'=>$service->preflight((string)($body['storage_dir']??''),$secure,$host)]);
    if($action==='test_database')setup_json(['success'=>true,'database'=>$service->testDatabase((array)($body['db']??[]),!empty($body['create_database']))]);
    if($action==='install_new'){
        $result=$service->installNew($body,$secure,$host);
        $_SESSION['sokna_public_setup_pairing_code']=(string)($result['pairing_code']??'');
        setup_json(['success'=>true]+$result);
    }
    if($action==='resume'){
        $result=$service->resume($secure,$host);
        if(($result['pairing_code']??'')!=='')$_SESSION['sokna_public_setup_pairing_code']=(string)$result['pairing_code'];
        setup_json(['success'=>true]+$result);
    }

    setup_json(['success'=>false,'code'=>'unknown_action','message'=>'عملیات Setup شناخته نشد.'],404);
}catch(SetupException $e){
    setup_json(['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details],$e->httpStatus);
}catch(Throwable $e){
    error_log('public setup: '.$e->getMessage());
    setup_json(['success'=>false,'code'=>'setup_failed','message'=>'راه‌اندازی Public Edge کامل نشد.'],500);
}
