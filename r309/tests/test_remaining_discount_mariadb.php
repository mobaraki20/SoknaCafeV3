<?php
declare(strict_types=1);
// Disposable MariaDB only — test the exact patched SettlementService through its public APIs.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R309_QA_MODE')!=='disposable_only')throw new RuntimeException('Only disposable CI may run this fixture');
$root=(getenv('SOKNA_QA_ROOT')?:dirname(__DIR__).'/source');
spl_autoload_register(static function(string $class)use($root):void{
 $prefix='Sokna\\Local\\';if(!str_starts_with($class,$prefix))return;
 $path=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;
});
require_once $root.'/src/Domain/Finance/SettlementException.php';
require_once $root.'/src/Domain/Tax/TaxException.php';
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Domain\Finance\SettlementService;
use Sokna\Local\Domain\Finance\SettlementException;
use Sokna\Local\Domain\Tax\TaxService;
class FixtureIdentity implements IdentityRepository{
 public function findActiveById(int $id):?array{return $id===1?['id'=>1,'role'=>'admin','display_name'=>'QA']:null;}
 public function findActiveByUsername(string $username):?array{return null;}
 public function capabilitiesForUser(int $id):array{return [];}
 public function preparationAreasForUser(int $id):array{return [];}
}
$p=new PDO('mysql:host=127.0.0.1;dbname=sokna_r309;charset=utf8mb4','root',getenv('R309_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema=[
"CREATE TABLE cafe_tables(id INT PRIMARY KEY,name VARCHAR(80)) ENGINE=InnoDB",
"CREATE TABLE table_sessions(id INT PRIMARY KEY,table_id INT,status VARCHAR(25),live_table_guard INT,discount_type VARCHAR(20) NULL,discount_value BIGINT NULL,discount_amount BIGINT NULL) ENGINE=InnoDB",
"CREATE TABLE orders(id INT PRIMARY KEY,session_id INT,status VARCHAR(30),total_amount BIGINT,accounting_origin VARCHAR(20)) ENGINE=InnoDB",
"CREATE TABLE order_items(id INT PRIMARY KEY,order_id INT,item_id INT NULL,item_name VARCHAR(100),unit_price BIGINT,quantity INT,line_total BIGINT,item_note TEXT,tax_policy_snapshot VARCHAR(30),tax_rate_bps_snapshot INT,tax_rate_version_id INT NULL,tax_item_policy_version_id INT NULL) ENGINE=InnoDB",
"CREATE TABLE settlement_records(id INT PRIMARY KEY,session_id INT,status VARCHAR(30),settlement_kind VARCHAR(30),allocation_version INT,reverses_settlement_id INT NULL,subtotal BIGINT,discount BIGINT,taxable_amount BIGINT,tax_amount BIGINT,total BIGINT,invoice_number VARCHAR(30),settled_at DATETIME,destination VARCHAR(25)) ENGINE=InnoDB",
"CREATE TABLE settlement_record_lines(id INT PRIMARY KEY AUTO_INCREMENT,settlement_id INT,order_item_id INT,quantity INT,discount_amount BIGINT,tax_amount BIGINT,gross_amount BIGINT,net_amount BIGINT,taxable_amount BIGINT,final_amount BIGINT) ENGINE=InnoDB",
"CREATE TABLE remaining_account_discount_grants(id INT PRIMARY KEY AUTO_INCREMENT,session_id INT UNIQUE,discount_type VARCHAR(12),discount_value BIGINT,base_unpaid_subtotal BIGINT,discount_amount BIGINT,actor_user_id INT) ENGINE=InnoDB",
"CREATE TABLE remaining_account_discount_lines(grant_id INT,order_item_id INT,quantity_snapshot INT,paid_quantity_at_grant INT,original_discount_snapshot BIGINT,discount_amount BIGINT,invoice_tax_after_grant BIGINT,invoice_taxable_after_grant BIGINT,PRIMARY KEY(grant_id,order_item_id)) ENGINE=InnoDB",
"CREATE TABLE settlement_followup_events(id INT PRIMARY KEY AUTO_INCREMENT,session_id INT,event_type VARCHAR(40),from_destination VARCHAR(30),to_destination VARCHAR(30),note TEXT,actor_user_id INT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
"CREATE TABLE users(id INT PRIMARY KEY,display_name VARCHAR(80)) ENGINE=InnoDB",
"CREATE TABLE audit_log(id INT AUTO_INCREMENT PRIMARY KEY,actor_user_id INT,actor_display_name_snapshot VARCHAR(80),action VARCHAR(100),entity_type VARCHAR(60),entity_id VARCHAR(60),details_json LONGTEXT) ENGINE=InnoDB"
];foreach($schema as $sql)$p->exec($sql);
$p->exec("INSERT INTO users VALUES(1,'QA')");
$identity=new FixtureIdentity();$capabilities=new Capabilities($identity);
$ctor=(new ReflectionClass(SettlementService::class));
$service=$ctor->newInstanceWithoutConstructor();
foreach(['pdo'=>$p,'identity'=>$identity,'capabilities'=>$capabilities] as $property=>$v)$ctor->getProperty($property)->setValue($service,$v);
$tax=new ReflectionClass(TaxService::class);$ctor->getProperty('tax')->setValue($service,$tax->newInstanceWithoutConstructor());
function makeSession(PDO $p,int $id,?string $discountType,int $discountValue,bool $taxed):array{
 $p->prepare('INSERT INTO cafe_tables VALUES(?,?)')->execute([$id,'میز آزمون '.$id]);
 $p->prepare('INSERT INTO table_sessions VALUES(?,?,\'active\',?,?,?,?)')->execute([$id,$id,$id,$discountType,$discountValue,0]);
 $p->prepare("INSERT INTO orders(id,session_id,status,total_amount,accounting_origin) VALUES(?,?,'accounted',440,'regular')")->execute([$id,$id]);
 $items=[];$rate=$taxed?1000:0;$policy=$taxed?'taxable':'disabled';
 foreach([[1,100,2],[2,80,3]] as [$offset,$price,$qty]){
  $itemId=$id*10+$offset;$p->prepare('INSERT INTO order_items VALUES(?,?,?,?,?,?,?,\'\',?,?,NULL,NULL)')->execute([$itemId,$id,null,$offset===1?'قهوه':'چای',$price,$qty,$price*$qty,$policy,$rate]);
  $items[]=['order_item_id'=>$itemId,'order_id'=>$id,'quantity'=>$qty,'unit_price'=>$price,'tax_policy_snapshot'=>$policy,'tax_rate_bps_snapshot'=>$rate,'accounting_origin'=>'regular'];
 }
 $calc=TaxService::calculateAccountLinesByOrigin($items,$discountType,$discountValue);
 $paidLine=$calc['lines'][0];
 $paidGross=100;$paidDiscount=TaxService::proportionalTarget((int)$paidLine['invoice_discount_amount'],2,1,false);
 $paidNet=$paidGross-$paidDiscount;$paidTax=TaxService::roundAmount($taxed?$paidNet:0,$rate);
 $p->prepare("INSERT INTO settlement_records VALUES(?,?, 'completed','itemized', ?,NULL,?,?,?, ?, ?,?,'2026-11-20 12:00:00','direct')")
  ->execute([$id,$id,$taxed?2:1,$paidGross,$paidDiscount,$taxed?$paidNet:0,$paidTax,$paidNet+$paidTax,'QA-'.$id]);
 $p->prepare('INSERT INTO settlement_record_lines(settlement_id,order_item_id,quantity,discount_amount,tax_amount,gross_amount,net_amount,taxable_amount,final_amount) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$id,$id*10+1,1,$paidDiscount,$paidTax,$paidGross,$paidNet,$taxed?$paidNet:0,$paidNet+$paidTax]);
 return ['session'=>$id,'paid'=>$paidNet+$paidTax,'paid_discount'=>$paidDiscount,'initial_discount'=>(int)$calc['discount']];
}
$tests=[];
$case=makeSession($p,1,'percent',10,true);
$beforeReceipt=$p->query('SELECT * FROM settlement_records')->fetchAll();$beforeLines=$p->query('SELECT * FROM settlement_record_lines')->fetchAll();
$acc=$service->account(1);assert($acc['receipt_count']===1&&$acc['remaining_subtotal']===340&&$acc['remaining_discount']===34);
$prev=$service->previewDiscount(1,'fixed',25,['id'=>1]);
assert($prev['subtotal']===306&&$prev['discount_amount']===25);
$updated=$service->setDiscount(1,'fixed',25,['id'=>1],$prev['expected_signature']);
assert($updated['discount_amount']===25&&$updated['remaining_only']===true);
$after=$service->account(1);
assert($after['remaining_total']===$prev['total']);
assert($p->query('SELECT * FROM settlement_records')->fetchAll()===$beforeReceipt);
assert($p->query('SELECT * FROM settlement_record_lines')->fetchAll()===$beforeLines);
$tests['second_discount_after_earlier_invoice_discount_paid_unchanged']=true;
try{$service->previewDiscount(1,'percent',5,['id'=>1]);throw new RuntimeException('Second remaining grant unexpectedly allowed');}
catch(SettlementException $e){assert($e->errorCode==='remaining_discount_exists');}
$tests['existing_remaining_grant_revision_blocked']=true;
try{$service->setDiscount(1,'fixed',26,['id'=>1],$prev['expected_signature']);throw new RuntimeException('stale write allowed');}
catch(SettlementException $e){assert(in_array($e->errorCode,['discount_account_changed','remaining_discount_exists'],true));}
assert((int)$p->query('SELECT COUNT(*) FROM remaining_account_discount_grants')->fetchColumn()===1);
$tests['stale_signature_and_duplicate_grant_blocked']=true;
$case2=makeSession($p,2,'fixed',44,true);$prev2=$service->previewDiscount(2,'percent',100,['id'=>1]);
assert($prev2['subtotal']===306&&$prev2['discount_amount']===306&&$prev2['total']===0);
$second=$service->setDiscount(2,'percent',100,['id'=>1],$prev2['expected_signature']);
assert($second['remaining_total']===0);
$after2=$service->account(2);assert($after2['paid_total']===$case2['paid']&&$after2['remaining_total']===0);
$tests['full_discount_100_on_unpaid_net_zero_balance']=true;
$case3=makeSession($p,3,null,0,false);$prev3=$service->previewDiscount(3,'percent',10,['id'=>1]);
assert($prev3['subtotal']===340&&$prev3['discount_amount']===34&&$prev3['total']===306);
$second3=$service->setDiscount(3,'percent',10,['id'=>1],$prev3['expected_signature']);
assert($second3['remaining_total']===306);
$tests['legacy_post_itemized_without_prior_discount']=true;
$case4=makeSession($p,4,'percent',10,false);$a4=$service->account(4);
try{$service->setDiscount(4,'fixed',25,['id'=>1],str_repeat('0',64));throw new RuntimeException('Expected preview signature was not guarded');}
catch(SettlementException $e){assert($e->errorCode==='discount_account_changed');}
assert((int)$p->query('SELECT COUNT(*) FROM remaining_account_discount_grants WHERE session_id=4')->fetchColumn()===0);
$tests['stale_preview_fails_closed']=true;
$result=['result'=>'PASS','tests'=>$tests,'paid_receipt_mutations'=>0,'additional_grant_count'=>(int)$p->query('SELECT COUNT(*) FROM remaining_account_discount_grants')->fetchColumn(),'source'=>'actual R307 PHP with R309 proposed patch on disposable MariaDB 11.4'];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'\n';
