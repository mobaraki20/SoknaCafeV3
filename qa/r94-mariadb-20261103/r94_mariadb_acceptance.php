<?php
declare(strict_types=1);
// CI ONLY.  No production connection; database provisioned by GitHub Actions service.
$root=dirname(__DIR__, 2);
require $root.'/local/bootstrap.php';
$checks=[];
function ck(bool $ok,string $label):void{
 global $checks;
 $checks[]=['name'=>$label,'pass'=>$ok];
 if(!$ok)throw new RuntimeException('FAILED: '.$label);
 echo "PASS ".$label."\n";
}
try{
 ck(in_array('mysql',PDO::getAvailableDrivers(),true),'PHP PDO MySQL available');
 $config=[
  'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-r94-ci-'.bin2hex(random_bytes(5))],
  'db'=>[
   'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),
   'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
   'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_r94_qa'),
   'charset'=>'utf8mb4',
   'user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna_ci'),
   'pass'=>(string)getenv('SOKNA_TEST_DB_PASS'),
  ]
 ];
 $core=sokna_local_bootstrap($config);
 $pdo=$core->database();
 ck($pdo instanceof PDO,'R94 original Local Database class connects MariaDB');
 $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
 ck(stripos($version,'MariaDB')!==false,'server is real MariaDB: '.$version);
 $offset=(string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
 ck(preg_match('/^[+-]\\d{2}:\\d{2}$/',$offset)===1,'Iran DB session timezone explicit: '.$offset);
 $catalog=array_keys($core->migrations()->catalog());
 ck(count($catalog)>=70,'R94 full Local migration catalog has at least 70 files');
 $applied=$core->migrations()->migrate();
 ck($applied===$catalog,'R94 full SQL migrations applied in order');
 ck($core->migrations()->migrate()===[],'second full migration is idempotent');
 $recorded=$core->migrations()->appliedVersions();
 ck($recorded===$catalog,'migration ledger equals candidate catalog exactly');
 $tableNames=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
 foreach(['schema_migrations','schema_migration_statements','users','user_capabilities','cafe_tables','orders','order_items','table_sessions','settlement_records','subscriber_ledger','inventory_items','inventory_balances','inventory_count_sessions','inventory_count_lines','inventory_supply_receipts','staff_consumptions','staff_account_ledger','public_sync_state'] as $table){
  ck(in_array($table,$tableNames,true),'real table created: '.$table);
 }
 $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['r94-qa-operator',password_hash('ci-only',PASSWORD_DEFAULT),'QA operator','operator']);
 $uid=(int)$pdo->lastInsertId();
 ck($uid>0,'insert synthetic staff user into real MariaDB');
 $pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)')->execute([$uid,'orders_floor']);
 $actor=$core->identityRepository()->findActiveById($uid);
 ck(($actor['username']??'')==='r94-qa-operator','identity authority real DB readback');
 ck(in_array('orders_floor',$core->capabilities()->forUser($actor),true),'capability authority real DB readback');
 $pdo->prepare('UPDATE users SET active=0 WHERE id=?')->execute([$uid]);
 ck($core->identityRepository()->findActiveById($uid)===null,'revoked inactive user unavailable to identity authority');
 echo 'R94 CI MariaDB PASS: '.count($checks).' assertions, '.count($catalog).' migrations, real PDO, no production data.'."\n";
}catch(Throwable $e){
 fwrite(STDERR,'R94 CI MariaDB FAIL: '.get_class($e).': '.$e->getMessage()."\n");
 exit(1);
}