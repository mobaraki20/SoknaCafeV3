<?php
declare(strict_types=1);

// Development-only QA: no DB connections, no files or print queues modified.
require_once __DIR__.'/../apps/local-web/src/Domain/Printing/PrintService.php';

$method=new ReflectionMethod(\Sokna\Local\Domain\Printing\PrintService::class,'preparationSections');
$assertions=0;
$assert=function(bool $ok,string $name)use(&$assertions):void{
    ++$assertions;
    if(!$ok)throw new RuntimeException("R321 failed: ".$name);
};

$rows=[
    ['item_name'=>'پیتزا','quantity'=>1,'item_note'=>'بدون پیاز','preparation_station'=>'kitchen','fulfillment_mode'=>'dine_in'],
    ['item_name'=>'چای ساده','quantity'=>2,'item_note'=>'کم‌رنگ','preparation_station'=>'hot_bar','fulfillment_mode'=>'dine_in'],
    ['item_name'=>'سیب‌زمینی','quantity'=>1,'item_note'=>null,'preparation_station'=>'kitchen','fulfillment_mode'=>'dine_in'],
    ['item_name'=>'لیموناد','quantity'=>1,'item_note'=>'بدون یخ','preparation_station'=>'cold_bar','fulfillment_mode'=>'takeaway'],
    ['item_name'=>'باقلوا','quantity'=>1,'item_note'=>null,'preparation_station'=>'other','fulfillment_mode'=>'dine_in'],
];
$sections=$method->invoke(null,$rows);
$assert(count($sections)===4,'four stations (kitchen, hot, cold, other)');
$assert(array_column($sections,'station')===['kitchen','hot_bar','cold_bar','other'],'legacy first-appearance group order');
$flat=[];
foreach($sections as $section){
    foreach($section['items'] as $item){
        $assert($item['station']===$section['station'],'station metadata preserved');
        $assert(is_int($item['order_index']),'integer original order');
        $flat[]=$item;
    }
}
$assert(count($flat)===count($rows),'no item dropped or duplicated');
usort($flat,static fn($a,$b)=>$a['order_index']<=>$b['order_index']);
$assert(array_column($flat,'name')===['پیتزا','چای ساده','سیب‌زمینی','لیموناد','باقلوا'],'exact cross-station order recoverable');
$assert($flat[1]['quantity']===2&&$flat[1]['note']==='کم‌رنگ','quantity and note unchanged');
$assert($flat[3]['fulfillment_mode']==='takeaway','fulfillment retained');
$assert($method->invoke(null,[])===[],'empty slips contain no sections');

$barFirst=$flat;
usort($barFirst,static function($a,$b){
    $rank=static fn($s)=>in_array($s,['bar','hot_bar','cold_bar'],true)?0:($s==='kitchen'?1:2);
    return [$rank($a['station']),$a['order_index']]<=>[$rank($b['station']),$b['order_index']];
});
$assert(array_column($barFirst,'name')===['چای ساده','لیموناد','پیتزا','سیب‌زمینی','باقلوا'],'bar before kitchen across all original groups');
$kitchenFirst=$flat;
usort($kitchenFirst,static function($a,$b){
    $rank=static fn($s)=>$s==='kitchen'?0:(in_array($s,['bar','hot_bar','cold_bar'],true)?1:2);
    return [$rank($a['station']),$a['order_index']]<=>[$rank($b['station']),$b['order_index']];
});
$assert(array_column($kitchenFirst,'name')===['پیتزا','سیب‌زمینی','چای ساده','لیموناد','باقلوا'],'kitchen before bar across all original groups');
echo "R321 preparation metadata and order contract: {$assertions} assertions passed\n";
