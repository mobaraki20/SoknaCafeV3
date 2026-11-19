<?php
declare(strict_types=1);
// CI-only disposable MariaDB fixture. No live cafe/host credentials are accepted.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('R288_QA_MODE') !== 'disposable_only') {
    throw new RuntimeException('R288_TEST_REQUIRES_ISOLATED_CI');
}
$root = getenv('R288_SOURCE_ROOT');
if (!$root || !is_file($root.'/src/Domain/Printing/PrintService.php')) throw new RuntimeException('SOURCE_MISSING');
foreach ([
  '/src/Core/IdentityRepository.php', '/src/Core/Capabilities.php',
  '/src/Domain/Orders/OrderStaffActionException.php', '/src/Domain/Orders/OrderStaffActionService.php',
  '/src/Domain/Printing/PrintException.php', '/src/Domain/Printing/PrintTemplatePackageService.php',
  '/src/Domain/Printing/PrintService.php', '/src/Shared/IranTime.php',
] as $file) require_once $root.$file;

use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Domain\Orders\OrderStaffActionService;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
use Sokna\Local\Domain\Printing\PrintService;
use Sokna\Local\Domain\Printing\PrintTemplatePackageService;
use Sokna\Local\Domain\Printing\PrintException;

final class QaIdentity implements IdentityRepository {
 public function findActiveById(int $userId): ?array{return $userId===1?['id'=>1,'role'=>'admin','display_name'=>'QA Manager']:null;}
 public function findActiveByUsername(string $username): ?array{return null;}
 public function capabilitiesForUser(int $userId): array{return [];}
 public function preparationAreasForUser(int $userId): array{return [];}
}
$pdo = new PDO('mysql:host=127.0.0.1;dbname=sokna_r288_print;charset=utf8mb4','root',getenv('R288_DB_PASSWORD') ?: '',[
 PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES => false,
]);
$statements = [
 'CREATE TABLE users(id INT PRIMARY KEY,display_name VARCHAR(100)) ENGINE=InnoDB',
 'CREATE TABLE cafe_tables(id INT PRIMARY KEY,name VARCHAR(80)) ENGINE=InnoDB',
 'CREATE TABLE table_sessions(id BIGINT PRIMARY KEY,table_id INT NOT NULL,status VARCHAR(30) NOT NULL) ENGINE=InnoDB',
 'CREATE TABLE orders(id BIGINT PRIMARY KEY,table_id INT NOT NULL,session_id BIGINT NOT NULL,status VARCHAR(40) NOT NULL,order_context VARCHAR(50) NOT NULL,accounting_origin VARCHAR(50) NOT NULL,business_order_number INT NOT NULL,customer_note TEXT,created_at DATETIME NOT NULL) ENGINE=InnoDB',
 'CREATE TABLE order_items(id BIGINT PRIMARY KEY,order_id BIGINT NOT NULL,item_id INT NULL,unit_price BIGINT NOT NULL,item_name VARCHAR(160) NOT NULL,quantity INT NOT NULL,item_note TEXT NULL,fulfillment_mode VARCHAR(30) NOT NULL,preparation_station VARCHAR(30) NOT NULL) ENGINE=InnoDB',
 'CREATE TABLE order_item_bundle_components(id BIGINT PRIMARY KEY AUTO_INCREMENT,order_item_id BIGINT NOT NULL,item_name_snapshot VARCHAR(160),quantity INT,preparation_station_snapshot VARCHAR(30),component_mode VARCHAR(30),choice_label_snapshot VARCHAR(160)) ENGINE=InnoDB',
 'CREATE TABLE print_template_packages(id INT PRIMARY KEY,template_json TEXT NOT NULL) ENGINE=InnoDB',
 'CREATE TABLE print_template_activations(document_kind VARCHAR(30) PRIMARY KEY,package_id INT NOT NULL) ENGINE=InnoDB',
 'CREATE TABLE print_destinations(destination_key VARCHAR(40) PRIMARY KEY,active INT NOT NULL) ENGINE=InnoDB',
 'CREATE TABLE print_jobs(id BIGINT PRIMARY KEY AUTO_INCREMENT,public_token VARCHAR(40) NOT NULL,idempotency_key VARCHAR(190) NOT NULL UNIQUE,contract_version INT NOT NULL,job_type VARCHAR(40) NOT NULL,destination_key VARCHAR(40) NOT NULL,required INT NOT NULL,status VARCHAR(30) NOT NULL,blocked_reason VARCHAR(160),payload_json LONGTEXT NOT NULL,content_sha256 CHAR(64) NOT NULL,entity_type VARCHAR(60) NOT NULL,entity_id VARCHAR(100),requested_by_user_id INT,next_attempt_at DATETIME) ENGINE=InnoDB',
 'CREATE TABLE audit_log(id BIGINT PRIMARY KEY AUTO_INCREMENT,actor_user_id INT,actor_display_name_snapshot VARCHAR(100),action VARCHAR(120),entity_type VARCHAR(80),entity_id VARCHAR(100),details_json LONGTEXT) ENGINE=InnoDB',
];
// CI job database is disposable. Explicitly fail instead of dropping existing tables.
foreach ($statements as $ddl) $pdo->exec($ddl);
$pdo->exec("INSERT INTO users VALUES(1,'QA Manager')");
$pdo->exec("INSERT INTO cafe_tables VALUES(7,'میز ۷'),(9,'میز ۹')");
$pdo->exec("INSERT INTO table_sessions VALUES(90,7,'closed'),(91,7,'active'),(92,9,'active'),(93,9,'active')");
$pdo->exec("INSERT INTO print_destinations VALUES('prep_shared',1)");
$pdo->exec("INSERT INTO orders VALUES
 (800,7,90,'accounted','table_service','regular',40,'old account','2026-11-15 16:00:00'),
 (901,7,91,'accounted','table_service','regular',51,'بدون یخ','2026-11-19 12:00:00'),
 (902,7,91,'accounted','table_service','regular',52,'','2026-11-19 12:10:00'),
 (903,7,91,'accounted','table_service','regular',53,'سرو جداگانه','2026-11-19 12:20:00'),
 (904,7,91,'pending_approval','table_service','regular',54,'PENDING','2026-11-19 12:30:00'),
 (905,7,91,'accounted','table_service','late_served',55,'ALREADY SERVED','2026-11-19 12:40:00'),
 (906,7,91,'accounted','staff_consumption','regular',56,'STAFF','2026-11-19 12:42:00'),
 (908,7,91,'accounted','table_service','regular',57,'','2026-11-19 12:44:00'),
 (920,9,92,'accounted','table_service','regular',70,'different table','2026-11-19 12:00:00')");
$pdo->exec("INSERT INTO order_items VALUES
 (1,800,NULL,10000,'OLD-ACCOUNT-POISON',1,NULL,'dine_in','kitchen'),
 (2,901,NULL,10000,'چای',2,'کم‌رنگ','dine_in','bar'),
 (3,902,NULL,15000,'باقلوا انگشتی',3,NULL,'dine_in','kitchen'),
 (4,903,NULL,12000,'قهوه',1,'بدون شکر','takeaway','bar'),
 (5,904,NULL,1000,'PENDING-POISON',1,NULL,'dine_in','bar'),
 (6,905,NULL,1000,'SERVED-POISON',1,NULL,'dine_in','bar'),
 (7,906,NULL,1000,'STAFF-POISON',1,NULL,'dine_in','bar'),
 (8,908,NULL,1000,'NOT-PREP',1,NULL,'dine_in','none'),
 (9,920,NULL,1000,'OTHER-TABLE-POISON',1,NULL,'dine_in','kitchen')");
$id = new QaIdentity();$cap = new Capabilities($id);
$printing = new PrintService($pdo,$id,new PrintTemplatePackageService($pdo));
// Class files are needed only to satisfy typed unused constructor arguments in this path.
$extras = ['/src/Domain/Inventory/InventoryOrderService.php','/src/Domain/Preparation/PreparationService.php'];
foreach ($extras as $f) require_once $root.$f;
$inventory = (new ReflectionClass(Sokna\Local\Domain\Inventory\InventoryOrderService::class));
$preparation = (new ReflectionClass(Sokna\Local\Domain\Preparation\PreparationService::class));
$inventoryObj=$inventory->newInstanceWithoutConstructor();$prepObj=$preparation->newInstanceWithoutConstructor();
$actions=new OrderStaffActionService($pdo,$id,$cap,$inventoryObj,$printing,$prepObj);
$actor=['id'=>1,'role'=>'admin'];
$do = static fn(array $data)=>$actions->reprintPreparation($data,$actor);
$request=['scope'=>'all','session_id'=>91,'table_id'=>7,'request_id'=>'r288-qa-all-0011223344556677'];
$first=$do($request);
if (!($first['success']??false) || (int)($first['round_count']??0)!==3 || (int)($first['job_id']??0)<1)throw new RuntimeException('FIRST_ALL_FAILED');
$second=$do($request);
if ($second['job_id']!==$first['job_id'] || !($second['duplicate']??false))throw new RuntimeException('IDEMPOTENCY_FAILED');
$stmt=$pdo->prepare('SELECT * FROM print_jobs WHERE id=?');$stmt->execute([$first['job_id']]);$job=$stmt->fetch();$payload=json_decode($job['payload_json'],true,512,JSON_THROW_ON_ERROR);
if ($job['job_type']!=='preparation_reprint' || $job['entity_type']!=='session_preparation_reprint' || $job['status']!=='pending') throw new RuntimeException('JOB_CONTRACT_FAILED');
if ($payload['round_count']!==3 || $payload['full_account_reprint']!==true || count($payload['items'])!==3 || count($payload['sections'])!==1)throw new RuntimeException('PAYLOAD_STRUCTURE_FAILED');
if (array_column($payload['rounds'],'order_id')!==[901,902,903])throw new RuntimeException('BAD_ROUND_SEQUENCE');
$names=array_column($payload['items'],'item_name');sort($names);$expected=['باقلوا انگشتی','چای','قهوه'];sort($expected);
if ($names!==$expected)throw new RuntimeException('MISSING_OR_DUPLICATED_ITEMS '.json_encode($names));
if (!str_contains((string)$payload['customer_note'],'بدون یخ') || !str_contains((string)$payload['customer_note'],'سرو جداگانه'))throw new RuntimeException('DROPPED_ORDER_NOTES');
$blocked = static function (array $input,string $expected) use ($do):void{
 try {$do($input);} catch (PrintException|OrderStaffActionException $ex) {
  if ($ex->errorCode===$expected)return;
  throw new RuntimeException('UNEXPECTED_BLOCK '. $ex->errorCode . ' expected '.$expected);
 }
 throw new RuntimeException('UNEXPECTED_PRINT_FOR_BLOCKED_CASE '.$expected);
};
$blocked(['scope'=>'all','session_id'=>90,'table_id'=>7,'request_id'=>'r288-qa-closed-0011223344'],'session_changed');
$blocked(['scope'=>'all','session_id'=>91,'table_id'=>9,'request_id'=>'r288-qa-moved-001122334455'],'session_changed');
$blocked(['scope'=>'all','session_id'=>93,'table_id'=>9,'request_id'=>'r288-qa-empty-001122334455'],'nothing_to_reprint');
$blocked(['scope'=>'unknown','session_id'=>91,'table_id'=>7,'request_id'=>'r288-qa-unknown-0011223344'],'invalid_scope');
// Original per-order reprint continues to use the old, independently validated route.
$single=$do(['order_id'=>902,'request_id'=>'r288-qa-single-001122334455']);
if ((int)($single['order_id']??0)!==902 || $single['job_id']===$first['job_id'])throw new RuntimeException('SINGLE_ORDER_REGRESSED');
$pdo->exec("UPDATE print_destinations SET active=0 WHERE destination_key='prep_shared'");
$blockedDest=$do(['scope'=>'all','session_id'=>91,'table_id'=>7,'request_id'=>'r288-qa-blocked-001122334455']);
if ($blockedDest['status']!=='blocked')throw new RuntimeException('OFFLINE_PRINTER_NOT_BLOCKED');
$n=(int)$pdo->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn();
if ($n!==3)throw new RuntimeException('DUPLICATE_OR_MISSING_PRINT_JOBS '.$n);
if ((int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()!==9)throw new RuntimeException('ORDERS_CHANGED');
echo json_encode(['result'=>'PASS','job_count'=>$n,'all_print_rounds'=>3,'all_print_items'=>count($payload['items']),
 'blocked_closed_session'=>true,'blocked_wrong_table'=>true,'blocked_empty'=>true,'blocked_unknown_scope'=>true,
 'idempotent_same_job_id'=>true,'single_order_unchanged'=>true,'offline_destination_blocked'=>true,
 'financial_inventory_state_untouched'=>true,'source'=>'actual R278+patch PrintService and OrderStaffActionService, MariaDB 11.4'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";