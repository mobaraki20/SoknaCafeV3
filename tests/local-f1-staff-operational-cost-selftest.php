<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/apps/local-web/bootstrap.php';
use Sokna\Local\Domain\Inventory\InventoryOrderService;
function f15c_fail(string $m):never{fwrite(STDERR,$m.PHP_EOL);exit(1);} function f15c_assert(bool $c,string $m):void{if(!$c)f15c_fail($m);}
$service=(new ReflectionClass(InventoryOrderService::class))->newInstanceWithoutConstructor();
$summary=$service->costSummaryFromRecipeSnapshot([
 ['order_item_id'=>11,'menu_item_id'=>7,'order_quantity'=>3,'department'=>'kitchen','recipe_version_id'=>4,'recipe_version_no'=>2,'components'=>[
   ['recipe_component_id'=>31,'inventory_item_id'=>101,'quantity_base'=>2,'unit_cost_snapshot'=>100.0,'cost_status'=>'known'],
   ['recipe_component_id'=>32,'inventory_item_id'=>102,'quantity_base'=>1,'unit_cost_snapshot'=>50.0,'cost_status'=>'estimated'],
   ['recipe_component_id'=>33,'inventory_item_id'=>103,'quantity_base'=>1,'unit_cost_snapshot'=>null,'cost_status'=>'unknown'],
 ]],
]);
f15c_assert((int)$summary['known_cost_amount']===600,'known cost must be unit cost × recipe qty × order qty');
f15c_assert((int)$summary['estimated_cost_amount']===150,'estimated cost evidence drifted');
f15c_assert((int)$summary['unknown_component_count']===1,'unknown cost component was lost');
$line=$summary['lines']['11']??null;f15c_assert(is_array($line)&&(string)$line['cost_status']==='partial','mixed known/estimated/unknown line must remain partial');
f15c_assert((int)$line['components'][0]['quantity_base_total']===6,'historical recipe quantity snapshot drifted');
fwrite(STDOUT,"F1.5 operational cost snapshot self-test: OK\n");
