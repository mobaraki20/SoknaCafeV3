<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Orders\BusinessClock;
use Sokna\Local\Domain\Orders\OrderCommitException;

function m52_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m52_assert(bool $condition,string $message): void { if(!$condition)m52_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m52-'.bin2hex(random_bytes(4))],
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
foreach(['cafe_tables','table_sessions','orders','order_items','order_business_sequences','order_status_history'] as $table)
    m52_assert(in_array($table,$tables,true),"missing {$table}");
foreach(['settlement_records','print_jobs'] as $later)
    m52_assert(!in_array($later,$tables,true),"post-M5.3 domain leaked into current Local stack: {$later}");

$pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('business_day_cutoff','04:00')");
$clock=new BusinessClock($pdo,'Asia/Tehran');
$a=$clock->assignment('2026-08-10 00:30:00');
m52_assert($a['business_date']==='2026-08-09','business date cutoff drifted');
m52_assert($a['shift_key']==='shift_2','default evening shift drifted');

$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
    ->execute(['m52-user',password_hash('x',PASSWORD_DEFAULT),'M52 User','operator']);
$userId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('m52','M52','guest_staff',10,1)");
$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('m52-main','M52 Main','active',10)");
$menuId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);

$normalId=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Service-like Coffee','price'=>150000,
    'staff_only'=>false,'takeaway_allowed'=>true,'preparation_station'=>'service','sellable_kind'=>'menu_item',
]);
$serviceId=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Explicit Service','price'=>25000,
    'staff_only'=>false,'takeaway_allowed'=>false,'preparation_station'=>'none','sellable_kind'=>'service_item',
]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?),(?,?)')->execute([$menuId,$normalId,$menuId,$serviceId]);

$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Table 1',1,'T1','m52-table-1',1,1)");
$tableId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Table 2',2,'T2','m52-table-2',1,2)");
$table2Id=(int)$pdo->lastInsertId();

$orders=$core->orders();
$token='m52-order-token-000001';
$created=$orders->commit([
    'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$tableId,'client_token'=>$token,
    'occurred_at'=>'2026-08-10 10:00:00',
    'items'=>[
        ['id'=>$normalId,'quantity'=>2,'expected_price'=>150000,'fulfillment_mode'=>'dine_in'],
        ['id'=>$serviceId,'quantity'=>1,'expected_price'=>25000,'fulfillment_mode'=>'dine_in'],
    ],
]);
m52_assert($created['duplicate']===false&&(int)$created['order_number']===1,'first order number invalid');
m52_assert((int)$created['total_amount']===325000,'total invalid');

$stmt=$pdo->prepare('SELECT item_id,sellable_kind_snapshot,preparation_station FROM order_items WHERE order_id=? ORDER BY id');
$stmt->execute([(int)$created['order_id']]);$lines=$stmt->fetchAll(PDO::FETCH_ASSOC);
m52_assert((string)$lines[0]['sellable_kind_snapshot']==='menu_item','sellable kind inferred incorrectly');
m52_assert((string)$lines[1]['sellable_kind_snapshot']==='service_item','explicit service kind lost');
m52_assert((string)$lines[0]['preparation_station']==='cold_bar','legacy station normalization drifted');

$duplicate=$orders->commit([
    'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$tableId,'client_token'=>$token,
    'occurred_at'=>'2026-08-10 10:01:00',
    'items'=>[['id'=>$normalId,'quantity'=>1,'expected_price'=>150000]],
]);
m52_assert($duplicate['duplicate']===true&&(int)$duplicate['order_id']===(int)$created['order_id'],'retry not idempotent');
$seq=(int)$pdo->query("SELECT last_number FROM order_business_sequences WHERE business_date='2026-08-10'")->fetchColumn();
m52_assert($seq===1,'retry advanced sequence');

$conflict=false;
try{
    $orders->commit([
        'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$table2Id,'client_token'=>$token,
        'occurred_at'=>'2026-08-10 10:02:00',
        'items'=>[['id'=>$normalId,'quantity'=>1,'expected_price'=>150000]],
    ]);
}catch(OrderCommitException $e){$conflict=$e->errorCode==='idempotency_conflict';}
m52_assert($conflict,'cross-table token reuse accepted');

$pdo->prepare('UPDATE items SET price=? WHERE id=?')->execute([175000,$normalId]);
$priceRejected=false;
try{
    $orders->commit([
        'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$tableId,'client_token'=>'m52-order-token-000002',
        'occurred_at'=>'2026-08-10 10:03:00',
        'items'=>[['id'=>$normalId,'quantity'=>1,'expected_price'=>150000]],
    ]);
}catch(OrderCommitException $e){$priceRejected=$e->errorCode==='price_changed';}
m52_assert($priceRejected,'stale price accepted');
$seq2=(int)$pdo->query("SELECT last_number FROM order_business_sequences WHERE business_date='2026-08-10'")->fetchColumn();
m52_assert($seq2===1,'rejected order consumed sequence');

$takeawayRejected=false;
try{
    $orders->commit([
        'source'=>'guest','table_id'=>$tableId,'client_token'=>'m52-order-token-000003',
        'occurred_at'=>'2026-08-10 10:04:00',
        'items'=>[['id'=>$serviceId,'quantity'=>1,'expected_price'=>25000,'fulfillment_mode'=>'takeaway']],
    ]);
}catch(OrderCommitException $e){$takeawayRejected=$e->errorCode==='takeaway_not_allowed';}
m52_assert($takeawayRejected,'takeaway guard ignored');

$serviceFile=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Orders/OrderCommitService.php');
m52_assert(!preg_match('/print_|settlement_/i',$serviceFile),'unmigrated Finance/Printing side effect leaked into Orders');

fwrite(STDOUT,"Local M5.2 canonical Orders self-test: OK\n");
