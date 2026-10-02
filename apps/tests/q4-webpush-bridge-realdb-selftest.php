<?php
declare(strict_types=1);

use Sokna\Local\Core\Config as LocalConfig;
use Sokna\Local\Core\Migrations as LocalMigrations;
use Sokna\Local\Domain\Notifications\NotificationService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\PublicEdge\Core\Migrations as PublicMigrations;
use Sokna\PublicEdge\Push\PushKeyStore;
use Sokna\PublicEdge\Push\WebPushService;
use Sokna\PublicEdge\Security\SignedLocalRequestVerifier;

$root=dirname(__DIR__,2);
$local=$root.'/local';$public=$root.'/public';
// In GitHub qualification, repo layout is apps/local-web + apps/public.
if(!is_dir($local)){ $local=$root.'/apps/local-web'; $public=$root.'/apps/public'; }
foreach([
 $local.'/src/Core/Config.php',$local.'/src/Core/Migrations.php',
 $local.'/src/Domain/PublicEdge/PublicEdgeSyncException.php',$local.'/src/Domain/PublicEdge/PublicEdgeSyncClient.php',
 $local.'/src/Domain/Notifications/NotificationException.php',$local.'/src/Domain/Notifications/NotificationService.php',
 $public.'/src/Core/Migrations.php',$public.'/src/Security/SignedLocalRequestVerifier.php',
 $public.'/src/Push/PushKeyStore.php',$public.'/src/Push/WebPushService.php',
] as $f) require_once $f;
function ev(string $k,string $d=''): string {$v=getenv($k);return $v===false?$d:$v;}
function db(string $name=''): PDO{$dsn='mysql:host='.ev('SOKNA_TEST_DB_HOST','127.0.0.1').';port='.ev('SOKNA_TEST_DB_PORT','3306').($name!==''?';dbname='.$name:'').';charset=utf8mb4';return new PDO($dsn,ev('SOKNA_TEST_DB_ROOT_USER','root'),ev('SOKNA_TEST_DB_ROOT_PASS','root'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
function ck(bool $ok,string $m): void{if(!$ok)throw new RuntimeException('ASSERT '.$m);echo "PASS {$m}\n";}
$stamp=gmdate('YmdHis').substr(bin2hex(random_bytes(3)),0,6);$ldb='sokna_q4_bridge_l_'.$stamp;$pdb='sokna_q4_bridge_p_'.$stamp;$rootPdo=db();$rootPdo->exec("CREATE DATABASE `{$ldb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$rootPdo->exec("CREATE DATABASE `{$pdb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$tmp=sys_get_temp_dir().'/sokna-q4-vapid-'.bin2hex(random_bytes(4));@mkdir($tmp,0700,true);
try{
 $lp=db($ldb);$pp=db($pdb);(new LocalMigrations($lp,$local.'/database/migrations'))->migrate();(new PublicMigrations($pp,$public.'/database/migrations'))->migrate();
 $lp->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)")->execute(['q4bridge',password_hash('x',PASSWORD_DEFAULT),'Q4 Bridge','operator']);$uid=(int)$lp->lastInsertId();
 $installation='q4-bridge-install';$secret=bin2hex(random_bytes(32));$pp->prepare("INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,1,1,1)")->execute([$installation,'Q4 Bridge']);
 $verifier=new SignedLocalRequestVerifier($pp,[$installation=>$secret],300,'');
 $keys=new PushKeyStore($tmp);$httpStatus=201;$sent=[];
 $webpush=new WebPushService($keys,'https://public.example.test',function(string $endpoint,array $headers,string $body)use(&$httpStatus,&$sent){$sent[]=['endpoint'=>$endpoint,'headers'=>$headers,'body'=>$body,'status'=>$httpStatus];return ['status'=>$httpStatus,'body'=>''];});
 $transport=function(string $url,string $method,array $headers,string $body)use($verifier,$webpush){$path=(string)parse_url($url,PHP_URL_PATH);$v=$verifier->verify((string)($headers['X-Sokna-Installation']??''),$method,$path,(string)($headers['X-Sokna-Timestamp']??''),(string)($headers['X-Sokna-Nonce']??''),$body,(string)($headers['X-Sokna-Signature']??''));if(($v['ok']??false)!==true)return ['status'=>(int)$v['status'],'body'=>json_encode(['ok'=>false,'error'=>$v['error']])];if($path==='/api/v1/local/push/config')return ['status'=>200,'body'=>json_encode(['ok'=>true,'push'=>$webpush->publicConfig()])];if($path==='/api/v1/local/push/deliver'){$p=json_decode($body,true);$r=$webpush->deliver((array)($p['notification']??[]),(array)($p['subscriptions']??[]),(string)($p['idempotency_key']??''));return ['status'=>200,'body'=>json_encode($r)];}return ['status'=>404,'body'=>json_encode(['ok'=>false,'error'=>'not_found'])];};
 $cfg=LocalConfig::fromArray(['installation'=>['id'=>$installation],'public'=>['base_url'=>'https://public.example.test/public','shared_secret'=>$secret]]);$client=new PublicEdgeSyncClient($cfg,$transport);$notifications=new NotificationService($lp,$client);$user=['id'=>$uid,'role'=>'operator'];
 $pushCfg=$notifications->remotePushConfig();ck(($pushCfg['bridge_configured']??false)===true&&strlen((string)$pushCfg['vapid_public_key'])>80,'Local reads VAPID public key through signed Public channel');
 ck((int)$pp->query('SELECT COUNT(*) FROM request_nonces')->fetchColumn()===1,'Public HMAC verifier persists anti-replay nonce');
 // Real P-256 client subscription key material.
 $clientKey=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$det=openssl_pkey_get_details($clientKey);$ec=$det['ec'];$pub="\x04".$ec['x'].$ec['y'];$b64=fn(string $v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$endpoint='https://fcm.googleapis.com/fcm/send/q4-bridge';$sub=['endpoint'=>$endpoint,'keys'=>['p256dh'=>$b64($pub),'auth'=>$b64(random_bytes(16))]];
 $notifications->registerPush($sub,$user);$notifications->savePreferences(['in_app_enabled'=>0,'push_enabled'=>1],$user);$notifications->queueForUser($uid,'q4.bridge.success','Bridge test','Web Push works','/staff',['q4'=>1]);$r=$notifications->processPending();ck(($r['delivered']??0)===1&&($r['failed']??0)===0,'Local durable outbox delivers Web Push through Public bridge');
 ck(count($sent)===1&&$sent[0]['endpoint']===$endpoint,'Public transport targets exact browser Push endpoint');ck(($sent[0]['headers']['Content-Encoding']??'')==='aes128gcm'&&str_starts_with((string)($sent[0]['headers']['Authorization']??''),'vapid t='),'Public transport emits aes128gcm + VAPID');ck(strlen((string)$sent[0]['body'])>100,'Public transport emits encrypted payload body');
 $state=$lp->query("SELECT state FROM notification_outbox WHERE event_key='q4.bridge.success' AND channel='web_push'")->fetchColumn();ck($state==='delivered','Canonical Local outbox records delivered after Public success');
 // Gone endpoint lifecycle.
 $httpStatus=410;$notifications->queueForUser($uid,'q4.bridge.gone','Gone test','Subscription expired','/staff',[]);$r2=$notifications->processPending();ck(($r2['skipped']??0)===1&&($r2['failed']??0)===0,'410 Push endpoint becomes skipped, not retry storm');$q=$lp->prepare('SELECT active FROM notification_push_subscriptions WHERE user_id=? AND endpoint_hash=?');$q->execute([$uid,hash('sha256',$endpoint)]);ck((int)$q->fetchColumn()===0,'Gone browser subscription is deactivated in canonical Local registry');
 ck((int)$pp->query('SELECT COUNT(*) FROM request_nonces')->fetchColumn()>=3,'Each signed Local→Public bridge request has unique replay-protected nonce');
 echo "Q4_WEBPUSH_BRIDGE_REALDB_PASS\n";
} finally {try{$rootPdo->exec("DROP DATABASE IF EXISTS `{$ldb}`");}catch(Throwable){}try{$rootPdo->exec("DROP DATABASE IF EXISTS `{$pdb}`");}catch(Throwable){}if(is_dir($tmp)){foreach(glob($tmp.'/secrets/*')?:[] as $f)@unlink($f);@rmdir($tmp.'/secrets');@rmdir($tmp);}}
