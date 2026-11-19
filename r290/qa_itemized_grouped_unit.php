<?php
declare(strict_types=1);
$root=getenv('SOKNA_QA_ROOT')?:__DIR__.'/source';
require_once $root.'/src/Domain/Orders/OrderStaffActionException.php';
require_once $root.'/src/Domain/Orders/OrderStaffActionService.php';
use Sokna\Local\Domain\Orders\OrderStaffActionService;
use Sokna\Local\Domain\Orders\OrderStaffActionException;
$object=(new ReflectionClass(OrderStaffActionService::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod(OrderStaffActionService::class,'planGroupedCorrectionTx');
$cases=0;
$run=static function(array $rows,array $paid,array $targets,bool $note=false) use($method,$object,&$cases):array{
 ++$cases;
 $result=$method->invoke($object,$rows,$paid,$targets,$note);
 foreach($rows as $r){$id=$r['id'];assert($result['quantities'][$id] >= ($paid[$id]??0));}
 foreach(['dine_in','takeaway'] as $mode){
  $existing=0;
  foreach($rows as $r)if($r['fulfillment_mode']===$mode)$existing+=$result['quantities'][$r['id']];
  $new=$mode==='dine_in'?$targets['dine_in']:$targets['takeaway'];
  if(in_array($mode,$result['insert_modes'],true))assert($existing===0);else assert($existing===$new);
 }
 return $result;
};
$blocked=static function(array $rows,array $paid,array $target,string $expected,bool $note=false) use($run):void{
 try{$run($rows,$paid,$target,$note);}
 catch(OrderStaffActionException $e){if($e->errorCode===$expected)return;throw new RuntimeException('WRONG_CODE '.$e->errorCode);}
 throw new RuntimeException('BLOCK_NOT_ENFORCED '.$expected);
};
$rows=[['id'=>10,'quantity'=>4,'fulfillment_mode'=>'dine_in'],['id'=>11,'quantity'=>2,'fulfillment_mode'=>'dine_in'],['id'=>12,'quantity'=>3,'fulfillment_mode'=>'takeaway']];
$paid=[10=>2,12=>1];
$r=$run($rows,$paid,['dine_in'=>4,'takeaway'=>3]);assert($r['quantities'][10]===4&&$r['quantities'][11]===0&&$r['quantities'][12]===3);
$r=$run($rows,$paid,['dine_in'=>3,'takeaway'=>2]);assert($r['quantities'][10]===3&&$r['quantities'][11]===0&&$r['quantities'][12]===2);
$r=$run($rows,$paid,['dine_in'=>8,'takeaway'=>3]);assert($r['quantities'][11]===4&&$r['quantities'][10]===4);
$blocked($rows,$paid,['dine_in'=>1,'takeaway'=>3],'paid_mode_quantity_floor');
$blocked($rows,$paid,['dine_in'=>7,'takeaway'=>0],'paid_mode_quantity_floor');
$blocked($rows,$paid,['dine_in'=>6,'takeaway'=>3],'paid_item_identity_locked',true);
$blocked([['id'=>5,'quantity'=>2,'fulfillment_mode'=>'dine_in']],[5=>3],['dine_in'=>2,'takeaway'=>0],'paid_quantity_invalid');
$test=$run([['id'=>20,'quantity'=>5,'fulfillment_mode'=>'dine_in']],[20=>3],['dine_in'=>3,'takeaway'=>1]);assert($test['quantities'][20]===3&&$test['insert_modes']===['takeaway']);
$test=$run([['id'=>21,'quantity'=>1,'fulfillment_mode'=>'dine_in']],[21=>0],['dine_in'=>0,'takeaway'=>1],true);assert($test['quantities'][21]===0&&$test['insert_modes']===['takeaway']);
echo json_encode(['status'=>'PASS','cases'=>$cases,'source'=>'actual Local OrderStaffActionService::planGroupedCorrectionTx'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";