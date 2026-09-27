<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
require_once dirname(__DIR__).'/apps/public/bootstrap.php';

use Sokna\Local\Core\Config as LocalConfig;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\PublicEdge\PublicEdgePublisherService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeRelayService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\Local\Domain\PublicEdge\PublicProjectionBuilder;
use Sokna\PublicEdge\Http\PublicHttpKernel;

function g41(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function body(array $response): array { $v=json_decode((string)($response['body']??''),true); return is_array($v)?$v:[]; }

$host=(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1');$port=(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306');
$localName=(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2');$user=(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna');$pass=(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna');
$rootUser=(string)(getenv('SOKNA_TEST_DB_ROOT_USER')?:'root');$rootPass=(string)(getenv('SOKNA_TEST_DB_ROOT_PASS')?:'root');$publicName=preg_replace('/[^A-Za-z0-9_]/','_',$localName).'_public_g41';
$root=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$rootUser,$rootPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec("DROP DATABASE IF EXISTS `{$publicName}`");$root->exec("CREATE DATABASE `{$publicName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$qu=$root->quote($user);$qp=$root->quote($pass);$root->exec("CREATE USER IF NOT EXISTS {$qu}@'%' IDENTIFIED BY {$qp}");$root->exec("GRANT ALL PRIVILEGES ON `{$publicName}`.* TO {$qu}@'%'");$root->exec('FLUSH PRIVILEGES');

$data=sys_get_temp_dir().'/sokna-g41-'.bin2hex(random_bytes(4));@mkdir($data,0700,true);$installation='g41-installation';$secret='g41-secret-'.bin2hex(random_bytes(12));
$localConfig=['app'=>['timezone'=>'UTC','data_dir'=>$data],'db'=>['host'=>$host,'port'=>$port,'name'=>$localName,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],'installation'=>['id'=>$installation],'runtime'=>['local_token'=>'g41'],'public'=>['base_url'=>'http://127.0.0.1','shared_secret'=>$secret],'integrations'=>['accommodation'=>['base_url'=>'','secret'=>'']]];
$local=sokna_local_bootstrap($localConfig);$local->migrations()->migrate();$lp=$local->database();

$pwd=password_hash('RemoteG41Pass!',PASSWORD_DEFAULT);
$mkUser=function(string $username,string $role,array $caps)use($lp,$pwd):array{$q=$lp->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');$q->execute([$username,$pwd,$username,$role]);$id=(int)$lp->lastInsertId();$c=$lp->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');foreach($caps as $cap)$c->execute([$id,$cap]);return ['id'=>$id,'username'=>$username,'display_name'=>$username,'role'=>$role,'active'=>1];};
$admin=$mkUser('g41-admin','admin',[]);
$remote=$mkUser('g41-remote','operator',['orders_floor','cashier_accounts','inventory_operations','remote_access','remote_settlement','remote_supply','remote_subscriber_payments']);
$lp->prepare("INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,'kitchen')")->execute([$remote['id']]);
$settings=$lp->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');foreach([['cafe.name','G41 Cafe'],['installation.id',$installation],['orders_accepting.cafe','1'],['orders_accepting.kitchen','1'],['orders_accepting.bar','1'],['waiter_call_enabled','1']] as [$k,$v])$settings->execute([$k,$v]);

$lp->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('g41-cat','G41','guest_staff',1,1)");$categoryId=(int)$lp->lastInsertId();
$lp->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('g41-menu','G41 Menu','active',1)");$menuId=(int)$lp->lastInsertId();$lp->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,1)')->execute([$menuId,$categoryId]);
$itemId=$local->sellables()->create(['category_id'=>$categoryId,'name'=>'G41 Settlement Item','price'=>1000,'preparation_station'=>'none','sellable_kind'=>'service_item','staff_only'=>false,'takeaway_allowed'=>true]);$lp->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$itemId]);
$lp->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('G41 Table',741,'G41T','g41-table-token',1,1)");$tableId=(int)$lp->lastInsertId();
$order=$local->staffQuickOrders()->commit(['table_id'=>$tableId,'expected_session_id'=>0,'request_token'=>'g41-order-0001','items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>1000]]],$remote);$sessionId=(int)$order['session_id'];g41($sessionId>0,'settlement fixture missing');

$inventory=$local->inventory()->createItem(['item_code'=>'G41-I','name'=>'G41 Supply Item','category'=>'ingredient','base_unit'=>'count','default_department'=>'kitchen'],$admin);$inventoryId=(int)$inventory['id'];
$subscriber=$local->subscribers()->create(['name'=>'G41 Subscriber','mobile'=>'09120000041','active'=>true],$admin);$subscriberId=(int)$subscriber['id'];
$lp->beginTransaction();$period=$local->financialPeriodIdentity()->forDateTx(date('Y-m-d'),(int)$admin['id']);$local->subscribers()->insertLedgerTx($subscriberId,'invoice',10000,(int)$admin['id'],(int)$period['id'],null,null,'g41-fixture','fixture',null,'g41-subscriber-opening');$lp->commit();

$publicConfig=['db'=>['host'=>$host,'port'=>$port,'name'=>$publicName,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],'app'=>['default_installation_id'=>$installation,'storage_dir'=>$data.'/public-storage','cookie_secure'=>'0'],'relay'=>['installation_secrets'=>[$installation=>$secret],'clock_skew_seconds'=>300],'auth'=>['session_ttl_seconds'=>3600,'failure_limit'=>5,'failure_window_seconds'=>900,'block_seconds'=>900]];
$public=sokna_public_bootstrap($publicConfig);$public->migrations()->migrate();$kernel=new PublicHttpKernel($public,dirname(__DIR__).'/apps/public');
$transport=function(string $url,string $method,array $headers,string $raw)use($kernel){$path=(string)(parse_url($url,PHP_URL_PATH)?:'/');$r=$kernel->handle($method,$path,[],$raw,$headers);return ['status'=>(int)$r['status'],'body'=>(string)($r['body']??'')];};
$client=new PublicEdgeSyncClient(LocalConfig::fromArray($localConfig),$transport);$obs=new Observability($data);$builder=new PublicProjectionBuilder($lp,$local->settlements(),$local->supply());$publisher=new PublicEdgePublisherService($lp,$client,$builder,$obs,'g41-ci');
g41(($publisher->syncAll()['success']??false)===true,'initial projection publish failed');
$relay=new PublicEdgeRelayService($lp,$client,$local->realtimeDispatch(),$local->deferredDispatch(),$obs);

$login=$kernel->handle('POST','/staff/login',[],http_build_query(['installation_id'=>$installation,'username'=>'g41-remote','password'=>'RemoteG41Pass!']),['Content-Type'=>'application/x-www-form-urlencoded']);g41((int)$login['status']===303,'remote login failed');preg_match('/sokna_staff=([^;]+)/',(string)($login['headers']['Set-Cookie']??''),$m);$cookie='sokna_staff='.($m[1]??'');g41(strlen($cookie)>20,'remote session cookie missing');
$staff=$kernel->handle('GET','/staff',[],'',['Cookie'=>$cookie]);g41(str_contains((string)$staff['body'],'data-action="settlement"')&&str_contains((string)$staff['body'],'data-action="supply"')&&str_contains((string)$staff['body'],'data-action="subscriber"'),'remote action surface missing');

// A05 + A15: Realtime settlement provider -> queue -> Local canonical settlement -> ACK.
$ops=body($kernel->handle('GET','/api/staff/read',['model'=>'operations'],'',['Cookie'=>$cookie]));$accounts=(array)($ops['payload']['settlement_accounts']??[]);$account=null;foreach($accounts as $a)if((int)$a['session_id']===$sessionId){$account=$a;break;}g41(is_array($account),'settlement account projection missing');
$settleId='g41-settle-'.bin2hex(random_bytes(4));$settleEnvelope=['request_id'=>$settleId,'kind'=>'settlement.commit','created_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+120),'payload'=>['session_id'=>$sessionId,'expected_session_id'=>$sessionId,'expected_remaining_total'=>(int)$account['remaining_total'],'expected_signature'=>(string)$account['signature'],'destination'=>'direct','mode'=>'full']];
$queued=body($kernel->handle('POST','/api/staff/realtime',[],json_encode($settleEnvelope),['Cookie'=>$cookie,'Content-Type'=>'application/json']));g41(($queued['state']??'')==='queued','settlement was not queued');$relayOut=$relay->sync();g41((int)$relayOut['realtime']>=1,'realtime worker did not dispatch settlement');$settled=body($kernel->handle('GET','/api/staff/realtime/result',['request_id'=>$settleId],'',['Cookie'=>$cookie]));g41(($settled['state']??'')==='committed','settlement realtime did not commit');g41((string)$lp->query("SELECT status FROM table_sessions WHERE id={$sessionId}")->fetchColumn()==='closed','canonical Local settlement did not close session');

// A13 + A06: Deferred Supply need provider -> Local Supply owner -> ACK.
$supplyId='g41-supply-'.bin2hex(random_bytes(4));$supplyEnvelope=['request_id'=>$supplyId,'kind'=>'supply.need.create','created_at'=>gmdate('c'),'occurred_at'=>gmdate('c'),'payload'=>['inventory_item_id'=>$inventoryId,'department'=>'kitchen','quantity_major'=>'2','note'=>'g41 remote']];
$sq=body($kernel->handle('POST','/api/staff/deferred',[],json_encode($supplyEnvelope),['Cookie'=>$cookie,'Content-Type'=>'application/json']));g41(($sq['state']??'')==='pending_sync','supply deferred was not queued');$relay->sync();$sres=body($kernel->handle('GET','/api/staff/deferred/result',['request_id'=>$supplyId],'',['Cookie'=>$cookie]));g41(($sres['state']??'')==='committed','supply deferred did not commit');g41((int)$lp->query("SELECT COUNT(*) FROM inventory_supply_needs WHERE inventory_item_id={$inventoryId}")->fetchColumn()===1,'canonical Supply owner did not receive deferred need');

// A16: Subscriber payment deferred E2E.
$payId='g41-pay-'.bin2hex(random_bytes(4));$payEnvelope=['request_id'=>$payId,'kind'=>'subscriber.payment','created_at'=>gmdate('c'),'occurred_at'=>gmdate('c'),'payload'=>['subscriber_id'=>$subscriberId,'amount'=>3000,'expected_balance'=>10000,'reference'=>'g41-remote']];
$pq=body($kernel->handle('POST','/api/staff/deferred',[],json_encode($payEnvelope),['Cookie'=>$cookie,'Content-Type'=>'application/json']));g41(($pq['state']??'')==='pending_sync','subscriber payment was not queued');$relay->sync();$pres=body($kernel->handle('GET','/api/staff/deferred/result',['request_id'=>$payId],'',['Cookie'=>$cookie]));g41(($pres['state']??'')==='committed','subscriber payment did not commit');$balance=(int)$lp->query("SELECT balance_after FROM subscriber_ledger WHERE subscriber_id={$subscriberId} ORDER BY id DESC LIMIT 1")->fetchColumn();g41($balance===7000,'canonical subscriber balance did not change');

// A06 reconciliation: stale expected balance -> durable Local review -> manager approval -> Public reconcile.
$reviewId='g41-review-'.bin2hex(random_bytes(4));$reviewEnvelope=['request_id'=>$reviewId,'kind'=>'subscriber.payment','created_at'=>gmdate('c'),'occurred_at'=>gmdate('c'),'payload'=>['subscriber_id'=>$subscriberId,'amount'=>1000,'expected_balance'=>9999,'reference'=>'g41-review']];
body($kernel->handle('POST','/api/staff/deferred',[],json_encode($reviewEnvelope),['Cookie'=>$cookie,'Content-Type'=>'application/json']));$relay->sync();$needs=body($kernel->handle('GET','/api/staff/deferred/result',['request_id'=>$reviewId],'',['Cookie'=>$cookie]));g41(($needs['state']??'')==='needs_review','stale subscriber payment did not enter review');$reviews=$local->deferredReceipts()->pendingReviews();$localReview=0;foreach($reviews as $r)if((string)$r['request_id']===$reviewId){$localReview=(int)$r['review_id'];break;}g41($localReview>0,'durable Local review item missing');$approved=$local->subscriberPaymentDeferred()->resolveReview($localReview,'approve','G4.1 manager reconciliation',$admin);g41(($approved['state']??'')==='committed','manager review approval failed');$recon=$relay->sync();g41((int)$recon['reconciled']>=1,'Public deferred reconciliation was not sent');$final=body($kernel->handle('GET','/api/staff/deferred/result',['request_id'=>$reviewId],'',['Cookie'=>$cookie]));g41(($final['state']??'')==='committed','Public review state was not reconciled to committed');

$pp=$public->database();g41((int)$pp->query("SELECT COUNT(*) FROM realtime_requests WHERE installation_id='{$installation}' AND state='committed'")->fetchColumn()>=1,'A05 realtime durable terminal evidence missing');g41((int)$pp->query("SELECT COUNT(*) FROM deferred_work WHERE installation_id='{$installation}' AND state='committed'")->fetchColumn()>=3,'A06 deferred terminal evidence missing');
$root->exec("DROP DATABASE IF EXISTS `{$publicName}`");
fwrite(STDOUT,"G4.1 cross-component parity MariaDB E2E: OK\n");
