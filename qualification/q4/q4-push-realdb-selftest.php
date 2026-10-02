<?php
declare(strict_types=1);

use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\Migrations as LocalMigrations;
use Sokna\Local\Core\PdoIdentityRepository;
use Sokna\Local\Domain\Notifications\NotificationException;
use Sokna\Local\Domain\Notifications\NotificationService;
use Sokna\Local\Relay\NotificationPushRealtimeAdapter;
use Sokna\PublicEdge\Connectivity\ConnectivityService;
use Sokna\PublicEdge\Core\Migrations as PublicMigrations;
use Sokna\PublicEdge\Realtime\RealtimeService;

$root=dirname(__DIR__,2);
$local=$root.'/apps/local-web';
$public=$root.'/apps/public';
foreach([
    $local.'/src/Core/IdentityRepository.php',
    $local.'/src/Core/PdoIdentityRepository.php',
    $local.'/src/Core/Capabilities.php',
    $local.'/src/Core/Migrations.php',
    $local.'/src/Domain/Notifications/NotificationException.php',
    $local.'/src/Domain/Notifications/NotificationService.php',
    $local.'/src/Relay/NotificationPushRealtimeAdapter.php',
    $public.'/src/Core/Migrations.php',
    $public.'/src/Connectivity/ConnectivityService.php',
    $public.'/src/Realtime/RealtimeService.php',
] as $file){ require_once $file; }

