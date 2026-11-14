<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R228_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('QA_CI_ONLY');
$localWeb=realpath((string)getenv('SOKNA_R213_WEB'));$publicRoot=realpath((string)getenv('SOKNA_R228_PUBLIC'));$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$localWeb||!$publicRoot||!$tmp||!str_starts_with($localWeb,$tmp.DIRECTORY_SEPARATOR)||!str_starts_with($publicRoot,$tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('NON_TEMP_REFUSED');
require_once $localWeb.'/bootstrap.php';
require_once $localWeb.'/src/Domain/GuestLan/GuestLanGatewayService.php';
require_once $publicRoot.'/bootstrap.php';
$pair=json_decode((string)file_get_contents($tmp.'/r228-pairing.json'),true,512,JSON_THROW_ON_ERROR);
$fixture=json_decode((string)file_get_contents($tmp.'/r225-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$localCfg=require dirname($localWeb).'/config.php';
$localCfg['installation']['id']=$pair['installation'];
$localCfg['public']['base_url']='http://127.0.0.1:18082';
$localCfg['public']['shared_secret']=$pair['secret'];
$local=\sokna_local_bootstrap($localCfg);$localDb=$local->database();$localDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pubConfig=require $publicRoot.'/config.php';$public=\sokna_public_bootstrap($pubConfig);$pubDb=$public->database();$pubDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$phase=$argv[1]??'';$count=0;
$ok=static function(bool $cond,string $label)use(&$count):void{if(!$cond)throw new RuntimeException('FAIL_R228_'.$label);++$count;echo 'PASS_R228_'.$label."\n";};
$client=$local->publicEdgeSyncClient();
$offline=static function(string $path,array $payload)use($client):bool{
 try{$client->postRaw($path,$payload,2);}catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){return in_array($e->errorCode,['public_unreachable','public_transport_timeout','public_deadline_exceeded'],true);}
 return false;
};
$http=static function(string $path,array $payload,string $id,string $secret,int $timestampDelta=0,?string $nonce=null,bool $tamper=false):array{
 $nonce=$nonce??bin2hex(random_bytes(16));$ts=(string)(time()+$timestampDelta);$body=json_encode($payload,JSON_THROW_ON_ERROR);
 $base=implode("\n",['sokna-relay-v1','POST',$path,$ts,$nonce,hash('sha256',$body)]);
 $signature=hash_hmac('sha256',$base,$secret);if($tamper)$signature=str_repeat('0',64);
 $c=curl_init('http://127.0.0.1:18082'.$path);
 curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Sokna-Installation: '.$id,'X-Sokna-Timestamp: '.$ts,'X-Sokna-Nonce: '.$nonce,'X-Sokna-Signature: '.$signature]]);
 $raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$error=curl_error($c);curl_close($c);
 if(!is_string($raw))throw new RuntimeException('HTTP_REQUEST_FAILED_'.$error);
 return [$status,json_decode($raw,true)];
};
if($phase==='online'){
 $wait=false;for($i=0;$i<30;$i++){try{$h=$client->postRaw('/api/v1/local/realtime/claim',['lease_seconds'=>20,'wait_ms'=>0],2);$wait=true;break;}catch(Throwable){usleep(200000);}}$ok($wait,'REAL_SIGNED_NETWORK_PUBLIC_READY');
 $path='/api/v1/local/realtime/claim';$nonce=bin2hex(random_bytes(16));
 [$s,$body]=$http($path,['lease_seconds'=>20,'wait_ms'=>0],$pair['installation'],$pair['secret'],0,$nonce);$ok($s!==401&&$s!==403&&$s!==500,'VALID_HMAC_ACCEPTED_REAL_HTTP');
 [$s,$body]=$http($path,['lease_seconds'=>20,'wait_ms'=>0],$pair['installation'],$pair['secret'],0,$nonce);$ok($s===409&&($body['error']??'')==='replay_detected','NONCE_REPLAY_DENIED');
 [$s,$body]=$http($path,['lease_seconds'=>20,'wait_ms'=>0],$pair['installation'],$pair['secret'],0,null,true);$ok($s===401&&($body['error']??'')==='bad_signature','TAMPERED_HMAC_DENIED');
 [$s,$body]=$http($path,['lease_seconds'=>20,'wait_ms'=>0],$pair['installation'],$pair['secret'],-900);$ok($s===401&&($body['error']??'')==='bad_signature','STALE_TIMESTAMP_DENIED');
 [$s,$body]=$http($path,['lease_seconds'=>20,'wait_ms'=>0],'r228-unpaired-installation',$pair['secret']);$ok($s===401,'UNKNOWN_INSTALLATION_DENIED');
 $clientToken='r228-http-client-00000001';$device='r228-http-web-device';$payload=['table_token'=>$fixture['table_token'],'session_token'=>'','device_token'=>$device,'client_token'=>$clientToken,'customer_note'=>'R228 signed HTTP QA','items'=>[['id'=>$fixture['parent'],'quantity'=>2,'expected_price'=>$fixture['unit_price'],'bundle_choice_item_id'=>$fixture['choice']]]];
 $id=\Sokna\Local\Domain\GuestLan\GuestLanGatewayService::requestId('guest_order.submit',['client_token'=>$clientToken,'table_token'=>$payload['table_token']]);
 $actor='guest:'.substr(hash('sha256',$pair['installation'].'|'.$device),0,32);
 $env=['request_id'=>$id,'kind'=>'guest_order.submit','actor_projection_id'=>$actor,'created_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+120),'payload'=>$payload];
 $queued=$public->realtime()->enqueueGuest($pair['installation'],$env);$ok(($queued['status']??0)===202,'REAL_PUBLIC_DB_COMMAND_QUEUED');
 $duplicate=$public->realtime()->enqueueGuest($pair['installation'],$env);$ok(($duplicate['body']['deduplicated']??false)===true,'QUEUE_REPEAT_DEDUP');
 file_put_contents($tmp.'/r228-drop-acks','QA-only',LOCK_EX);
 $result=$local->publicEdgeRelay()->syncRealtimeExchange(5,3,(int)floor(microtime(true)*1000)+9000);
 $ok(($result['processed']??0)===1,'REAL_LOCAL_RELAY_EXECUTED_OVER_HTTP');
 $q=$localDb->prepare('SELECT id,status FROM orders WHERE client_token=?');$q->execute([$clientToken]);$rows=$q->fetchAll();
 $ok(count($rows)===1&&$rows[0]['status']==='pending_approval','REAL_LOCAL_ORDER_EXACT_ONCE');
 $outbox=$localDb->prepare('SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE request_id=?');$outbox->execute([$id]);
 $ok((int)$outbox->fetchColumn()===1,'LOST_ACK_PERSISTED_DURABLY');
 $pr=$pubDb->prepare('SELECT state FROM realtime_requests WHERE request_id=?');$pr->execute([$id]);$state=(string)$pr->fetchColumn();$ok($state==='claimed','PUBLIC_WAITING_FOR_RETRY_ACK');
 file_put_contents($tmp.'/r228-command.json',json_encode(['id'=>$id,'client'=>$clientToken,'order_id'=>(int)$rows[0]['id'],'actor'=>$actor],JSON_THROW_ON_ERROR),LOCK_EX);
 echo 'PASS_R228_SIGNED_ONLINE_'.$count."_CHECKS\n";
} elseif($phase==='offline'){
 $command=json_decode((string)file_get_contents($tmp.'/r228-command.json'),true,512,JSON_THROW_ON_ERROR);
 $ok($offline('/api/v1/local/realtime/exchange',['protocol'=>2,'max_requests'=>0,'lease_seconds'=>30,'acks'=>[],'heartbeat'=>[]]),'PUBLIC_SERVER_OUTAGE_CLASSIFIED');
 $q=$localDb->prepare('SELECT COUNT(*) FROM orders WHERE client_token=?');$q->execute([$command['client']]);$ok((int)$q->fetchColumn()===1,'OUTAGE_NO_DUPLICATE_ORDER');
 $q=$localDb->prepare('SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE request_id=?');$q->execute([$command['id']]);$ok((int)$q->fetchColumn()===1,'OUTAGE_ACK_STAYS_DURABLE');
 echo 'PASS_R228_OUTAGE_'.$count."_CHECKS\n";
} elseif($phase==='recover'){
 $command=json_decode((string)file_get_contents($tmp.'/r228-command.json'),true,512,JSON_THROW_ON_ERROR);
 $r=$local->publicEdgeRelay()->syncRealtimeExchange(5,4,(int)floor(microtime(true)*1000)+12000);
 $ok(($r['acked']??0)>=1 || (($r['commit_flush']['acked']??0)>=1),'RECOVERY_ACK_SENT_ON_SIGNED_HTTP');
 $pubRes=$public->realtime()->result(['installation_id'=>$pair['installation'],'projection_id'=>$command['actor'],'capabilities'=>[]],$command['id']);
 $ok(($pubRes['body']['state']??'')==='committed','PUBLIC_RECEIPT_AFTER_RECONNECT');
 $q=$localDb->prepare('SELECT COUNT(*) FROM orders WHERE client_token=?');$q->execute([$command['client']]);$ok((int)$q->fetchColumn()===1,'RECOVERY_NO_DUPLICATE_LOCAL_ORDER');
 $q=$localDb->prepare('SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE request_id=?');$q->execute([$command['id']]);$ok((int)$q->fetchColumn()===0,'RECOVERY_LOCAL_ACK_OUTBOX_CLEARED');
 $idem=$public->realtime()->enqueueGuest($pair['installation'],['request_id'=>$command['id'],'kind'=>'guest_order.submit','actor_projection_id'=>$command['actor'],'created_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+120),'payload'=>['table_token'=>$fixture['table_token'],'session_token'=>'','device_token'=>'r228-http-web-device','client_token'=>$command['client'],'customer_note'=>'R228 signed HTTP QA','items'=>[['id'=>$fixture['parent'],'quantity'=>2,'expected_price'=>$fixture['unit_price'],'bundle_choice_item_id'=>$fixture['choice']]]]]);
 $ok(($idem['body']['deduplicated']??false)===true,'AFTER_RECONNECT_CLIENT_RETRY_DEDUP');
 $q=$localDb->prepare('SELECT status FROM orders WHERE id=?');$q->execute([$command['order_id']]);$ok($q->fetchColumn()==='pending_approval','RECOVERY_SAME_ORDER_STILL_PENDING');
 echo 'PASS_R228_RECOVERY_'.$count."_CHECKS\n";
}else{throw new RuntimeException('UNKNOWN_PHASE');}