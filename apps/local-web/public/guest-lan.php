<?php
declare(strict_types=1);

use Sokna\Local\Domain\GuestLan\GuestLanGatewayService;
use Sokna\Local\Domain\GuestLan\GuestLanPageRenderer;
use Sokna\Local\Domain\GuestLan\GuestLanRuntimeService;
use Sokna\Local\Domain\GuestLan\GuestLanThemeCssService;
use Sokna\Local\UI\LocalUrl;

$core=require __DIR__.'/_guest_app.php';
if(!(bool)$core->config()->get('lan_guest.enabled',false)){http_response_code(404);header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');echo '{"success":false,"error":"guest_lan_disabled"}';exit;}
$installationId=trim($core->config()->string('installation.id',''));
if($installationId===''){http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo '{"success":false,"code":"installation_missing"}';exit;}
$runtime=new GuestLanRuntimeService($core->publicProjectionBuilder(),$installationId);
$renderer=new GuestLanPageRenderer($runtime,LocalUrl::basePath());
$theme=new GuestLanThemeCssService($runtime);
$gateway=new GuestLanGatewayService($installationId,$core->database(),$core->guestOrders(),$core->waiterCalls(),$core->lanStaffRealtimeReceipts(),$core->realtimeDispatch());

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
$base=rtrim(LocalUrl::basePath(),'/');if($base!==''&&$base!=='/'&&str_starts_with($path,$base))$path=substr($path,strlen($base))?:'/';
$query=$_GET;
$requestedInstallation=trim((string)($query['installation']??''));
if($requestedInstallation!==''&&!hash_equals($installationId,$requestedInstallation)){guest_json(['status'=>404,'body'=>['success'=>false,'code'=>'installation_not_found']]);}

$version=trim((string)@file_get_contents(dirname(__DIR__).'/VERSION.txt'));
$asset=static fn(string $path):string=>LocalUrl::path($path).($version!==''?'?v='.rawurlencode($version):'');
$renderEndpoints=[
    'css'=>$asset('/assets/scds/guest.css'),'js'=>$asset('/assets/scds/guest.js'),
    'theme_css'=>LocalUrl::path('/theme/'.rawurlencode($installationId).'.css'),
    'create_order'=>LocalUrl::path('/api/guest/order'),'orders'=>LocalUrl::path('/api/guest/orders'),
    'order_quote'=>LocalUrl::path('/api/guest/order/quote'),'order_status'=>LocalUrl::path('/api/guest/order/status'),
    'table_context'=>LocalUrl::path('/api/guest/table/context'),'waiter_call'=>LocalUrl::path('/api/guest/waiter'),
    'changes'=>LocalUrl::path('/api/guest/changes'),'metric'=>LocalUrl::path('/api/guest/metric'),
    'media_base'=>LocalUrl::path('/media'),'transport_mode'=>'local_lan',
];

if($method==='GET'&&$path==='/menu'){guest_response($renderer->render($installationId,$query,$renderEndpoints));}
if($method==='GET'&&$path==='/menu/about'){guest_response($renderer->about($installationId,$query,['css'=>$renderEndpoints['css'],'theme_css'=>$renderEndpoints['theme_css'],'media_base'=>$renderEndpoints['media_base']]));}
if($method==='GET'&&$path==='/menu/manifest.webmanifest'){guest_response($renderer->manifest($installationId,['media_base'=>$renderEndpoints['media_base']]));}
if($method==='GET'&&preg_match('#^/theme/([A-Za-z0-9._:-]{1,96})\.css$#D',$path,$m)===1){if(!hash_equals($installationId,$m[1])){http_response_code(404);exit;}http_response_code(200);header('Content-Type: text/css; charset=utf-8');header('Cache-Control: no-store, max-age=0');echo $theme->css($installationId);exit;}
if($method==='GET'&&preg_match('#^/media/([A-Za-z0-9._-]{1,96})/([a-f0-9]{64})\.(jpg|png|webp|gif)$#D',$path,$m)===1){if(!hash_equals($installationId,$m[1])){http_response_code(404);exit;}$file=$core->guestContent()->publishedMediaFile($m[2],$m[3]);if(!is_array($file)){http_response_code(404);exit;}header('Content-Type: '.(string)$file['mime']);header('Cache-Control: public, max-age=31536000, immutable');header('Content-Length: '.(string)filesize((string)$file['path']));readfile((string)$file['path']);exit;}

if(str_starts_with($path,'/api/guest/')){
    if($method!=='POST')guest_json(['status'=>405,'body'=>['success'=>false,'code'=>'method_not_allowed']]);
    $raw=(string)file_get_contents('php://input');if(strlen($raw)>262144)guest_json(['status'=>413,'body'=>['success'=>false,'code'=>'payload_too_large']]);
    try{$body=$raw===''?[]:json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable){guest_json(['status'=>400,'body'=>['success'=>false,'code'=>'invalid_json','message'=>'بدنه درخواست معتبر نیست.']]);}
    if(!is_array($body))$body=[];
    $result=match($path){
        '/api/guest/order'=>$gateway->createOrder($body),'/api/guest/orders'=>$gateway->guestOrders($body),
        '/api/guest/order/quote'=>$gateway->orderQuote($body),'/api/guest/order/status'=>$gateway->orderStatus($body),
        '/api/guest/table/context'=>$gateway->tableContext($body),'/api/guest/waiter'=>$gateway->waiterCall($body),
        '/api/guest/changes'=>$gateway->changes($body),'/api/guest/metric'=>$gateway->metric($body),
        default=>['status'=>404,'body'=>['success'=>false,'code'=>'not_found']],
    };
    guest_json($result);
}

guest_json(['status'=>404,'body'=>['success'=>false,'code'=>'not_found']]);

function guest_response(array $response): never
{
    http_response_code((int)($response['status']??200));foreach((array)($response['headers']??[]) as $name=>$value)header((string)$name.': '.(string)$value);echo (string)($response['body']??'');exit;
}
function guest_json(array $response): never
{
    http_response_code((int)($response['status']??200));header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, max-age=0');$json=json_encode((array)($response['body']??[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);echo is_string($json)?$json:'{}';exit;
}
