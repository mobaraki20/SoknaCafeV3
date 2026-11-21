<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/local-web/src/Domain/Printing/PrintTemplatePackageService.php';
$ref = new ReflectionClass(\Sokna\Local\Domain\Printing\PrintTemplatePackageService::class);
$instance = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod('fixture');
$checks = [];
function check(bool $yes, string $label): void {global $checks; $checks[$label]=$yes; if(!$yes)throw new RuntimeException($label);}
$fixture = $method->invoke($instance, 'preparation');
$sections = $fixture['sections'] ?? [];
check(count($sections)===1, 'one_realistic_continuous_section');
check(($sections[0]['title']??null)==='', 'no_fake_group_section_heading');
$items=$sections[0]['items']??[];
check(count($items)===4, 'sample_has_four_distinct_items');
check(count(array_unique(array_column($items,'name')))==4,'item_names_distinct');
check(count(array_unique(array_column($items,'order_index')))==4,'original_order_indices_unique');
check(count(array_unique(array_column($items,'station')))>=3,'three_real_station_categories');
check(array_column($items,'station')===['kitchen','kitchen','hot_bar','cold_bar'],'default_production_order_is_kitchen_first');
foreach($items as $item)check(isset($item['name'],$item['quantity'],$item['station'],$item['order_index']),'renderer_contract_keys_present');
$as=$items; usort($as,fn($a,$b)=>$a['order_index']<=>$b['order_index']);
check(array_column($as,'name')===['چای ساده','پیتزا مخصوص','لیموناد','سیب‌زمینی'],'as_ordered_is_original_customer_sequence');
$rank=function(array $item,string $mode):int { $v=$item['station']; $isKitchen=$v==='kitchen'; $isBar=in_array($v,['bar','hot_bar','cold_bar'],true); return $mode==='bar_first'?($isBar?0:($isKitchen?1:2)):($isKitchen?0:($isBar?1:2)); };
foreach(['bar_first','kitchen_first'] as $mode){$out=$items;usort($out,fn($a,$b)=>($rank($a,$mode)<=>$rank($b,$mode)) ?: ($a['order_index']<=>$b['order_index']));check(count($out)===4,'no_item_dropped_in_'.$mode);$names=array_column($out,'name');check(count(array_unique($names))===4,'no_duplicate_in_'.$mode);if($mode==='bar_first')check($names===['چای ساده','لیموناد','پیتزا مخصوص','سیب‌زمینی'],'bar_first_order');else check($names===['پیتزا مخصوص','سیب‌زمینی','چای ساده','لیموناد'],'kitchen_first_order');}
$customer=$method->invoke($instance,'customer');check(($customer['document_role']??'')==='receipt_preview','customer_fixture_not_falsely_unpaid');
check(($customer['total']??0)===580000,'customer_total_fixture_consistent');
check(!array_key_exists('invoice_number',$fixture),'preparation_never_has_invoice');
require_once __DIR__.'/../apps/local-web/src/Domain/Printing/PrintService.php';
$printService = new ReflectionClass(\Sokna\Local\Domain\Printing\PrintService::class);
$sectionsFn=$printService->getMethod('preparationSections');
$raw=[
 ['item_name'=>'چای ساده','quantity'=>2,'item_note'=>'کم‌رنگ','preparation_station'=>'hot_bar'],
 ['item_name'=>'پیتزا مخصوص','quantity'=>1,'item_note'=>'بدون پیاز؛ سس جداگانه','preparation_station'=>'kitchen'],
 ['item_name'=>'لیموناد','quantity'=>1,'item_note'=>'بدون یخ','preparation_station'=>'cold_bar'],
 ['item_name'=>'سیب‌زمینی','quantity'=>1,'item_note'=>'','preparation_station'=>'kitchen'],
];
$normal=$sectionsFn->invoke(null,$raw);
check(count($normal)===1,'real_document_single_section');
$actual=$normal[0]['items'];
check(array_column($actual,'name')===array_column($items,'name'),'exact_preview_default_matches_real_document_order');
check(array_column($actual,'order_index')===array_column($items,'order_index'),'preview_original_order_matches_real_document');
check(array_column($actual,'station')===array_column($items,'station'),'preview_stations_match_real_document');
check(array_column($actual,'note')===array_column($items,'note'),'preview_notes_match_real_document');
check(count($actual)===4,'real_document_no_items_lost');
check(count(array_unique(array_column($actual,'order_index')))==4,'real_document_original_order_unique');
echo json_encode(['status'=>'PASS','case_count'=>count($checks),'checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";