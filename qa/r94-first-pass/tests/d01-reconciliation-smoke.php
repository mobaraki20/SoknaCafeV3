<?php
declare(strict_types=1);
require __DIR__.'/../overlays/public/src/Remote/PublicDashboardFinance.php';
use Sokna\PublicEdge\Remote\PublicDashboardFinance as F;
$checks=0;
function expect(bool $v,string $what):void{global $checks;$checks++;if(!$v)throw new \RuntimeException($what);}
$days=[];for($d=27;$d<=31;$d++)$days[]='2026-10-'.$d;$days[]='2026-11-01';$days[]='2026-11-02';
$archiveSales=[];$archiveSummary=[];$dashDaily=[];
foreach($days as $i=>$date){$n=($i===3?-25000:($i===6?0:10000+$i*2000));$archiveSales[]=['date'=>$date,'net_sales'=>$n];$archiveSummary[]=['date'=>$date,'net_sales'=>$n,'completed_settlements'=>1,'completed_sales'=>$n>0?$n:0];$dashDaily[]=['business_date'=>$date,'net_sales'=>$n];}
$input=['business_clock'=>['cutoff'=>'04:00'],'archive'=>['from'=>'2026-10-27','to'=>'2026-11-02','sales'=>$archiveSales,'summary'=>$archiveSummary],
'dashboard'=>['range'=>['from'=>'2026-10-27','to'=>'2026-11-02'],'today_summary'=>['net_sales'=>0,'completed_settlements'=>0,'avg_ticket'=>0,'order_count'=>0],
'yesterday_summary'=>['net_sales'=>20000,'completed_settlements'=>1,'avg_ticket'=>20000,'order_count'=>3], 'daily'=>$dashDaily]];
$r=F::reconcile($input,'2026-11-02');
expect($r['daily_conflicts']===[],'same source must reconcile');expect($r['today_available']===true,'known zero today');expect($r['daily']['2026-11-02']===0,'zero exactly');expect($r['daily']['2026-10-30']===-25000,'negative preserved');
$changed=$input;$changed['archive']['sales'][]=$archiveSales[2];$r=F::reconcile($changed,'2026-11-02');expect(in_array('2026-10-29',$r['daily_conflicts'],true),'duplicate equal archive sales conflicts');
$changed=$input;$changed['archive']['summary'][]=$archiveSummary[2];$r=F::reconcile($changed,'2026-11-02');expect(in_array('2026-10-29',$r['daily_conflicts'],true),'duplicate equal archive summary conflicts');
$changed=$input;$changed['dashboard']['daily'][]=$dashDaily[2];$r=F::reconcile($changed,'2026-11-02');expect(in_array('2026-10-29',$r['daily_conflicts'],true),'duplicate equal dashboard daily conflicts');
$changed=$input;$changed['archive']['summary'][2]['net_sales']=1234;$r=F::reconcile($changed,'2026-11-02');expect(in_array('2026-10-29',$r['daily_conflicts'],true),'archive summary mismatch detected for older day');
$changed=$input;$changed['archive']['sales'][6]['net_sales']=7777;$r=F::reconcile($changed,'2026-11-02');expect(!$r['today_available']&&in_array('2026-11-02',$r['daily_conflicts'],true),'today disagreement hides figure');
$changed=$input;array_pop($changed['archive']['sales']);array_pop($changed['archive']['summary']);array_pop($changed['dashboard']['daily']);unset($changed['dashboard']['today_summary']);$r=F::reconcile($changed,'2026-11-02');expect(!$r['today_available']&&!array_key_exists('2026-11-02',$r['daily']),'missing day not invented');
$changed=$input;$changed['archive']['sales'][1]['net_sales']=null;$r=F::reconcile($changed,'2026-11-02');expect($r['daily']['2026-10-28']===$dashDaily[1]['net_sales'],'malformed archive does not overwrite valid daily');
$changed=$input;$changed['archive']['sales'][3]['net_sales']=-50000;$r=F::reconcile($changed,'2026-11-02');expect(in_array('2026-10-30',$r['daily_conflicts'],true),'negative mismatch detected');
echo "PASS {$checks} reconciliation safety checks\n";
