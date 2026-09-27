<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\StaffConsumption\StaffConsumptionException;

function f14_fail(string $m): never { fwrite(STDERR,$m.PHP_EOL); exit(1); }
function f14_assert(bool $c,string $m): void { if(!$c)f14_fail($m); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-f14-'.bin2hex(random_bytes(4))],
    'db'=>[
        'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
        'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),'charset'=>'utf8mb4',
        'user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),
    ],
]);
$core->migrations()->migrate();$pdo=$core->database();
$pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('business_day_cutoff','04:00') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");

$u=$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');
$u->execute(['f14-manager',password_hash('x',PASSWORD_DEFAULT),'F14 Manager','operator']);$managerId=(int)$pdo->lastInsertId();
$u->execute(['f14-outsider',password_hash('x',PASSWORD_DEFAULT),'F14 Outsider','operator']);$outsiderId=(int)$pdo->lastInsertId();
$cap=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');
foreach(['staff_consumption_proxy','staff_benefit_manage','staff_account_manage'] as $c)$cap->execute([$managerId,$c]);
$manager=$core->identityRepository()->findActiveById($managerId);$outsider=$core->identityRepository()->findActiveById($outsiderId);
f14_assert(is_array($manager)&&is_array($outsider),'test users missing');

$p=$pdo->prepare('INSERT INTO personnel(display_name,linked_user_id,personnel_code,job_title,active) VALUES(?,?,?,?,1)');
$p->execute(['F14 No Login Personnel',null,'F14-NOLOGIN','Service']);$personnelId=(int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('f14','F14','guest_staff',10,1)");$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('f14-main','F14 Main','active',10)");$menuId=(int)$pdo->lastInsertId();
$item=$pdo->prepare("INSERT INTO items(item_code,category_id,name,price,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order) VALUES(?,?,?,?,1,1,0,0,'menu_item',1,'none',10)");
$item->execute(['F14-MEAL',$categoryId,'F14 Meal',300000]);$itemId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$itemId]);

$post=$core->staffConsumptionPosting();$account=$core->staffAccountService();
$payable=$post->postForPersonnel($personnelId,[
    'request_token'=>'f14-payable-request-0001','occurred_at'=>'2026-09-27 14:00:00',
    'runtime_override'=>['scope_type'=>'all','benefit_type'=>'none','reason'=>'F14 payable'],
    'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>300000]],
],$manager);
f14_assert((int)$payable['payable_amount']===300000&&!empty($payable['staff_account_charge_id']),'payable posting did not create charge');
f14_assert((int)$payable['staff_account_balance_after']===300000,'charge balance incorrect');
$chargeId=(int)$payable['staff_account_charge_id'];
$charge=$pdo->query("SELECT * FROM staff_account_ledger WHERE id={$chargeId}")->fetch(PDO::FETCH_ASSOC);
f14_assert(is_array($charge)&&(string)$charge['entry_type']==='charge'&&(int)$charge['amount_delta']===300000&&(int)$charge['consumption_id']===(int)$payable['consumption_id'],'charge ledger snapshot invalid');
f14_assert((string)$charge['occurred_at']==='2026-09-27 14:00:00'&&(int)$charge['financial_period_id']>0,'charge time/period missing');

$retry=$post->postForPersonnel($personnelId,['request_token'=>'f14-payable-request-0001','items'=>[['id'=>$itemId,'quantity'=>1]]],$manager);
f14_assert($retry['duplicate']===true&&(int)$retry['staff_account_charge_id']===$chargeId,'posting retry lost charge identity');
f14_assert((int)$pdo->query("SELECT COUNT(*) FROM staff_account_ledger WHERE entry_type='charge'")->fetchColumn()===1,'duplicate posting created duplicate charge');

$payment=$account->payment(['personnel_id'=>$personnelId,'amount'=>50000,'payment_method'=>'cash','request_id'=>'f14-payment-0001','occurred_at'=>'2026-09-27 15:00:00','reference'=>'cash-desk'],$manager);
f14_assert((string)$payment['entry_type']==='payment'&&(int)$payment['balance_after']===250000,'payment failed');
$paymentRetry=$account->payment(['personnel_id'=>$personnelId,'amount'=>50000,'payment_method'=>'cash','request_id'=>'f14-payment-0001','occurred_at'=>'2026-09-27 15:00:00'],$manager);
f14_assert(!empty($paymentRetry['idempotent'])&&(int)$paymentRetry['id']===(int)$payment['id'],'payment retry not idempotent');

