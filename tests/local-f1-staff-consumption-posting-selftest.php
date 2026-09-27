<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\StaffConsumption\StaffConsumptionException;

function f13_fail(string $m): never { fwrite(STDERR,$m.PHP_EOL); exit(1); }
function f13_assert(bool $c,string $m): void { if(!$c)f13_fail($m); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-f13-'.bin2hex(random_bytes(4))],
    'db'=>[
        'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),
        'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
        'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),
        'charset'=>'utf8mb4','user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),
    ],
]);
$core->migrations()->migrate();$pdo=$core->database();
$pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('business_day_cutoff','04:00') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");

$u=$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');
$u->execute(['f13-self',password_hash('x',PASSWORD_DEFAULT),'F13 Self','operator']);$selfUserId=(int)$pdo->lastInsertId();
$u->execute(['f13-manager',password_hash('x',PASSWORD_DEFAULT),'F13 Manager','operator']);$managerId=(int)$pdo->lastInsertId();
$cap=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');
$cap->execute([$selfUserId,'staff_consumption_self']);
$cap->execute([$managerId,'staff_consumption_proxy']);
$cap->execute([$managerId,'staff_benefit_manage']);
$selfUser=$core->identityRepository()->findActiveById($selfUserId);$manager=$core->identityRepository()->findActiveById($managerId);
f13_assert(is_array($selfUser)&&is_array($manager),'test users missing');

$p=$pdo->prepare('INSERT INTO personnel(display_name,linked_user_id,personnel_code,job_title,active) VALUES(?,?,?,?,1)');
$p->execute(['F13 Self Personnel',$selfUserId,'F13-SELF','Bar']);$selfPersonnelId=(int)$pdo->lastInsertId();
$p->execute(['F13 No Login Personnel',null,'F13-NOLOGIN','Service']);$proxyPersonnelId=(int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('f13','F13','guest_staff',10,1)");$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('f13-main','F13 Main','active',10)");$menuId=(int)$pdo->lastInsertId();
$item=$pdo->prepare("INSERT INTO items(item_code,category_id,name,price,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order) VALUES(?,?,?,?,1,1,0,0,'menu_item',1,'none',10)");
$item->execute(['F13-MEAL',$categoryId,'F13 Meal',200000]);$itemId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$itemId]);

$policy=$core->staffBenefitManagement()->savePolicy(['policy_key'=>'f13-free','name'=>'F13 Free','active'=>true,'is_default'=>true,'priority'=>10],$manager);
$core->staffBenefitManagement()->saveRule(['policy_id'=>(int)$policy['id'],'scope_type'=>'all','benefit_type'=>'free','priority'=>10,'active'=>true],$manager);

$post=$core->staffConsumptionPosting();
$self=$post->postSelf([
    'request_token'=>'f13-self-request-000001','occurred_at'=>'2026-09-27 12:30:00',
    'items'=>[['id'=>$itemId,'quantity'=>2,'expected_price'=>200000]],
],$selfUser);
f13_assert($self['duplicate']===false,'self posting unexpectedly duplicate');
f13_assert((int)$self['consumer_personnel_id']===$selfPersonnelId&&(int)$self['recorded_by_user_id']===$selfUserId,'self consumer/recorder identity drifted');
f13_assert((int)$self['menu_value_amount']===400000&&(int)$self['benefit_amount']===400000&&(int)$self['payable_amount']===0&&!empty($self['zero_payable']),'zero-payable benefit posting failed');
$stmt=$pdo->prepare('SELECT table_id,session_id,order_source,order_context,status,total_amount FROM orders WHERE id=?');$stmt->execute([(int)$self['order_id']]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
f13_assert(is_array($order)&&$order['table_id']===null&&$order['session_id']===null&&(string)$order['order_source']==='staff'&&(string)$order['order_context']==='staff_consumption'&&(string)$order['status']==='accounted','staff order is not canonical non-table accounted order');
$count=(int)$pdo->query('SELECT COUNT(*) FROM staff_consumptions')->fetchColumn();$orderCount=(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$retry=$post->postSelf(['request_token'=>'f13-self-request-000001','items'=>[['id'=>$itemId,'quantity'=>1]]],$selfUser);
f13_assert($retry['duplicate']===true&&(int)$retry['consumption_id']===(int)$self['consumption_id'],'self retry not idempotent');
f13_assert((int)$pdo->query('SELECT COUNT(*) FROM staff_consumptions')->fetchColumn()===$count&&(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$orderCount,'idempotent retry created duplicate rows');

$conflict=false;
try{$post->postForPersonnel($proxyPersonnelId,['request_token'=>'f13-self-request-000001','items'=>[['id'=>$itemId,'quantity'=>1]]],$manager);}catch(StaffConsumptionException $e){$conflict=$e->errorCode==='idempotency_conflict';}
f13_assert($conflict,'cross-consumer/recorder token reuse accepted');

$proxy=$post->postForPersonnel($proxyPersonnelId,[
    'request_token'=>'f13-proxy-request-00001','occurred_at'=>'2026-09-27 12:40:00',
    'runtime_override'=>['scope_type'=>'all','benefit_type'=>'none','reason'=>'F13 payable path'],
    'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>200000]],
],$manager);
f13_assert((int)$proxy['consumer_personnel_id']===$proxyPersonnelId&&(int)$proxy['recorded_by_user_id']===$managerId,'proxy consumer/recorder identity drifted');
f13_assert((int)$proxy['payable_amount']===200000&&empty($proxy['zero_payable']),'proxy payable snapshot failed');
$linked=$pdo->prepare('SELECT linked_user_id FROM personnel WHERE id=?');$linked->execute([$proxyPersonnelId]);f13_assert($linked->fetchColumn()===null,'proxy test personnel unexpectedly has login');
$ledger=(int)$pdo->query('SELECT COUNT(*) FROM staff_account_ledger')->fetchColumn();f13_assert($ledger===1,'F1.4 integration must create one payable staff-account charge and no zero-payable charge');
f13_assert(isset($proxy['staff_account_charge_id'])&&(int)$proxy['staff_account_charge_id']>0&&(int)$proxy['staff_account_balance_after']===200000,'F1.4 staff-account charge result missing from posting');
$lines=(int)$pdo->query('SELECT COUNT(*) FROM staff_consumption_lines')->fetchColumn();f13_assert($lines===2,'staff consumption line snapshots missing');
$audit=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='staff_consumption.posted'")->fetchColumn();f13_assert($audit===2,'staff consumption posting audit missing or duplicated');

fwrite(STDOUT,"F1.3 Staff Consumption posting DB self-test: OK\n");
