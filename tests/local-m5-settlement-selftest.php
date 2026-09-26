<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Finance\SettlementException;
use Sokna\Local\Domain\Finance\SettlementStateConflict;

function m510_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m510_assert(bool $condition,string $message): void { if(!$condition)m510_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m510-'.bin2hex(random_bytes(4))],
    'db'=>[
        'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),
        'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
        'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),
        'charset'=>'utf8mb4',
        'user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),
        'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),
    ],
]);
$core->migrations()->migrate();
$pdo=$core->database();

$tables=array_map('strval',$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
foreach(['settlement_records','settlement_record_lines','invoice_discount_audit'] as $table)
    m510_assert(in_array($table,$tables,true),"missing {$table}");
foreach(['print_jobs'] as $later)
    m510_assert(!in_array($later,$tables,true),"M5.10 pulled adapter/later owner {$later} forward");

$makeUser=function(string $name,string $role,array $caps)use($pdo):array{
    $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
        ->execute([$name,password_hash('x',PASSWORD_DEFAULT),$name,$role]);
    $id=(int)$pdo->lastInsertId();
    $st=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');
    foreach($caps as $cap)$st->execute([$id,$cap]);
    return ['id'=>$id,'username'=>$name,'display_name'=>$name,'role'=>$role,'active'=>1];
};
$admin=$makeUser('m510-admin','admin',[]);
$cashier=$makeUser('m510-cashier','operator',['cashier_accounts','orders_floor']);
$viewer=$makeUser('m510-viewer','operator',['orders_floor']);

$core->tax()->createRateVersion(['rate_percent'=>'10','effective_from'=>date('Y-m-d H:i:s',time()-120)],$admin);
$core->tax()->setEnabled(true,$admin);

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('m510','M510','guest_staff',10,1)");
$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('m510-main','M510 Main','active',10)");
$menuId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);
$itemA=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Settlement A','price'=>1000,'preparation_station'=>'none',
    'sellable_kind'=>'service_item','staff_only'=>false,'takeaway_allowed'=>true,
]);
$itemB=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Settlement B','price'=>2000,'preparation_station'=>'none',
    'sellable_kind'=>'service_item','staff_only'=>false,'takeaway_allowed'=>true,
]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?),(?,?)')->execute([$menuId,$itemA,$menuId,$itemB]);
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Settlement Table',88,'S88','m510-table',1,1)");
$tableId=(int)$pdo->lastInsertId();

$order=$core->staffQuickOrders()->commit([
    'table_id'=>$tableId,'expected_session_id'=>0,'request_token'=>'m510-order-00001',
    'items'=>[
        ['id'=>$itemA,'quantity'=>2,'expected_price'=>1000],
        ['id'=>$itemB,'quantity'=>1,'expected_price'=>2000],
    ],
],$cashier);
$sessionId=(int)$order['session_id'];
m510_assert($sessionId>0,'fixture session missing');

$forbidden=false;
try{$core->settlements()->setDiscount($sessionId,'fixed',300,$viewer);}
catch(SettlementException $e){$forbidden=$e->errorCode==='forbidden';}
m510_assert($forbidden,'non-cashier changed settlement discount');

$discount=$core->settlements()->setDiscount($sessionId,'fixed',300,$cashier);
m510_assert((int)$discount['discount_amount']===300,'fixed discount owner drifted');

$account0=$core->settlements()->account($sessionId);
m510_assert($account0['subtotal']===4000&&$account0['discount']===300&&$account0['tax']===370&&$account0['total']===4070,'tax-aware account calculation drifted');
$lineA=null;foreach($account0['items'] as $line)if((int)$line['item_id']===$itemA){$lineA=$line;break;}
m510_assert(is_array($lineA),'itemized line fixture missing');

