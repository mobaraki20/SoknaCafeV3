<?php
declare(strict_types=1);
require __DIR__.'/../apps/local-web/src/Domain/Printing/PrintService.php';
use Sokna\Local\Domain\Printing\PrintService;

$method=new ReflectionMethod(PrintService::class,'preparationSections');
$legacyMethod=new ReflectionMethod(PrintService::class,'sortPreparationRows');
$pass=0;$failed=[];
function check(bool $ok,string $label): void {global $pass,$failed;if($ok)$pass++;else $failed[]=$label;}
function project(array $rows):array {
 $m=new ReflectionMethod(PrintService::class,'preparationSections');
 return $m->invoke(null,$rows);
}
function flattened(array $sections):array{return array_merge(...array_map(fn($s)=>$s['items'],$sections?:[['items'=>[]]]));}
function simulate(array $items,string $mode):array {
 $rank=static function(array $row)use($mode):int {
  $station=$row['station'];$bar=in_array($station,['bar','hot_bar','cold_bar'],true);$kitchen=$station==='kitchen';
  return $mode==='as_ordered'?0:($mode==='bar_first'?($bar?0:($kitchen?1:2)):($kitchen?0:($bar?1:2)));
 };
 usort($items,static fn($a,$b)=>[$rank($a),$a['order_index']]<=>[$rank($b),$b['order_index']]);
 return $items;
}
$rows=[
 ['item_name'=>'چای','quantity'=>2,'item_note'=>'کم‌رنگ','preparation_station'=>'hot_bar','fulfillment_mode'=>'dine_in'],
 ['item_name'=>'پیتزا','quantity'=>1,'item_note'=>'بدون قارچ','preparation_station'=>'kitchen','fulfillment_mode'=>'dine_in'],
 ['item_name'=>'لیموناد','quantity'=>1,'item_note'=>'کم یخ','preparation_station'=>'cold_bar','fulfillment_mode'=>'takeaway'],
 ['item_name'=>'سیب‌زمینی','quantity'=>2,'item_note'=>'ترد','preparation_station'=>'kitchen','fulfillment_mode'=>'dine_in'],
 ['item_name'=>'سفارش متفرقه','quantity'=>1,'item_note'=>null,'preparation_station'=>'other','fulfillment_mode'=>'dine_in'],
];
$doc=project($rows);$items=flattened($doc);
check(count($doc)===1,'single default section');
check(($doc[0]['title']??'!')==='','default title empty, no station heading');
check(count($items)===count($rows),'all rows retained');
check(array_column($items,'name')===['پیتزا','سیب‌زمینی','چای','لیموناد','سفارش متفرقه'],'default kitchen-first unchanged');
check(array_column($items,'order_index')===[1,3,0,2,4],'source order indices preserved across groups');
check(array_column($items,'station')===['kitchen','kitchen','hot_bar','cold_bar','other'],'stations preserved');
check(array_column($items,'note')===['بدون قارچ','ترد','کم‌رنگ','کم یخ',null],'notes preserved by item');
check(array_column($items,'quantity')===[1,2,2,1,1],'quantities preserved');
check(array_column(simulate($items,'as_ordered'),'name')===['چای','پیتزا','لیموناد','سیب‌زمینی','سفارش متفرقه'],'original source order recoverable');
check(array_column(simulate($items,'bar_first'),'name')===['چای','لیموناد','پیتزا','سیب‌زمینی','سفارش متفرقه'],'bar first global');
check(array_column(simulate($items,'kitchen_first'),'name')===['پیتزا','سیب‌زمینی','چای','لیموناد','سفارش متفرقه'],'kitchen first global');
$empty=project([]);check($empty===[],'empty input');
$permutations=[];for($i=0;$i<30;$i++){$station=['bar','kitchen','hot_bar','other','cold_bar'][$i%5];$permutations[]=['item_name'=>'item'.$i,'quantity'=>$i+1,'item_note'=>'note'.$i,'preparation_station'=>$station];}
$items=flattened(project($permutations));
check(count($items)===30,'30 items retained');
check(count(array_unique(array_column($items,'order_index')))===30,'indices unique');
check(array_column(simulate($items,'as_ordered'),'name')===array_column($permutations,'item_name'),'30-item original order exact');
check(array_sum(array_column($items,'quantity'))===array_sum(array_column($permutations,'quantity')),'quantity totals unchanged');
check(count(array_unique(array_column($items,'name')))===30,'duplicate-free by identity');
check(array_column(simulate($items,'bar_first'),'name')!==array_column(simulate($items,'kitchen_first'),'name'),'sort modes differ');
$legacy=$legacyMethod->invoke(null,$rows);check(array_column($legacy,'item_name')===['پیتزا','سیب‌زمینی','چای','لیموناد','سفارش متفرقه'],'legacy sorting retained');
if($failed){fwrite(STDERR,'FAIL '.implode('; ',$failed)."\n");exit(1);}echo json_encode(['result'=>'PASS','passed'=>$pass,'failed'=>0,'fixture_items'=>count($rows),'stress_items'=>30,'legacy_default'=>'kitchen_then_bar','verified'=>['source order','bar first','kitchen first','notes','quantities','no missing items']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";