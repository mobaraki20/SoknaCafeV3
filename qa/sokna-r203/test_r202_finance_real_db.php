<?php
declare(strict_types=1);
// Never execute outside disposable GitHub-hosted MariaDB.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R202_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('R202_CI_ONLY');
$web=(string)getenv('SOKNA_R202_WEB');$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$tmp||!is_file($web.'/bootstrap.php')||!str_starts_with(realpath($web)?:'',$tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('R202_REFUSE_NON_TEMP_WEB');
$ciRoot=dirname($web);$data=$ciRoot.'/Data';$media=$data.'/guest-media';
if(!is_dir($media)&&!mkdir($media,0700,true)&&!is_dir($media))throw new RuntimeException('NO_MEDIA');
if(file_exists($ciRoot.'/config.php'))throw new RuntimeException('EXISTING_CONFIG_REFUSED');
file_put_contents($ciRoot.'/config.php','<?php return '.var_export(['app'=>['data_dir'=>$data]],true).';');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('SOKNA_R202_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS sokna_r202_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE sokna_r202_disposable');
$migrations=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');$migrations->migrate('0059_menu_business_reconciliation_v4');
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['qa.r202',password_hash('only-in-ci',PASSWORD_DEFAULT),'QA Admin','admin']);$uid=(int)$pdo->lastInsertId();$actor=['id'=>$uid,'role'=>'admin'];
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$seeder=new \Sokna\Local\Domain\Sellables\DefaultContentSeeder($pdo,$icons,$web.'/resources/default-content/v1',$media);
if(($seeder->seed($uid)['status']??'')!=='complete')throw new RuntimeException('SEED_INCOMPLETE');$migrations->migrate();
$ledger=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();if($ledger!==81)throw new RuntimeException('MIGRATIONS_'.$ledger);
$id=new \Sokna\Local\Core\PdoIdentityRepository($pdo);$caps=new \Sokna\Local\Core\Capabilities($id);$clock=new \Sokna\Local\Domain\Orders\BusinessClock($pdo);$time=new \Sokna\Local\Core\TimeAuthority();
$periodIds=new \Sokna\Local\Domain\Finance\FinancialPeriodIdentityService($pdo);$periods=new \Sokna\Local\Domain\Finance\FinancialPeriodService($pdo,$id,$clock,$periodIds);
$tax=new \Sokna\Local\Domain\Tax\TaxService($pdo,$id);$subscribers=new \Sokna\Local\Domain\Integrations\SubscriberService($pdo,$id);$templates=new \Sokna\Local\Domain\Printing\PrintTemplatePackageService($pdo);$printing=new \Sokna\Local\Domain\Printing\PrintService($pdo,$id,$templates);
$settlements=new \Sokna\Local\Domain\Finance\SettlementService($pdo,$id,$caps,$clock,$time,$periods,$tax,$subscribers,$printing);
$reporting=new \Sokna\Local\Domain\Reporting\ReportingService($pdo,$time);
function ok(bool $test,string $code):void{if(!$test)throw new RuntimeException('FAIL_R202_'.$code);}
function scalar(PDO $pdo,string $sql,array $params=[]):mixed{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
function fixture(PDO $pdo,int $uid,int $price):array{
 static $n=0;++$n;
 $pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active) VALUES(?,?,?,?,1)')->execute(['R202 Table '.$n,$n,'R202-T-'.$n,'R202-TOKEN-'.$n]);$table=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,CURDATE(),'day','QA','05:00')")->execute(['R202-S-'.$n,$table,$table,$uid]);$sid=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,accepted_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff','accounted',?, ?, ?,CURDATE(),'day','QA','05:00')")->execute(['R202-ORDER-'.$n,'R202-CLIENT-'.$n,$table,$sid,$price,$uid,900000+$n]);$order=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,fulfillment_mode,preparation_station,line_total,item_note) VALUES(?,NULL,'چای آزمایشی', ?,1,1,'dine_in','none',?,'QA')")->execute([$order,$price,$price]);
 return [$sid,$order];
}
function pay($service,int $sid,array $actor,string $requestId,array $tenders):array{
 $a=$service->account($sid);
 return $service->settle(['session_id'=>$sid,'expected_session_id'=>$sid,'expected_remaining_total'=>(int)$a['remaining_total'],'expected_signature'=>(string)$a['signature'],'destination'=>'direct','mode'=>'full','tenders'=>$tenders,'print_receipt'=>false,'request_id'=>$requestId],$actor);
}
// 100% discount: normal direct settlement, no new button, no tender records.
[$sid,$order]=fixture($pdo,$uid,550000);
$settlements->setDiscount($sid,'percent',100,$actor);
$a=$settlements->account($sid);ok((int)$a['remaining_total']===0 && (int)$a['discount']===550000,'FREE_CALC');
$freePayload=['session_id'=>$sid,'expected_session_id'=>$sid,'expected_remaining_total'=>(int)$a['remaining_total'],'expected_signature'=>(string)$a['signature'],'destination'=>'direct','mode'=>'full','tenders'=>[],'print_receipt'=>false,'request_id'=>'r202-full-discount-0001'];
$first=$settlements->settle($freePayload,$actor);
$receipt=(int)$first['settlement_id'];
ok((string)scalar($pdo,"SELECT status FROM table_sessions WHERE id=?",[$sid])==='closed','FREE_TABLE_CLOSED');
ok((string)scalar($pdo,"SELECT status FROM orders WHERE id=?",[$order])==='completed','FREE_ORDER_COMPLETED');
ok((int)scalar($pdo,'SELECT total FROM settlement_records WHERE id=?',[$receipt])===0,'FREE_ZERO_INVOICE');
ok((int)scalar($pdo,'SELECT discount FROM settlement_records WHERE id=?',[$receipt])===550000,'FREE_DISCOUNT_IMMUTABLE');
ok((string)scalar($pdo,'SELECT tender_capture_status FROM settlement_records WHERE id=?',[$receipt])==='not_applicable','FREE_NO_FAKE_TENDER_STATUS');
ok((int)scalar($pdo,'SELECT COUNT(*) FROM settlement_tender_allocations WHERE settlement_id=?',[$receipt])===0,'FREE_NO_FAKE_PAYMENT');
ok(str_starts_with((string)scalar($pdo,'SELECT invoice_number FROM settlement_records WHERE id=?',[$receipt]),'I-'),'FREE_INVOICE_EXISTS');
ok((int)scalar($pdo,'SELECT COUNT(*) FROM settlement_record_lines WHERE settlement_id=? AND gross_amount=550000 AND discount_amount=550000 AND final_amount=0',[$receipt])===1,'FREE_LINE_TRACE');
echo "PASS_R202_FULL_DISCOUNT_ZERO_SETTLEMENT_CLOSES_WITHOUT_FAKE_TENDER\n";
$again=$settlements->settle($freePayload,$actor);ok(($again['idempotent']??false)===true,'FREE_REPEAT_IDEMPOTENT');
echo "PASS_R202_FREE_REQUEST_IDEMPOTENT\n";
// Positive balance without payment method is still forbidden.
[$sid2,$order2]=fixture($pdo,$uid,200000);
try{pay($settlements,$sid2,$actor,'r202-positive-no-tender',[]);throw new RuntimeException('FAIL_R202_POSITIVE_WITHOUT_TENDER_ACCEPTED');}
catch(\Sokna\Local\Domain\Finance\SettlementException $e){ok($e->errorCode==='tender_required','POSITIVE_STILL_REQUIRES_TENDER');}
ok((int)scalar($pdo,'SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$sid2])===0,'POSITIVE_NOT_PARTIALLY_COMMITTED');
echo "PASS_R202_POSITIVE_TENDER_REQUIRED\n";
$paid=pay($settlements,$sid2,$actor,'r202-positive-cash-0001',[['type'=>'cash','amount'=>200000]]);
ok((int)$paid['total_amount']===200000,'POSITIVE_PAYMENT_WORKS');
echo "PASS_R202_ORDINARY_CASH_SETTLEMENT_UNCHANGED\n";
$date=(string)scalar($pdo,'SELECT business_date FROM settlement_records WHERE id=?',[$receipt]);$date2=(string)scalar($pdo,'SELECT business_date FROM settlement_records WHERE id=?',[(int)$paid['settlement_id']]);
ok($date===$date2,'CI_SAME_BUSINESS_DAY');
$report=$reporting->report($date,$date);$s=$report['summary'];
ok($s['gross_sales']===750000&&$s['discount_total']===550000&&$s['net_sales']===200000,'SUMMARY_GROSS_DISCOUNT_NET');
$daily=array_values(array_filter($report['daily'],fn($row)=>$row['business_date']===$date));ok(count($daily)===1,'DAILY_ONE_ROW');
ok((int)$daily[0]['gross_sales']===750000&&(int)$daily[0]['discount_total']===550000&&(int)$daily[0]['net_sales']===200000,'DAILY_GROSS_DISCOUNT_NET');
echo "PASS_R202_REPORTS_GROSS_DISCOUNT_NET_COHERENT\n";
$dash=$reporting->dashboardSnapshot($date);ok((int)$dash['today_summary']['net_sales']===200000,'DASH_AFTER_DISCOUNT');
echo "PASS_R202_DASHBOARD_NET_SALES_UNCHANGED\n";
$settlements->reverse((int)$paid['settlement_id'],'QA reversal','r202-reversal-0001',$actor);
$reversed=$reporting->report($date,$date);ok($reversed['summary']['gross_sales']===550000&&$reversed['summary']['discount_total']===550000&&$reversed['summary']['net_sales']===0,'REVERSAL_LEDGER');
echo "PASS_R202_REVERSAL_LEDGER_ADJUSTED\n";
echo "PASS_R202_REAL_MARIADB_81_MIGRATIONS_SEVEN_FINANCIAL_SCENARIOS\n";
