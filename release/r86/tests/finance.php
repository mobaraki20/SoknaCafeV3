<?php
declare(strict_types=1);
$root=$argv[1];require $root.'/local/bootstrap.php';
require $root.'/public/src/Remote/PublicDashboardFinance.php';
require $root.'/public/src/Http/PublicReportArchiveRange.php';
use Sokna\PublicEdge\Remote\PublicDashboardFinance as F;
use Sokna\PublicEdge\Remote\PublicDashboardFinance;
$db=(string)(getenv('FINANCE_DB')?:'r86_finance');$port=(string)(getenv('DB_PORT')?:3306);$password=(string)(getenv('DB_PASSWORD')?:'r86-test');
$cfg=['app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/r86-finance-'.bin2hex(random_bytes(4))],'installation'=>['id'=>'r86-test'],'db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>$db,'charset'=>'utf8mb4','user'=>'root','pass'=>$password]];
$core=sokna_local_bootstrap($cfg);$core->migrations()->migrate('0059_menu_business_reconciliation_v4');$core->defaultContentSeeder()->seed();$core->migrations()->migrate();$p=$core->database();
$results=[];function check(bool $pass,string $test):void{global $results;$results[]=['test'=>$test,'pass'=>$pass];}
$p->exec("INSERT INTO users(username,password_hash,display_name,role,active) VALUES('r86', 'test', 'R86', 'admin',1)");$actor=['id'=>(int)$p->lastInsertId(),'role'=>'admin','display_name'=>'R86'];
$p->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('r86','R86','guest_staff',10,1)");$cat=(int)$p->lastInsertId();
$item=$core->sellables()->create(['category_id'=>$cat,'name'=>'R86 test coffee','price'=>1000,'preparation_station'=>'bar','sellable_kind'=>'menu_item','staff_only'=>false,'takeaway_allowed'=>true]);
$menu=(int)$p->query("SELECT id FROM menus WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();$p->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,900)')->execute([$menu,$cat]);$p->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menu,$item]);
$today=$core->businessClock()->assignment()['business_date'];$date=new DateTimeImmutable($today);$days=[];for($i=6;$i>=0;$i--)$days[]=$date->modify('-'.$i.' days')->format('Y-m-d');$counter=0;
// Only isolated test records are dated into the seven-day history. No business
// data is loaded from, or written to, either real installation.
$pay=function(int $quantity,string $day,bool $split=false)use($core,$p,$actor,$item,&$counter):array{
 $n=++$counter;$p->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active,sort_order) VALUES(?,?,?,?,1,?)')->execute(['R86 '.$n,860+$n,'R86-'.$n,'r86-table-'.$n,$n]);$table=(int)$p->lastInsertId();
 $order=$core->staffQuickOrders()->commit(['table_id'=>$table,'expected_session_id'=>0,'request_token'=>'r86-order-'.str_pad((string)$n,20,'0'),'items'=>[['id'=>$item,'quantity'=>$quantity,'expected_price'=>1000]]],$actor);$sid=(int)$order['session_id'];$ids=[];
 foreach($split?[1,$quantity-1]:[$quantity] as $i=>$count){$a=$core->settlements()->account($sid);$d=['session_id'=>$sid,'destination'=>'direct','mode'=>$split&&$i===0?'itemized':'full','selection'=>[['order_item_id'=>(int)$a['items'][0]['id'],'quantity'=>$count]],'tenders'=>[['type'=>'cash','amount'=>$count*1000]],'request_id'=>'r86-pay-'.$n.'-'.$i.'-000000000000','expected_session_id'=>$sid,'expected_remaining_total'=>$a['remaining_total'],'expected_signature'=>$a['signature'],'print_receipt'=>false];$r=$core->settlements()->settle($d,$actor);$id=(int)$r['settlement_id'];$p->prepare('UPDATE settlement_records SET business_date=? WHERE id=?')->execute([$day,$id]);$ids[]=$id;}
 $p->prepare('UPDATE orders SET business_date=? WHERE session_id=?')->execute([$day,$sid]);return $ids;
};
$pay(2,$days[1]);$pay(1,$days[2]);$laterReversed=$pay(3,$days[2])[0];$sameDayReversed=$pay(2,$days[3])[0];
foreach([[$sameDayReversed,$days[3]],[$laterReversed,$days[5]]] as [$id,$day]){$r=$core->settlements()->reverse($id,'R86 isolated report fixture','r86-reversal-'.$id.'-0000000000',$actor);$p->prepare('UPDATE settlement_records SET business_date=? WHERE id=?')->execute([$day,(int)$r['settlement_id']]);}
$pay(4,$days[6],true);
$snapshot=$core->reporting()->dashboardSnapshot($today);$expected=[0,2000,4000,0,0,-3000,4000];
check(array_column($snapshot['daily'],'business_date')===$days,'seven business dates including inactive dates are explicit');
check(array_map('intval',array_column($snapshot['daily'],'net_sales'))===$expected,'real settlement and reversal ledger yields expected seven-day net sales');
check($snapshot['today_summary']['net_sales']===4000&&$snapshot['today_summary']['avg_ticket']===2000&&$snapshot['today_summary']['completed_settlements']===2,'partial payments preserve existing average-per-successful-settlement contract');
$report=$core->reporting()->report($days[0],$today);$archive=$core->reporting()->remoteArchive();$projection=['dashboard'=>$snapshot,'archive'=>$archive];
$public=F::reconcile($projection,$today);
check($public['today']==$snapshot['today_summary'],'Public today metrics exactly match Local ledger snapshot');
check($public['yesterday']==$snapshot['yesterday_summary'],'Public reversal-only previous day equals Local');
check($public['yesterday_has_receipt']===false,'reversal-only day never masquerades as a successful receipt for average display');
foreach($days as $i=>$day)check(($public['daily'][$day]??null)===$expected[$i],'Public chart equals Local for '.$day);
$range=Sokna\PublicEdge\Http\PublicReportArchiveRange::aggregate($projection,$days[0],$today);
check($range['summary']['net_sales']===$report['summary']['net_sales']&&$range['summary']['avg_ticket']===$report['summary']['avg_ticket'],'Public range aggregation matches Local report net and average, including reversals');
$cutoff='04:00';foreach(['2026-11-01T03:59:00+03:30'=>'2026-10-31','2026-11-01T04:00:00+03:30'=>'2026-11-01','2026-11-01T00:31:00Z'=>'2026-11-01'] as $instant=>$expectedDay){
 $clock=$core->businessClock()->assignment($instant);$value=method_exists(F::class,'businessDate')?F::businessDate(['business_clock'=>['cutoff'=>$cutoff,'business_date'=>'2026-10-29']],new DateTimeImmutable($instant)):null;check($value===$expectedDay&&$value===$clock['business_date'],'Public applies canonical Local cutoff at '.$instant);
}
check(method_exists(F::class,'businessDate')&&F::businessDate([])===null,'missing business-day policy is unknown, never a guessed snapshot day');
// Execute the actual renderer date-selection fragment: an old snapshot must not
// label its old day as today even while the heartbeat remains recent.
$source=file_get_contents($root.'/public/src/Remote/RemoteStaffPageRenderer.php');$a=strpos($source,'$dashboard=is_array(');$b=strpos($source,'        $financeView=', $a);$reports=['business_clock'=>['cutoff'=>'04:00','business_date'=>$date->modify('-2 days')->format('Y-m-d')]];
$rendererDate=(static function($fragment,$reports){eval($fragment);return $todayKey;})(substr($source,$a,$b-$a),$reports);check($rendererDate===$today,'actual Public renderer advances current business date past an old snapshot');
$bad=$projection;$bad['dashboard']['today_summary']['net_sales']+=100;check(F::reconcile($bad,$today)['today_available']===false,'KPI conflicts with same-day chart/archive do not display as trustworthy sales');
$partial=['dashboard'=>['range'=>['from'=>$days[0],'to'=>$today],'today_summary'=>['net_sales'=>4000,'completed_settlements'=>2]]];$view=F::reconcile($partial,$today);
check(($view['today_avg_available']??false)===false,'incomplete average is unknown rather than zero');check(($view['today_orders_available']??false)===false,'incomplete order count is unknown rather than zero');
// Two independent real MariaDB connections: the writer commits a valid order
// and settlement after the reader's daily query but before its summary query.
class R86Statement extends PDOStatement{
 public static ?Closure $afterDaily=null;public static bool $failSummary=false;
 protected function __construct(){}
 public function execute(?array $params=null): bool{if(self::$failSummary&&str_contains($this->queryString,'AVG(CASE'))throw new RuntimeException('R86 injected summary failure');return parent::execute($params);}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{$r=parent::fetchAll($mode,...$args);if(self::$afterDaily!==null&&str_contains($this->queryString,'GROUP BY business_date ORDER BY business_date')){$f=self::$afterDaily;self::$afterDaily=null;$f();}return $r;}
}
$reader=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname='.$db.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[R86Statement::class,[]]]);$reader->exec("SET time_zone='+03:30'");
$service=new Sokna\Local\Domain\Reporting\ReportingService($reader,new Sokna\Local\Core\TimeAuthority());
$before=$core->reporting()->dashboardSnapshot($today)['today_summary']['net_sales'];$injected=false;R86Statement::$afterDaily=function()use($pay,$today,&$injected):void{$pay(1,$today);$injected=true;};$s=$service->dashboardSnapshot($today);
check($injected,'independent writer committed during dashboard read');check($s['today_summary']['net_sales']===$before&&end($s['daily'])['net_sales']===$before,'concurrent commit cannot split dashboard KPI and chart snapshot');
check($core->reporting()->dashboardSnapshot($today)['today_summary']['net_sales']===$before+1000,'next dashboard snapshot sees committed sale');
// Exercise the actual Local home adapter with the same interleaving.
$source=file_get_contents($root.'/local/public/index.php');$a=strpos($source,'function() use ($core,$todayKey,$yesterdayKey,$weekFrom,$today): array {');$b=strpos($source,'    });',$a);$fragment=substr($source,$a,$b-$a+5);$actual=$core;$core=new class($service){public function __construct(private $s){}public function reporting(){return $this->s;}};
$todayKey=$today;$today=new DateTimeImmutable($today);$yesterdayKey=$today->modify('-1 day')->format('Y-m-d');$weekFrom=$today->modify('-6 days')->format('Y-m-d');function home_weekday(DateTimeImmutable $day,bool $today=false):string{return $today?'today':$day->format('D');}
$before=$actual->reporting()->dashboardSnapshot($todayKey)['today_summary']['net_sales'];R86Statement::$afterDaily=function()use($pay,$todayKey):void{$pay(1,$todayKey);};$callback=eval('return '.$fragment.';');$home=$callback();
check($home['today_summary']['net_sales']===$before&&end($home['week_daily'])['value']===$before,'actual Local home adapter uses one coherent ledger snapshot');
check(!$reader->inTransaction(),'dashboard closes only its own read transaction');
$reader->beginTransaction();$service->dashboardSnapshot($todayKey);check($reader->inTransaction(),'dashboard never commits a caller-owned transaction');$reader->rollBack();
R86Statement::$failSummary=true;$failed=false;try{$service->dashboardSnapshot($todayKey);}catch(Throwable){$failed=true;}check($failed&&!$reader->inTransaction(),'failed dashboard rolls back its own transaction');R86Statement::$failSummary=false;
echo json_encode($results,JSON_PRETTY_PRINT).PHP_EOL;
exit(count(array_filter($results,fn($r)=>!$r['pass']))?1:0);
