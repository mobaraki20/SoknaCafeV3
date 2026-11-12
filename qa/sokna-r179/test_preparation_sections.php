<?php
declare(strict_types=1);
// Non-mutating source regression: this test intentionally DOES NOT render or print.
require_once dirname(__DIR__,2).'/apps/local-web/src/Domain/Printing/PrintService.php';
$method = new ReflectionMethod(\Sokna\Local\Domain\Printing\PrintService::class, 'preparationSections');
$make = static fn(string $name,string $station): array => ['item_name'=>$name,'quantity'=>1,'item_note'=>null,'fulfillment_mode'=>'dine_in','preparation_station'=>$station];
$same = $method->invoke(null,[$make('قلم اول','bar'),$make('قلم دوم','bar'),$make('قلم سوم','bar'),$make('قلم چهارم','bar')]);
if (count($same)!==1 || count($same[0]['items'])!==4 || $same[0]['title']!=='بار') throw new RuntimeException('SAME_STATION_GROUPING_FAILED');
echo "PASS SAME_STATION_FOUR_ITEMS_ONE_GROUP\n";
$mixed = $method->invoke(null,[$make('قلم اول','bar'),$make('قلم دوم','bar'),$make('قلم سوم','bar'),$make('قلم چهارم','unmapped_station')]);
if (count($mixed)!==2 || $mixed[1]['title']!=='آماده‌سازی'||count($mixed[1]['items'])!==1) throw new RuntimeException('UNKNOWN_STATION_FALLBACK_CHANGED');
echo "PASS CURRENT_UNKNOWN_STATION_CREATES_SECOND_GENERIC_PREP_HEADING\n";
$known=$method->invoke(null,[$make('قلم اول','bar'),$make('قلم دوم','kitchen')]);
if(count($known)!==2 || $known[1]['title']!=='آشپزخانه')throw new RuntimeException('KNOWN_STATIONS_MIXED');
echo "PASS KNOWN_STATIONS_PRESERVE_EXPLICIT_LABELS\n";
