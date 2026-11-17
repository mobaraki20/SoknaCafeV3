<?php
declare(strict_types=1);
// Isolated R269 business end-to-end using the unmodified public service entry methods.
// Deliberately no connection to a real café/production schema.
$root=dirname(__DIR__).'/source/src';
foreach([
 '/Core/IdentityRepository.php','/Core/Capabilities.php',
 '/Domain/Orders/OrderStaffActionException.php',
 '/Domain/Inventory/InventoryOrderService.php',
 '/Domain/Preparation/PreparationAccessService.php',
 '/Domain/Preparation/PreparationService.php',
 '/Domain/Printing/PrintService.php',
 '/Domain/Orders/OrderStaffActionService.php',
] as $f)require_once $root.$f;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Domain\Inventory\InventoryOrderService;
use Sokna\Local\Domain\Printing\PrintService;
use Sokna\Local\Domain\Preparation\PreparationService;
use Sokna\Local\Domain\Orders\OrderStaffActionService;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
$host=getenv('SOKNA_QA_DB_HOST')?:'127.0.0.1';$name=getenv('SOKNA_QA_DB_NAME')?:'';
if($name!=='sokna_qa_fin_cancel')throw new RuntimeException('wrong DB target');
$pdo=new PDO("mysql:host=$host;port=3306;dbname=$name;charset=utf8mb4",getenv('SOKNA_QA_DB_USER')?:'root',getenv('SOKNA_QA_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$exec=static fn(string $s)=>$pdo->exec($s);
foreach(['audit_log','order_status_history','waiter_calls','table_drafts','settings','order_item_bundle_components','inventory_order_events','order_item_adjustments','order_preparation_claims','settlement_followup_events','settlement_record_lines','settlement_records','print_jobs','order_items','orders','table_sessions','cafe_tables'] as $table)$exec('DROP TABLE IF EXISTS '.$table);
$ddl=[
 'CREATE TABLE cafe_tables(id BIGINT PRIMARY KEY) ENGINE=InnoDB',
 'CREATE TABLE table_sessions(id BIGINT PRIMARY KEY,table_id BIGINT,status VARCHAR(24),live_table_guard BIGINT,service_detached_at DATETIME NULL,ended_at DATETIME NULL,ended_reason VARCHAR(50) NULL,closed_by_user_id BIGINT NULL) ENGINE=InnoDB',
 'CREATE TABLE orders(id BIGINT PRIMARY KEY,table_id BIGINT,session_id BIGINT,status VARCHAR(32),order_source VARCHAR(20),accounting_origin VARCHAR(30),total_amount BIGINT,updated_at DATETIME NULL) ENGINE=InnoDB',
 'CREATE TABLE order_items(id BIGINT PRIMARY KEY,order_id BIGINT,item_id INT NULL,item_name VARCHAR(160),unit_price BIGINT,quantity INT,ordered_quantity INT,item_note VARCHAR(500) NULL,fulfillment_mode VARCHAR(30),preparation_station VARCHAR(30),line_total BIGINT) ENGINE=InnoDB',
 'CREATE TABLE settlement_records(id BIGINT PRIMARY KEY,session_id BIGINT,status VARCHAR(20),reverses_settlement_id BIGINT NULL,discount BIGINT DEFAULT 0,tax_amount BIGINT DEFAULT 0,INDEX(session_id)) ENGINE=InnoDB',
 'CREATE TABLE settlement_record_lines(id BIGINT PRIMARY KEY AUTO_INCREMENT,settlement_id BIGINT,order_item_id BIGINT NULL,quantity INT,INDEX(settlement_id)) ENGINE=InnoDB',
 'CREATE TABLE order_item_adjustments(id BIGINT PRIMARY KEY AUTO_INCREMENT,request_id VARCHAR(96) UNIQUE,order_id BIGINT,order_item_id BIGINT,area_key VARCHAR(30) NULL,adjustment_kind VARCHAR(25),from_quantity INT,to_quantity INT,delta_quantity INT NULL,from_fulfillment_mode VARCHAR(25),to_fulfillment_mode VARCHAR(25),from_dine_in_quantity INT NULL,to_dine_in_quantity INT NULL,from_takeaway_quantity INT NULL,to_takeaway_quantity INT NULL,from_item_note VARCHAR(500) NULL,to_item_note VARCHAR(500) NULL,reason VARCHAR(500),preparation_claimed_before TINYINT,preparation_ack_status VARCHAR(25),inventory_quantity_delta INT,inventory_effect VARCHAR(60),actor_user_id INT,correction_print_job_id BIGINT NULL) ENGINE=InnoDB',
 'CREATE TABLE settings(setting_key VARCHAR(160) PRIMARY KEY,setting_value VARCHAR(255)) ENGINE=InnoDB',
 'CREATE TABLE print_jobs(id BIGINT PRIMARY KEY,entity_type VARCHAR(60),entity_id VARCHAR(100),job_type VARCHAR(50),status VARCHAR(30)) ENGINE=InnoDB',
 'CREATE TABLE order_item_bundle_components(id BIGINT PRIMARY KEY,order_item_id BIGINT,item_name_snapshot VARCHAR(160),quantity INT,preparation_station_snapshot VARCHAR(40),component_mode VARCHAR(20),choice_label_snapshot VARCHAR(120)) ENGINE=InnoDB',
 'CREATE TABLE order_preparation_claims(order_id BIGINT,area_key VARCHAR(30),item_signature VARCHAR(64),updated_at DATETIME NULL) ENGINE=InnoDB',
 'CREATE TABLE settlement_followup_events(id BIGINT PRIMARY KEY,session_id BIGINT) ENGINE=InnoDB',
 'CREATE TABLE inventory_order_events(id BIGINT PRIMARY KEY,event_type VARCHAR(30),status VARCHAR(30),payload_json TEXT,order_id BIGINT) ENGINE=InnoDB',
 'CREATE TABLE table_drafts(id BIGINT PRIMARY KEY,table_id BIGINT,state VARCHAR(20)) ENGINE=InnoDB',
 'CREATE TABLE waiter_calls(id BIGINT PRIMARY KEY,session_id BIGINT,status VARCHAR(30),active_table_guard BIGINT NULL,cancelled_at DATETIME NULL,cancel_reason VARCHAR(80) NULL,cancelled_by_user_id BIGINT NULL) ENGINE=InnoDB',
 'CREATE TABLE order_status_history(order_id BIGINT,from_status VARCHAR(30),to_status VARCHAR(30),actor_user_id BIGINT) ENGINE=InnoDB',
 'CREATE TABLE audit_log(id BIGINT PRIMARY KEY AUTO_INCREMENT,actor_user_id BIGINT,actor_display_name_snapshot VARCHAR(100),action VARCHAR(100),entity_type VARCHAR(50),entity_id VARCHAR(100),details_json JSON) ENGINE=InnoDB'
];foreach($ddl as $sql)$exec($sql);
$exec('INSERT INTO cafe_tables VALUES (7),(8),(9),(10),(11)');
$exec("INSERT INTO table_sessions(id,table_id,status,live_table_guard) VALUES (70,7,'active',7),(80,8,'active',8),(90,9,'active',9),(100,10,'active',10),(110,11,'active',11)");
$exec("INSERT INTO orders VALUES (101,7,70,'accounted','staff','regular',550000,NULL),(201,8,80,'accounted','staff','regular',40000,NULL),(301,9,90,'accounted','staff','regular',40000,NULL),(401,10,100,'accounted','staff','regular',40000,NULL),(501,11,110,'accounted','staff','regular',40000,NULL)");
$values=[];for($i=1;$i<=10;$i++){$q=$i===3?2:1;$name=$i===1?'Paid item 1':($i===2?'Paid item 2':('Other item '.$i));$values[]="($i,101,NULL,'$name',50000,$q,$q,NULL,'dine_in','none',".(50000*$q).')';}
$values[]="(201,201,NULL,'Water small',40000,1,1,NULL,'dine_in','cold_bar',40000)";
$values[]="(301,301,NULL,'Water small',40000,1,1,NULL,'dine_in','cold_bar',40000)";
$values[]="(401,401,NULL,'Water small',40000,1,1,NULL,'dine_in','cold_bar',40000)";
$values[]="(501,501,NULL,'Water small',40000,1,1,NULL,'dine_in','cold_bar',40000)";
$exec('INSERT INTO order_items VALUES '.implode(',',$values));
$exec("INSERT INTO settlement_records VALUES (1,70,'completed',NULL,0,0),(2,70,'completed',NULL,0,0)");
$exec('INSERT INTO settlement_record_lines(settlement_id,order_item_id,quantity) VALUES (1,1,1),(2,2,1)');
$exec("INSERT INTO settings VALUES ('printing.quick.auto_preparation','0')");
$exec("INSERT INTO print_jobs VALUES (30,'order_reprint','301','preparation_reprint','completed'),(31,'order','501','preparation','submitted')");
$exec("INSERT INTO order_preparation_claims(order_id,area_key,item_signature,updated_at) VALUES (501,'bar','intentionally-stale-claim',NOW())");
$exec("INSERT INTO order_item_adjustments(order_id,order_item_id,preparation_ack_status,area_key,reason) VALUES (301,301,'pending','bar','earlier pending preparation note')");
$ident=new class implements IdentityRepository {
 public function findActiveById(int $userId):?array{return $userId===1?['id'=>1,'display_name'=>'QA Admin','role'=>'admin']:null;}
 public function findActiveByUsername(string $s):?array{return null;}
 public function capabilitiesForUser(int $id):array{return [];}
 public function preparationAreasForUser(int $id):array{return [];}
};
$cap=new Capabilities($ident);
$inv=(new ReflectionClass(InventoryOrderService::class))->newInstanceWithoutConstructor();(new ReflectionProperty(InventoryOrderService::class,'pdo'))->setValue($inv,$pdo);
$prep=(new ReflectionClass(PreparationService::class))->newInstanceWithoutConstructor();(new ReflectionProperty(PreparationService::class,'pdo'))->setValue($prep,$pdo);
$printer=(new ReflectionClass(PrintService::class))->newInstanceWithoutConstructor();
$service=new OrderStaffActionService($pdo,$ident,$cap,$inv,$printer,$prep);
$user=['id'=>1];$assert=static function(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException('FAIL '.$msg);};
$expect=static function(string $code,callable $fn)use($assert):void{
 try{$fn();}catch(OrderStaffActionException $e){$assert($e->errorCode===$code,'expected '.$code.' got '.$e->errorCode);return;}
 throw new RuntimeException('FAIL expected '.$code);
};
// True operator route, not a helper: 10 items with 2 paid; edit an UNPAID third item.
$r=$service->adjustItemQuantity(['order_item_id'=>3,'quantity'=>1,'fulfillment_quantities'=>['dine_in'=>1,'takeaway'=>0],'request_id'=>'QA-ITEM-UNPAID-0001','reason'=>'incorrect quantity'],$user);
$assert($r['success']===true && $r['to_quantity']===1,'unpaid item edited while two other items paid');
$assert((int)$pdo->query('SELECT quantity FROM order_items WHERE id=3')->fetchColumn()===1,'actual order item quantity reduced');
$assert((int)$pdo->query('SELECT total_amount FROM orders WHERE id=101')->fetchColumn()===500000,'order total reduced by 50000');
$assert((int)$pdo->query('SELECT SUM(quantity) FROM settlement_record_lines')->fetchColumn()===2,'paid receipts unchanged');
$again=$service->adjustItemQuantity(['order_item_id'=>3,'quantity'=>1,'fulfillment_quantities'=>['dine_in'=>1,'takeaway'=>0],'request_id'=>'QA-ITEM-UNPAID-0001','reason'=>'incorrect quantity'],$user);
$assert($again['idempotent']===true,'same request id no double mutation');
$expect('paid_group_row_locked',fn()=>$service->adjustItemQuantity(['order_item_id'=>1,'quantity'=>0,'fulfillment_quantities'=>['dine_in'=>0,'takeaway'=>0],'request_id'=>'QA-PAID-REJECT-0001'],$user));
$assert((int)$pdo->query('SELECT quantity FROM order_items WHERE id=1')->fetchColumn()===1,'paid item unchanged');
// True round-cancel route; no auto-print and no prior print = confirmed cancellation.
$expect('preparation_dispatch_confirmation_required',fn()=>$service->cancelUnsettledStaffOrder(['order_id'=>201,'request_id'=>'QA-NO-ATTEST-0001','reason'=>'mistaken order'],$user));
$assert((string)$pdo->query('SELECT status FROM orders WHERE id=201')->fetchColumn()==='accounted','missing attestation caused complete transaction rollback');
$ok=$service->cancelUnsettledStaffOrder(['order_id'=>201,'request_id'=>'QA-CANCEL-UNSENT-001','reason'=>'mistaken order','preparation_never_dispatched_confirmed'=>true],$user);
$assert($ok['success']===true && $ok['status']==='cancelled','unprinted order can be cancelled manually');
$assert((int)$pdo->query('SELECT COUNT(*) FROM print_jobs WHERE entity_id="201"')->fetchColumn()===0,'no surprise print in manual mode');
$assert((string)$pdo->query('SELECT status FROM table_sessions WHERE id=80')->fetchColumn()==='closed','empty table closed atomically');
// New operator responsibility workflow; a stale/pending kitchen action is NOT a forced waiting gate.
$expect('operator_responsibility_required',fn()=>$service->cancelUnsettledStaffOrder(['order_id'=>301,'request_id'=>'QA-MISSING-RSP','reason'=>'mistaken order','operator_preparation_disposition'=>'unknown'],$user));
$expect('invalid_operator_preparation_disposition',fn()=>$service->cancelUnsettledStaffOrder(['order_id'=>301,'request_id'=>'QA-INVALID-CHOICE','reason'=>'mistaken order','operator_preparation_disposition'=>'yes','operator_accepts_preparation_responsibility'=>true],$user));
$expect('pending_adjustment',fn()=>$service->cancelUnsettledStaffOrder(['order_id'=>301,'request_id'=>'QA-OLD-PENDING-BLOCK','reason'=>'mistaken order','preparation_never_dispatched_confirmed'=>true],$user));
$cancelRisk=$service->cancelUnsettledStaffOrder(['order_id'=>301,'request_id'=>'QA-SUPERVISED-RISK','reason'=>'cancel by operator after radio unanswered','operator_preparation_disposition'=>'unknown','operator_accepts_preparation_responsibility'=>true],$user);
$assert($cancelRisk['success']===true && $cancelRisk['preparation_followup_required']===true,'printed pending order cancelled with operator responsibility');
$assert((string)$pdo->query('SELECT status FROM orders WHERE id=301')->fetchColumn()==='cancelled','printed round cancelled despite earlier pending kitchen update');
$assert((int)$pdo->query("SELECT COUNT(*) FROM order_item_adjustments WHERE order_id=301 AND preparation_ack_status='pending'")->fetchColumn()===2,'prior and new kitchen followup notifications retained');
$assert((int)$pdo->query("SELECT COUNT(*) FROM print_jobs WHERE entity_id='301'")->fetchColumn()===1,'no fake correction print when auto printing off');
$assert((int)$pdo->query("SELECT inventory_quantity_delta FROM order_item_adjustments WHERE order_id=301 ORDER BY id DESC LIMIT 1")->fetchColumn()===0,'unknown preparation keeps stock consumed to avoid phantom reversal');
// A physically-claimed, stale preparation state is still cancelable by an accountable supervisor.
$expect('preparation_claimed',fn()=>$service->cancelUnsettledStaffOrder(['order_id'=>501,'request_id'=>'QA-CLAIM-OLD-BLOCK','reason'=>'mistake'],$user));
$claimedResult=$service->cancelUnsettledStaffOrder(['order_id'=>501,'request_id'=>'QA-CLAIM-RESPONSIBLE','reason'=>'operator confirms cancellation','operator_preparation_disposition'=>'received_or_preparing','operator_accepts_preparation_responsibility'=>true],$user);
$assert($claimedResult['success']===true,'claimed and stale-preparation order cancelled by authorized supervisor');
$assert((int)$pdo->query("SELECT COUNT(*) FROM order_item_adjustments WHERE order_id=501 AND preparation_ack_status='pending'")->fetchColumn()===1,'prep claim cancellation visible for later acknowledgment');
$assert((int)$pdo->query("SELECT inventory_quantity_delta FROM order_item_adjustments WHERE order_id=501 ORDER BY id DESC LIMIT 1")->fetchColumn()===0,'claimed preparation does not reverse stock as never prepared');
$assert((int)$pdo->query('SELECT COUNT(*) FROM order_preparation_claims WHERE order_id=501')->fetchColumn()===1,'historical claim remains auditable');
// New operator UI can explicitly choose verified not-received without needing kitchen response.
$notReceived=$service->cancelUnsettledStaffOrder(['order_id'=>401,'request_id'=>'QA-RSP-NOT-RECEIVED','reason'=>'wrong order','operator_preparation_disposition'=>'not_received','operator_accepts_preparation_responsibility'=>true],$user);
$assert($notReceived['success']===true,'not-received decision accepted by supervisor');
$assert((int)$pdo->query("SELECT inventory_quantity_delta FROM order_item_adjustments WHERE order_id=401 ORDER BY id DESC LIMIT 1")->fetchColumn()===-1,'not received correctly returns unprepared consumption');
$assert((int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='order.cancelled_unsettled_staff' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.operator_preparation_disposition'))='unknown'")->fetchColumn()>=1,'audit contains operator status without forging chef ACK');
$assert((int)$pdo->query('SELECT COUNT(*) FROM settlement_records WHERE session_id=70')->fetchColumn()===2,'receipt history unchanged by item adjustment and cancellation');
echo json_encode(['ok'=>true,'suite'=>'real action integration + operator responsibility','unpaid_corrected'=>true,'paid_still_locked'=>true,'idempotent'=>true,'unsent_manual_cancelled'=>true,'printed_pending_cancelled'=>true,'claimed_stale_cancelled'=>true,'unknown_stock_protected'=>true,'receipts_immutable'=>true],JSON_UNESCAPED_SLASHES)."\n";
