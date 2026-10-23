<?php
declare(strict_types=1);
use Sokna\Local\Http\RequestHeaders;
$soknaRequestStarted=(float)($_SERVER['REQUEST_TIME_FLOAT']??microtime(true));
$soknaScriptEntered=microtime(true);
$core=require dirname(__DIR__,3).'/_app.php';
$soknaBootstrapEnded=microtime(true);
$raw=(string)file_get_contents('php://input');
$headers=RequestHeaders::all($_SERVER);
$soknaHandlerStarted=microtime(true);
$response=$core->runtimeTriggerHttp()->handle([
    'Authorization'=>(string)($headers['Authorization']??''),
    'X-Sokna-Runtime-Contract'=>RequestHeaders::value($headers,'X-Sokna-Runtime-Contract'),
 ],$raw);
$soknaHandlerEnded=microtime(true);
$soknaElapsedMs=(int)round(($soknaHandlerEnded-$soknaRequestStarted)*1000);
$soknaStatus=(int)($response['status']??503);
$soknaBody=is_array($response['body']??null)?$response['body']:[];
if($soknaElapsedMs>=1000||$soknaStatus!==200||($soknaBody['worker_success']??true)===false){
    // Do not echo sensitive request fields or credentials; trace only allowlisted trigger metadata.
    $soknaInput=json_decode($raw,true);
    $soknaKey=is_array($soknaInput)?(string)($soknaInput['trigger_key']??''):'';
    if(!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$soknaKey))$soknaKey='invalid';
    $core->observability()->logEvent($soknaStatus===200?'info':'warning','runtime.trigger_http_timing',[
        'trigger_key'=>$soknaKey,'status'=>$soknaStatus,'elapsed_ms'=>$soknaElapsedMs,
        'php_start_to_entry_ms'=>max(0,(int)round(($soknaScriptEntered-$soknaRequestStarted)*1000)),
        'bootstrap_ms'=>max(0,(int)round(($soknaBootstrapEnded-$soknaScriptEntered)*1000)),
        'handler_ms'=>max(0,(int)round(($soknaHandlerEnded-$soknaHandlerStarted)*1000)),
        'worker_completed'=>($soknaBody['worker_completed']??null),
        'worker_success'=>($soknaBody['worker_success']??null),
        'error_code'=>(string)($soknaBody['worker_error_code']??$soknaBody['code']??''),
    ]);
}
http_response_code((int)$response['status']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response['body'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
