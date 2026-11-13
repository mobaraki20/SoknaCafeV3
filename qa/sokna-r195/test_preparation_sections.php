<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/local/src/Domain/Printing/PrintService.php';
use Sokna\Local\Domain\Printing\PrintService;
$method = new ReflectionMethod(PrintService::class, 'preparationSections');
$make=static fn(string $name,string $station,int $qty=1,?string $note=null): array => [
    'item_name'=>$name,'preparation_station'=>$station,'quantity'=>$qty,'item_note'=>$note,
    'fulfillment_mode'=>'dine_in',
];
function ensure(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
$cases=0;
// Case 1: reported four-item layout, with the fourth on an unknown station.
$rows=[$make('قلم ۱','bar',1,'یادداشت یک'),$make('قلم ۲','bar',2),$make('قلم ۳','bar'),$make('قلم ۴','legacy_station',1,'یادداشت چهار')];
$s=$method->invoke(null,$rows);
ensure(count($s)===2,'four_item_section_count');
ensure(array_column($s,'title')===['بار','سایر'],'four_item_section_titles');
ensure(array_column($s[0]['items'],'name')===['قلم ۱','قلم ۲','قلم ۳'],'first_three_preserved');
ensure(array_column($s[1]['items'],'name')===['قلم ۴'],'fourth_preserved');
ensure($s[1]['items'][0]['note']==='یادداشت چهار','fourth_note_preserved');
ensure(!in_array('آماده‌سازی',array_column($s,'title'),true),'duplicate_generic_title');
$cases++;
// Unknown station identifiers may be from old catalog versions and should merge with other.
$s=$method->invoke(null,[$make('X','unknown_A'),$make('Y','other'),$make('Z','unknown_B')]);
ensure(count($s)===1 && $s[0]['title']==='سایر','unknown_station_merging');
ensure(array_column($s[0]['items'],'name')===['X','Y','Z'],'unknown_station_item_order');
$cases++;
// Known distinct stations retain original segmentation and relative order.
$s=$method->invoke(null,[$make('K','kitchen'),$make('B','bar'),$make('O','other')]);
ensure(array_column($s,'title')===['آشپزخانه','بار','سایر'],'known_stations');
$cases++;
// Empty/whitespace station and missing station use the documented fallback.
$s=$method->invoke(null,[$make('Empty',''),$make('Whitespace','   '),['item_name'=>'Missing','quantity'=>1]]);
ensure(count($s)===1 && $s[0]['title']==='سایر' && count($s[0]['items'])===3,'empty_missing_stations');
$cases++;
// Quantity preserved, note appears once, HTML-like content remains text (renderer escapes/paints it).
$s=$method->invoke(null,[$make('شربت','bar',9,'<note> test')]);
ensure($s[0]['items'][0]['quantity']===9 && $s[0]['items'][0]['note']==='<note> test','quantity_note_integrity');
$cases++;
// No items => no sections, never create phantom headers.
ensure($method->invoke(null,[])===[],'empty_order');
$cases++;
echo 'PASS_R195_PREPARATION_GROUPING_'.$cases.'_CASES' . PHP_EOL;
