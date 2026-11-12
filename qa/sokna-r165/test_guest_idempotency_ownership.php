<?php
declare(strict_types=1);
// Uses a disposable MariaDB 11.4 only. The real GuestOrderService executes SQL.
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R165_DISPOSABLE')!=='YES')exit(87);
if($argc!==3 || !in_array($argv[1],['baseline','candidate'],true))exit(64);
$phase=$argv[1];$root=$argv[2];
foreach([
 'src/Domain/Orders/GuestOrderException.php',
 'src/Domain/Orders/BusinessClock.php',
 'src/Domain/Orders/OrderCatalogService.php',
 'src/Domain/Orders/OrderCommitService.php',
 'src/Domain/Orders/GuestOrderService.php',
 'src/Domain/Tax/TaxService.php',
] as $path)require_once $root.'/'.$path;
use Sokna\Local\Domain\Orders\BusinessClock;
use Sokna\Local\Domain\Orders\OrderCatalogService;
use Sokna\Local\Domain\Orders\OrderCommitService;
use Sokna\Local\Domain\Orders\GuestOrderException;
use Sokna\Local\Domain\Orders\GuestOrderService;
use Sokna\Local\Domain\Tax\TaxService;
function need(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('R165_'.$message);echo "PASS $message\n";}
$password=getenv('SOKNA_R165_DB_PASS') ?: 'r165-ci-only';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('DROP DATABASE IF EXISTS sokna_r165_idempotency_ci');
$pdo->exec('CREATE DATABASE sokna_r165_idempotency_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE sokna_r165_idempotency_ci');
$pdo->exec('CREATE TABLE settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE cafe_tables(id INT UNSIGNED PRIMARY KEY,access_token VARCHAR(80) UNIQUE,active TINYINT NOT NULL DEFAULT 1,name VARCHAR(100),code VARCHAR(50)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO cafe_tables VALUES(1,'table-qr-one',1,'میز یک','1'),(2,'table-qr-two',1,'میز دو','2')");
// Exact columns queried by GuestOrderService's real, locked, duplicate path.
$pdo->exec('CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,public_code VARCHAR(32) UNIQUE NOT NULL,client_token VARCHAR(80) UNIQUE NOT NULL,table_id INT UNSIGNED NOT NULL,device_token VARCHAR(80),order_source VARCHAR(20) NOT NULL,status VARCHAR(30) NOT NULL,business_order_number INT UNSIGNED,total_amount BIGINT UNSIGNED,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$insert=$pdo->prepare('INSERT INTO orders(public_code,client_token,table_id,device_token,order_source,status,business_order_number,total_amount) VALUES(?,?,?,?,?,?,?,?)');
$insert->execute(['GUESTX001','token-for-guest-order-00001',1,'device-A','guest','pending_approval',101,50000]);
$insert->execute(['STAFFX002','token-for-staff-order-00002',1,'device-A','staff','accounted',102,90000]);
$clock=new BusinessClock($pdo);
$catalog=new OrderCatalogService($pdo);
$orders=(new ReflectionClass(OrderCommitService::class))->newInstanceWithoutConstructor();
$tax=(new ReflectionClass(TaxService::class))->newInstanceWithoutConstructor();
$svc=new GuestOrderService($pdo,$clock,$catalog,$orders,$tax);
$call=static fn(string $table,string $device,string $token):array=>$svc->submit(['table_token'=>$table,'session_token'=>'old-session-already-closed','device_token'=>$device,'client_token'=>$token,'items'=>[]]);
$legit=$call('table-qr-one','device-A','token-for-guest-order-00001');
need(($legit['success']??false)===true && ($legit['duplicate']??false)===true && ($legit['order_code']??'')==='GUESTX001','GENUINE_RETRY_RETURNS_ORIGINAL_GUEST_RECEIPT');
$cases=[
 ['other_device','table-qr-one','device-B','token-for-guest-order-00001'],
 ['staff_token','table-qr-one','device-A','token-for-staff-order-00002'],
 ['other_table','table-qr-two','device-A','token-for-guest-order-00001'],
];
$failedCount=0;
foreach($cases as [$label,$table,$device,$token]){
  $rejected=false;
  try{$response=$call($table,$device,$token); if($phase==='baseline'&&in_array($label,['other_device','staff_token'],true)){$failedCount++;echo "REPRODUCED $label returned another order's receipt\n";}}
  catch(GuestOrderException $e){$rejected=$e->httpStatus===409&&($e->errorCode==='idempotency_conflict'||($phase==='baseline'&&$e->errorCode==='invalid_order'));}
  if($phase==='candidate')need($rejected,'REJECT_'.$label);
  elseif($label==='other_table')need($rejected,'ALREADY_REJECTS_OTHER_TABLE');
}
if($phase==='baseline')need($failedCount===2,'BASELINE_REPRODUCES_TWO_OWNERSHIP_BYPASSES');
need((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===2,'NO_NEW_DUPLICATE_ORDER_ROWS');
need((int)$pdo->query('SELECT COUNT(*) FROM orders WHERE order_source="guest"')->fetchColumn()===1,'GUEST_ORDER_COUNT_STABLE');
echo "PASS R165_REAL_MARIADB_{$phase}_GUEST_IDEMPOTENCY\n";
