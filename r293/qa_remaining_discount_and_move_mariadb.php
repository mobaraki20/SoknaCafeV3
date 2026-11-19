<?php
declare(strict_types=1);
// Test only in disposable GitHub Actions MariaDB; never point at cafe credentials.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R293_QA_MODE')!=='disposable_only')throw new RuntimeException('QA_RUNTIME_REQUIRED');
$root=getenv('R293_SOURCE_ROOT');if(!$root||!is_file($root.'/src/Domain/Finance/SettlementService.php'))throw new RuntimeException('SOURCE_MISSING');
foreach([
 '/src/Core/IdentityRepository.php','/src/Core/Capabilities.php','/src/Core/Observability.php',
 '/src/Domain/Finance/SettlementException.php','/src/Domain/Tax/TaxService.php',
 '/src/Domain/Finance/SettlementService.php','/src/Domain/Orders/TableChangeException.php',
 '/src/Domain/Orders/TableChangeService.php','/src/Domain/Orders/OrderStaffActionException.php',
 '/src/Domain/Orders/OrderStaffActionService.php'
] as $file)require_once $root.$file;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Finance\SettlementService;
use Sokna\Local\Domain\Finance\SettlementException;
use Sokna\Local\Domain\Orders\TableChangeService;
use Sokna\Local\Domain\Orders\TableChangeException;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
use Sokna\Local\Domain\Orders\OrderStaffActionService;
final class QAIdentity implements IdentityRepository{
 public function findActiveById(int $id):?array{return $id===1?['id'=>1,'role'=>'admin','display_name'=>'QA Admin']:null;}
 public function findActiveByUsername(string $u):?array{return null;}
 public function capabilitiesForUser(int $id):array{return [];}
 public function preparationAreasForUser(int $id):array{return [];}
}
$pwd=getenv('R293_DB_PASSWORD');$p=new PDO('mysql:host=127.0.0.1;dbname=sokna_r293;charset=utf8mb4','root',$pwd,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$ddl=[
 'CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,display_name VARCHAR(120)) ENGINE=InnoDB',
 'CREATE TABLE cafe_tables(id INT UNSIGNED PRIMARY KEY,name VARCHAR(100),table_number INT,active TINYINT(1)) ENGINE=InnoDB',
 "CREATE TABLE table_sessions(id BIGINT UNSIGNED PRIMARY KEY,public_token VARCHAR(80) NOT NULL UNIQUE,table_id INT UNSIGNED,status VARCHAR(20),live_table_guard INT UNSIGNED NULL,opened_by_user_id INT UNSIGNED,started_at DATETIME,business_date DATE,business_shift_key VARCHAR(40),business_shift_label VARCHAR(80),business_cutoff_snapshot CHAR(5),discount_type VARCHAR(20) NULL,discount_value BIGINT DEFAULT 0,discount_amount BIGINT DEFAULT 0,service_detached_at DATETIME NULL,UNIQUE KEY uq_live(live_table_guard)) ENGINE=InnoDB",
 'CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,session_id BIGINT UNSIGNED,table_id INT UNSIGNED,status VARCHAR(40),total_amount BIGINT,accounting_origin VARCHAR(40),created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
 'CREATE TABLE order_items(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,item_id INT UNSIGNED NULL,item_name VARCHAR(100),unit_price BIGINT,quantity INT,line_total BIGINT,item_note VARCHAR(255),tax_policy_snapshot VARCHAR(30),tax_rate_bps_snapshot INT,tax_rate_version_id INT NULL,tax_item_policy_version_id INT NULL) ENGINE=InnoDB',
 'CREATE TABLE settlement_records(id BIGINT UNSIGNED PRIMARY KEY,session_id BIGINT UNSIGNED,status VARCHAR(30),settlement_kind VARCHAR(30),allocation_version INT,reverses_settlement_id BIGINT UNSIGNED NULL,subtotal BIGINT,discount BIGINT,taxable_amount BIGINT,tax_amount BIGINT,total BIGINT,invoice_number VARCHAR(40),destination VARCHAR(30),table_name_snapshot VARCHAR(140),settled_at DATETIME NULL) ENGINE=InnoDB',
 'CREATE TABLE settlement_record_lines(id BIGINT UNSIGNED PRIMARY KEY,settlement_id BIGINT UNSIGNED,order_item_id BIGINT UNSIGNED,quantity INT,discount_amount BIGINT,tax_amount BIGINT,gross_amount BIGINT,net_amount BIGINT,taxable_amount BIGINT,final_amount BIGINT) ENGINE=InnoDB',
 'CREATE TABLE table_drafts(id BIGINT UNSIGNED PRIMARY KEY,table_id INT UNSIGNED,state VARCHAR(20),active_table_guard INT UNSIGNED NULL,expected_session_id BIGINT UNSIGNED NULL,version INT,updated_by_user_id INT NULL) ENGINE=InnoDB',
 'CREATE TABLE waiter_calls(id BIGINT UNSIGNED PRIMARY KEY,table_id INT UNSIGNED,session_id BIGINT UNSIGNED,status VARCHAR(20),active_table_guard INT UNSIGNED NULL) ENGINE=InnoDB',
 'CREATE TABLE accommodation_transfers(id BIGINT UNSIGNED PRIMARY KEY,session_id BIGINT UNSIGNED,status VARCHAR(30),suspicious_response TINYINT,resolved_at DATETIME NULL) ENGINE=InnoDB',
 'CREATE TABLE audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor_user_id INT,actor_display_name_snapshot VARCHAR(120),action VARCHAR(80),entity_type VARCHAR(80),entity_id VARCHAR(100),details_json LONGTEXT) ENGINE=InnoDB',
];foreach($ddl as $sql)$p->exec($sql);
// Test the actual additional migration, including all FK/check constraints.
$sql=file_get_contents($root.'/database/migrations/0083_remaining_account_discount.sql');
foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement){
 $statement=preg_replace('/^\s*--[^\n]*\n/m','',$statement);
 if(trim($statement)!=='')$p->exec($statement);
}
$p->exec("INSERT INTO users VALUES(1,'QA Admin')");
$p->exec("INSERT INTO cafe_tables VALUES(1,'میز ۱',1,1),(2,'میز ۲',2,1),(3,'میز ۳',3,1),(4,'میز ۴',4,1)");
$p->exec("INSERT INTO table_sessions VALUES
 (100,'S-source-token',1,'active',1,1,NOW(),'2026-11-19','evening','عصر','04:00',NULL,0,0,NULL),
 (200,'S-occupied-token',2,'active',2,1,NOW(),'2026-11-19','evening','عصر','04:00',NULL,0,0,NULL),
 (101,'S-tax-token',4,'active',4,1,NOW(),'2026-11-19','evening','عصر','04:00',NULL,0,0,NULL)");
$p->exec("INSERT INTO orders(id,session_id,table_id,status,total_amount,accounting_origin) VALUES
 (10,100,1,'accounted',1000000,'regular'),(20,200,2,'accounted',10000,'regular'),(11,101,4,'accounted',200000,'regular')");
$p->exec("INSERT INTO order_items VALUES
 (1,10,NULL,'چای',250000,2,500000,'','disabled',0,NULL,NULL),
 (2,10,NULL,'قهوه',250000,2,500000,'','disabled',0,NULL,NULL),
 (3,20,NULL,'میز اشغال',10000,1,10000,'','disabled',0,NULL,NULL),
 (4,11,NULL,'غذای مشمول مالیات',100000,2,200000,'','inherit_default',900,NULL,NULL)");
$p->exec("INSERT INTO settlement_records VALUES
 (301,100,'completed','itemized',1,NULL,500000,0,0,0,500000,'I-1405-000301','direct','میز ۱',NOW()),
 (302,101,'completed','itemized',2,NULL,100000,0,100000,9000,109000,'I-1405-000302','direct','میز ۴',NOW())");
$p->exec("INSERT INTO settlement_record_lines VALUES
 (401,301,1,1,0,0,250000,250000,0,250000),
 (402,301,2,1,0,0,250000,250000,0,250000),
 (403,302,4,1,0,9000,100000,100000,100000,109000)");
$id=new QAIdentity();$cap=new Capabilities($id);$actor=['id'=>1,'role'=>'admin'];
$settle=(new ReflectionClass(SettlementService::class))->newInstanceWithoutConstructor();
foreach(['pdo'=>$p,'identity'=>$id,'capabilities'=>$cap] as $key=>$value){$prop=new ReflectionProperty(SettlementService::class,$key);$prop->setValue($settle,$value);}
$move=new TableChangeService($p,$id,$cap,new Observability(sys_get_temp_dir().'/sokna-r293-qa'));
$block=static function(Closure $fn,string $code):void{
 try{$fn();}catch(SettlementException|TableChangeException|OrderStaffActionException $e){if($e->errorCode!==$code)throw new RuntimeException('WRONG_CODE '.$e->errorCode.' expected '.$code);return;}throw new RuntimeException('NOT_BLOCKED '.$code);
};
$before=$p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll();$paidLines=$p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll();
$a=$settle->accountTx(100);
if($a['remaining_total']!==500000||$a['receipt_count']!==1)throw new RuntimeException('INITIAL_ACCOUNT_WRONG '.json_encode($a));
$preview=$settle->previewDiscount(100,'percent',10,$actor);
if($preview['subtotal']!==500000||$preview['discount_amount']!==50000||$preview['total']!==450000||$preview['remaining_only']!==true)throw new RuntimeException('PREVIEW_WRONG '.json_encode($preview));
$block(fn()=>$settle->setDiscount(100,'percent',10,$actor,''),'discount_preview_required');
$block(fn()=>$settle->setDiscount(100,'percent',10,$actor,str_repeat('a',64)),'discount_account_changed');
$receipt=$settle->setDiscount(100,'percent',10,$actor,$preview['expected_signature']);
if($receipt['discount_amount']!==50000||$receipt['remaining_total']!==450000)throw new RuntimeException('SAVE_WRONG '.json_encode($receipt));
$a2=$settle->accountTx(100);
if($a2['remaining_total']!==450000||$a2['paid_total']!==500000||$a2['discount']!==50000||$a2['paid_discount']!==0)throw new RuntimeException('AFTER_SAVE_WRONG '.json_encode($a2));
if($a2['signature']===$a['signature'])throw new RuntimeException('SIGNATURE_UNCHANGED_AFTER_GRANT');
$block(fn()=>$settle->setDiscount(100,'fixed',10000,$actor,$a2['signature']),'remaining_discount_exists');
$block(fn()=>$settle->setDiscount(100,null,0,$actor,$a2['signature']),'remaining_discount_exists');
// Existing invoice rows stay byte-for-byte identical, including table snapshot.
if($before!==$p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll()||$paidLines!==$p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll())throw new RuntimeException('HISTORICAL_LEDGER_CHANGED');
// Tested against actual reviewSelection, not a rewritten calculator.
$reviewMethod=new ReflectionMethod(SettlementService::class,'reviewSelection');
$remaining=[];foreach($a2['items'] as $line)if($line['remaining_quantity']>0)$remaining[(int)$line['id']]=(int)$line['remaining_quantity'];
$review=$reviewMethod->invoke($settle,$a2,$remaining);
if($review['total']!==450000||$review['discount']!==50000||$review['remaining_total']!==0)throw new RuntimeException('REVIEW_WRONG '.json_encode($review));
// New order after grant is not retroactively discounted.
$p->exec("INSERT INTO orders(id,session_id,table_id,status,total_amount,accounting_origin) VALUES(12,100,1,'accounted',70000,'late_served')");
$p->exec("INSERT INTO order_items VALUES(5,12,NULL,'آب',70000,1,70000,'','disabled',0,NULL,NULL)");
$more=$settle->accountTx(100);
if($more['remaining_total']!==520000||$more['discount']!==50000)throw new RuntimeException('NEW_ORDER_DISCOUNT_LEAK '.json_encode($more));
// Decrease a discounted line must be rejected by the actual order adjustment guard.
$orderAdjust=(new ReflectionClass(OrderStaffActionService::class))->newInstanceWithoutConstructor();
$property=new ReflectionProperty(OrderStaffActionService::class,'pdo');$property->setValue($orderAdjust,$p);
$guard=new ReflectionMethod(OrderStaffActionService::class,'assertRemainingDiscountGroupUnlockedTx');
$block(fn()=>$guard->invoke($orderAdjust,[['id'=>1]]),'discounted_unpaid_line_locked');
$guard->invoke($orderAdjust,[['id'=>5]]);
// Table 2 is occupied; partial account cannot be moved there.
$block(fn()=>$move->move(['table_id'=>1,'target_table_id'=>2,'expected_session_id'=>100,'request_id'=>'r293-move-occupied-001'],$actor),'target_occupied');
// Must preserve the complete financial account ID 100, and original invoice table label.
$oldToken=$p->query('SELECT public_token FROM table_sessions WHERE id=100')->fetchColumn();
$m=$move->move(['table_id'=>1,'target_table_id'=>3,'expected_session_id'=>100,'request_id'=>'r293-move-success-001'],$actor);
if(!$m['success']||!$m['financial_session_preserved']||$m['new_session_id']!==100||$m['moved_order_count']!==2)throw new RuntimeException('MOVED_RESULT_WRONG '.json_encode($m));
$now=$p->query('SELECT * FROM table_sessions WHERE id=100')->fetch();
if((int)$now['table_id']!==3||(int)$now['live_table_guard']!==3||$now['public_token']===$oldToken||$now['status']!=='active')throw new RuntimeException('SESSION_NOT_PRESERVED '.json_encode($now));
if((int)$p->query('SELECT COUNT(*) FROM table_sessions WHERE id=100')->fetchColumn()!==1)throw new RuntimeException('SECOND_FINANCIAL_SESSION_CREATED');
if((int)$p->query('SELECT COUNT(*) FROM orders WHERE session_id=100 AND table_id=3')->fetchColumn()!==2)throw new RuntimeException('ORDER_LINK_INCONSISTENT');
$after=$settle->accountTx(100);
if($after['remaining_total']!==520000||$after['paid_total']!==500000||$after['session']['table_name']!=='میز ۳')throw new RuntimeException('MOVED_ACCOUNT_WRONG');
if($before!==$p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll()||$paidLines!==$p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll())throw new RuntimeException('MOVED_LEDGER_CHANGED');
$second=$move->move(['table_id'=>1,'target_table_id'=>3,'expected_session_id'=>100,'request_id'=>'r293-move-success-001'],$actor);
if(!$second['idempotent']||$second['new_session_id']!==100)throw new RuntimeException('IDEMPOTENT_MOVE_FAILED');
$block(fn()=>$move->move(['table_id'=>1,'target_table_id'=>3,'expected_session_id'=>100,'request_id'=>'r293-move-different-002'],$actor),'session_changed');
$status=$move->requestStatus(['table_id'=>1,'target_table_id'=>3,'expected_session_id'=>100,'request_id'=>'r293-move-success-001'],$actor);
if(($status['state']??'')!=='committed')throw new RuntimeException('MOVE_RECEIPT_STATUS_FAILED');
// Real tax scenario preserves the paid receipt and recalculates only unpaid tax.
$t=$settle->accountTx(101);if($t['remaining_total']!==109000)throw new RuntimeException('TAX_INITIAL_WRONG');
$pt=$settle->previewDiscount(101,'fixed',10000,$actor);
if($pt['discount_amount']!==10000||$pt['tax']!==8100||$pt['total']!==98100)throw new RuntimeException('TAX_PREVIEW_WRONG '.json_encode($pt));
$settle->setDiscount(101,'fixed',10000,$actor,$pt['expected_signature']);
$ta=$settle->accountTx(101);if($ta['remaining_total']!==98100||$ta['paid_tax']!==9000||$ta['tax']!==17100)throw new RuntimeException('TAX_AFTER_WRONG '.json_encode($ta));
if($before!==$p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll()||$paidLines!==$p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll())throw new RuntimeException('TAX_MUTATED_LEDGER');
$tr=$reviewMethod->invoke($settle,$ta,[4=>1]);if($tr['total']!==98100||$tr['tax']!==8100||$tr['discount']!==10000)throw new RuntimeException('TAX_REVIEW_WRONG '.json_encode($tr));
if($p->inTransaction())throw new RuntimeException('TRANSACTION_LEAK');
$report=['result'=>'PASS','base_receipt_toman'=>500000,'discount_percent'=>10,'discount_toman'=>50000,'remaining_after_discount'=>450000,
 'adding_new_order_after_grant_not_retroactive'=>true,'moved_financial_session_id_preserved'=>100,'moved_table_id'=>3,
 'invoice_rows_byte_identical'=>true,'duplicate_discount_blocked'=>true,'stale_signature_blocked'=>true,
 'discounted_quantity_correction_blocked'=>true,'tax_after_discount_toman'=>8100,
 'tax_and_paid_receipt_preserved'=>true,'wrong_table_blocked'=>true,'idempotent_move'=>true,
 'actual_source'=>'SettlementService, TableChangeService, OrderStaffActionService + migration 0083 + MariaDB 11.4'];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
