<?php
declare(strict_types=1);
// R94 CI ONLY — real MariaDB transactions with synthetic fixtures. No production access.
$root=dirname(__DIR__,2);
require $root.'/local/bootstrap.php';
$rows=[];
function txcheck(bool $ok,string $label):void {global $rows;$rows[]=['name'=>$label,'pass'=>$ok];if(!$ok)throw new RuntimeException('FAILED: '.$label);echo "PASS ".$label."\n";}
function scalar(PDO $pdo,string $sql,array $args=[]):mixed {$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn();}
try{
  $core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/r94-tx-'.bin2hex(random_bytes(5))],
    'db'=>['host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_r94_qa'),'charset'=>'utf8mb4','user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna_ci'),'pass'=>(string)getenv('SOKNA_TEST_DB_PASS')]
  ]);
  $pdo=$core->database();
  txcheck(count($core->migrations()->appliedVersions())===count($core->migrations()->catalog()),'full R94 schema migrated before transactions');
  $uid=(int)scalar($pdo,"SELECT id FROM users WHERE username='r94-qa-admin' LIMIT 1");
  txcheck($uid>0,'synthetic administrator from seed is available');
  $user=['id'=>$uid,'role'=>'admin','display_name'=>'R94 QA admin'];
  $pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('r94-test-variant','R94 isolated variant','guest_staff',999,1)");
  $category=(int)$pdo->lastInsertId();
  $small=$core->sellables()->create(['category_id'=>$category,'name'=>'QA portion small','price'=>1200,'preparation_station'=>'other','sellable_kind'=>'menu_item','staff_only'=>false,'takeaway_allowed'=>true]);
  $large=$core->sellables()->create(['category_id'=>$category,'name'=>'QA portion LARGE','price'=>2400,'preparation_station'=>'other','sellable_kind'=>'menu_item','staff_only'=>false,'takeaway_allowed'=>true]);
  $menu=(int)scalar($pdo,"SELECT id FROM menus WHERE status='active' ORDER BY id LIMIT 1");
  txcheck($menu>0,'real active guest menu exists');
  $pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,999)')->execute([$menu,$category]);
  $add=$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)');$add->execute([$menu,$small]);$add->execute([$menu,$large]);
  $pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('QA guest',994,'QA-G','r94-qa-table-guest',1,994)");
  $guestTable=(int)$pdo->lastInsertId();
  $pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('QA staff',995,'QA-S','r94-qa-table-staff',1,995)");
  $staffTable=(int)$pdo->lastInsertId();
  // Guest chooses the large SKU; DB must store exact selected ID/amount, not the small SKU.
  $guestPayload=['table_token'=>'r94-qa-table-guest','session_token'=>'','device_token'=>'ci-device-one','client_token'=>'ci-r94-guest-submit-0001','items'=>[['id'=>$large,'quantity'=>1,'expected_price'=>2400,'fulfillment_mode'=>'dine_in']]];
  $guest=$core->guestOrders()->submit($guestPayload);
  txcheck(($guest['status']??'')==='pending_approval','guest order persisted pending operator approval');
  $gOrder=(int)scalar($pdo,'SELECT id FROM orders WHERE client_token=?',[$guestPayload['client_token']]);
  txcheck($gOrder>0,'guest order exists in real MariaDB orders table');
  txcheck((int)scalar($pdo,'SELECT item_id FROM order_items WHERE order_id=?',[$gOrder])===$large,'selected LARGE SKU persisted without fallback to small');
  txcheck((int)scalar($pdo,'SELECT unit_price FROM order_items WHERE order_id=?',[$gOrder])===2400,'selected LARGE price persisted');
  $repeat=$core->guestOrders()->submit($guestPayload);
  txcheck(($repeat['duplicate']??false)===true||($repeat['idempotent']??false)===true||($repeat['order_code']??'')===($guest['order_code']??''),'duplicate guest submission returns existing receipt');
  txcheck((int)scalar($pdo,'SELECT COUNT(*) FROM orders WHERE client_token=?',[$guestPayload['client_token']])===1,'guest order retry has no double charge/order');
  $wrong=$guestPayload;$wrong['table_token']='r94-qa-table-staff';$crossBlocked=false;
  try{$core->guestOrders()->submit($wrong);}catch(\Sokna\Local\Domain\Orders\GuestOrderException $ex){$crossBlocked=$ex->errorCode==='invalid_order';}
  txcheck($crossBlocked,'replayed guest client token cannot transfer order to another table');
  $orders=$core->guestOrders()->list(['table_token'=>'r94-qa-table-guest','session_token'=>'','device_token'=>'ci-device-one']);
  txcheck(count($orders['orders']??[])===1,'guest readback lists exactly one pending order');
  // Independently open an operator order and settle the actual Local ledger.
  $staff=$core->staffQuickOrders()->commit(['table_id'=>$staffTable,'expected_session_id'=>0,'request_token'=>'ci-r94-staff-order-0001','items'=>[['id'=>$small,'quantity'=>2,'expected_price'=>1200]]],$user);
  $session=(int)($staff['session_id']??0);
  txcheck($session>0,'staff quick order creates canonical table session');
  txcheck((int)scalar($pdo,'SELECT COUNT(*) FROM orders WHERE session_id=?',[$session])===1,'staff order committed exactly once');
  $account=$core->settlements()->account($session);
  $balance=(int)($account['remaining_total']??0);
  txcheck($balance>0,'real account exposes positive remaining balance');
  $request=['session_id'=>$session,'destination'=>'direct','mode'=>'full','request_id'=>'ci-r94-settlement-0001','expected_session_id'=>$session,'expected_remaining_total'=>$balance,'expected_signature'=>(string)$account['signature'],'tenders'=>[['type'=>'cash','amount'=>$balance]],'print_receipt'=>false];
  $result=$core->settlements()->settle($request,$user);
  txcheck((int)($result['settlement_id']??0)>0,'direct settlement writes canonical Local receipt');
  $repeatSettle=$core->settlements()->settle($request,$user);
  txcheck(($repeatSettle['duplicate']??false)===true||($repeatSettle['idempotent']??false)===true||((int)($repeatSettle['settlement_id']??0)===(int)($result['settlement_id']??0)),'lost settlement response replay returns same receipt');
  txcheck((int)scalar($pdo,'SELECT COUNT(*) FROM settlement_records WHERE request_id=?',[$request['request_id']])===1,'settlement replay does not produce double financial ledger entry');
  $readback=$core->settlements()->requestStatus($session,$request['request_id'],$user);
  txcheck(($readback['state']??'')==='committed','settlement request status readback reports committed');
  // Stock receiving uses Local authority, purchase workflow, and durable idempotency token.
  $pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('module.inventory.enabled','1'),('module.supply.enabled','1'),('inventory_initialized','1'),('inventory_reconciliation_required','0') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
  $inventory=$core->inventory()->createItem(['name'=>'R94 isolated stock','base_unit'=>'count','category'=>'ingredient','default_department'=>'shared'],$user);
  $itemId=(int)$inventory['id'];txcheck($itemId>0,'inventory item created in real MariaDB');
  $core->supply()->upsertNeed(['inventory_item_id'=>$itemId,'quantity_major'=>'3','department'=>'shared'],$user);
  $groups=$core->supply()->purchaseGroups();$candidates=array_values(array_filter($groups,static fn($g)=>(int)($g['item_id']??0)===$itemId));
  txcheck(count($candidates)===1,'supply need becomes an actual purchase group');
  $g=$candidates[0];$prepared=$core->supply()->prepare((string)$g['group_key'],3,$user);
  txcheck(is_array($prepared),'purchase group prepared on Local');
  $receiptPayload=['request_token'=>bin2hex(random_bytes(16)),'unit_count'=>'3','purchase_unit_id'=>0,'total_cost'=>900,'occurred_at'=>gmdate('c')];
  $receipt=$core->supply()->receive((string)$g['group_key'],$receiptPayload,$user);
  txcheck(is_array($receipt),'purchase receipt committed into inventory');
  txcheck((int)scalar($pdo,'SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=?',[$itemId])===3,'inventory balance increased by exactly 3');
  $receiptAgain=$core->supply()->receive((string)$g['group_key'],$receiptPayload,$user);
  txcheck(is_array($receiptAgain),'duplicate supply receipt returns existing result');
  txcheck((int)scalar($pdo,'SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=?',[$itemId])===3,'duplicate supply delivery does not inflate stock');
  echo 'R94 REAL MARIADB TRANSACTIONS PASS: '.count($rows).' assertions across Guest, Staff, Settlement and Inventory.' . "\n";
}catch(Throwable $e){fwrite(STDERR,'R94 REAL MARIADB TRANSACTIONS FAIL: '.get_class($e).': '.$e->getMessage()."\n");exit(1);}