$partialRequest=[
    'session_id'=>$sessionId,'destination'=>'direct','mode'=>'itemized','request_id'=>'m510-settle-part-1',
    'expected_session_id'=>$sessionId,'expected_remaining_total'=>$account0['remaining_total'],'expected_signature'=>$account0['signature'],
    'selection'=>[['order_item_id'=>(int)$lineA['id'],'quantity'=>1]],
];
$partial=$core->settlements()->settle($partialRequest,$cashier);
m510_assert(empty($partial['idempotent'])&&empty($partial['closes_session'])&&$partial['settlement_kind']==='itemized','itemized partial settlement did not stay open');
$partialRetry=$core->settlements()->settle($partialRequest,$cashier);
m510_assert(!empty($partialRetry['idempotent'])&&(int)$partialRetry['settlement_id']===(int)$partial['settlement_id'],'lost-ACK settlement retry did not replay canonical receipt');

$account1=$core->settlements()->account($sessionId);
m510_assert($account1['remaining_total']<$account0['remaining_total'],'itemized payment did not reduce canonical remaining total');

$stale=false;
try{$core->settlements()->settle([
    'session_id'=>$sessionId,'destination'=>'direct','mode'=>'full','request_id'=>'m510-stale-full',
    'expected_session_id'=>$sessionId,'expected_remaining_total'=>$account0['remaining_total'],'expected_signature'=>$account0['signature'],
],$cashier);}catch(SettlementStateConflict $e){$stale=$e->errorCode==='account_changed';}
m510_assert($stale,'stale account confirmation committed after itemized payment');

$discountLocked=false;
try{$core->settlements()->setDiscount($sessionId,'fixed',100,$cashier);}
catch(SettlementStateConflict $e){$discountLocked=$e->errorCode==='itemized_locked';}
m510_assert($discountLocked,'discount changed after itemized payment began');

$adapterDenied=false;
try{$core->settlements()->settle([
    'session_id'=>$sessionId,'destination'=>'accommodation','mode'=>'full','request_id'=>'m510-adapter-block',
    'expected_session_id'=>$sessionId,'expected_remaining_total'=>$account1['remaining_total'],'expected_signature'=>$account1['signature'],
],$cashier);}catch(SettlementException $e){$adapterDenied=$e->errorCode==='adapter_not_migrated';}
m510_assert($adapterDenied,'M5.10 activated Accommodation adapter early');

$final=$core->settlements()->settle([
    'session_id'=>$sessionId,'destination'=>'direct','mode'=>'full','request_id'=>'m510-settle-final',
    'expected_session_id'=>$sessionId,'expected_remaining_total'=>$account1['remaining_total'],'expected_signature'=>$account1['signature'],
],$cashier);
m510_assert(!empty($final['closes_session'])&&$final['remaining_total']===0,'final settlement did not close exact remaining account');
$session=(array)$pdo->query("SELECT status,live_table_guard,checkout_subtotal,checkout_discount,checkout_tax,checkout_total FROM table_sessions WHERE id={$sessionId}")->fetch(PDO::FETCH_ASSOC);
m510_assert($session['status']==='closed'&&$session['live_table_guard']===null&&(int)$session['checkout_total']===4070,'session close snapshot drifted');
$orderStatus=(string)$pdo->query("SELECT status FROM orders WHERE id=".(int)$order['order_id'])->fetchColumn();
m510_assert($orderStatus==='completed','final settlement did not complete accounted order');

$fingerprintConflict=false;
$bad=$partialRequest;$bad['selection']=[['order_item_id'=>(int)$lineA['id'],'quantity'=>2]];
try{$core->settlements()->settle($bad,$cashier);}
catch(SettlementStateConflict $e){$fingerprintConflict=$e->errorCode==='request_id_conflict';}
m510_assert($fingerprintConflict,'same settlement request_id accepted different payload');