function envv(string $key,string $default=''): string { $v=getenv($key); return $v===false?$default:$v; }
function ok(bool $condition,string $message): void { if(!$condition) throw new RuntimeException('ASSERT: '.$message); echo "PASS {$message}\n"; }
function pdo(string $db=''): PDO {
    $host=envv('SOKNA_TEST_DB_HOST','127.0.0.1');
    $port=envv('SOKNA_TEST_DB_PORT','3306');
    $user=envv('SOKNA_TEST_DB_ROOT_USER','root');
    $pass=envv('SOKNA_TEST_DB_ROOT_PASS','root');
    $dsn='mysql:host='.$host.';port='.$port.($db!==''?';dbname='.$db:'').';charset=utf8mb4';
    return new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function envelope(string $requestId,string $kind,array $payload): array {
    $now=time();
    return ['request_id'=>$requestId,'kind'=>$kind,'created_at'=>gmdate('c',$now),'expires_at'=>gmdate('c',$now+300),'payload'=>$payload];
}
function expectNotification(callable $fn,string $code): NotificationException {
    try{$fn();}catch(NotificationException $e){ok($e->errorCode===$code,"Local rejection {$code}");return $e;}
    throw new RuntimeException("Expected NotificationException {$code}");
}

$stamp=preg_replace('/[^0-9]/','',gmdate('YmdHis')).substr(bin2hex(random_bytes(3)),0,6);
$localDb='sokna_q4_local_'.$stamp;
$publicDb='sokna_q4_public_'.$stamp;
$rootPdo=pdo();
$rootPdo->exec("CREATE DATABASE `{$localDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$rootPdo->exec("CREATE DATABASE `{$publicDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try{
    $localPdo=pdo($localDb);$publicPdo=pdo($publicDb);
    (new LocalMigrations($localPdo,$local.'/database/migrations'))->migrate();
    (new PublicMigrations($publicPdo,$public.'/database/migrations'))->migrate();
    ok((int)$localPdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn()>=24,'Local migration stack on real MariaDB');
    ok((int)$publicPdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn()>=3,'Public migration stack on real MariaDB');

    $localPdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)")->execute(['q4staff',password_hash('test-only',PASSWORD_DEFAULT),'Q4 Staff','operator']);
    $uid=(int)$localPdo->lastInsertId();
    $cap=$localPdo->prepare("INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)");
    foreach(['remote_access','remote_notifications'] as $c)$cap->execute([$uid,$c]);

    $installation='q4-installation';
    $publicPdo->prepare("INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,1,1,1)")->execute([$installation,'Q4']);
    $connectivity=new ConnectivityService($publicPdo);
    $connectivity->heartbeat($installation,'q4-local','running',['source'=>'q4-realdb']);
    ok(($connectivity->status($installation,45)['local_fresh']??false)===true,'Public sees fresh Local heartbeat');

    $realtime=new RealtimeService($publicPdo,$connectivity);
    $identity=new PdoIdentityRepository($localPdo);
    $adapter=new NotificationPushRealtimeAdapter($identity,new Capabilities($identity),new NotificationService($localPdo));
    $session=['installation_id'=>$installation,'projection_id'=>'user:'.$uid,'capabilities'=>['notifications.push']];
    $subscription=['endpoint'=>'https://push.example.test/subscription/alpha','keys'=>['p256dh'=>'p256dh-alpha','auth'=>'auth-alpha']];

    $req=envelope('q4-subscribe-1','notification.push.subscribe',['subscription'=>$subscription]);
    $queued=$realtime->enqueue($session,$req);
    ok($queued['status']===202&&($queued['body']['state']??'')==='queued','Public queues push subscription only while Local is fresh');
    $dupe=$realtime->enqueue($session,$req);
    ok($dupe['status']===200&&($dupe['body']['deduplicated']??false)===true,'Public request-id idempotency');

    $claim=$realtime->claim($installation,20);
    ok($claim['status']===200&&($claim['body']['request']['kind']??'')==='notification.push.subscribe','Local claims exact push request');
    $localResult=$adapter->dispatch($claim['body']['request']);
    ok(($localResult['registered']??false)===true,'Local registers subscription after authority revalidation');
    $stored=$localPdo->prepare("SELECT active FROM notification_push_subscriptions WHERE user_id=? AND endpoint_hash=?");$stored->execute([$uid,hash('sha256',$subscription['endpoint'])]);
    ok((int)$stored->fetchColumn()===1,'Push subscription persisted in canonical Local DB');
    $pref=$localPdo->prepare("SELECT push_enabled FROM notification_preferences WHERE user_id=?");$pref->execute([$uid]);
    ok((int)$pref->fetchColumn()===1,'Push preference enabled by successful subscription');

    $ack=$realtime->ack($installation,['request_id'=>'q4-subscribe-1','lease_token'=>$claim['body']['lease_token'],'state'=>'committed','result'=>$localResult]);
    ok($ack['status']===200&&($ack['body']['state']??'')==='committed','Public accepts Local committed ACK');
    $result=$realtime->result($session,'q4-subscribe-1');
    ok(($result['body']['terminal']??false)===true&&($result['body']['state']??'')==='committed','Staff sees definitive committed result');

    $localPdo->prepare("UPDATE user_capabilities SET enabled=0 WHERE user_id=? AND capability='remote_notifications'")->execute([$uid]);
    $req2=envelope('q4-subscribe-denied','notification.push.subscribe',['subscription'=>['endpoint'=>'https://push.example.test/subscription/denied','keys'=>['p256dh'=>'p256dh-denied','auth'=>'auth-denied']]]);
    $queued2=$realtime->enqueue($session,$req2);
    ok($queued2['status']===202,'Public transport can queue with still-valid projected capability');
    $claim2=$realtime->claim($installation,20);
    $denied=expectNotification(fn()=> $adapter->dispatch($claim2['body']['request']),'forbidden');
    $realtime->ack($installation,['request_id'=>'q4-subscribe-denied','lease_token'=>$claim2['body']['lease_token'],'state'=>'rejected','result'=>[],'error_code'=>$denied->errorCode]);
    $deniedResult=$realtime->result($session,'q4-subscribe-denied');
    ok(($deniedResult['body']['state']??'')==='rejected'&&($deniedResult['body']['error_code']??'')==='forbidden','Local permission revocation wins over stale Public session');
    ok((int)$localPdo->query("SELECT COUNT(*) FROM notification_push_subscriptions WHERE endpoint_url LIKE '%/denied'")->fetchColumn()===0,'Denied subscription never reaches Local registry');

    $localPdo->prepare("UPDATE user_capabilities SET enabled=1 WHERE user_id=? AND capability='remote_notifications'")->execute([$uid]);
    $un=envelope('q4-unsubscribe-1','notification.push.unsubscribe',['endpoint'=>$subscription['endpoint']]);
    ok($realtime->enqueue($session,$un)['status']===202,'Public queues unsubscribe');
    $claim3=$realtime->claim($installation,20);$unResult=$adapter->dispatch($claim3['body']['request']);
    ok(($unResult['removed']??0)===1,'Local unregisters exact user endpoint');
    $realtime->ack($installation,['request_id'=>'q4-unsubscribe-1','lease_token'=>$claim3['body']['lease_token'],'state'=>'committed','result'=>$unResult]);
    $stored->execute([$uid,hash('sha256',$subscription['endpoint'])]);ok((int)$stored->fetchColumn()===0,'Canonical subscription is inactive after unsubscribe');

    $publicPdo->prepare("UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE installation_id=?")->execute([$installation]);
    $before=(int)$publicPdo->query("SELECT COUNT(*) FROM realtime_requests")->fetchColumn();
    $offline=$realtime->enqueue($session,envelope('q4-offline','notification.push.subscribe',['subscription'=>$subscription]));
    ok($offline['status']===503&&($offline['body']['error']??'')==='local_unavailable','Public fails closed when Local heartbeat is stale');
    $after=(int)$publicPdo->query("SELECT COUNT(*) FROM realtime_requests")->fetchColumn();
    ok($before===$after,'Offline push request is not silently queued for later surprise commit');

    $connectivity->heartbeat($installation,'q4-local','running');
    $localPdo->prepare("UPDATE users SET active=0 WHERE id=?")->execute([$uid]);
    $req4=envelope('q4-inactive-user','notification.push.subscribe',['subscription'=>$subscription]);
    ok($realtime->enqueue($session,$req4)['status']===202,'Public transport still reflects projected session before refresh');
    $claim4=$realtime->claim($installation,20);
    $inactive=expectNotification(fn()=> $adapter->dispatch($claim4['body']['request']),'actor_invalid');
    $realtime->ack($installation,['request_id'=>'q4-inactive-user','lease_token'=>$claim4['body']['lease_token'],'state'=>'rejected','result'=>[],'error_code'=>$inactive->errorCode]);
    ok(($realtime->result($session,'q4-inactive-user')['body']['error_code']??'')==='actor_invalid','Inactive Local account is authoritatively rejected');

    echo "Q4_PUSH_REALDB_E2E_PASS\n";
} finally {
    try{$rootPdo->exec("DROP DATABASE IF EXISTS `{$localDb}`");}catch(Throwable){}
    try{$rootPdo->exec("DROP DATABASE IF EXISTS `{$publicDb}`");}catch(Throwable){}
}
