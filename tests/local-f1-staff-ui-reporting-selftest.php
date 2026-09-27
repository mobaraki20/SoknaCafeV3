<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
function f16_fail(string $m):never{fwrite(STDERR,$m.PHP_EOL);exit(1);} function f16_assert(bool $c,string $m):void{if(!$c)f16_fail($m);}
$core=sokna_local_bootstrap(['app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-f16-'.bin2hex(random_bytes(4))],'db'=>[
 'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),'charset'=>'utf8mb4','user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna')]]);
$core->migrations()->migrate();$pdo=$core->database();
$pdo->exec("INSERT INTO settings(setting_key,setting_value) VALUES('business_day_cutoff','04:00'),('module.staff_consumption.enabled','1') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
$u=$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');
$u->execute(['f16-manager',password_hash('x',PASSWORD_DEFAULT),'F16 Manager','operator']);$managerId=(int)$pdo->lastInsertId();
foreach(['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports'] as $cap)$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)')->execute([$managerId,$cap]);
$manager=$core->identityRepository()->findActiveById($managerId);f16_assert(is_array($manager),'manager missing');
$p=$pdo->prepare('INSERT INTO personnel(display_name,linked_user_id,personnel_code,job_title,active) VALUES(?,?,?,?,1)');
$p->execute(['F16 Manager Personnel',$managerId,'F16-MGR','Manager']);$selfId=(int)$pdo->lastInsertId();
$p->execute(['F16 No Login',null,'F16-NOLOGIN','Service']);$proxyId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('f16','F16','guest_staff',10,1)");$categoryId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('f16-main','F16 Main','active',10)");$menuId=(int)$pdo->lastInsertId();
$item=$pdo->prepare("INSERT INTO items(item_code,category_id,name,price,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order) VALUES(?,?,?,?,1,1,0,0,'menu_item',1,'none',10)");
$item->execute(['F16-MEAL',$categoryId,'F16 Meal',200000]);$itemId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId,$categoryId]);$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$itemId]);
$core->staffBenefitManagement()->savePolicy(['policy_key'=>'f16-default','name'=>'F16 Default','priority'=>10,'is_default'=>true,'active'=>true],$manager);
$policyId=(int)$pdo->query("SELECT id FROM staff_benefit_policies WHERE policy_key='f16-default'")->fetchColumn();
$core->staffBenefitManagement()->saveRule(['policy_id'=>$policyId,'scope_type'=>'all','benefit_type'=>'percent','percent_bps'=>5000,'priority'=>10,'active'=>true],$manager);
$snap=$core->staffConsumptionWorkspace()->snapshot($manager);f16_assert(!empty($snap['self_personnel'])&&(int)$snap['self_personnel']['id']===$selfId,'self personnel missing from workspace');f16_assert(count($snap['personnel'])===2,'proxy personnel directory incomplete');f16_assert(count($snap['catalog'])===1,'staff catalog missing');f16_assert(is_array($snap['benefits'])&&count($snap['benefits']['policies'])===1,'benefit management snapshot missing');
$quote=$core->staffConsumptionWorkspace()->quoteSelf(['occurred_at'=>'2026-09-27 12:00:00','items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>200000]]],$manager);f16_assert((int)$quote['quote']['benefit_amount']===100000&&(int)$quote['quote']['payable_amount']===100000,'self quote incorrect');
$self=$core->staffConsumptionPosting()->postSelf(['request_token'=>'f16-self-consume-0001','occurred_at'=>'2026-09-27 12:05:00','items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>200000]]],$manager);
$proxy=$core->staffConsumptionPosting()->postForPersonnel($proxyId,['request_token'=>'f16-proxy-consume-001','occurred_at'=>'2026-09-27 12:10:00','runtime_override'=>['scope_type'=>'all','benefit_type'=>'none','reason'=>'F16 payable'],'items'=>[['id'=>$itemId,'quantity'=>1,'expected_price'=>200000]]],$manager);
f16_assert((int)$self['payable_amount']===100000&&(int)$proxy['payable_amount']===200000,'posting amounts incorrect');
$hist=$core->staffConsumptionWorkspace()->history(null,$manager,20);f16_assert(count($hist['rows'])===2,'manager history incomplete');f16_assert(isset($hist['rows'][0]['recorder_name'])&&isset($hist['rows'][0]['consumer_name_snapshot']),'consumer/recorder history separation missing');
$waiver=$core->staffAccountService()->waiver(['personnel_id'=>$proxyId,'amount'=>50000,'request_id'=>'f16-waiver-0001','occurred_at'=>'2026-09-27 13:00:00','reason'=>'F16 independent waiver'],$manager);f16_assert((string)$waiver['entry_type']==='waiver','waiver failed');
$report=$core->staffConsumptionReports()->report(['from'=>'2026-09-27','to'=>'2026-09-27'],$manager);f16_assert((int)$report['summary']['document_count']===2,'report document count incorrect');f16_assert((int)$report['summary']['menu_value_amount']===400000,'report menu value incorrect');f16_assert((int)$report['summary']['benefit_amount']===100000,'report benefit incorrect');f16_assert((int)$report['summary']['payable_amount']===300000,'report payable incorrect');f16_assert((int)$report['summary']['waiver_amount']===50000,'report waiver is not ledger based');f16_assert(count($report['by_consumer'])===2&&count($report['by_recorder'])===1,'report identity breakdown incorrect');
$cons=$pdo->query('SELECT benefit_amount,payable_amount FROM staff_consumptions WHERE id='.(int)$proxy['consumption_id'])->fetch(PDO::FETCH_ASSOC);f16_assert((int)$cons['benefit_amount']===0&&(int)$cons['payable_amount']===200000,'waiver rewrote consumption snapshot');
$idx=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='staff_consumptions' AND index_name='idx_f16_staff_consumption_report'")->fetchColumn();f16_assert($idx>0,'F1.6 consumption report index missing');
$idx=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='staff_account_ledger' AND index_name='idx_f16_staff_account_report'")->fetchColumn();f16_assert($idx>0,'F1.6 account report index missing');
fwrite(STDOUT,"F1.6 Staff UI/reporting DB self-test: OK\n");
