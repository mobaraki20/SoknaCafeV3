<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
use Sokna\Local\Http\RuntimeTriggerHttpAdapter;
use Sokna\Local\Runtime\RuntimeTriggerException;

function m7_fail(string $m): never {fwrite(STDERR,$m.PHP_EOL);exit(1);} function m7_assert(bool $c,string $m):void{if(!$c)m7_fail($m);}
$core=sokna_local_bootstrap([
 'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m7-'.bin2hex(random_bytes(4))],
 'runtime'=>['local_token'=>'runtime-local-test-token-000000000000'],
 'db'=>['host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),'charset'=>'utf8mb4','user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna')]
]);
$core->migrations()->migrate();$pdo=$core->database();
$base=['request_id'=>'runtime-request-0001','runtime_instance_id'=>'runtime-machine-001','trigger_key'=>'maintenance.health','requested_at'=>date(DATE_ATOM),'correlation_id'=>'corr-runtime-0001'];
$r1=$core->runtimeTriggers()->accept($base);$r2=$core->runtimeTriggers()->accept($base);
m7_assert($r1['accepted']===true&&$r1['deduplicated']===false,'Runtime trigger did not accept Local allowlisted handler');
m7_assert(array_keys($r1)===['success','accepted','deduplicated','trigger_key','accepted_at','correlation_id'],'Runtime success response drifted from v1 contract');
m7_assert((bool)preg_match('/(?:Z|[+-]\\d{2}:\\d{2})$/',(string)$r1['accepted_at']),'Runtime accepted_at lacks explicit offset');
m7_assert(!array_key_exists('state',$r1)&&!array_key_exists('result',$r1),'Runtime v1 response leaked internal worker state/result');
$receipt=$pdo->query("SELECT state,result_json FROM runtime_trigger_receipts WHERE request_id='runtime-request-0001'")->fetch(PDO::FETCH_ASSOC);m7_assert(is_array($receipt)&&$receipt['state']==='completed','Runtime trigger did not execute Local allowlisted handler');
m7_assert($r2['deduplicated']===true&&array_keys($r2)===array_keys($r1),'Runtime lost-ACK retry was not deduplicated under the same v1 response contract');
$conflict=false;try{$changed=$base;$changed['trigger_key']='inventory.order_events';$core->runtimeTriggers()->accept($changed);}catch(RuntimeTriggerException $e){$conflict=$e->errorCode==='request_id_conflict';}m7_assert($conflict,'Runtime request_id accepted different trigger payload');
$commandBlocked=false;try{$bad=$base;$bad['request_id']='runtime-request-0002';$bad['command']='powershell.exe';$core->runtimeTriggers()->accept($bad);}catch(RuntimeTriggerException $e){$commandBlocked=$e->errorCode==='invalid_request';}m7_assert($commandBlocked,'Runtime arbitrary command field was accepted');
$unsupported=false;try{$bad=$base;$bad['request_id']='runtime-request-0003';$bad['trigger_key']='business.do_anything';$core->runtimeTriggers()->accept($bad);}catch(RuntimeTriggerException $e){$unsupported=$e->errorCode==='unsupported_trigger';}m7_assert($unsupported,'Runtime unsupported trigger escaped Local allowlist');
$offsetless=false;try{$bad=$base;$bad['request_id']='runtime-request-0005';$bad['requested_at']=date('Y-m-d\TH:i:s');$core->runtimeTriggers()->accept($bad);}catch(RuntimeTriggerException $e){$offsetless=$e->errorCode==='invalid_request';}m7_assert($offsetless,'Runtime accepted offsetless requested_at despite explicit-offset contract');

$http=new RuntimeTriggerHttpAdapter($core->runtimeTriggers(),'runtime-local-test-token-000000000000');
$unauth=$http->handle(['X-Sokna-Runtime-Contract'=>'1'],json_encode($base));m7_assert($unauth['status']===401,'Runtime HTTP adapter did not require bearer token');
$upgrade=$http->handle(['Authorization'=>'Bearer runtime-local-test-token-000000000000','X-Sokna-Runtime-Contract'=>'2'],json_encode($base));m7_assert($upgrade['status']===426,'Runtime HTTP adapter did not reject contract mismatch');
$ok=$base;$ok['request_id']='runtime-request-0004';$ok['correlation_id']='corr-runtime-0004';
$httpOk=$http->handle(['Authorization'=>'Bearer runtime-local-test-token-000000000000','X-Sokna-Runtime-Contract'=>'1'],json_encode($ok));m7_assert($httpOk['status']===200&&$httpOk['body']['accepted']===true,'Runtime HTTP adapter did not pass canonical trigger request');
m7_assert(array_keys($httpOk['body'])===['success','accepted','deduplicated','trigger_key','accepted_at','correlation_id'],'Runtime HTTP adapter returned fields outside v1 contract');
$endpoint=file_get_contents(dirname(__DIR__).'/apps/local-web/public/internal/runtime/v1/trigger.php');m7_assert(is_string($endpoint)&&str_contains($endpoint,"->handle(['Authorization'=>"),'Runtime endpoint still calls adapter with legacy positional arguments');
$count=(int)$pdo->query("SELECT COUNT(*) FROM runtime_trigger_receipts")->fetchColumn();m7_assert($count===2,'Runtime receipt store duplicated rejected/replayed requests');
fwrite(STDOUT,"Local M7 Runtime trigger self-test: OK\n");
