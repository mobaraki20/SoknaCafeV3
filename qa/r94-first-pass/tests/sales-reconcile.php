<?php
declare(strict_types=1);
require __DIR__.'/../overlays/local/src/Domain/Reporting/ReportingService.php';
require __DIR__.'/../../../apps/public-edge/src/Remote/PublicDashboardFinance.php';
require __DIR__.'/../../../apps/public-edge/src/Http/PublicReportArchiveRange.php';
use Sokna\Local\Domain\Reporting\ReportingService as L;
use Sokna\PublicEdge\Remote\PublicDashboardFinance as P;
use Sokna\PublicEdge\Http\PublicReportArchiveRange as A;
$n=0;
function expectSame(mixed $a,mixed $b,string $label): void {global $n;$n++;if($a!==$b)throw new RuntimeException($label.' expected '.json_encode($b).' got '.json_encode($a));}
function expectThrows(callable $fn,string $klass,string $label):void{global $n;$n++;try{$fn();}catch(Throwable $e){if($e instanceof $klass)return;throw $e;}throw new RuntimeException('did not throw '.$label);}
$s=L::completeDailySales('2026-10-31','2026-11-02',[
 ['date'=>'2026-11-01','net_sales'=>-20000,'settlement_count'=>2,'completed_settlements'=>1,'completed_sales'=>30000,'reversals'=>1],
 ['date'=>'2026-11-02','net_sales'=>230000,'settlement_count'=>3,'completed_settlements'=>3,'completed_sales'=>230000,'reversals'=>0]
]);
expectSame(count($s),3,'complete daily coverage');
expectSame($s[0]['net_sales'],0,'explicit known zero');
expectSame($s[1]['net_sales'],-20000,'signed reversal');
expectSame($s[2]['net_sales'],230000,'positive sale');
expectSame(count(L::completeDailySales('2024-02-28','2024-03-01',[])),3,'leap day');
expectSame(count(L::completeDailySales('2025-11-02','2026-11-02',[])),366,'annual bound');
expectThrows(fn()=>L::completeDailySales('2026-11-04','2026-11-02',[]),InvalidArgumentException::class,'reversed interval');
expectThrows(fn()=>L::completeDailySales('2026-02-30','2026-03-02',[]),InvalidArgumentException::class,'invalid date');
expectThrows(fn()=>L::completeDailySales('2026-11-01','2026-11-02',[['date'=>'2026-11-01','net_sales'=>1],['date'=>'2026-11-01','net_sales'=>2]]),UnexpectedValueException::class,'duplicate day');
expectThrows(fn()=>L::completeDailySales('2026-11-01','2026-11-02',[['date'=>'2026-11-03','net_sales'=>1]]),UnexpectedValueException::class,'out of range');
$r=['business_clock'=>['cutoff'=>'06:00'],'archive'=>['from'=>'2026-10-31','to'=>'2026-11-02','sales'=>$s,'summary'=>$s],
'dashboard'=>['range'=>['from'=>'2026-10-27','to'=>'2026-11-02'],
'today_summary'=>['net_sales'=>230000,'completed_settlements'=>3,'avg_ticket'=>76667,'order_count'=>4],
'yesterday_summary'=>['net_sales'=>-20000,'completed_settlements'=>1,'avg_ticket'=>30000,'order_count'=>2],
'daily'=>array_map(static fn($row)=>['business_date'=>$row['date'],'net_sales'=>$row['net_sales']],$s)]];
$p=P::reconcile($r,'2026-11-02');
expectSame($p['daily']['2026-10-31'],0,'public known zero');
expectSame($p['daily']['2026-11-01'],-20000,'public negative');
expectSame($p['daily']['2026-11-02'],230000,'public positive');
expectSame($p['daily_conflicts'],[],'same source');
expectSame($p['today_available'],true,'available today');
$a=A::aggregate(['archive'=>$r['archive']],'2026-10-31','2026-11-02');
expectSame(count($a['daily']),3,'daily aggregate coverage');
expectSame($a['summary']['net_sales'],210000,'negative reversal in total');
$r['archive']['sales'][2]['net_sales']=240000;
$p=P::reconcile($r,'2026-11-02');
expectSame(in_array('2026-11-02',$p['daily_conflicts'],true),true,'conflict exposed');
expectSame($p['today_available'],false,'conflict not treated as valid today');
unset($r['archive']['sales'][0]);$r['dashboard']['daily']=array_values(array_filter($r['dashboard']['daily'],static fn($row)=>$row['business_date']!=='2026-10-31'));
$p=P::reconcile($r,'2026-11-02');
expectSame(array_key_exists('2026-10-31',$p['daily']),false,'Public never infers missing day');
$t=new DateTimeImmutable('2026-11-02 05:59:00',new DateTimeZone('Asia/Tehran'));
expectSame(P::businessDate($r,$t),'2026-11-01','cutoff before');
$t=new DateTimeImmutable('2026-11-02 06:01:00',new DateTimeZone('Asia/Tehran'));
expectSame(P::businessDate($r,$t),'2026-11-02','cutoff after');
echo 'PASS '.$n.' sales coverage assertions'.PHP_EOL;
