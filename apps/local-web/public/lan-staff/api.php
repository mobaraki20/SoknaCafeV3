<?php
declare(strict_types=1);
$core=require dirname(__DIR__).'/_app.php';
$svc=$core->lanStaffGateway();$origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));$allowed=$svc->allowedOrigin();
if($allowed===''||$origin===''||!hash_equals($allowed,rtrim($origin,'/'))){http_response_code(403);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"origin_forbidden"}';exit;}
header('Access-Control-Allow-Origin: '.$allowed);header('Vary: Origin');header('Access-Control-Allow-Headers: Content-Type, X-Sokna-Lan-Token');header('Access-Control-Allow-Methods: GET, POST, OPTIONS');header('Access-Control-Max-Age: 300');if(strtolower((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK']??''))==='true')header('Access-Control-Allow-Private-Network: true');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Sokna-Access-Mode: lan-direct');
if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){http_response_code(204);exit;}
header('Content-Type: application/json; charset=utf-8');
try{
 $token=trim((string)($_SERVER['HTTP_X_SOKNA_LAN_TOKEN']??''));$action=trim((string)($_GET['action']??'health'));$session=$action==='preflight_health'?$svc->authenticatePreflight($token,(string)($_SERVER['REMOTE_ADDR']??'')):$svc->authenticate($token,(string)($_SERVER['REMOTE_ADDR']??''));$method=(string)($_SERVER['REQUEST_METHOD']??'GET');$data=[];
 if($method==='POST'){$raw=file_get_contents('php://input');$data=json_decode(is_string($raw)?$raw:'',true);if(!is_array($data))throw new RuntimeException('invalid_json');}
 $out=match($action){'health'=>$svc->health($session),'preflight_health'=>$svc->health($session),'read'=>$svc->read($session,(string)($_GET['model']??'')),'realtime'=>$svc->realtime($session,$data),'realtime_result'=>$svc->realtimeResult($session,(string)($_GET['request_id']??'')),'deferred'=>$svc->deferred($session,$data),'deferred_result'=>$svc->deferredResult($session,(string)($_GET['request_id']??'')),'deferred_list'=>$svc->deferredList($session,(int)($_GET['limit']??12)),default=>['ok'=>false,'error'=>'invalid_action','status'=>404]};
 $status=(int)($out['status']??200);unset($out['status']);http_response_code($status);echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $e){$code=$e->getMessage();$map=['lan_disabled'=>403,'lan_token_invalid'=>401,'lan_token_expired'=>401,'lan_token_wrong_installation'=>401,'lan_session_revoked'=>401,'lan_source_forbidden'=>403,'forbidden'=>403,'unknown_model'=>404,'model_unavailable'=>404,'invalid_json'=>400];http_response_code($map[$code]??500);echo json_encode(['ok'=>false,'error'=>array_key_exists($code,$map)?$code:'internal_error'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
