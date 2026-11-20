<?php
declare(strict_types=1);
// Dedicated QA-only disposable MariaDB: no deployment credentials or live data.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R290_QA_MODE')!=='disposable_only')throw new RuntimeException('QA_RUNTIME_REQUIRED');
$root=getenv('R290_SOURCE_ROOT');if(!$root||!is_file($root.'/src/Domain/Orders/OrderStaffActionService.php'))throw new RuntimeException('ACTUAL_SOURCE_MISSING');
foreach([
'/src/Core/IdentityRepository.php','/src/Core/Capabilities.php',
'/src/Domain/Orders/OrderStaffActionException.php','/src/Domain/Orders/OrderStaffActionService.php',
'/src/Domain/Printing/PrintService.php','/src/Domain/Printing/PrintException.php',
'/src/Domain/Inventory/InventoryService.php','/src/Domain/Inventory/InventoryOrderService.php',
'/src/Domain/Preparation/PreparationService.php'
] as $f)require_once $root.$f;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Domain\Orders\OrderStaffActionService;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
use Sokna\Local\Domain\Printing\PrintService;
use Sokna\Local\Domain\Inventory\InventoryOrderService;
use Sokna\Local\Domain\Inventory\InventoryService;
use Sokna\Local\Domain\Preparation\PreparationService;
final class TestIdentity implements IdentityRepository{
 public function findActiveById(int $id):?array{return $id===1?['id'=>1,'role'=>'admin','display_name'=>'Test operator']:null;}
 public function findActiveByUsername(string $username):?array{return null;}
 public function capabilitiesForUser(int $id):array{return [];}
 public function preparationAreasForUser(int $id):array{return [];}
}
$p=new PDO('mysql:host=127.0.0.1;dbname=sokna_r290;charset=utf8mb4','root',getenv('R290_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$ddl=[
"CREATE TABLE cafe_tables(id INT PRIMARY KEY) ENGINE=InnoDB",
"CREATE TABLE table_sessions(id INT PRIMARY KEY,table_id INT,status VARCHAR(20),live_table_guard INT) ENGINE=InnoDB",
"CREATE TABLE orders(id INT PRIMARY KEY,table_id INT,session_id INT,status VARCHAR(30),accounting_origin VARCHAR(50),total_amount BIGINT,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
"CREATE TABLE order_items(id INT PRIMARY KEY AUTO_INCREMENT,order_id INT,item_id INT NULL,category_id_snapshot INT NULL,category_name_snapshot VARCHAR(200) NULL,category_snapshot_quality VARCHAR(30),item_name VARCHAR(150),sellable_kind_snapshot VARCHAR(40) NULL,unit_price BIGINT,quantity INT,ordered_quantity INT,item_note VARCHAR(500),fulfillment_mode VARCHAR(20),preparation_station VARCHAR(30),line_total BIGINT,tax_policy_snapshot VARCHAR(20),tax_rate_bps_snapshot INT,tax_rate_version_id INT NULL,tax_item_policy_version_id INT NULL) ENGINE=InnoDB",
"CREATE TABLE settlement_records(id INT PRIMARY KEY,session_id INT,status VARCHAR(30),discount BIGINT,tax_amount BIGINT,reverses_settlement_id INT NULL) ENGINE=InnoDB",
"CREATE TABLE settlement_record_lines(id INT PRIMARY KEY AUTO_INCREMENT,settlement_id INT,order_item_id INT,quantity INT,final_amount BIGINT) ENGINE=InnoDB",
"CREATE TABLE order_item_adjustments(id INT PRIMARY KEY AUTO_INCREMENT,request_id VARCHAR(100) UNIQUE,order_id INT,order_item_id INT,area_key VARCHAR(20),adjustment_kind VARCHAR(50),from_quantity INT,to_quantity INT,delta_quantity INT NULL,from_fulfillment_mode VARCHAR(20),to_fulfillment_mode VARCHAR(20),from_dine_in_quantity INT NULL,to_dine_in_quantity INT NULL,from_takeaway_quantity INT NULL,to_takeaway_quantity INT NULL,from_item_note VARCHAR(500) NULL,to_item_note VARCHAR(500) NULL,reason VARCHAR(250),preparation_claimed_before TINYINT,preparation_ack_status VARCHAR(30),inventory_quantity_delta INT,inventory_effect VARCHAR(100),actor_user_id INT,correction_print_job_id INT NULL) ENGINE=InnoDB",
"CREATE TABLE audit_log(id INT PRIMARY KEY AUTO_INCREMENT,actor_user_id INT,actor_display_name_snapshot VARCHAR(100),action VARCHAR(80),entity_type VARCHAR(80),entity_id VARCHAR(80),details_json LONGTEXT) ENGINE=InnoDB",
"CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB",
"CREATE TABLE remaining_account_discount_lines(id INT PRIMARY KEY AUTO_INCREMENT,order_item_id INT) ENGINE=InnoDB",
"CREATE TABLE items(id INT PRIMARY KEY,price BIGINT) ENGINE=InnoDB"
];
foreach($ddl as $sql)$p->exec($sql);
$p->exec("INSERT INTO cafe_tables VALUES(1),(2)");
$p->exec("INSERT INTO table_sessions VALUES(100,1,'active',1),(99,1,'closed',NULL),(200,2,'active',2)");
$p->exec("INSERT INTO orders(id,table_id,session_id,status,accounting_origin,total_amount) VALUES(10,1,100,'accounted','regular',90000),(11,1,99,'accounted','regular',10000),(12,2,200,'accounted','regular',20000)");
$p->exec("INSERT INTO settings VALUES('module.inventory.enabled','0')");
$p->exec("INSERT INTO order_items(id,order_id,item_id,category_snapshot_quality,item_name,unit_price,quantity,ordered_quantity,item_note,fulfillment_mode,preparation_station,line_total,tax_policy_snapshot,tax_rate_bps_snapshot) VALUES
 (1,10,NULL,'unknown','چای',4,4,4,'گروهی','dine_in','none',16,'disabled',0),
 (2,10,NULL,'unknown','چای',4,2,2,'گروهی','dine_in','none',8,'disabled',0),
 (3,10,NULL,'unknown','چای',4,3,3,'گروهی','takeaway','none',12,'disabled',0),
 (4,10,NULL,'unknown','باقلوا',10,3,3,'','dine_in','none',30,'disabled',0),
 (5,10,NULL,'unknown','پیتزا',20,2,2,'','dine_in','none',40,'disabled',0),
 (6,11,NULL,'unknown','حساب قبلی',10,1,1,'','dine_in','none',10,'disabled',0),
 (7,12,NULL,'unknown','میز دیگر',10,2,2,'','dine_in','none',20,'disabled',0)");
$p->exec("INSERT INTO settlement_records VALUES(101,100,'completed',0,0,NULL),(99,99,'completed',0,0,NULL),(202,200,'completed',0,0,NULL)");
$p->exec("INSERT INTO settlement_record_lines(settlement_id,order_item_id,quantity,final_amount) VALUES(101,1,2,8),(101,3,1,4),(101,5,2,40),(99,6,1,10),(202,7,2,20)");
$id=new TestIdentity();$cap=new Capabilities($id);$inventory=new InventoryService($p,$id,$cap);
$inventoryOrders=new InventoryOrderService($p,$inventory);
$print=(new ReflectionClass(PrintService::class))->newInstanceWithoutConstructor();
$prep=(new ReflectionClass(PreparationService::class))->newInstanceWithoutConstructor();
$service=new OrderStaffActionService($p,$id,$cap,$inventoryOrders,$print,$prep);$user=['id'=>1,'role'=>'admin'];
$seq=1000;
$adjust=static function(int $item,int $quantity,int $dine,int $take,string $note='گروهی') use($service,$user,&$seq):array{
 ++$seq;return $service->adjustItemQuantity(['order_item_id'=>$item,'quantity'=>$quantity,'fulfillment_quantities'=>['dine_in'=>$dine,'takeaway'=>$take],'item_note'=>$note,'reason'=>'QAِ کاهش مانده','request_id'=>'r290-qa-group-'.str_pad((string)$seq,20,'0',STR_PAD_LEFT)],$user);
};
$single=static function(int $item,int $quantity) use($service,$user,&$seq):array{
 ++$seq;return $service->adjustItemQuantity(['order_item_id'=>$item,'quantity'=>$quantity,'reason'=>'QAِ اصلاح تک‌قلم','request_id'=>'r290-qa-single-'.str_pad((string)$seq,20,'0',STR_PAD_LEFT)],$user);
};
$assertBlocked=static function(Closure $func,string $code)use($p):void{
 $before=(int)$p->query('SELECT COUNT(*) FROM order_item_adjustments')->fetchColumn();
 try{$func();}catch(OrderStaffActionException $e){
  if($e->errorCode!==$code)throw new RuntimeException('WRONG_ERROR '.$e->errorCode.' expected '.$code);
  if((int)$p->query('SELECT COUNT(*) FROM order_item_adjustments')->fetchColumn()!==$before)throw new RuntimeException('FAILED_REQUEST_MUTATED_LEDGER');
  return;
 }throw new RuntimeException('FAILED_TO_BLOCK '.$code);
};
$originalReceipts=$p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll();
$originalSettlements=$p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll();
$r=$adjust(1,7,4,3);
if(!$r['success']||(int)$p->query('SELECT quantity FROM order_items WHERE id=2')->fetchColumn()!==0)throw new RuntimeException('GROUP_UNPAID_REDUCTION_FAILED');
$r=$adjust(1,6,3,3);
if(!$r['success']||(int)$p->query('SELECT quantity FROM order_items WHERE id=1')->fetchColumn()!==3)throw new RuntimeException('PAID_ROW_UNPAID_TAIL_FAILED');
$assertBlocked(fn()=>$adjust(1,5,1,4),'paid_mode_quantity_floor');
$assertBlocked(fn()=>$adjust(1,6,3,3,'یادداشت جدید'),'paid_item_identity_locked');
$assertBlocked(fn()=>$adjust(1,2,2,0),'paid_mode_quantity_floor');
$r=$single(4,2);if(!$r['success']||(int)$p->query('SELECT quantity FROM order_items WHERE id=4')->fetchColumn()!==2)throw new RuntimeException('SINGLE_UNPAID_FAILED');
$assertBlocked(fn()=>$single(5,1),'paid_quantity_floor');
$assertBlocked(fn()=>$single(6,0),'session_closed');
$assertBlocked(fn()=>$single(7,0),'paid_quantity_floor');
$journal=$p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll();if($journal!==$originalReceipts)throw new RuntimeException('RECEIPTS_MUTATED');
if($p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll()!==$originalSettlements)throw new RuntimeException('SETTLEMENT_MUTATED');
$before=(int)$p->query('SELECT COUNT(*) FROM order_item_adjustments')->fetchColumn();
$receipt=(int)$p->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
if($before!==3||$receipt!==3)throw new RuntimeException('BAD_AUDIT_COUNT '.$before.'/'.$receipt);
$p->exec("UPDATE settlement_records SET discount=5 WHERE id=101");
$assertBlocked(fn()=>$adjust(1,5,2,3),'receipt_allocation_rebalance_required');
if((int)$p->query('SELECT quantity FROM order_items WHERE id=1')->fetchColumn()!==3)throw new RuntimeException('BLOCKED_DISCOUNT_MUTATED_ORDER');

// R308 finance-neutral correction: a non-paid row note does not reprice any
// receipt even if a DIFFERENT receipt had a discount or tax.
$beforeUnchangedSettlements=$p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll();
$beforeUnchangedLines=$p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll();
$beforeOrderAmount=(int)$p->query('SELECT total_amount FROM orders WHERE id=10')->fetchColumn();
$seq++;
$edited=$service->adjustItemQuantity([
 'order_item_id'=>4,'quantity'=>2,'item_note'=>'بدون شکر','reason'=>'QA یادداشت قلم پرداخت‌نشده',
 'request_id'=>'r308-note-'.str_pad((string)$seq,20,'0',STR_PAD_LEFT)
],$user);
if(empty($edited['success'])||(string)$p->query('SELECT item_note FROM order_items WHERE id=4')->fetchColumn()!=='بدون شکر')
 throw new RuntimeException('UNPAID_NOTE_EDIT_WITH_PRIOR_DISCOUNT_FAILED');
if((int)$p->query('SELECT total_amount FROM orders WHERE id=10')->fetchColumn()!==$beforeOrderAmount)
 throw new RuntimeException('NOTE_CHANGED_INVOICE_TOTAL');
if($p->query('SELECT * FROM settlement_record_lines ORDER BY id')->fetchAll()!==$beforeUnchangedLines||
   $p->query('SELECT * FROM settlement_records ORDER BY id')->fetchAll()!==$beforeUnchangedSettlements)
 throw new RuntimeException('NOTE_CHANGED_ORIGINAL_RECEIPTS');
$beforeAdjustments=(int)$p->query('SELECT COUNT(*) FROM order_item_adjustments')->fetchColumn();
$seq++;
$noop=$service->adjustItemQuantity([
 'order_item_id'=>4,'quantity'=>2,'item_note'=>'بدون شکر','reason'=>'QA بدون تغییر',
 'request_id'=>'r308-note-'.str_pad((string)$seq,20,'0',STR_PAD_LEFT)
],$user);
if(empty($noop['idempotent'])||(int)$p->query('SELECT COUNT(*) FROM order_item_adjustments')->fetchColumn()!==$beforeAdjustments)
 throw new RuntimeException('NOOP_WITH_PRIOR_DISCOUNT_CHANGED_LEDGER');
$seq++;
$assertBlocked(fn()=>$service->adjustItemQuantity([
 'order_item_id'=>1,'quantity'=>3,'item_note'=>'متن دیگری برای قلم پرداخت‌شده',
 'request_id'=>'r308-note-'.str_pad((string)$seq,20,'0',STR_PAD_LEFT)
],$user),'paid_item_identity_locked');
$result=['result'=>'PASS','group_unpaid_reduced'=>true,'partial_paid_row_tail_reduced'=>true,'paid_mode_transfer_blocked'=>true,'paid_note_mutation_blocked'=>true,'paid_single_row_floor_blocked'=>true,'closed_session_blocked'=>true,'discount_tax_guard_preserved'=>true,'original_receipt_ledger_byte_identical'=>true,'new_audit_rows'=>3,'unpaid_note_with_prior_discount'=>true,'noop_with_prior_discount_idempotent'=>true,'prior_receipt_ledger_unchanged_after_note'=>true,'source'=>'actual R307->R308 OrderStaffActionService + MariaDB 11.4'];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";