$reversal=$core->settlements()->reverse((int)$final['settlement_id'],'cash correction','m510-reverse-final',$cashier);
m510_assert($reversal['settlement_kind']==='reversal'&&!empty($reversal['invoice_number']),'exact receipt reversal was not created');
$origLineCount=(int)$pdo->query("SELECT COUNT(*) FROM settlement_record_lines WHERE settlement_id=".(int)$final['settlement_id'])->fetchColumn();
$revLineCount=(int)$pdo->query("SELECT COUNT(*) FROM settlement_record_lines WHERE settlement_id=".(int)$reversal['settlement_id'])->fetchColumn();
m510_assert($origLineCount>0&&$origLineCount===$revLineCount,'reversal did not copy exact settlement lines');
$reopened=(array)$pdo->query("SELECT status,live_table_guard FROM table_sessions WHERE id={$sessionId}")->fetch(PDO::FETCH_ASSOC);
m510_assert($reopened['status']==='active'&&(int)$reopened['live_table_guard']===$tableId,'full receipt reversal did not reopen original session');
$orderStatus2=(string)$pdo->query("SELECT status FROM orders WHERE id=".(int)$order['order_id'])->fetchColumn();
m510_assert($orderStatus2==='accounted','full receipt reversal did not restore completed order to accounted');

$reverseRetry=$core->settlements()->reverse((int)$final['settlement_id'],'retry reason','m510-reverse-final-2',$cashier);
m510_assert(!empty($reverseRetry['idempotent'])&&(int)$reverseRetry['settlement_id']===(int)$reversal['settlement_id'],'receipt reversal retry created a second reversal');

$pdo->prepare("UPDATE table_sessions SET status='closed',live_table_guard=NULL,ended_at=NOW(),closed_by_user_id=?,ended_reason='test' WHERE id=?")
    ->execute([$admin['id'],$sessionId]);

$pdo->beginTransaction();
$old=$core->financialPeriodIdentity()->forDateTx('2026-03-20',(int)$admin['id']);
$pdo->commit();
$oldId=(int)$old['id'];
$pdo->prepare(
    "INSERT INTO settlement_records(session_id,financial_period_id,invoice_number,invoice_snapshot_json,destination,table_name_snapshot,
     subtotal,discount,taxable_amount,tax_amount,total,status,actor_user_id,request_id,request_fingerprint,settlement_kind,closes_session,
     remaining_subtotal,remaining_discount,remaining_tax,remaining_total,allocation_version,settled_at,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot)
     VALUES(?,?,?,'{}','direct','Historical',1000,100,900,90,990,'completed',?,?,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','full',1,0,0,0,0,2,'2026-03-20 12:00:00','2026-03-20','shift_1','صبح','04:00')"
)->execute([$sessionId,$oldId,'I-1404-999998',$admin['id'],'m510-historical-settlement']);
$core->expenses()->create([
    'category_key'=>'other','amount'=>200,'occurred_at'=>'2026-03-20 13:00:00','source_request_id'=>'m510-old-expense',
],$admin);

$closed=$core->financialPeriodClose()->close($oldId,[
    'paired'=>true,'known'=>true,'counts'=>['pending_sync'=>0,'needs_review'=>0,'committed'=>1,'rejected'=>0],'blocking'=>0
],'',$admin);
m510_assert(!empty($closed['closed'])&&empty($closed['idempotent']),'Financial Period final close did not activate with Settlement owner');
m510_assert((int)$closed['summary']['sales_amount']===900&&(int)$closed['summary']['tax_amount']===90&&(int)$closed['summary']['total_amount']===990,'period close Settlement summary drifted');
m510_assert((int)$closed['summary']['general_expenses']['net_amount']===200,'period close Expense summary drifted');
$oldStatus=(string)$pdo->query("SELECT status FROM financial_periods WHERE id={$oldId}")->fetchColumn();
m510_assert($oldStatus==='closed','Financial Period status did not close after canonical summary');

$closedRetry=$core->financialPeriodClose()->close($oldId,[
    'paired'=>false,'known'=>true,'counts'=>[],'blocking'=>0
],'',$admin);
m510_assert(!empty($closedRetry['idempotent']),'Financial Period close retry was not idempotent');

$settlementSource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Finance/SettlementService.php');
m510_assert(!preg_match('/print_jobs/i',$settlementSource),'Settlement owner pulled Printing side effects forward');
m510_assert(str_contains($settlementSource,'request_fingerprint')&&str_contains($settlementSource,'settlement_record_lines'),'Settlement lost request fingerprint or itemized line owner');

fwrite(STDOUT,"Local M5.10 Settlement/Reconciliation self-test: OK\n");
