<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Core\Config;
use Sokna\Local\Domain\Finance\SettlementStateConflict;
use Sokna\Local\Domain\Integrations\AccommodationService;
use Sokna\Local\Domain\Integrations\AccommodationTransport;

function m511_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m511_assert(bool $condition,string $message): void { if(!$condition)m511_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m511-'.bin2hex(random_bytes(4))],
    'db'=>[
        'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),
        'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
        'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),
        'charset'=>'utf8mb4','user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),
    ],
]);
$core->migrations()->migrate();$pdo=$core->database();

$tables=array_map('strval',$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
foreach(['subscribers','subscriber_ledger','accommodation_transfers','center_projection_receipts','center_entitlement_cache'] as $t)m511_assert(in_array($t,$tables,true),"missing {$t}");
foreach(['print_jobs'] as $later)m511_assert(!in_array($later,$tables,true),"M5.11 pulled later owner {$later} forward");

$makeUser=function(string $name,string $role,array $caps)use($pdo):array{
    $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute([$name,password_hash('x',PASSWORD_DEFAULT),$name,$role]);
    $id=(int)$pdo->lastInsertId();$st=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');foreach($caps as $cap)$st->execute([$id,$cap]);
    return ['id'=>$id,'username'=>$name,'display_name'=>$name,'role'=>$role,'active'=>1];
};
$admin=$makeUser('m511-admin','admin',[]);$cashier=$makeUser('m511-cashier','operator',['cashier_accounts','orders_floor']);
$subscriber=$core->subscribers()->create(['name'=>'مشتری تست','mobile'=>'09120000001'],$admin);$subscriberId=(int)$subscriber['id'];
m511_assert($subscriberId>0,'subscriber create failed');

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('m511','M511','guest_staff',10,1)");$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('m511-main','M511 Main','active',10)");$menuId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);
$item=$core->sellables()->create(['category_id'=>$categoryId,'name'=>'Integration Item','price'=>3000,'preparation_station'=>'none','sellable_kind'=>'service_item','staff_only'=>false,'takeaway_allowed'=>true]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$item]);
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Subscriber Table',91,'I91','m511-sub-table',1,1),('Accommodation Table',92,'I92','m511-acc-table',1,2),('Ambiguous Table',93,'I93','m511-amb-table',1,3)");
$table1=(int)$pdo->query("SELECT id FROM cafe_tables WHERE table_number=91")->fetchColumn();$table2=(int)$pdo->query("SELECT id FROM cafe_tables WHERE table_number=92")->fetchColumn();$table3=(int)$pdo->query("SELECT id FROM cafe_tables WHERE table_number=93")->fetchColumn();

$order1=$core->staffQuickOrders()->commit(['table_id'=>$table1,'expected_session_id'=>0,'request_token'=>'m511-order-subscriber-01','items'=>[['id'=>$item,'quantity'=>1,'expected_price'=>3000]]],$cashier);
$session1=(int)$order1['session_id'];$account1=$core->settlements()->account($session1);
$settled=$core->settlements()->settle([
    'session_id'=>$session1,'destination'=>'subscriber','subscriber_id'=>$subscriberId,'mode'=>'full','request_id'=>'m511-subscriber-settle-1',
    'expected_session_id'=>$session1,'expected_remaining_total'=>$account1['remaining_total'],'expected_signature'=>$account1['signature'],
],$cashier);
m511_assert($settled['destination']==='subscriber'&&!empty($settled['closes_session']),'subscriber adapter did not finalize canonical settlement');
$ledger=(array)$pdo->query("SELECT * FROM subscriber_ledger WHERE id=(SELECT subscriber_ledger_entry_id FROM settlement_records WHERE id=".(int)$settled['settlement_id'].")")->fetch(PDO::FETCH_ASSOC);
m511_assert((int)$ledger['subscriber_id']===$subscriberId&&(int)$ledger['amount_delta']===3000&&(int)$ledger['balance_after']===3000,'subscriber invoice ledger drifted');
$retry=$core->settlements()->settle([
    'session_id'=>$session1,'destination'=>'subscriber','subscriber_id'=>$subscriberId,'mode'=>'full','request_id'=>'m511-subscriber-settle-1',
    'expected_session_id'=>$session1,'expected_remaining_total'=>999,'expected_signature'=>str_repeat('a',64),
],$cashier);
m511_assert(!empty($retry['idempotent'])&&(int)$retry['settlement_id']===(int)$settled['settlement_id'],'subscriber lost-ACK retry duplicated settlement');
$rev=$core->settlements()->reverse((int)$settled['settlement_id'],'subscriber correction','m511-subscriber-reverse-1',$cashier);
$balance=(int)$pdo->query("SELECT balance_after FROM subscriber_ledger WHERE subscriber_id={$subscriberId} ORDER BY id DESC LIMIT 1")->fetchColumn();
m511_assert($balance===0&&$rev['settlement_kind']==='reversal','subscriber reversal did not reverse ledger and settlement together');

