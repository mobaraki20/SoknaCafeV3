<?php
declare(strict_types=1);
// Disposable GitHub CI only; never use against a live café/Home installation.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R231_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('R231_DISPOSABLE_ONLY');
$web=realpath((string)getenv('SOKNA_R213_WEB'));$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$web||!$tmp||!str_starts_with($web,$tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('R231_REFUSE_REAL_WEB');
$cfgFile=dirname($web).'/config.php';if(!is_file($cfgFile))throw new RuntimeException('R231_NEEDS_SETUP');
require_once $web.'/bootstrap.php';$cfg=require $cfgFile;$core=sokna_local_bootstrap($cfg);
$pdo=$core->database();$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$uid=(int)$pdo->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
if($uid<1)throw new RuntimeException('NO_ADMIN');$actor=['id'=>$uid,'role'=>'admin'];
$run=trim((string)($argv[1]??'full'));
$checks=0;
function ok(bool $condition,string $name):void{global $checks;if(!$condition)throw new RuntimeException('FAIL_R231_'.$name);$checks++;echo 'PASS_R231_'.$name."\n";}
function one(PDO $pdo,string $sql,array $args=[]):mixed{$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function setting(PDO $pdo,string $key,string $value):void{$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]);}
function fixture(PDO $pdo,int $uid,string $tag,int $qty=1,int $price=1000):array {
  $n=random_int(10000000,99999999);$token='r231:'.$tag.':'.bin2hex(random_bytes(4));
  $pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active) VALUES(?,?,?,?,1)')->execute(['R231 Table '.$tag,$n,'R231-T-'.$n,$token]);$tid=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,CURDATE(),'day','R231','05:00')")->execute(['R231-S-'.$n,$tid,$tid,$uid]);$sid=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,accepted_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff','accounted',?,?, ?,CURDATE(),'day','R231','05:00')")
    ->execute(['R231-O-'.$n,'R231-C-'.$n,$tid,$sid,$price*$qty,$uid,$n]);$oid=(int)$pdo->lastInsertId();
  // 'none' has no preparation claim/print requirement: inventory is the ONLY gate under test.
  $pdo->prepare("INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,fulfillment_mode,preparation_station,line_total,item_note) VALUES(?,NULL,?,?,?,?,'dine_in','none',?,NULL)")
    ->execute([$oid,'R231 item '.$tag,$price,$qty,$qty,$price*$qty]);$oi=(int)$pdo->lastInsertId();
  return [$tid,$sid,$oid,$oi];
}
function queue(PDO $pdo,int $orderId,int $userId,array $snapshot,string $status='pending'):int{
  $p=json_encode(['recipe_snapshot'=>$snapshot],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
  $pdo->prepare('INSERT INTO inventory_order_events(event_type,order_id,order_item_id,payload_json,idempotency_key,status,actor_user_id) VALUES(\'accounted\',?,NULL,?,?,?,?)')
   ->execute([$orderId,$p,'accounted:order:'.$orderId.':v2',$status,$userId]);return (int)$pdo->lastInsertId();
}
function status(PDO $pdo,string $table,int $id):string{return (string)one($pdo,"SELECT status FROM $table WHERE id=?",[$id]);}
function cancel($core,int $order,array $actor,string $tag):array{return $core->orderStaffActions()->cancelUnsettledStaffOrder(['order_id'=>$order,'request_id'=>'r231-cancel-'.$tag,'reason'=>'رفع سفارش اشتباه آزمایشی'],$actor);}
function rejected($core,int $order,array $actor,string $tag,string $expected='inventory_pending'):void{
  try{cancel($core,$order,$actor,$tag);}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ok($e->errorCode===$expected,'GUARD_'.$tag.'_'.strtoupper($expected));return;}
  throw new RuntimeException('FAIL_R231_EXPECTED_REJECTION_'.$tag);
}
$fixtureFile=$tmp.'/r231-baseline-fixture.json';
if($run==='baseline'){
    // Reproduce actual photographed failure on pristine R230: an accounted
    // order has a durable, explicitly UNTRACKED inventory event (count not live).
    setting($pdo,'inventory_initialized','0');setting($pdo,'inventory_reconciliation_required','1');
    [$t,$s,$o,$oi]=fixture($pdo,$uid,'baseline');$event=queue($pdo,$o,$uid,[]);
    rejected($core,$o,$actor,'R230_BASELINE');
    ok(status($pdo,'orders',$o)==='accounted','BEFORE_FIX_ORDER_STILL_ACCOUNTED');
    ok(status($pdo,'inventory_order_events',$event)==='pending','BEFORE_FIX_EVENT_STILL_PENDING');
    file_put_contents($fixtureFile,json_encode(['order'=>$o,'event'=>$event],JSON_THROW_ON_ERROR));
    echo 'PASS_R231_PRISTINE_R230_REPRODUCES_SCREENSHOT_BLOCK\n';
    exit;
}
if(!is_file($fixtureFile))throw new RuntimeException('MISSING_PRISTINE_BASELINE');
$baseline=json_decode((string)file_get_contents($fixtureFile),true,512,JSON_THROW_ON_ERROR);
$oldOrder=(int)$baseline['order'];$oldEvent=(int)$baseline['event'];
$done=cancel($core,$oldOrder,$actor,'R230_BASELINE');
ok(($done['status']??'')==='cancelled','OLD_PENDING_UNTRACKED_ORDER_CANCELLABLE');
ok(status($pdo,'inventory_order_events',$oldEvent)==='done','OLD_UNTRACKED_EVENT_FINALIZED');
ok((int)one($pdo,'SELECT COUNT(*) FROM inventory_movements WHERE source_type=\'order_item\' AND source_id IN (SELECT id FROM order_items WHERE order_id=?)',[$oldOrder])===0,'OLD_UNTRACKED_ORDER_NO_INVENTORY_MOVEMENTS');
$retry=cancel($core,$oldOrder,$actor,'R230_BASELINE');ok(($retry['idempotent']??false)===true,'OLD_ORDER_CANCEL_RETRY_IDEMPOTENT');
// Explicitly untracked ingredient, not just empty recipe. An initial count later
// must never retroactively debit this historical order.
[$t,$s,$order,$oi]=fixture($pdo,$uid,'untracked');
$component=['inventory_item_id'=>777777,'quantity_base'=>5,'recipe_component_id'=>1,'opening_eligible_at_order'=>false,'cost_status'=>'unknown','unit_cost_snapshot'=>null];
$snapshot=[['order_item_id'=>$oi,'menu_item_id'=>1,'recipe_version_id'=>5,'order_quantity'=>1,'department'=>'shared','components'=>[$component]]];
$eid=queue($pdo,$order,$uid,$snapshot);
$r=cancel($core,$order,$actor,'UNTRACKED');
ok(($r['status']??'')==='cancelled','EXPLICIT_UNTRACKED_RECIPE_CANCEL');
ok(status($pdo,'inventory_order_events',$eid)==='done','EXPLICIT_UNTRACKED_RECIPE_EVENT_DONE');
setting($pdo,'inventory_reconciliation_required','0');setting($pdo,'inventory_initialized','1');
$core->inventoryOrders()->processPending();
ok((int)one($pdo,'SELECT COUNT(*) FROM inventory_movements WHERE source_type=\'order_item\' AND source_id=?',[$oi])===0,'NO_LATE_BACKFILL_AFTER_STOCK_ACTIVATION');
// Failed events are never rewritten to done or bypassed.
[$t,$s,$failedOrder,$failedOi]=fixture($pdo,$uid,'failed');$failedEvent=queue($pdo,$failedOrder,$uid,[],'failed');
rejected($core,$failedOrder,$actor,'FAILED_EVENT');
ok(status($pdo,'orders',$failedOrder)==='accounted'&&status($pdo,'inventory_order_events',$failedEvent)==='failed','FAILED_EVENT_NO_MUTATION');
// A legacy snapshot without the at-accounting eligibility flag is not proven
// untracked. With runtime unavailable it must still fail closed.
setting($pdo,'inventory_reconciliation_required','1');
[$t,$s,$legacyOrder,$legacyOi]=fixture($pdo,$uid,'legacy');
$legacySnapshot=[['order_item_id'=>$legacyOi,'menu_item_id'=>1,'recipe_version_id'=>5,'order_quantity'=>1,'components'=>[['inventory_item_id'=>777777,'quantity_base'=>5]]]];
$legacyEvent=queue($pdo,$legacyOrder,$uid,$legacySnapshot);
rejected($core,$legacyOrder,$actor,'LEGACY_UNKNOWN_ELIGIBILITY');
ok(status($pdo,'orders',$legacyOrder)==='accounted'&&status($pdo,'inventory_order_events',$legacyEvent)==='pending','LEGACY_SNAPSHOT_PRESERVED');
// Tracked pending event while inventory paused must remain blocked, THEN be
// processed on demand after readiness returns before its quantity is reversed.
$code='R231-INV-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO inventory_items(item_code,name,category,base_unit,active,review_status) VALUES(?,?,'ingredient','g',1,'ready')")->execute([$code,'R231 tracked ingredient']);
$inv=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory_balances(inventory_item_id,quantity_base,average_unit_cost,cost_status) VALUES(?,100,3.000000,'known')")->execute([$inv]);
[$t,$s,$trackedOrder,$trackedOi]=fixture($pdo,$uid,'tracked');
$trackedComponent=['inventory_item_id'=>$inv,'quantity_base'=>6,'recipe_component_id'=>99,'quantity_status'=>'known','opening_eligible_at_order'=>true,'unit_cost_snapshot'=>3.0,'cost_status'=>'known'];
$trackedSnapshot=[['order_item_id'=>$trackedOi,'menu_item_id'=>1,'recipe_version_id'=>5,'order_quantity'=>1,'department'=>'shared','components'=>[$trackedComponent]]];
$trackedEvent=queue($pdo,$trackedOrder,$uid,$trackedSnapshot);
rejected($core,$trackedOrder,$actor,'TRACKED_PAUSED');
ok(status($pdo,'orders',$trackedOrder)==='accounted'&&(int)one($pdo,'SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=?',[$inv])===100,'TRACKED_PAUSED_NEVER_BYPASSED');
setting($pdo,'inventory_reconciliation_required','0');
$r=cancel($core,$trackedOrder,$actor,'TRACKED_RESUMED');
ok(($r['status']??'')==='cancelled','TRACKED_AFTER_RESUME_CANCEL_SUCCESS');
ok(status($pdo,'inventory_order_events',$trackedEvent)==='done','TRACKED_ORIGINAL_EVENT_POSTED');
$rows=$pdo->prepare('SELECT quantity_base FROM inventory_movements WHERE source_type=\'order_item\' AND source_id=? ORDER BY id');$rows->execute([$trackedOi]);
$moves=array_map('intval',$rows->fetchAll(PDO::FETCH_COLUMN));sort($moves);
ok($moves===[-6,6],'TRACKED_CONSUMPTION_AND_EXACT_REVERSAL');
ok((int)one($pdo,'SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=?',[$inv])===100,'TRACKED_STOCK_RETURNED_EXACTLY');
ok((int)one($pdo,'SELECT COUNT(*) FROM inventory_order_events WHERE order_id=? AND status<>\'done\'',[$trackedOrder])===0,'TRACKED_ALL_EVENTS_DONE');
$r=cancel($core,$trackedOrder,$actor,'TRACKED_RESUMED');ok(($r['idempotent']??false)===true,'TRACKED_NO_DOUBLE_CANCELLATION');
ok(!$pdo->inTransaction(),'NO_LEAKED_TRANSACTION');
echo 'PASS_R231_REAL_MARIADB_CANCELLATION_AND_FAIL_CLOSED_'.$checks.'_CHECKS'."\n";