<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\StaffConsumption\StaffConsumptionException;

function f12db_fail(string $m): never { fwrite(STDERR,$m.PHP_EOL); exit(1); }
function f12db_assert(bool $c,string $m): void { if(!$c)f12db_fail($m); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-f12-'.bin2hex(random_bytes(4))],
    'db'=>[
        'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),
        'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
        'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),
        'charset'=>'utf8mb4',
        'user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),
        'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),
    ],
]);
$core->migrations()->migrate();$pdo=$core->database();

$u=$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');
$u->execute(['f12-manager',password_hash('x',PASSWORD_DEFAULT),'F12 Manager','operator']);$managerId=(int)$pdo->lastInsertId();
$u->execute(['f12-none',password_hash('x',PASSWORD_DEFAULT),'F12 None','operator']);$noneId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)')->execute([$managerId,'staff_benefit_manage']);
$manager=$core->identityRepository()->findActiveById($managerId);$none=$core->identityRepository()->findActiveById($noneId);
f12db_assert(is_array($manager)&&is_array($none),'test users missing');

$pdo->prepare('INSERT INTO personnel(display_name,personnel_code,job_title,active) VALUES(?,?,?,1)')->execute(['F12 Person','F12-P','Service']);$personnelId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('f12-drink','Drink','guest_staff',10,1)");$catDrink=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('f12-food','Food','guest_staff',20,1)");$catFood=(int)$pdo->lastInsertId();
$item=$pdo->prepare("INSERT INTO items(item_code,category_id,name,price,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order) VALUES(?,?,?,?,1,1,0,0,'menu_item',1,'other',?)");
$item->execute(['F12-TEA',$catDrink,'Tea',100000,10]);$tea=(int)$pdo->lastInsertId();
$item->execute(['F12-FOOD',$catFood,'Food',300000,20]);$food=(int)$pdo->lastInsertId();

$manage=$core->staffBenefitManagement();
$policy=$manage->savePolicy(['policy_key'=>'f12-default','name'=>'F12 Default','active'=>true,'is_default'=>true,'priority'=>10],$manager);$policyId=(int)$policy['id'];
$manage->saveRule(['policy_id'=>$policyId,'scope_type'=>'all','benefit_type'=>'percent','percent_bps'=>1000,'priority'=>100,'active'=>true],$manager);
$manage->saveRule(['policy_id'=>$policyId,'scope_type'=>'item','scope_id'=>$tea,'benefit_type'=>'fixed','fixed_amount'=>25000,'priority'=>100,'active'=>true],$manager);

$lines=[
 ['item_id'=>$tea,'category_id'=>$catDrink,'item_name'=>'Tea','unit_price'=>100000,'quantity'=>2],
 ['item_id'=>$food,'category_id'=>$catFood,'item_name'=>'Food','unit_price'=>300000,'quantity'=>1],
];
$quote=$core->staffBenefitCalculation()->quote($personnelId,$lines,'2026-09-27 12:00:00',$manager);
f12db_assert($quote['profile_resolution']==='default_policy','default policy resolution failed');
f12db_assert($quote['benefit_amount']===80000,'default/rule benefit calculation failed');

$manage->assignProfile(['personnel_id'=>$personnelId,'policy_id'=>null,'active'=>true,'valid_from'=>'2026-09-01'],$manager);
$noBenefit=$core->staffBenefitCalculation()->quote($personnelId,$lines,'2026-09-27 12:00:00',$manager);
f12db_assert($noBenefit['profile_resolution']==='profile_explicit_no_benefit'&&$noBenefit['benefit_amount']===0,'profile explicit no-benefit did not suppress default');

$manage->assignProfile(['personnel_id'=>$personnelId,'policy_id'=>$policyId,'active'=>true,'valid_from'=>'2026-09-01'],$manager);
$manage->saveOverride(['personnel_id'=>$personnelId,'scope_type'=>'item','scope_id'=>$tea,'benefit_type'=>'none','active'=>true,'valid_from'=>'2026-09-01 00:00:00','reason'=>'F12 exception'],$manager);
$stored=$core->staffBenefitCalculation()->quote($personnelId,$lines,'2026-09-27 12:00:00',$manager);
f12db_assert($stored['lines'][0]['benefit_resolution']['source']==='personnel_override'&&$stored['lines'][0]['benefit_amount']===0,'stored override did not outrank policy');
f12db_assert($stored['lines'][1]['benefit_amount']===30000,'policy fallback for unmatched line failed');

$runtime=$core->staffBenefitCalculation()->quote($personnelId,$lines,'2026-09-27 12:00:00',$manager,[
 'scope_type'=>'all','benefit_type'=>'percent','percent_bps'=>5000,'reason'=>'F12 one-time manager override'
]);
f12db_assert($runtime['benefit_amount']===250000,'runtime override did not outrank stored/policy rules');
f12db_assert((int)$runtime['lines'][0]['benefit_resolution']['actor_user_id']===$managerId,'runtime override actor missing');

$denied=false;try{$core->staffBenefitCalculation()->quote($personnelId,$lines,'2026-09-27 12:00:00',$none,['scope_type'=>'all','benefit_type'=>'free','reason'=>'not allowed']);}catch(StaffConsumptionException $e){$denied=$e->errorCode==='benefit_override_forbidden';}
f12db_assert($denied,'runtime override did not fail closed without capability');

$ledger=(int)$pdo->query('SELECT COUNT(*) FROM staff_account_ledger')->fetchColumn();
f12db_assert($ledger===0,'F1.2 pre-post calculation must not create staff-account ledger entries');
$waivers=(int)$pdo->query("SELECT COUNT(*) FROM staff_account_ledger WHERE entry_type='waiver'")->fetchColumn();
f12db_assert($waivers===0,'F1.2 benefit calculation crossed Benefit/Waiver boundary');
$audit=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'staff_benefit.%'")->fetchColumn();
f12db_assert($audit>=5,'benefit management audit trail missing');

fwrite(STDOUT,"F1.2 Staff Benefit policy/profile/override DB self-test: OK\n");
