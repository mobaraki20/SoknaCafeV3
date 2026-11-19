<?php
declare(strict_types=1);
// Dedicated disposable MariaDB. Pull the exact functions from the actual Local dashboard.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R292_QA_MODE')!=='disposable_only')throw new RuntimeException('QA_RUNTIME_REQUIRED');
$root=getenv('R292_SOURCE_ROOT');if(!$root||!is_file($root.'/public/index.php'))throw new RuntimeException('SOURCE_MISSING');
$real=file_get_contents($root.'/public/index.php');
$start=strpos($real,'function home_fa_num(');$after=strpos($real,'function home_weekday(', $start);
if($start===false||$after===false)throw new RuntimeException('CANNOT_EXTRACT_REAL_DASHBOARD');
$original=substr($real,$start,$after-$start);
if(!str_contains($original,'function home_open_accounts_snapshot(')||!str_contains($original,'->projectionAccountTx('))throw new RuntimeException('REAL_FUNCTION_UNVERIFIED');
eval($original);
foreach(['/src/Domain/Finance/SettlementException.php','/src/Domain/Tax/TaxService.php','/src/Domain/Finance/SettlementService.php'] as $f)require_once $root.$f;
use Sokna\Local\Domain\Finance\SettlementService;
$p=new PDO('mysql:host=127.0.0.1;dbname=sokna_r292;charset=utf8mb4','root',getenv('R292_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$ddl=[
 'CREATE TABLE cafe_tables(id INT PRIMARY KEY,name VARCHAR(100)) ENGINE=InnoDB',
 'CREATE TABLE table_sessions(id INT PRIMARY KEY,table_id INT,status VARCHAR(20),discount_type VARCHAR(20) NULL,discount_value INT DEFAULT 0) ENGINE=InnoDB',
 'CREATE TABLE orders(id INT PRIMARY KEY,session_id INT,status VARCHAR(40),total_amount BIGINT,accounting_origin VARCHAR(40)) ENGINE=InnoDB',
 'CREATE TABLE order_items(id INT PRIMARY KEY,order_id INT,item_id INT NULL,item_name VARCHAR(100),unit_price BIGINT,quantity INT,line_total BIGINT,item_note VARCHAR(255),tax_policy_snapshot VARCHAR(30),tax_rate_bps_snapshot INT,tax_rate_version_id INT NULL,tax_item_policy_version_id INT NULL) ENGINE=InnoDB',
 'CREATE TABLE settlement_records(id INT PRIMARY KEY,session_id INT,status VARCHAR(30),allocation_version INT,reverses_settlement_id INT NULL,subtotal BIGINT,discount BIGINT,taxable_amount BIGINT,tax_amount BIGINT,total BIGINT,invoice_number VARCHAR(40),destination VARCHAR(30),settled_at DATETIME NULL) ENGINE=InnoDB',
 'CREATE TABLE settlement_record_lines(id INT PRIMARY KEY,settlement_id INT,order_item_id INT,quantity INT,discount_amount BIGINT,tax_amount BIGINT,gross_amount BIGINT,net_amount BIGINT,taxable_amount BIGINT,final_amount BIGINT) ENGINE=InnoDB',
 'CREATE TABLE settlement_followup_events(id INT PRIMARY KEY AUTO_INCREMENT,session_id INT,event_type VARCHAR(50),from_destination VARCHAR(20),to_destination VARCHAR(20),note VARCHAR(255),created_at DATETIME,actor_user_id INT NULL) ENGINE=InnoDB',
 'CREATE TABLE users(id INT PRIMARY KEY,display_name VARCHAR(100)) ENGINE=InnoDB',
 'CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB',
];foreach($ddl as $sql)$p->exec($sql);
$p->exec("INSERT INTO cafe_tables VALUES(1,'میز ۱'),(2,'میز ۲'),(3,'میز ۳'),(4,'میز ۴')");
$p->exec("INSERT INTO table_sessions VALUES(100,1,'active',NULL,0),(200,2,'active','percent',10),(300,3,'closed',NULL,0),(400,4,'active',NULL,0)");
$p->exec("INSERT INTO orders VALUES(10,100,'accounted',30000,'regular'),(20,200,'accounted',60000,'regular'),(30,300,'accounted',50000,'regular')");
$p->exec("INSERT INTO order_items VALUES(1,10,NULL,'چای',10000,3,30000,'','disabled',0,NULL,NULL),(2,20,NULL,'قهوه',30000,2,60000,'','disabled',0,NULL,NULL),(3,30,NULL,'غذا',50000,1,50000,'','disabled',0,NULL,NULL)");
$p->exec("INSERT INTO settlement_records VALUES(501,100,'completed',1,NULL,10000,0,0,0,10000,'I-501','direct',NOW())");
$p->exec("INSERT INTO settlement_record_lines VALUES(601,501,1,1,0,0,10000,10000,0,10000)");
$p->exec("INSERT INTO settings VALUES('payment.final_amount_rial','0')");
$settle=(new ReflectionClass(SettlementService::class))->newInstanceWithoutConstructor();$prop=new ReflectionProperty(SettlementService::class,'pdo');$prop->setValue($settle,$p);
final class QACore{public function __construct(public PDO $pdo,public SettlementService $svc){}public function database():PDO{return $this->pdo;}public function settlements():SettlementService{return $this->svc;}}
$core=new QACore($p,$settle);
$read=fn()=>home_open_accounts_snapshot($core);
$a=$read();if($a!==['amount_toman'=>74000,'account_count'=>2,'rial_display'=>false])throw new RuntimeException('BASELINE_WRONG '.json_encode($a));
if(home_open_money(74000,false)!=='۷۴٬۰۰۰ تومان')throw new RuntimeException('TOMAN_FORMAT_BAD');
$p->exec("UPDATE settings SET setting_value='1' WHERE setting_key='payment.final_amount_rial'");
$b=$read();if($b['amount_toman']!==74000||$b['rial_display']!==true||home_open_money(74000,true)!=='۷۴۰٬۰۰۰ ریال')throw new RuntimeException('RIAL_SCALING_BAD');
$p->exec("INSERT INTO settlement_records VALUES(502,200,'completed',1,NULL,30000,3000,0,0,27000,'I-502','direct',NOW())");
$p->exec("INSERT INTO settlement_record_lines VALUES(602,502,2,1,3000,0,30000,27000,0,27000)");
$c=$read();if($c['amount_toman']!==47000||$c['account_count']!==2)throw new RuntimeException('PARTIAL_PAYMENT_NOT_DEDUCTED '.json_encode($c));
$p->exec("UPDATE table_sessions SET status='closed' WHERE id=200");$closed=$read();if($closed['amount_toman']!==20000||$closed['account_count']!==1)throw new RuntimeException('CLOSED_SESSION_INCLUDED');
$p->exec("UPDATE table_sessions SET status='active' WHERE id=200");
$p->exec("INSERT INTO orders VALUES(40,100,'pending_approval',20000,'regular')");
try{$read();throw new RuntimeException('UNCONFIRMED_MISREPORTED_AS_ZERO');}catch(\Sokna\Local\Domain\Finance\SettlementStateConflict $e){if($e->errorCode!=='unconfirmed_orders')throw $e;}
$p->exec('DELETE FROM orders WHERE id=40');
$p->exec('UPDATE settlement_record_lines SET quantity=4 WHERE id=601');
try{$read();throw new RuntimeException('INVALID_RECEIPT_MISREPORTED_AS_ZERO');}catch(\Sokna\Local\Domain\Finance\SettlementStateConflict $e){if($e->errorCode!=='paid_quantity_invalid')throw $e;}
$p->exec('UPDATE settlement_record_lines SET quantity=1 WHERE id=601');
$final=$read();if($final['amount_toman']!==47000)throw new RuntimeException('RECOVERY_FAILED');
if($p->inTransaction())throw new RuntimeException('LEAKED_TRANSACTION');
if((int)$p->query('SELECT COUNT(*) FROM settlement_records')->fetchColumn()!==2)throw new RuntimeException('REPORT_MUTATED_RECEIPTS');
echo json_encode(['result'=>'PASS','open_accounts_sum_initial_toman'=>74000,'payment_adjusted_toman'=>47000,'closed_session_excluded'=>true,'empty_session_excluded'=>true,'old_closed_session_excluded'=>true,'rial_display_only'=>true,'unconfirmed_order_fails_closed'=>true,'mismatched_paid_quantity_fails_closed'=>true,'settlement_ledger_unchanged_by_dashboard'=>true,'source'=>'actual R292 Local dashboard function and actual SettlementService::projectionAccountTx + MariaDB 11.4'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
