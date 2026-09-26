<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Tax\TaxException;
use Sokna\Local\Domain\Tax\TaxService;

function m57_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m57_assert(bool $condition,string $message): void { if(!$condition)m57_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m57-'.bin2hex(random_bytes(4))],
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
foreach(['tax_rate_versions','tax_item_policy_versions'] as $table)m57_assert(in_array($table,$tables,true),"missing {$table}");
foreach(['financial_periods','settlement_records','settlement_record_lines','print_jobs'] as $later)
    m57_assert(!in_array($later,$tables,true),"M5.7 pulled later owner {$later} forward");

m57_assert(TaxService::rateBpsNormalize('10')===1000,'10% basis-point normalization drifted');
m57_assert(TaxService::rateBpsNormalize('10.25%')===1025,'fractional percent normalization drifted');
m57_assert(TaxService::roundAmount(1000,1000)===100,'10% tax calculation drifted');
m57_assert(TaxService::roundAmount(5,1000)===1,'half-up tax rounding drifted');
m57_assert(TaxService::roundAmount(4,1000)===0,'below-half tax rounding drifted');

$invoice=TaxService::calculateInvoiceLines([
    ['order_item_id'=>1,'quantity'=>1,'unit_price'=>1000,'tax_policy_snapshot'=>'inherit_default','tax_rate_bps_snapshot'=>1000],
    ['order_item_id'=>2,'quantity'=>1,'unit_price'=>1000,'tax_policy_snapshot'=>'exempt','tax_rate_bps_snapshot'=>0],
],200);
m57_assert($invoice['subtotal']===2000&&$invoice['discount']===200&&$invoice['net']===1800,'invoice discount allocation drifted');
m57_assert($invoice['taxable']===900&&$invoice['tax']===90&&$invoice['total']===1890,'tax-after-discount calculation drifted');

$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
    ->execute(['m57-admin',password_hash('x',PASSWORD_DEFAULT),'M57 Admin','admin']);
$adminId=(int)$pdo->lastInsertId();
$admin=['id'=>$adminId,'username'=>'m57-admin','display_name'=>'M57 Admin','role'=>'admin','active'=>1];
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
    ->execute(['m57-user',password_hash('x',PASSWORD_DEFAULT),'M57 User','operator']);
$userId=(int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('m57','M57','guest_staff',10,1)");
$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('m57-main','M57 Main','active',10)");
$menuId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);

$itemDefault=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Tax Default','price'=>1000,'staff_only'=>false,'takeaway_allowed'=>true,
    'preparation_station'=>'none','sellable_kind'=>'service_item',
]);
$itemExempt=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Tax Exempt','price'=>1000,'staff_only'=>false,'takeaway_allowed'=>true,
    'preparation_station'=>'none','sellable_kind'=>'service_item',
]);
$itemCustom=$core->sellables()->create([
    'category_id'=>$categoryId,'name'=>'Tax Custom','price'=>1000,'staff_only'=>false,'takeaway_allowed'=>true,
    'preparation_station'=>'none','sellable_kind'=>'service_item',
]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?),(?,?),(?,?)')
    ->execute([$menuId,$itemDefault,$menuId,$itemExempt,$menuId,$itemCustom]);

$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES('Tax Table',57,'T57','m57-table',1,1)");
$tableId=(int)$pdo->lastInsertId();

$legacy=$core->orders()->commit([
    'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$tableId,'client_token'=>'m57-order-legacy-0001',
    'items'=>[['id'=>$itemDefault,'quantity'=>1,'expected_price'=>1000]],
]);
$legacyLine=(array)$pdo->query("SELECT tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id,tax_item_policy_version_id FROM order_items WHERE order_id=".(int)$legacy['order_id'])->fetch(PDO::FETCH_ASSOC);
m57_assert($legacyLine['tax_policy_snapshot']==='disabled'&&(int)$legacyLine['tax_rate_bps_snapshot']===0&&$legacyLine['tax_rate_version_id']===null,'Tax-disabled order did not retain legacy semantics');

$initialAt=date('Y-m-d H:i:s',time()-120);
$rate1=$core->tax()->createRateVersion(['rate_percent'=>'10','effective_from'=>$initialAt],$admin);
$core->tax()->createItemPolicyVersion([
    'item_id'=>$itemExempt,'policy'=>'exempt','effective_from'=>$initialAt,
],$admin);
$core->tax()->createItemPolicyVersion([
    'item_id'=>$itemCustom,'policy'=>'custom_rate','custom_rate_percent'=>'5','effective_from'=>$initialAt,
],$admin);
$enabled=$core->tax()->setEnabled(true,$admin);
m57_assert(!empty($enabled['enabled']),'Tax did not enable after initial rate');

