<?php
declare(strict_types=1);
// R165 additional contract checks for live source. No DB writes or inferred counts.
$root=rtrim((string)($argv[1]??''),'/');
if(!is_file("$root/src/Domain/Inventory/InventoryService.php"))exit(64);
require_once "$root/src/Domain/Inventory/InventoryException.php";
require_once "$root/src/Domain/Inventory/InventoryService.php";
require_once "$root/src/Domain/Inventory/InventoryCountService.php";
require_once "$root/src/Domain/Reporting/CatalogFoodCost.php";
use Sokna\Local\Domain\Inventory\InventoryService;
use Sokna\Local\Domain\Inventory\InventoryCountService;
use Sokna\Local\Domain\Inventory\InventoryException;
use Sokna\Local\Domain\Inventory\InventoryStateConflict;
use Sokna\Local\Domain\Reporting\CatalogFoodCost;
function need(bool $result,string $reason):void{if(!$result)throw new RuntimeException($reason);echo "PASS $reason\n";}
need(InventoryService::canonicalNumber('۱٬۲۰۰٫۵')==='1200.5','PERSIAN_NUMBER_NORMALIZATION');
need(InventoryService::majorToBase('۱۲٫۵','g')===12500,'FRACTIONAL_KG_TO_GRAMS');
need(InventoryService::majorToBase('۲','count')===2,'COUNT_INTEGER_VALID');
try {InventoryService::majorToBase('۲٫۵','count');throw new RuntimeException('unexpected fractional count accepted');}catch(InventoryException $e){need($e->errorCode==='invalid_quantity','FRACTIONAL_COUNT_REJECTED');}
$recount=new ReflectionMethod(InventoryCountService::class,'recountRequested');
need($recount->invoke(null,'false')===false,'STRING_FALSE_NOT_RECOUNT');
need($recount->invoke(null,'true')===true,'STRING_TRUE_IS_RECOUNT');
try{$recount->invoke(null,'maybe');throw new RuntimeException('unexpected invalid flag accepted');}catch(InventoryException $e){need($e->errorCode==='invalid_recount','BAD_RECOUNT_FLAG_REJECTED');}
$version=new ReflectionMethod(InventoryCountService::class,'assertExpectedLineVersion');
try{$version->invoke(null,'','2026-11-12 10:00:00.000001',false);throw new RuntimeException('unexpected unversioned acceptance');}catch(InventoryStateConflict $e){need($e->errorCode==='count_version_required','AUTOSAVE_VERSION_REQUIRED');}
try{$version->invoke(null,'2026-11-12 10:00:00.000001','2026-11-12 10:00:00.000002',false);throw new RuntimeException('unexpected stale acceptance');}catch(InventoryStateConflict $e){need($e->errorCode==='count_line_changed','STALE_LINE_WRITE_REJECTED');}
$complete=['completeness_status'=>'complete','review_status'=>'ready'];
$known=[['inventory_item_id'=>1,'name'=>'قهوه','quantity_base'=>18000,'cost_status'=>'known','average_unit_cost'=>1.5],['inventory_item_id'=>2,'name'=>'شیر','quantity_base'=>25000,'cost_status'=>'known','average_unit_cost'=>0.5]];
$r=CatalogFoodCost::estimate($known,$complete,250000);
need($r['status']==='known'&&$r['ingredient_cost']===39500&&$r['food_cost_percent']===15.8,'COMPLETE_RECIPE_KNOWN_COST');
$unknown=$known;$unknown[1]['cost_status']='unknown';$unknown[1]['average_unit_cost']=null;$unknown[1]['quantity_status']='not_counted';
$r2=CatalogFoodCost::estimate($unknown,$complete,250000);
need($r2['status']==='partial'&&$r2['ingredient_cost']===null&&$r2['price_minus_ingredients']===null&&$r2['food_cost_percent']===null&&$r2['known_cost_subtotal']===27000,'UNKNOWN_INGREDIENT_NEVER_REPORTED_AS_ZERO_COST');
$none=CatalogFoodCost::estimate([],null,250000);
need($none['status']==='missing_recipe'&&$none['ingredient_cost']===null,'MISSING_RECIPE_NOT_ZERO_COST');
echo "PASS R165_INVENTORY_INPUT_AND_COST_PURE_CONTRACTS\n";
