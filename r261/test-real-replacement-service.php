<?php
declare(strict_types=1);
/** The actual R260 one-button backend, not a hand-rolled pair/sync sequence.
 * Independent HTTPS Public, authenticated Public owner, Local admin approval,
 * old Public offline, normal full sync with real media, one 503 outage, resume,
 * atomic Local connection switch and signed guest intake activation.
 */
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('R261_QA_ONLY');
$localRoot=(string)getenv('R261_LOCAL_ROOT');
$configPath=dirname($localRoot).'/config.php';
$dataDir=(string)getenv('R261_DATA_DIR');
$offlineFlag=(string)getenv('R261_PUBLIC_OFFLINE_FLAG');
if($offlineFlag==='' || !is_file($configPath))
    throw new RuntimeException('R261_REAL_LOCAL_CONFIG_REQUIRED');
require_once $localRoot.'/bootstrap.php';
$oldConfig=require $configPath;
$oldUrl=(string)$oldConfig['public']['base_url'];
$installation=(string)$oldConfig['installation']['id'];
$target='https://move.cafe-e2e.example:18443/new-public';
if($oldUrl!=='https://dead-old-public.example')throw new RuntimeException('R261_WRONG_LOCAL_SOURCE');
$core=sokna_local_bootstrap($oldConfig);
$pdo=$core->database();
$ledger=new \Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger($pdo);
$keyStore=new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalKeyStore($dataDir,$localRoot.'/public');
$keys=$keyStore->keys();
$sessionToken=bin2hex(random_bytes(32));
$resolver=static fn(string $token):?array=>hash_equals($sessionToken,$token)
 ?['authenticated'=>true,'role'=>'admin','installation_id'=>$installation]:null;
$approval=new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalApprovalService(
    $ledger,$resolver,$keys['mac'],$keys['csrf']);
$flow=new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalFlow($ledger,$approval,$installation);
$challengeResult=$flow->begin($target,$oldUrl,'',time());
$challenge=$challengeResult['challenge'];$op=$challengeResult['operation_id'];
function publicOwnerAction(string $target,string $route,array $payload,?string $cookie=null):array{
 $headers="Origin: https://move.cafe-e2e.example:18443\r\nSec-Fetch-Site: same-origin\r\n".
    "Accept: application/json\r\nContent-Type: application/json\r\n";
 if($cookie!==null)$headers.="Cookie: ".$cookie."\r\n";
 $ctx=stream_context_create(['http'=>[
   'method'=>'POST','header'=>$headers,
   'content'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
   'timeout'=>10,'ignore_errors'=>true
 ]]);
 $raw=@file_get_contents($target.'/api/v1/owner/'.$route,false,$ctx);
 $headersRaw=$http_response_header??[];
 $status=0;$setCookie='';
 foreach($headersRaw as $line){
  if(preg_match('~^HTTP/\S+\s+(\d+)~i',$line,$m))$status=(int)$m[1];
  if(stripos($line,'Set-Cookie:')===0)$setCookie=trim(substr($line,11));
 }
 $body=is_string($raw)?json_decode($raw,true):null;
 if(!is_array($body))throw new RuntimeException('R261_REAL_PUBLIC_OWNER_HTTP_UNREADABLE '.$route);
 return ['status'=>$status,'body'=>$body,'cookie'=>$setCookie];
}
$login=publicOwnerAction($target,'login',['password'=>'isolated-owner-strong-2574']);
if($login['status']!==200)throw new RuntimeException('R261_REAL_PUBLIC_OWNER_LOGIN_FAILED '.json_encode($login['body']));
$cookie=explode(';',$login['cookie'])[0];
$prep=publicOwnerAction($target,'consent_prepare',['challenge'=>$challenge],$cookie);
if($prep['status']!==200 || !is_string($prep['body']['csrf']??null))
 throw new RuntimeException('R261_OWNER_CHALLENGE_NOT_ACCEPTED '.json_encode($prep['body']));
$signed=publicOwnerAction($target,'consent_sign',[
 'challenge'=>$challenge,'csrf'=>$prep['body']['csrf']
],$cookie);
if($signed['status']!==200)throw new RuntimeException('R261_SIGNED_OWNER_RECEIPT_FAILED '.json_encode($signed['body']));
$body=$signed['body'];
$code=(string)($body['pairing_code']??'');
$attestation=(array)($body['proof']['attestation']??[]);
$receipt=(array)($body['receipt_envelope']??[]);
if($code===''||$receipt===[])throw new RuntimeException('R261_OWNER_DID_NOT_ISSUE_FRESH_EMPTY_PAIRING');
$confirmed=$flow->confirm($op,$sessionToken,$attestation,$receipt,time());
if($confirmed['status']!=='owner_approved')throw new RuntimeException('R261_LOCAL_ADMIN_NOT_APPROVED');
$move=new \Sokna\Local\Domain\PublicEdge\PublicMoveSimpleReplacement(
    $core,$ledger,$installation,$dataDir);
$started=$move->start($op,$code,1);
if($started['status']!=='sync_pending')throw new RuntimeException('R261_REPLACEMENT_NOT_PAIRED');
if((require $configPath)['public']['base_url']!==$oldUrl)
 throw new RuntimeException('R261_LOCAL_URL_CHANGED_BEFORE_PUBLISH');
echo "R261_REAL_TWO_SIDED_OWNER_AND_PAIR_PASS\n";
// Now simulate real connection outage to new Public, NO old Public available.
// The current Local URL must stay as-is; the target must stay intake-locked.
file_put_contents($offlineFlag,"offline\n");
try{
 $retry=$move->finish($op);
 if($retry['status']!=='sync_retry')throw new RuntimeException('R261_OUTAGE_NOT_RETRYABLE');
 if((require $configPath)['public']['base_url']!==$oldUrl)
   throw new RuntimeException('R261_LOCAL_CONFIG_CHANGED_DURING_OUTAGE');
 echo "R261_ONE_NETWORK_OUTAGE_PRESERVES_LOCAL_CONNECTION_PASS\n";
}finally{@unlink($offlineFlag);}
$completed=false;
for($i=0;$i<8;$i++){
 $result=$move->finish($op);
 echo 'R261_REPLACEMENT_FINISH_ATTEMPT_'.($i+1).' '.$result['status']."\n";
 if(($result['completed']??false)===true){$completed=true;break;}
 if(!in_array($result['status'],['sync_retry','activation_retry'],true))
    throw new RuntimeException('R261_MOVE_UNEXPECTED_RESULT '.json_encode($result));
}
if(!$completed)throw new RuntimeException('R261_REAL_LOCAL_REPLACEMENT_DID_NOT_COMPLETE');
$onDisk=require $configPath;
if(($onDisk['public']['base_url']??null)!==$target)
 throw new RuntimeException('R261_REAL_LOCAL_CONFIG_NOT_SWITCHED');
if($move->status()['active']??true)throw new RuntimeException('R261_STAGED_SECRET_STILL_PENDING');
if($ledger->status($installation,$op)['status']!=='completed')
 throw new RuntimeException('R261_OWNER_APPROVAL_SLOT_NOT_COMPLETED');
if($pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()<1)
 throw new RuntimeException('R261_EXISTING_LOCAL_ORDERS_LOST');
echo "R261_REAL_CONTROLLER_MOVE_WITH_OUTAGE_RESUME_COMPLETE_PASS\n";
