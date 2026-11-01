<?php
declare(strict_types=1);
$root=$argv[1];
require $root.'/public/src/Guest/GuestCompatibilityService.php';
require $root.'/public/src/Remote/PublicDashboardFinance.php';
require $root.'/public/src/Core/ResumablePublicSchema.php';
use Sokna\PublicEdge\Guest\GuestCompatibilityService as G;
use Sokna\PublicEdge\Remote\PublicDashboardFinance as F;
$results=[];
function check(bool $value,string $name):void{global $results;$results[]=['test'=>$name,'pass'=>$value];}
$view=['stale'=>false,'payload'=>['tables'=>['1'=>['active_call'=>null]]]];
check(G::waiterProjectionStatus($view,1,'W-existing')===null,'recent pre-commit snapshot cannot erase waiter receipt');
$view['payload']['tables']['1']['active_call']=['public_code'=>'W-other','status'=>'new'];
check(G::waiterProjectionStatus($view,1,'W-existing')===null,'different active call cannot prove tracked call absent');
$view['payload']['tables']['1']['active_call']=['public_code'=>'W-existing','status'=>'accepted'];
check(G::waiterProjectionStatus($view,1,'W-existing')==='accepted','same fresh call proves presence');
$view['stale']=true;
check(G::waiterProjectionStatus($view,1,'W-existing')===null,'stale call remains unresolved');
$view['stale']=false;$view['payload']['tables']['1']['active_call']['status']='nonsense';
check(G::waiterProjectionStatus($view,1,'W-existing')===null,'invalid state never becomes terminal');
$f=F::reconcile([], '2026-11-01');
check(!$f['today_available']&&!$f['yesterday_available'],'missing report is not zero');
$r=['dashboard'=>['range'=>['from'=>'2026-10-26','to'=>'2026-11-01'],'today_summary'=>['net_sales'=>0,'avg_ticket'=>0,'completed_settlements'=>0,'order_count'=>0],'daily'=>[['business_date'=>'2026-11-01','net_sales'=>0]]]];
$f=F::reconcile($r,'2026-11-01');
check($f['today_available']&&$f['daily']['2026-11-01']===0,'explicit Local zero is usable on Public');
$r['archive']=['from'=>'2026-10-26','to'=>'2026-11-01','sales'=>[['date'=>'2026-11-01','net_sales'=>900]]];
check(F::reconcile($r,'2026-11-01')['daily_conflicts']===['2026-11-01'],'conflicting report sources remain visible');
$method=(new ReflectionClass(Sokna\PublicEdge\Core\ResumablePublicSchema::class))->getMethod('assertColumn');
$row=['COLUMN_TYPE'=>'datetime(6)','IS_NULLABLE'=>'YES','COLUMN_DEFAULT'=>'NULL'];$accepted=true;
try{$method->invoke(null,'installations','authority_changed_at',$row,'datetime(6)','YES',null);}catch(Throwable){$accepted=false;}
check($accepted,'MariaDB SQL NULL metadata accepted as null default');
$row['COLUMN_DEFAULT']="'NULL'";$rejected=false;
try{$method->invoke(null,'installations','authority_changed_at',$row,'datetime(6)','YES',null);}catch(Throwable){$rejected=true;}
check($rejected,'quoted literal NULL remains incompatible');
echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit(count(array_filter($results,fn($r)=>!$r['pass']))?1:0);
