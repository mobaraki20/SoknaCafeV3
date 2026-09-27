<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

use Sokna\PublicEdge\Http\PublicHttpKernel;

function g31_assert(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,$message.PHP_EOL);exit(1);}}
$host=(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1');$port=(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306');$name=(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m3');$user=(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna');$pass=(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna');
$storage=sys_get_temp_dir().'/sokna-g31-'.bin2hex(random_bytes(4));
$core=sokna_public_bootstrap(['db'=>['host'=>$host,'port'=>$port,'name'=>$name,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],'app'=>['default_installation_id'=>'g31-installation','storage_dir'=>$storage]]);
$core->migrations()->migrate();$pdo=$core->database();$installation='g31-installation';$tableToken='g31-table-token';$revision='guest-'.str_repeat('a',32);
$snapshot=['format'=>'sokna-guest-snapshot-v1','cafe_name'=>'سکنا G3','features'=>['table_sessions_enabled'=>false],'tables'=>[['id'=>31,'name'=>'میز ۳۱','token'=>$tableToken]],'menus'=>[['menu_key'=>'main','name'=>'منو','sort_order'=>1]],'catalogs'=>['main'=>['menu'=>['menu_key'=>'main','name'=>'منو','sort_order'=>1],'categories'=>[['id'=>1,'name'=>'نوشیدنی','sort_order'=>1]],'items'=>[['id'=>1,'category_id'=>1,'category_name'=>'نوشیدنی','name'=>'آب','description'=>'','price'=>10000,'available'=>true,'image_path'=>'']]]]];
$availability=['version'=>hash('sha256','g31-avail'),'items'=>['1'=>['available'=>true]],'order_acceptance'=>['cafe'=>true],'waiter_enabled_table'=>true,'tables'=>[]];
$pdo->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=1,order_intake_enabled=1')->execute([$installation,'G31',1,1,1]);
foreach(['guest_active_revisions','guest_publish_revisions','guest_availability_state','installation_heartbeats'] as $t){$pdo->prepare("DELETE FROM {$t} WHERE installation_id=?")->execute([$installation]);}
$pdo->prepare('INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')->execute([$installation,$revision,hash('sha256','g31'),json_encode($snapshot,JSON_UNESCAPED_UNICODE),json_encode([])]);
$pdo->prepare('INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())')->execute([$installation,$revision]);
$pdo->prepare('INSERT INTO guest_availability_state(installation_id,version,payload_json,generated_at,last_sync_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$installation,$availability['version'],json_encode($availability,JSON_UNESCAPED_UNICODE)]);
$pdo->prepare('INSERT INTO installation_heartbeats(installation_id,local_version,runtime_status,telemetry_json,last_seen_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$installation,'g31','healthy','{}']);
$kernel=new PublicHttpKernel($core,dirname(__DIR__).'/apps/public');
$menu=$kernel->handle('GET','/menu',['table'=>$tableToken]);
g31_assert((int)$menu['status']===200,'/menu did not render published guest surface');$html=(string)($menu['body']??'');
g31_assert(str_contains($html,'data-table-token="'.$tableToken.'"'),'table token did not reach Guest renderer');
g31_assert(str_contains($html,'data-order-endpoint="/api/guest/order"'),'deployable order endpoint missing from Guest HTML');
g31_assert(str_contains($html,'data-waiter-endpoint="/api/guest/waiter"'),'deployable waiter endpoint missing from Guest HTML');
g31_assert(str_contains((string)($menu['headers']['Content-Security-Policy']??''),"frame-ancestors 'none'"),'Guest security headers missing');
$invalid=$kernel->handle('GET','/menu',['table'=>'bad-token']);g31_assert((int)$invalid['status']===404,'invalid table QR did not fail 404');
$health=$kernel->handle('GET','/health');$healthBody=json_decode((string)($health['body']??''),true);g31_assert((int)$health['status']===200&&($healthBody['ok']??false)===true,'Public /health not deployable');
$pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE installation_id=?')->execute([$installation]);
$stale=$kernel->handle('GET','/menu',['table'=>$tableToken]);g31_assert((int)$stale['status']===200&&str_contains((string)$stale['body'],'is-degraded'),'stale Local did not preserve read-only menu');
fwrite(STDOUT,"G3.1 Public deployable entrypoints DB self-test: OK\n");