$over=false;try{$account->payment(['personnel_id'=>$personnelId,'amount'=>300000,'payment_method'=>'cash','request_id'=>'f14-payment-over','occurred_at'=>'2026-09-27 15:01:00'],$manager);}catch(StaffConsumptionException $e){$over=$e->errorCode==='staff_account_balance_underflow';}
f14_assert($over,'overpayment was accepted');

$waiver=$account->waiver(['personnel_id'=>$personnelId,'amount'=>75000,'request_id'=>'f14-waiver-0001','occurred_at'=>'2026-09-27 15:10:00','reason'=>'Manager approved benefit-independent waiver'],$manager);
f14_assert((string)$waiver['entry_type']==='waiver'&&(int)$waiver['balance_after']===175000,'waiver failed');
$cons=$pdo->query('SELECT benefit_amount,payable_amount FROM staff_consumptions WHERE id='.(int)$payable['consumption_id'])->fetch(PDO::FETCH_ASSOC);
f14_assert(is_array($cons)&&(int)$cons['benefit_amount']===0&&(int)$cons['payable_amount']===300000,'waiver rewrote Benefit/Payable snapshot');
$audit=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='staff_account.waiver_recorded'")->fetchColumn();f14_assert($audit===1,'waiver audit missing');

$revPayment=$account->reverse(['entry_id'=>(int)$payment['id'],'request_id'=>'f14-rev-payment','occurred_at'=>'2026-09-27 15:20:00','reason'=>'Cash entry correction'],$manager);
f14_assert((string)$revPayment['entry_type']==='payment_reversal'&&(int)$revPayment['balance_after']===225000&&(string)$revPayment['payment_method']==='cash','payment reversal failed');
$revPaymentRetry=$account->reverse(['entry_id'=>(int)$payment['id'],'request_id'=>'f14-rev-payment-other','reason'=>'retry'],$manager);
f14_assert(!empty($revPaymentRetry['idempotent'])&&(int)$revPaymentRetry['id']===(int)$revPayment['id'],'reversal retry not idempotent');

$revWaiver=$account->reverse(['entry_id'=>(int)$waiver['id'],'request_id'=>'f14-rev-waiver','occurred_at'=>'2026-09-27 15:30:00','reason'=>'Waiver cancelled'],$manager);
f14_assert((string)$revWaiver['entry_type']==='waiver_reversal'&&(int)$revWaiver['balance_after']===300000,'waiver reversal failed');
$revCharge=$account->reverse(['entry_id'=>$chargeId,'request_id'=>'f14-rev-charge','occurred_at'=>'2026-09-27 15:40:00','reason'=>'Consumption charge reversal qualification'],$manager);
f14_assert((string)$revCharge['entry_type']==='charge_reversal'&&(int)$revCharge['balance_after']===0,'charge reversal failed');

$zero=$post->postForPersonnel($personnelId,[
    'request_token'=>'f14-zero-request-000001','occurred_at'=>'2026-09-27 16:00:00',
    'runtime_override'=>['scope_type'=>'all','benefit_type'=>'free','reason'=>'F14 zero payable'],
    'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>300000]],
],$manager);
f14_assert((int)$zero['payable_amount']===0&&$zero['staff_account_charge_id']===null,'zero-payable created Staff Account charge');

$view=$account->account($personnelId,$manager,20);f14_assert((int)$view['balance']===0&&count($view['history'])===6,'account balance/history incorrect');
$forbidden=false;try{$account->account($personnelId,$outsider);}catch(StaffConsumptionException $e){$forbidden=$e->errorCode==='forbidden';}f14_assert($forbidden,'account history ignored staff_account_manage');
$noLogin=$pdo->prepare('SELECT linked_user_id FROM personnel WHERE id=?');$noLogin->execute([$personnelId]);f14_assert($noLogin->fetchColumn()===null,'F14 personnel unexpectedly has Login account');

fwrite(STDOUT,"F1.4 Staff Account DB self-test: OK\n");
