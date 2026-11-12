<?php
declare(strict_types=1);
// SOKNA R165 — disposable MariaDB integration test on exact R162/R164 backend sources.
// No external endpoint, user DB, or production host is ever contacted.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R165_DISPOSABLE') !== 'YES') {
    fwrite(STDERR, "REFUSE_OUTSIDE_ISOLATED_GITHUB_CI\n");exit(87);
}
$local=rtrim((string)($argv[1]??''),'/');$public=rtrim((string)($argv[2]??''),'/');
foreach([
    "$local/src/Domain/LanStaff/LanStaffRealtimeReceiptService.php",
    "$local/database/migrations/0037_lan_staff_realtime_receipts.sql",
    "$public/src/Realtime/RealtimeService.php", "$public/src/Core/Migrations.php",
    "$public/src/Remote/RemoteReadModelService.php",
    "$public/src/Connectivity/ConnectivityService.php", "$public/src/Shared/IranTime.php",
] as $p) {if(!is_file($p))throw new RuntimeException('SOURCE_FILE_MISSING '.basename($p));}
require_once "$local/src/Domain/LanStaff/LanStaffRealtimeReceiptService.php";
require_once "$public/src/Shared/IranTime.php";
require_once "$public/src/Core/Migrations.php";
require_once "$public/src/Connectivity/ConnectivityService.php";
require_once "$public/src/Remote/RemoteReadModelService.php";
require_once "$public/src/Realtime/RealtimeService.php";
use Sokna\Local\Domain\LanStaff\LanStaffRealtimeReceiptService;
use Sokna\PublicEdge\Core\Migrations;
use Sokna\PublicEdge\Connectivity\ConnectivityService;
use Sokna\PublicEdge\Remote\RemoteReadModelService;
use Sokna\PublicEdge\Realtime\RealtimeService;
function need(bool $b,string $s):void{if(!$b)throw new RuntimeException("FAILED_".$s);echo "PASS $s\n";}
function countRows(PDO $db,string $table,string $where='1=1'):int{return (int)$db->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn();}
$dsn='mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$root=new PDO($dsn,'root','ci-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['sokna_r165_pub','sokna_r165_local'] as $db){$root->exec("DROP DATABASE IF EXISTS `$db`");$root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
$root=null;
$makeDb=static function(string $name)use($dsn):PDO {
  $db=new PDO($dsn.';dbname='.$name,'root','ci-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  $db->exec("SET time_zone='+00:00'");return $db;
};
$pub=$makeDb('sokna_r165_pub');$localDb=$makeDb('sokna_r165_local');
$m=new Migrations($pub,$public.'/database/migrations');$applied=$m->migrate();
need(count($applied)===8 && count($m->appliedVersions())===8,'PUBLIC_REAL_EIGHT_MIGRATIONS');
$pub->exec("INSERT INTO installations (installation_id,display_name,active,remote_enabled,order_intake_enabled,authority_epoch) VALUES ('cafe-A','test-A',1,1,1,1),('cafe-B','test-B',1,1,1,1)");
$sql=file_get_contents("$local/database/migrations/0037_lan_staff_realtime_receipts.sql");
$localDb->exec($sql);
$localDb->exec('CREATE TABLE test_order_effects(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_id VARCHAR(96) NOT NULL,kind VARCHAR(40) NOT NULL) ENGINE=InnoDB');
$localDb->exec('CREATE TABLE test_ack_outbox(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_id VARCHAR(96) NOT NULL, UNIQUE KEY uq_ack_request(request_id)) ENGINE=InnoDB');
$receipt=new LanStaffRealtimeReceiptService($localDb);
$connectivity=new ConnectivityService($pub);$readModel=new RemoteReadModelService($pub,$connectivity);$queue=new RealtimeService($pub,$connectivity,$readModel);
$reqId='guest-order-submit:'.str_repeat('a',56);$actor='guest:'.substr(hash('sha256','cafe-A|browser-1'),0,32);
$payload=['table_token'=>'table-test-1','session_token'=>'sess-test','device_token'=>'browser-1','client_token'=>'token-test-12345678901234567890','items'=>[['id'=>10,'quantity'=>2]],'customer_note'=>''];
$e=['request_id'=>$reqId,'kind'=>'guest_order.submit','created_at'=>gmdate('c',time()-5),'expires_at'=>gmdate('c',time()+90),'actor_projection_id'=>$actor,'payload'=>$payload];
$r1=$receipt->dispatchAtomic('cafe-A',$e,function() use($localDb,$reqId):array{$localDb->prepare('INSERT INTO test_order_effects(request_id,kind) VALUES(?,?)')->execute([$reqId,'guest_order.submit']);return ['success'=>true,'order_code'=>'test-100'];},function(array $receipt)use($localDb,$reqId):void{$localDb->prepare('INSERT INTO test_ack_outbox(request_id) VALUES(?)')->execute([$reqId]);});
need($r1['state']==='committed' && countRows($localDb,'test_order_effects')===1 && countRows($localDb,'test_ack_outbox')===1,'LAN_FIRST_COMMIT_AND_DURABLE_ACK_ATOMIC');
$eRetry=$e;$eRetry['created_at']=gmdate('c',time());$eRetry['expires_at']=gmdate('c',time()+120);
$r2=$receipt->dispatchAtomic('cafe-A',$eRetry,function()use($localDb,$reqId):array{$localDb->prepare('INSERT INTO test_order_effects(request_id,kind) VALUES(?,?)')->execute([$reqId,'duplicate']);return [];});
need($r2['state']==='committed' && $r2['idempotent']===true && countRows($localDb,'test_order_effects')===1,'LAN_TIMESTAMP_DIFFERENT_RETRY_NO_SECOND_ORDER');
$eConflict=$eRetry;$eConflict['payload']['items'][0]['quantity']=3;
$r3=$receipt->dispatchAtomic('cafe-A',$eConflict,function()use($localDb,$reqId):array{$localDb->prepare('INSERT INTO test_order_effects(request_id,kind) VALUES(?,?)')->execute([$reqId,'conflict']);return [];});
need($r3['state']==='rejected'&&$r3['error_code']==='request_id_conflict'&&countRows($localDb,'test_order_effects')===1,'LAN_SAME_ID_DIFFERENT_ITEMS_CONFLICT');
need(hash_equals(LanStaffRealtimeReceiptService::traceId('cafe-A',$reqId),RealtimeService::traceId('cafe-A',$reqId)),'LAN_PUBLIC_SAME_TRACE_ID');
$queued=$queue->enqueueGuest('cafe-A',$eRetry);
need($queued['status']===202&&($queued['body']['state']??'')==='queued'&&countRows($pub,'realtime_requests')===1,'PUBLIC_WAN_AFTER_LAN_COMMIT_ONE_QUEUE_ROW');
$queued2=$queue->enqueueGuest('cafe-A',array_replace($eRetry,['created_at'=>gmdate('c',time()+1),'expires_at'=>gmdate('c',time()+140)]));
need($queued2['status']===200 && ($queued2['body']['deduplicated']??false) && countRows($pub,'realtime_requests')===1,'PUBLIC_RETRY_NEW_TIMESTAMPS_DEDUPLICATED');
$queuedBad=$queue->enqueueGuest('cafe-A',$eConflict);
need($queuedBad['status']===409 && ($queuedBad['body']['error']??'')==='request_id_conflict' && countRows($pub,'realtime_requests')===1,'PUBLIC_SAME_ID_DIFFERENT_PAYLOAD_REJECTED');
$claim=$queue->claim('cafe-A',30);
need($claim['status']===200 && ($claim['body']['request']['request_id']??'')===$reqId,'PUBLIC_CLAIM_SINGLE_COMMAND');
$claimedEnvelope=$claim['body']['request'];
$replayedFromWan=$receipt->dispatchAtomic('cafe-A',$claimedEnvelope,function()use($localDb,$reqId):array{$localDb->prepare('INSERT INTO test_order_effects(request_id,kind) VALUES(?,?)')->execute([$reqId,'wan-second']);return [];});
need($replayedFromWan['state']==='committed'&&$replayedFromWan['idempotent']===true&&countRows($localDb,'test_order_effects')===1,'CROSS_WAN_AFTER_LAN_SINGLE_BUSINESS_EFFECT');
$ack=['request_id'=>$reqId,'lease_token'=>(string)$claim['body']['lease_token'],'state'=>'committed','result'=>['success'=>true,'order_code'=>'test-100'],'error_code'=>''];
$a=$queue->ack('cafe-A',$ack);need($a['status']===200&&($a['body']['deduplicated']??true)===false,'PUBLIC_FIRST_ACK_COMMITTED');
$b=$queue->ack('cafe-A',$ack);need($b['status']===200&&($b['body']['deduplicated']??false)===true,'PUBLIC_SAME_ACK_REPLAY_IDEMPOTENT');
$ackBad=$ack;$ackBad['result']['order_code']='test-different';$badAck=$queue->ack('cafe-A',$ackBad);
need($badAck['status']===409&&($badAck['body']['error']??'')==='terminal_conflict','PUBLIC_TERMINAL_ACK_CONFLICT_NOT_OVERWRITTEN');
$again=$queue->enqueueGuest('cafe-A',$e);
need($again['status']===200 && ($again['body']['state']??'')==='committed' && countRows($pub,'realtime_requests')===1,'PUBLIC_RETRY_AFTER_COMMIT_RETURNS_FIRST_RECEIPT');
$other=$queue->enqueueGuest('cafe-B',$e);
need($other['status']===202 && countRows($pub,'realtime_requests')===2,'PUBLIC_INSTALLATION_BOUNDARY_SEPARATE_REQUEST');
// A handler failure must roll back the business effects but durably record rejected receipt.
$failed=$e;$failed['request_id']='guest-order-submit:'.str_repeat('b',56);
$x=$receipt->dispatchAtomic('cafe-A',$failed,function()use($localDb):array{$localDb->exec("INSERT INTO test_order_effects(request_id,kind) VALUES ('x','will-rollback')");throw new RuntimeException('fixture simulated SQL failure');});
need($x['state']==='rejected'&&countRows($localDb,'test_order_effects')===1,'LAN_HANDLER_ERROR_ROLLBACK_WITH_REJECTED_RECEIPT');
$y=$receipt->dispatchAtomic('cafe-A',$failed,function()use($localDb):array{$localDb->exec("INSERT INTO test_order_effects(request_id,kind) VALUES ('y','should-not-run')");return [];});
need($y['state']==='rejected'&&$y['idempotent']===true&&countRows($localDb,'test_order_effects')===1,'LAN_RETRY_AFTER_FAILED_TRANSACTION_NEVER_RERUNS');
// An ACK-outbox failure must roll back both terminal receipt and business write atomically.
$broken=$e;$broken['request_id']='guest-order-submit:'.str_repeat('c',56);
try{$receipt->dispatchAtomic('cafe-A',$broken,function()use($localDb):array{$localDb->exec("INSERT INTO test_order_effects(request_id,kind) VALUES ('z','will-rollback')");return ['ok'=>true];},function():void{throw new RuntimeException('simulated ack persistence outage');});throw new RuntimeException('Expected callback failure did not surface');}catch(RuntimeException $err){need(str_contains($err->getMessage(),'simulated ack persistence outage'),'ACK_CALLBACK_FAILURE_SURFACES');}
need(countRows($localDb,'test_order_effects')===1 && countRows($localDb,'lan_staff_realtime_receipts')===2,'ACK_FAILURE_ROLLS_BACK_BUSINESS_AND_RECEIPT');
$recovery=$receipt->dispatchAtomic('cafe-A',$broken,function()use($localDb):array{$localDb->exec("INSERT INTO test_order_effects(request_id,kind) VALUES ('recovered','once')");return ['ok'=>true];});
need($recovery['state']==='committed' && countRows($localDb,'test_order_effects')===2,'ACK_FAILURE_CLEAN_RETRY_ONCE');
// Do not mutate any persistent or host data; all connections are isolated under CI.
echo "PASS R165_REAL_MARIADB_GUEST_IDEMPOTENCY_END_TO_END_SEPARATE_DB\n";
