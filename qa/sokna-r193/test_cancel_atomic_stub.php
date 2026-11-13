<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Inventory {
 final class InventoryOrderService {
  public static bool $tracked=false,$enqueueFail=false;
  public function accountedRecipeSnapshotTx(int $oid):array{return self::$tracked?[['order_item_id'=>21,'components'=>[['opening_eligible_at_order'=>true]]]]:[];}
  public function enqueueQuantityAdjustedTx(int $oid,int $item,int $from,int $to,int $delta,int $actor,string $request):?int{return self::$enqueueFail?null:83;}
 }
}
namespace Sokna\Local\Domain\Printing {
 final class PrintService {public function enqueueOrderAdjustmentTx(int $id,int $actor):array{return ['job_id'=>72,'status'=>'pending'];}}
}
namespace Sokna\Local\Domain\Preparation {
 final class PreparationService {
  public static function areaForPreparationStation(string $station):?string{return in_array($station,['bar','kitchen'],true)?$station:null;}
  public function claimStateForAdjustmentTx(int $order,string $area):array{return ['has_claim'=>false,'current'=>false];}
  public function refreshClaimSignatureTx(int $order,string $area):void{}
 }
}
namespace {
 use Sokna\Local\Domain\Orders\OrderStaffActionService;
 use Sokna\Local\Domain\Orders\OrderStaffActionException;
 $root=dirname(__DIR__).'/local';
 require $root.'/src/Core/IdentityRepository.php';require $root.'/src/Core/Capabilities.php';require $root.'/src/Domain/Orders/OrderStaffActionException.php';require $root.'/src/Domain/Orders/OrderStaffActionService.php';
 class StubIdentity implements \Sokna\Local\Core\IdentityRepository {
  public function findActiveById(int $id):?array{return ['id'=>$id,'role'=>'admin','display_name'=>'QA'];}
  public function findActiveByUsername(string $s):?array{return null;}
  public function capabilitiesForUser(int $id):array{return [];}
  public function preparationAreasForUser(int $id):array{return [];}
 }
 class FixturePdo extends \PDO {
  public bool $tx=false;public int $commits=0,$rollbacks=0,$lastId=0;public array $state;private array $backup=[];
  public function __construct(bool $otherOrders=false,string $station='other'){$this->state=['order_status'=>'accounted','order_total'=>100,'item_quantity'=>1,'item_total'=>100,'prep_station'=>$station,'session_status'=>'active','other_orders'=>$otherOrders,'audit'=>[],'history'=>[],'item_adjustments'=>[]];}
  public function beginTransaction():bool{$this->tx=true;$this->backup=$this->state;return true;}
  public function inTransaction():bool{return $this->tx;}
  public function commit():bool{$this->tx=false;$this->commits++;return true;}
  public function rollBack():bool{$this->state=$this->backup;$this->tx=false;$this->rollbacks++;return true;}
  public function prepare(string $q,array $options=[]):PDOStatement|false{return new FixtureStatement($this,$q);}
  public function lastInsertId(?string $name=null):string|false{return (string)$this->lastId;}
 }
 class FixtureStatement extends PDOStatement {
  private array $rows=[];public int $changed=0;
  public function __construct(private FixturePdo $db,private string $sql){}
  public function execute(?array $params=null):bool{
   $s=&$this->db->state;$q=$this->sql;$p=$params??[];$this->rows=[];$this->changed=0;
   if(str_contains($q,'SELECT table_id,session_id FROM orders'))$this->rows=[['table_id'=>7,'session_id'=>3]];
   elseif(str_contains($q,'SELECT id FROM cafe_tables'))$this->rows=[['id'=>7]];
   elseif(str_contains($q,'SELECT id,status,table_id,live_table_guard,service_detached_at FROM table_sessions'))$this->rows=[['id'=>3,'table_id'=>7,'status'=>$s['session_status'],'live_table_guard'=>7,'service_detached_at'=>null]];
   elseif(str_contains($q,'FROM orders WHERE id=? FOR UPDATE'))$this->rows=[['id'=>11,'status'=>$s['order_status'],'table_id'=>7,'session_id'=>3,'order_source'=>'staff','accounting_origin'=>'regular','total_amount'=>$s['order_total']]];
   elseif(str_contains($q,'SELECT id FROM settlement_followup_events'))$this->rows=[];
   elseif(str_contains($q,'SELECT id FROM settlement_records')||str_contains($q,'SELECT order_id FROM order_preparation_claims')||str_contains($q,'SELECT id FROM order_item_adjustments WHERE order_id')||str_contains($q,'SELECT id FROM inventory_order_events'))$this->rows=[];
   elseif(str_contains($q,'SELECT id,quantity,line_total,preparation_station FROM order_items'))$this->rows=[['id'=>21,'quantity'=>$s['item_quantity'],'line_total'=>$s['item_total'],'preparation_station'=>$s['prep_station']]];
   elseif(str_contains($q,"SELECT setting_value FROM settings"))$this->rows=[['setting_value'=>'1']];
   elseif(str_contains($q,'SELECT * FROM order_item_adjustments WHERE request_id'))$this->rows=[];
   elseif(str_contains($q,'FROM order_items oi JOIN orders o ON'))$this->rows=[['order_item_id'=>21,'order_id'=>11,'item_name'=>'محصول آزمایشی','quantity'=>$s['item_quantity'],'ordered_quantity'=>1,'unit_price'=>100,'item_note'=>'','fulfillment_mode'=>'dine_in','preparation_station'=>$s['prep_station'],'order_status'=>$s['order_status'],'accounting_origin'=>'regular','session_id'=>3,'table_id'=>7,'session_status'=>'active','live_table_guard'=>7]];
   elseif(str_contains($q,'SELECT 1 FROM settlement_records'))$this->rows=[];
   elseif(str_contains($q,'UPDATE order_items SET quantity=')){$s['item_quantity']=(int)$p[0];$s['item_total']=(int)$p[1];$this->changed=1;}
   elseif(str_contains($q,'UPDATE orders SET total_amount=')){$s['order_total']=$s['item_total'];$this->changed=1;}
   elseif(str_contains($q,'INSERT INTO order_item_adjustments(')){$this->db->lastId=42;$s['item_adjustments'][]=$p;$this->changed=1;}
   elseif(str_contains($q,'SELECT * FROM order_item_adjustments WHERE id=?'))$this->rows=[['id'=>42,'order_id'=>11,'order_item_id'=>21,'from_quantity'=>1,'to_quantity'=>0,'delta_quantity'=>-1,'adjustment_kind'=>'quantity','from_fulfillment_mode'=>'dine_in','to_fulfillment_mode'=>'dine_in','preparation_ack_status'=>'not_required','inventory_quantity_delta'=>-1,'inventory_effect'=>'reverse_unprepared','correction_print_job_id'=>0,'reason'=>'لغو کامل سفارش']];
   elseif(str_contains($q,"UPDATE orders SET status='cancelled'")){$s['order_status']='cancelled';$this->changed=1;}
   elseif(str_contains($q,'INSERT INTO order_status_history')){$s['history'][]=$p;$this->changed=1;}
   elseif(str_contains($q,"SELECT COUNT(*) FROM orders WHERE session_id"))$this->rows=[['count'=>(int)$s['other_orders']]];
   elseif(str_contains($q,'SELECT id FROM table_drafts'))$this->rows=[];
   elseif(str_contains($q,"UPDATE waiter_calls SET status='cancelled'")){$this->changed=0;}
   elseif(str_contains($q,"UPDATE table_sessions SET status='closed'")){$s['session_status']='closed';$this->changed=1;}
   elseif(str_contains($q,'INSERT INTO audit_log')){$s['audit'][]=$p;$this->changed=1;}
   else throw new \RuntimeException('Unexpected fixture SQL: '.substr($q,0,150));
   return true;
  }
  public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return array_shift($this->rows)?:false;}
  public function fetchColumn(int $column=0):mixed{$r=$this->fetch();return is_array($r)?array_values($r)[$column]??false:false;}
  public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return $this->rows;}
  public function rowCount():int{return $this->changed;}
 }
 function service(FixturePdo $db):OrderStaffActionService{$id=new StubIdentity();return new OrderStaffActionService($db,$id,new \Sokna\Local\Core\Capabilities($id),new \Sokna\Local\Domain\Inventory\InventoryOrderService(),new \Sokna\Local\Domain\Printing\PrintService(),new \Sokna\Local\Domain\Preparation\PreparationService());}
 $n=0;
 foreach([false,true] as $other){
  $db=new FixturePdo($other);$out=service($db)->cancelUnsettledStaffOrder(['order_id'=>11,'request_id'=>'r192-fixture-1234','reason'=>'ثبت اشتباه'],['id'=>1]);
  if(($out['success']??false)!==true||$db->commits!==1||$db->rollbacks!==0||$db->state['order_status']!=='cancelled'||$db->state['item_quantity']!==0||$db->state['order_total']!==0||$db->state['session_status']!==($other?'active':'closed')||count($db->state['audit'])!==2)throw new \RuntimeException('Cancelled order fixture mismatch '.json_encode($out));
  $n++;
 }
 \Sokna\Local\Domain\Inventory\InventoryOrderService::$tracked=true;\Sokna\Local\Domain\Inventory\InventoryOrderService::$enqueueFail=true;
 $db=new FixturePdo(false);try{service($db)->cancelUnsettledStaffOrder(['order_id'=>11,'request_id'=>'r192-fixture-1234','reason'=>'ثبت اشتباه'],['id'=>1]);throw new \RuntimeException('Expected inventory error');}
 catch(OrderStaffActionException $e){if($e->errorCode!=='inventory_event_missing')throw $e;}
 if($db->rollbacks!==1||$db->commits!==0||$db->state['order_total']!==100||$db->state['item_quantity']!==1||$db->state['order_status']!=='accounted')throw new \RuntimeException('Atomic rollback failed');
 $n++;echo "PASS_R192_ATOMIC_STUB $n (fake PDO and mocked dependencies; not MariaDB)\n";
}