$transportMode='success';
$fakeTransport=new AccommodationTransport(Config::fromArray(['integrations'=>['accommodation'=>['enabled'=>true]]]),static function(string $action,string $method,array $payload,int $timeout)use(&$transportMode):array{
    if($transportMode==='ambiguous')return ['success'=>false,'code'=>'transport_error','ambiguous'=>true,'retryable'=>true,'message'=>'unknown'];
    return $action==='void'
        ?['success'=>true,'idempotent'=>false,'transaction_id'=>'V-1','original_transaction_id'=>'C-1','tracking_id'=>'T-2']
        :['success'=>true,'idempotent'=>false,'transaction_id'=>'C-1','tracking_id'=>'T-1'];
});
$accommodation=new AccommodationService($pdo,$core->identityRepository(),$core->capabilities(),$core->settlements(),$fakeTransport);
$order2=$core->staffQuickOrders()->commit(['table_id'=>$table2,'expected_session_id'=>0,'request_token'=>'m511-order-accommodation-01','items'=>[['id'=>$item,'quantity'=>1,'expected_price'=>3000]]],$cashier);
$session2=(int)$order2['session_id'];$account2=$core->settlements()->account($session2);
$transfer=$accommodation->prepare($session2,['reservation_code'=>'R-100','guest_name'=>'Guest','room_name'=>'Room 1'],[
    'expected_session_id'=>$session2,'expected_remaining_total'=>$account2['remaining_total'],'expected_signature'=>$account2['signature'],
],$cashier);
$charge=$accommodation->attemptCharge((int)$transfer['id'],$cashier);
m511_assert(!empty($charge['success'])&&!empty($charge['posted'])&&!empty($charge['local']['closes_session']),'Accommodation remote success did not finalize Local canonical settlement');
$posted=(array)$pdo->query("SELECT * FROM accommodation_transfers WHERE id=".(int)$transfer['id'])->fetch(PDO::FETCH_ASSOC);
m511_assert($posted['status']==='posted'&&(int)$posted['local_finalize_pending']===0,'Accommodation local-finalize recovery flag drifted');
$accSettlement=(array)$pdo->query("SELECT * FROM settlement_records WHERE accommodation_transfer_id=".(int)$transfer['id'])->fetch(PDO::FETCH_ASSOC);
m511_assert($accSettlement['destination']==='accommodation'&&(string)$accSettlement['invoice_number']===(string)$posted['invoice_number'],'Accommodation settlement did not preserve pre-issued invoice snapshot');
$genericBlocked=false;try{$core->settlements()->reverse((int)$accSettlement['id'],'bad direct reverse','m511-bad-acc-reverse',$cashier);}catch(SettlementStateConflict $e){$genericBlocked=$e->errorCode==='external_reversal_required';}
m511_assert($genericBlocked,'Accommodation settlement reversed locally before remote void');
$void=$accommodation->attemptVoid((int)$transfer['id'],'guest correction',$cashier);
m511_assert(!empty($void['success'])&&!empty($void['voided']),'Accommodation remote void did not complete');
$voided=(array)$pdo->query("SELECT status,local_reversal_pending FROM accommodation_transfers WHERE id=".(int)$transfer['id'])->fetch(PDO::FETCH_ASSOC);
m511_assert($voided['status']==='voided'&&(int)$voided['local_reversal_pending']===0,'Accommodation local reversal did not close recovery flag');

$order3=$core->staffQuickOrders()->commit(['table_id'=>$table3,'expected_session_id'=>0,'request_token'=>'m511-order-ambiguous-001','items'=>[['id'=>$item,'quantity'=>1,'expected_price'=>3000]]],$cashier);
$session3=(int)$order3['session_id'];$account3=$core->settlements()->account($session3);
$ambTransfer=$accommodation->prepare($session3,['reservation_code'=>'R-AMB','guest_name'=>'Guest 2','room_name'=>'Room 2'],[
    'expected_session_id'=>$session3,'expected_remaining_total'=>$account3['remaining_total'],'expected_signature'=>$account3['signature'],
],$cashier);
$transportMode='ambiguous';$amb=$accommodation->attemptCharge((int)$ambTransfer['id'],$cashier);
m511_assert(empty($amb['success'])&&!empty($amb['ambiguous']),'ambiguous Accommodation result was treated as deterministic failure');
$blocked=false;try{$core->settlements()->settle([
    'session_id'=>$session3,'destination'=>'direct','mode'=>'full','request_id'=>'m511-direct-after-ambiguous',
    'expected_session_id'=>$session3,'expected_remaining_total'=>$account3['remaining_total'],'expected_signature'=>$account3['signature'],
],$cashier);}catch(SettlementStateConflict $e){$blocked=$e->errorCode==='accommodation_transfer_open';}
m511_assert($blocked,'ambiguous Accommodation transfer allowed alternate settlement and double-charge risk');

$projection=$core->centerIntegration()->projection();m511_assert(preg_match('/^[a-f0-9]{64}$/',(string)$projection['source_version'])===1&&count($projection['users'])>=2,'Center projection is not deterministic/canonical');
$core->centerIntegration()->recordProjectionAttempt($projection,'synced');
$centerRow=(array)$pdo->query("SELECT state,user_count FROM center_projection_receipts WHERE source_version='".$projection['source_version']."'")->fetch(PDO::FETCH_ASSOC);
m511_assert($centerRow['state']==='synced'&&(int)$centerRow['user_count']===count($projection['users']),'Center projection receipt missing');
$unknown=$core->centerIntegration()->entitlementState((int)$cashier['id']);m511_assert($unknown['state']==='unknown','Center entitlement must fail closed before fresh remote evidence');
$core->centerIntegration()->cacheEntitlement((int)$cashier['id'],true,'center-user-1',600);
$allowed=$core->centerIntegration()->entitlementState((int)$cashier['id']);m511_assert($allowed['state']==='allow'&&!empty($allowed['fresh']),'Center entitlement cache did not preserve explicit allow evidence');

$settlementSource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Finance/SettlementService.php');
m511_assert(!preg_match('/file_get_contents\(|stream_context_create|Authorization:/i',$settlementSource),'Settlement owner absorbed remote transport implementation');
$accSource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Integrations/AccommodationService.php');
m511_assert(str_contains($accSource,'local_finalize_pending')&&str_contains($accSource,'local_reversal_pending'),'Accommodation recovery state owner missing');

fwrite(STDOUT,"Local M5.11 integration adapters self-test: OK\n");
