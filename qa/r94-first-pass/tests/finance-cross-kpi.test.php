<?php
declare(strict_types=1);
require __DIR__.'/../overlays/public/src/Remote/PublicDashboardFinance.php';
use Sokna\PublicEdge\Remote\PublicDashboardFinance as F;
function check(bool $ok,string $name):void {static $n=0;if(!$ok)throw new RuntimeException('FAIL: '.$name);echo 'PASS '.(++$n).' '.$name."\n";}
$today='2026-11-02';$before='2026-11-01';
$doc=['business_clock'=>['cutoff'=>'04:00'],
'archive'=>['from'=>$before,'to'=>$today,'sales'=>[['date'=>$before,'net_sales'=>20000],['date'=>$today,'net_sales'=>0]],
'summary'=>[['date'=>$before,'net_sales'=>20000,'completed_settlements'=>1,'completed_sales'=>20000,'order_count'=>1],['date'=>$today,'net_sales'=>0,'completed_settlements'=>0,'completed_sales'=>0,'order_count'=>0]]],
'dashboard'=>['range'=>['from'=>$before,'to'=>$today],'daily'=>[['business_date'=>$before,'net_sales'=>20000],['business_date'=>$today,'net_sales'=>0]],'today_summary'=>['net_sales'=>0,'completed_settlements'=>0,'avg_ticket'=>0,'order_count'=>0], 'yesterday_summary'=>['net_sales'=>20000,'completed_settlements'=>1,'avg_ticket'=>20000,'order_count'=>1]]];
$r=F::reconcile($doc,$today);
check($r['daily_conflicts']===[], 'aligned Local projections do not invent a conflict');
check($r['today_available']===true&&$r['today_avg_available']===true,'confirmed zero and zero completed count remain known');
$bad=$doc;$bad['dashboard']['today_summary']['completed_settlements']=1;$r=F::reconcile($bad,$today);
check(in_array($today,$r['daily_conflicts'],true)&&!$r['today_available']&& !$r['today_avg_available'],'completed settlement count conflict blocks today and average');
$bad=$doc;$bad['dashboard']['yesterday_summary']['avg_ticket']=15000;$r=F::reconcile($bad,$today);
check(in_array($before,$r['daily_conflicts'],true)&&!$r['yesterday_avg_available'],'wrong average is a conflict even when net sales agree');
$bad=$doc;$bad['archive']['summary'][0]['completed_settlements']=2;$r=F::reconcile($bad,$today);
check(in_array($before,$r['daily_conflicts'],true),'archive count changed without sales change caught');
$bad=$doc;unset($bad['dashboard']['yesterday_summary']['avg_ticket']);$r=F::reconcile($bad,$today);
check(!in_array($before,$r['daily_conflicts'],true)&&$r['yesterday']['avg_ticket']===20000,'missing raw average derived from fully covered archive');
$bad=$doc;unset($bad['archive']['summary'][0]['completed_sales']);$r=F::reconcile($bad,$today);
check(!in_array($before,$r['daily_conflicts'],true),'unknown archive gross not mistaken for zero');
$bad=$doc;$bad['archive']['summary'][]=$bad['archive']['summary'][1];$r=F::reconcile($bad,$today);
check(in_array($today,$r['daily_conflicts'],true),'duplicate summary still blocked');