$order1=$core->orders()->commit([
    'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$tableId,'client_token'=>'m57-order-tax-000001',
    'items'=>[
        ['id'=>$itemDefault,'quantity'=>1,'expected_price'=>1000],
        ['id'=>$itemExempt,'quantity'=>1,'expected_price'=>1000],
        ['id'=>$itemCustom,'quantity'=>1,'expected_price'=>1000],
    ],
]);
m57_assert((int)$order1['total_amount']===3000,'orders.total_amount was reinterpreted as tax-inclusive');
$stmt=$pdo->prepare('SELECT id,item_id,unit_price,quantity,tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id,tax_item_policy_version_id FROM order_items WHERE order_id=? ORDER BY id');
$stmt->execute([(int)$order1['order_id']]);$lines=$stmt->fetchAll(PDO::FETCH_ASSOC);
$byItem=[];foreach($lines as $line)$byItem[(int)$line['item_id']]=$line;
m57_assert($byItem[$itemDefault]['tax_policy_snapshot']==='inherit_default'&&(int)$byItem[$itemDefault]['tax_rate_bps_snapshot']===1000,'default Tax snapshot wrong');
m57_assert($byItem[$itemExempt]['tax_policy_snapshot']==='exempt'&&(int)$byItem[$itemExempt]['tax_rate_bps_snapshot']===0,'exempt Tax snapshot wrong');
m57_assert($byItem[$itemCustom]['tax_policy_snapshot']==='custom_rate'&&(int)$byItem[$itemCustom]['tax_rate_bps_snapshot']===500,'custom Tax snapshot wrong');
m57_assert((int)$byItem[$itemDefault]['tax_rate_version_id']===(int)$rate1['id'],'default rate owner version missing');

$calcLines=array_map(static fn(array $line):array=>[
    'order_item_id'=>(int)$line['id'],'quantity'=>(int)$line['quantity'],'unit_price'=>(int)$line['unit_price'],
    'tax_policy_snapshot'=>(string)$line['tax_policy_snapshot'],'tax_rate_bps_snapshot'=>(int)$line['tax_rate_bps_snapshot'],
],$lines);
$taxed=TaxService::calculateInvoiceLines($calcLines,300);
m57_assert($taxed['subtotal']===3000&&$taxed['discount']===300&&$taxed['net']===2700,'three-line tax invoice net wrong');
m57_assert($taxed['taxable']===1800&&$taxed['tax']===135&&$taxed['total']===2835,'mixed default/exempt/custom tax wrong');

$secondAt=date('Y-m-d H:i:s',time()-1);
$rate2=$core->tax()->createRateVersion(['rate_percent'=>'20','effective_from'=>$secondAt],$admin);
$core->tax()->createItemPolicyVersion([
    'item_id'=>$itemCustom,'policy'=>'custom_rate','custom_rate_percent'=>'7','effective_from'=>$secondAt,
],$admin);
$order2=$core->orders()->commit([
    'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$tableId,'client_token'=>'m57-order-tax-000002',
    'items'=>[
        ['id'=>$itemDefault,'quantity'=>1,'expected_price'=>1000],
        ['id'=>$itemCustom,'quantity'=>1,'expected_price'=>1000],
    ],
]);
$stmt=$pdo->prepare('SELECT item_id,tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id FROM order_items WHERE order_id=? ORDER BY id');
$stmt->execute([(int)$order2['order_id']]);$newLines=$stmt->fetchAll(PDO::FETCH_ASSOC);
m57_assert((int)$newLines[0]['tax_rate_bps_snapshot']===2000&&(int)$newLines[0]['tax_rate_version_id']===(int)$rate2['id'],'new default rate was not used for new line');
m57_assert((int)$newLines[1]['tax_rate_bps_snapshot']===700,'new custom policy was not used for new line');

$stmt=$pdo->prepare('SELECT item_id,tax_policy_snapshot,tax_rate_bps_snapshot,tax_rate_version_id FROM order_items WHERE order_id=? ORDER BY id');
$stmt->execute([(int)$order1['order_id']]);$oldAgain=$stmt->fetchAll(PDO::FETCH_ASSOC);
m57_assert((int)$oldAgain[0]['tax_rate_bps_snapshot']===1000&&(int)$oldAgain[0]['tax_rate_version_id']===(int)$rate1['id'],'rate change rewrote historical order Tax snapshot');
m57_assert((int)$oldAgain[2]['tax_rate_bps_snapshot']===500,'policy change rewrote historical custom Tax snapshot');

$pdo->prepare(
    "INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,started_at,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot)
     VALUES(?,?,'active',?,?,NOW(),CURDATE(),'shift_1','صبح','04:00')"
)->execute(['m57-live-session',$tableId,$tableId,$userId]);
$sessionId=(int)$pdo->lastInsertId();
$core->orders()->commit([
    'source'=>'staff','actor_user_id'=>$userId,'table_id'=>$tableId,'session_id'=>$sessionId,'client_token'=>'m57-order-live-000001',
    'items'=>[['id'=>$itemDefault,'quantity'=>1,'expected_price'=>1000]],
]);
$toggleBlocked=false;
try{$core->tax()->setEnabled(false,$admin);}catch(TaxException $e){$toggleBlocked=$e->errorCode==='live_accounts';}
m57_assert($toggleBlocked,'Tax module toggled while a live account had order rows');

$migration=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/database/migrations/0009_m5_tax.sql');
m57_assert(!preg_match('/CREATE TABLE IF NOT EXISTS\s+settlement_/i',$migration),'Tax slice invented Settlement authority early');
m57_assert(str_contains($migration,"VALUES('module.tax.enabled','0')"),'Tax did not default disabled');
$orderSource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Orders/OrderCommitService.php');
m57_assert(str_contains($orderSource,'tax_policy_snapshot')&&str_contains($orderSource,'orderLineSnapshotTx'),'canonical Order insert is missing immutable Tax snapshot');
m57_assert(!preg_match('/settlement_|print_/i',$orderSource),'Tax integration pulled Settlement/Printing side effects into Order commit');

fwrite(STDOUT,"Local M5.7 Tax owner/order-snapshot self-test: OK\n");
