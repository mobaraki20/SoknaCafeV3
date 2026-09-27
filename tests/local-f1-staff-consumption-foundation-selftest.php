<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\StaffConsumption\StaffConsumptionException;

function f11_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function f11_assert(bool $condition,string $message): void { if(!$condition)f11_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-f11-'.bin2hex(random_bytes(4))],
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
foreach(['staff_benefit_policies','staff_benefit_policy_rules','staff_benefit_profiles','staff_benefit_overrides','staff_consumptions','staff_consumption_lines','staff_account_ledger'] as $table)
    f11_assert(in_array($table,$tables,true),"missing {$table}");

$orderColumns=$pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_ASSOC);
$byName=[];foreach($orderColumns as $column)$byName[(string)$column['Field']]=$column;
f11_assert(isset($byName['order_context']),'orders.order_context missing');
f11_assert((string)($byName['table_id']['Null']??'')==='YES','orders.table_id must be nullable for staff consumption');

$insertUser=$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');
$insertUser->execute(['f11-self',password_hash('x',PASSWORD_DEFAULT),'F11 Self','operator']);$selfUserId=(int)$pdo->lastInsertId();
$insertUser->execute(['f11-proxy',password_hash('x',PASSWORD_DEFAULT),'F11 Proxy','operator']);$proxyUserId=(int)$pdo->lastInsertId();
$insertUser->execute(['f11-none',password_hash('x',PASSWORD_DEFAULT),'F11 None','operator']);$noneUserId=(int)$pdo->lastInsertId();
$cap=$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)');
$cap->execute([$selfUserId,'staff_consumption_self']);
$cap->execute([$proxyUserId,'staff_consumption_proxy']);

$person=$pdo->prepare('INSERT INTO personnel(display_name,linked_user_id,personnel_code,job_title,active) VALUES(?,?,?,?,1)');
$person->execute(['Self Person',$selfUserId,'F11-S','Barista']);$selfPersonnelId=(int)$pdo->lastInsertId();
$person->execute(['No Login Person',null,'F11-N','Service']);$noLoginPersonnelId=(int)$pdo->lastInsertId();

$self=$core->identityRepository()->findActiveById($selfUserId);$proxy=$core->identityRepository()->findActiveById($proxyUserId);$none=$core->identityRepository()->findActiveById($noneUserId);
f11_assert(is_array($self)&&is_array($proxy)&&is_array($none),'test identities missing');
f11_assert((int)$core->staffConsumptionFoundation()->selfConsumer($self)['id']===$selfPersonnelId,'self consumer did not resolve linked Personnel');
f11_assert((int)$core->staffConsumptionFoundation()->proxyConsumer($noLoginPersonnelId,$proxy)['id']===$noLoginPersonnelId,'proxy consumer could not resolve Personnel without login');
$denied=false;try{$core->staffConsumptionFoundation()->proxyConsumer($noLoginPersonnelId,$none);}catch(StaffConsumptionException $e){$denied=$e->errorCode==='forbidden';}
f11_assert($denied,'proxy flow did not fail closed without capability');

$pdo->prepare("INSERT INTO orders(public_code,client_token,device_token,table_id,session_id,order_source,order_context,status,customer_note,total_amount,accepted_at,accepted_by_user_id,created_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,?,'staff','staff_consumption','accounted',NULL,0,NOW(),?,?,1,'2026-09-27','shift_1','روز','04:00')")
    ->execute(['F11STAFFORDER0001','f11-staff-order-token-0001',null,null,null,$proxyUserId,$proxyUserId]);
$orderId=(int)$pdo->lastInsertId();
f11_assert($orderId>0,'non-table staff order was rejected');

$rejected=false;
try{
    $pdo->prepare("INSERT INTO orders(public_code,client_token,device_token,table_id,session_id,order_source,order_context,status,customer_note,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,?,'staff','table_service','accounted',NULL,0,2,'2026-09-27','shift_1','روز','04:00')")
        ->execute(['F11BADORDER000001','f11-bad-order-token-000001',null,null,null]);
}catch(PDOException){$rejected=true;}
f11_assert($rejected,'table_service order without table bypassed F1 context invariant');

$pdo->prepare('INSERT INTO staff_benefit_policies(policy_key,name,active,is_default,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?)')
    ->execute(['f11-default','F11 Default',1,1,$proxyUserId,$proxyUserId]);$policyId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO staff_benefit_profiles(personnel_id,policy_id,active,created_by_user_id,updated_by_user_id) VALUES(?,?,1,?,?)')
    ->execute([$noLoginPersonnelId,$policyId,$proxyUserId,$proxyUserId]);
f11_assert((int)($core->staffBenefits()->profileForPersonnel($noLoginPersonnelId)['policy_id']??0)===$policyId,'benefit profile repository failed');

$pdo->prepare("INSERT INTO staff_consumptions(public_code,client_token,order_id,consumer_personnel_id,recorded_by_user_id,benefit_policy_id,status,menu_value_amount,benefit_amount,discount_amount,payable_amount,known_cost_amount,consumer_name_snapshot,calculation_snapshot_json,business_date,business_shift_key) VALUES(?,?,?,?,?,?,'posted',100000,100000,0,0,0,?,?,'2026-09-27','shift_1')")
    ->execute(['F11CONSUMPTION001','f11-consumption-token-0001',$orderId,$noLoginPersonnelId,$proxyUserId,$policyId,'No Login Person',json_encode(['foundation'=>true])]);
$consumptionId=(int)$pdo->lastInsertId();
f11_assert($consumptionId>0,'zero-payable staff consumption document was rejected');
f11_assert($core->staffAccounts()->balanceForPersonnel($noLoginPersonnelId)===0,'zero-payable foundation unexpectedly created debt');

fwrite(STDOUT,"F1.1 Staff Consumption foundation self-test: OK\n");
