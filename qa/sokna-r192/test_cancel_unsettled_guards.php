<?php
declare(strict_types=1);
/** QA-only transaction guard matrix. Does not simulate MariaDB/stock/printing. */
$root=dirname(__DIR__).'/local';
require_once $root.'/src/Core/IdentityRepository.php';
require_once $root.'/src/Core/Capabilities.php';
require_once $root.'/src/Domain/Orders/OrderStaffActionException.php';
require_once $root.'/src/Domain/Orders/OrderStaffActionService.php';
require_once $root.'/src/Domain/Inventory/InventoryOrderService.php';
require_once $root.'/src/Domain/Printing/PrintService.php';
require_once $root.'/src/Domain/Preparation/PreparationService.php';
class FakeId implements \Sokna\Local\Core\IdentityRepository {
 public function findActiveById(int $id):?array{return ['id'=>$id,'role'=>'admin','display_name'=>'QA'];}
 public function findActiveByUsername(string $s):?array{return null;}
 public function capabilitiesForUser(int $id):array{return ['orders_floor','shift_supervision'];}
 public function preparationAreasForUser(int $id):array{return [];}
}
class FakeDb extends PDO {
 public array $state=[]; public array $sql=[]; private bool $transaction=false; public int $commits=0,$rollbacks=0;
 public function __construct(array $state){$this->state=$state;}
 public function inTransaction():bool{return $this->transaction;}
 public function beginTransaction():bool{$this->transaction=true;return true;}
 public function commit():bool{$this->transaction=false;$this->commits++;return true;}
 public function rollBack():bool{$this->transaction=false;$this->rollbacks++;return true;}
 public function prepare(string $query,array $options=[]):PDOStatement|false{$this->sql[]=$query;return new FakeStmt($this,$query);}
}
class FakeStmt extends PDOStatement {
 private array $rows=[]; public function __construct(private FakeDb $db,private string $sql){}
 public function execute(?array $params=null):bool{
  $s=$this->db->state;$q=$this->sql;$this->rows=[];
  if(str_contains($q,'SELECT table_id,session_id FROM orders')) $this->rows=[['table_id'=>7,'session_id'=>3]];
  elseif(str_contains($q,'SELECT id FROM cafe_tables')) $this->rows=[['id'=>7]];
  elseif(str_contains($q,'FROM table_sessions WHERE id=?')) $this->rows=[['id'=>3,'table_id'=>7,'status'=>$s['session']??'active','live_table_guard'=>$s['guard']??7,'service_detached_at'=>null]];
  elseif(str_contains($q,'FROM orders WHERE id=? FOR UPDATE')) $this->rows=[['id'=>11,'status'=>$s['status']??'accounted','table_id'=>7,'session_id'=>3,'order_source'=>$s['source']??'staff','accounting_origin'=>'regular','total_amount'=>100]];
  elseif(str_contains($q,'FROM settlement_records')) $this->rows=!empty($s['payment'])?[['id'=>44]]:[];
  elseif(str_contains($q,'FROM order_preparation_claims')) $this->rows=!empty($s['claimed'])?[['order_id'=>11]]:[];
  elseif(str_contains($q,'FROM order_item_adjustments')) $this->rows=!empty($s['pending'])?[['id'=>12]]:[];
  elseif(str_contains($q,'FROM inventory_order_events')) $this->rows=!empty($s['inventory_pending'])?[['id'=>20]]:[];
  elseif(str_contains($q,'FROM order_items WHERE order_id=? ORDER BY')) $this->rows=$s['items']??[];
  elseif(str_contains($q,'FROM settings WHERE')) $this->rows=[['setting_value'=>$s['auto_print']??'1']];
  else throw new RuntimeException('Unexpected test query: '.$q);
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return array_shift($this->rows)?:false;}
 public function fetchColumn(int $column=0):mixed{$r=$this->fetch();return is_array($r)?array_values($r)[$column]??false:false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;}
}
function service(FakeDb $db):\Sokna\Local\Domain\Orders\OrderStaffActionService{
 $id=new FakeId();$cap=new \Sokna\Local\Core\Capabilities($id);
 $inventory=(new ReflectionClass(\Sokna\Local\Domain\Inventory\InventoryOrderService::class))->newInstanceWithoutConstructor();
 $print=(new ReflectionClass(\Sokna\Local\Domain\Printing\PrintService::class))->newInstanceWithoutConstructor();
 $prep=(new ReflectionClass(\Sokna\Local\Domain\Preparation\PreparationService::class))->newInstanceWithoutConstructor();
 return new \Sokna\Local\Domain\Orders\OrderStaffActionService($db,$id,$cap,$inventory,$print,$prep);
}
$case=[
 'settled_receipt'=>[['payment'=>true],'settlement_exists'],
 'already_claimed'=>[['claimed'=>true],'preparation_claimed'],
 'pending_adjustment'=>[['pending'=>true],'pending_adjustment'],
 'inventory_pending'=>[['inventory_pending'=>true],'inventory_pending'],
 'completed_order'=>[['status'=>'completed'],'unsafe_order'],
 'guest_order'=>[['source'=>'guest'],'unsafe_order'],
 'closed_session'=>[['session'=>'closed'],'session_closed'],
 'empty_order'=>[['items'=>[]],'order_empty'],
 'subtotal_mismatch'=>[['items'=>[['id'=>1,'quantity'=>1,'line_total'=>90,'preparation_station'=>'other']]],'order_amount_mismatch'],
 'prep_print_disabled'=>[['items'=>[['id'=>1,'quantity'=>1,'line_total'=>100,'preparation_station'=>'bar']],'auto_print'=>'0'],'correction_print_disabled'],
 ];
$i=0;foreach($case as $name=>[$state,$expected]){
 $db=new FakeDb($state);$s=service($db);
 try{$s->cancelUnsettledStaffOrder(['order_id'=>11,'request_id'=>'r192-test-001','reason'=>'خطای ثبت'],['id'=>1]);throw new RuntimeException('expected failure missing '.$name);}
 catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){if($e->errorCode!==$expected)throw new RuntimeException($name.': '.$e->errorCode.' != '.$expected);}
 if($db->commits!==0||$db->rollbacks!==1||$db->inTransaction())throw new RuntimeException($name.': transaction not rolled back');
 if(array_any($db->sql,static fn($q):bool=>str_contains($q,'UPDATE orders')||str_contains($q,'INSERT INTO order_status_history')))throw new RuntimeException($name.': wrote to orders');
 $i++;
}
echo "PASS_R192_BLOCKED_CANCEL_GUARDS $i (mock PDO, no MariaDB)\n";