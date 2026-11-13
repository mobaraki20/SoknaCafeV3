<?php
declare(strict_types=1);
/** Disposable CI only. Absolutely never connect to any real Sokna database. */
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R196_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('R196_DISPOSABLE_CI_ONLY');
$web=(string)getenv('SOKNA_R196_WEB');
$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$tmp||!$web||!is_file($web.'/bootstrap.php')||!str_starts_with(realpath($web)?:'', $tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('R196_DISPOSABLE_WEB_ONLY');
$ciRoot=dirname($web);$ciData=$ciRoot.'/Data';$ciMedia=$ciData.'/guest-media';
if(!is_dir($ciMedia)&&!mkdir($ciMedia,0700,true)&&!is_dir($ciMedia))throw new RuntimeException('R196_MEDIA_DIR');
if(file_exists($ciRoot.'/config.php')||is_link($ciRoot.'/config.php'))throw new RuntimeException('R196_REFUSE_EXISTING_CONFIG');
file_put_contents($ciRoot.'/config.php','<?php return '.var_export(['app'=>['data_dir'=>$ciData]],true).';');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('SOKNA_R196_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS sokna_r196_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE sokna_r196_disposable');
$migrations=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
$migrations->migrate('0059_menu_business_reconciliation_v4');
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['qa.r196',password_hash('only-in-CI',PASSWORD_DEFAULT),'QA Admin','admin']);
$uid=(int)$pdo->lastInsertId();$actor=['id'=>$uid,'role'=>'admin'];
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$seeder=new \Sokna\Local\Domain\Sellables\DefaultContentSeeder($pdo,$icons,$web.'/resources/default-content/v1',$ciMedia);
if(($seeder->seed($uid)['status']??'')!=='complete')throw new RuntimeException('R196_SEED_INCOMPLETE');
$migrations->migrate();
$ledger=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if($ledger!==81)throw new RuntimeException('R196_REAL_MIGRATIONS_'.$ledger);
$id=new \Sokna\Local\Core\PdoIdentityRepository($pdo);
$caps=new \Sokna\Local\Core\Capabilities($id);
$clock=new \Sokna\Local\Domain\Orders\BusinessClock($pdo);
$time=new \Sokna\Local\Core\TimeAuthority();
$periodIds=new \Sokna\Local\Domain\Finance\FinancialPeriodIdentityService($pdo);
$periods=new \Sokna\Local\Domain\Finance\FinancialPeriodService($pdo,$id,$clock,$periodIds);
$tax=new \Sokna\Local\Domain\Tax\TaxService($pdo,$id);
$subscribers=new \Sokna\Local\Domain\Integrations\SubscriberService($pdo,$id);
$templates=new \Sokna\Local\Domain\Printing\PrintTemplatePackageService($pdo);
$printing=new \Sokna\Local\Domain\Printing\PrintService($pdo,$id,$templates);
$settlements=new \Sokna\Local\Domain\Finance\SettlementService($pdo,$id,$caps,$clock,$time,$periods,$tax,$subscribers,$printing);
function ensure(bool $ok,string $code):void{if(!$ok)throw new RuntimeException('R196_ASSERT_'.$code);}
function countQ(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}
function fixture(PDO $pdo,int $uid,int $price=250,bool $pending=false):array{
 static $n=0;++$n;
 $pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active) VALUES(?,?,?,?,1)')->execute(['R196 Table '.$n,$n,'R196-T-'.$n,'R196-TOKEN-'.$n]);$table=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,CURDATE(),'day','QA','05:00')")->execute(['R196-S-'.$n,$table,$table,$uid]);$sid=(int)$pdo->lastInsertId();
 $oid=addOrder($pdo,$uid,$table,$sid,$price,$pending,$n.'-1');
 return [$table,$sid,$oid];
}
function addOrder(PDO $pdo,int $uid,int $table,int $sid,int $price,bool $pending,string $key):int{
 $status=$pending?'pending_approval':'accounted';
 $pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,accepted_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff',?,?,?, ?,CURDATE(),'day','QA','05:00')")
  ->execute(['R196-ORDER-'.$key,'R196-CLIENT-'.$key,$table,$sid,$status,$price,$uid,random_int(100000,999999)]);
 $oid=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,fulfillment_mode,preparation_station,line_total,item_note) VALUES(?,NULL,?, ?,1,1,'dine_in','none',?,?)")
  ->execute([$oid,'آیتم آزمایشی '.$key,$price,$price,'بدون شکر']);
 return $oid;
}
function queuePayload(PDO $pdo,int $jobId):array{$q=$pdo->prepare('SELECT payload_json FROM print_jobs WHERE id=?');$q->execute([$jobId]);$p=json_decode((string)$q->fetchColumn(),true);if(!is_array($p))throw new RuntimeException('R196_BAD_PAYLOAD');return $p;}
function printWith(\Sokna\Local\Domain\Finance\SettlementService $service,int $sid,array $a,string $rid,array $user):array{
 return $service->printOpenAccount($sid,(int)$a['remaining_total'],(string)$a['signature'],$rid,$user);
}
function rejected(callable $f,string $expected):void{
 try{$f();}catch(\Sokna\Local\Domain\Finance\SettlementException $e){ensure($e->errorCode===$expected,'REJECT_'.$expected.'_GOT_'.$e->errorCode);return;}
 throw new RuntimeException('R196_EXPECTED_REJECTION_'.$expected);
}
// A. Ordinary open-account print; explicitly non-fiscal and no settlement or invoice sequence.
[$table,$sid,$order]=fixture($pdo,$uid,250);
$a=$settlements->account($sid);ensure($a['total']===250&&$a['remaining_total']===250,'CANONICAL_AMOUNT');
$beforeInvoice=countQ($pdo,'SELECT COUNT(*) FROM settlement_records');
$beforePeriod=countQ($pdo,'SELECT COUNT(*) FROM financial_periods');
$r=printWith($settlements,$sid,$a,'r196-preview-original-001',$actor);$job=(int)$r['job_id'];
ensure($job>0&&$r['status']==='blocked','BLOCKED_ROUTING_SAFETY');
$p=queuePayload($pdo,$job);
ensure($p['document_kind']==='customer'&&$p['document_role']==='account_preview','ROLE');
ensure(($p['invoice_number']??'missing')===''&&!isset($p['settlement_id']),'NO_INVOICE_OR_SETTLEMENT_ID');
ensure($p['total']===250&&$p['subtotal']===250&&$p['discount']===0,'AMOUNTS');
ensure(count($p['sections'][0]['items'])===1&&$p['sections'][0]['items'][0]['note']==='بدون شکر','LINE_AND_NOTE');
ensure(str_contains((string)$p['title'],'تسویه نشده')&&str_contains((string)$p['footer'],'رسید پرداخت نیست'),'CLEAR_NON_RECEIPT_MARK');
ensure(countQ($pdo,'SELECT COUNT(*) FROM settlement_records')===$beforeInvoice&&countQ($pdo,'SELECT COUNT(*) FROM financial_periods')===$beforePeriod,'NO_FINANCIAL_SIDE_EFFECT');
ensure(countQ($pdo,'SELECT COUNT(*) FROM print_jobs WHERE entity_type=? AND entity_id=?',['account_preview',(string)$sid])===1,'ONE_JOB');
echo "PASS_R196_PREPAYMENT_OPEN_ACCOUNT_NO_FINANCIAL_MUTATION\n";
// B. Lost ACK replays same job; no double print or invoice number.
$replay=printWith($settlements,$sid,$a,'r196-preview-original-001',$actor);
ensure($replay['duplicate']===true&&(int)$replay['job_id']===$job,'IDEMPOTENT_SAME_JOB');
ensure(countQ($pdo,'SELECT COUNT(*) FROM print_jobs WHERE entity_type=? AND entity_id=?',['account_preview',(string)$sid])===1,'REPLAY_ONCE');
echo "PASS_R196_PRINT_IDEMPOTENCY\n";
// C. Stale financial snapshot fails closed with no print job.
addOrder($pdo,$uid,$table,$sid,300,false,'add-after-preview');
rejected(fn()=>printWith($settlements,$sid,$a,'r196-preview-changed-002',$actor),'account_changed');
ensure(countQ($pdo,'SELECT COUNT(*) FROM print_jobs WHERE entity_type=? AND entity_id=?',['account_preview',(string)$sid])===1,'STALE_NO_NEW_JOB');
$a2=$settlements->account($sid);ensure((int)$a2['total']===550,'REFRESHED_TOTAL');
$r2=printWith($settlements,$sid,$a2,'r196-preview-refreshed-003',$actor);
ensure(queuePayload($pdo,(int)$r2['job_id'])['total']===550,'REFRESHED_AMOUNT');
echo "PASS_R196_STALE_ACCOUNT_REJECTED_AND_REFRESHED\n";
// D. Pending orders cannot result in an incomplete bill.
[$t2,$s2]=fixture($pdo,$uid,200);$confirmed=$settlements->account($s2);addOrder($pdo,$uid,$t2,$s2,200,true,'pending');
rejected(fn()=>printWith($settlements,$s2,$confirmed,'r196-preview-pending-004',$actor),'unconfirmed_orders');
echo "PASS_R196_PENDING_ORDER_GUARD\n";
// E. Closed session cannot be printed as a live bill.
[$t3,$s3]=fixture($pdo,$uid,200);$closed=$settlements->account($s3);
$pdo->prepare("UPDATE table_sessions SET status='closed',live_table_guard=NULL WHERE id=?")->execute([$s3]);
rejected(fn()=>printWith($settlements,$s3,$closed,'r196-preview-closed-005',$actor),'session_closed');
echo "PASS_R196_CLOSED_ACCOUNT_GUARD\n";
// F. Disabled actors cannot print: server-side permission, never trust UI.
rejected(fn()=>printWith($settlements,$sid,$a2,'r196-preview-forbidden-006',['id'=>999999]),'forbidden');
echo "PASS_R196_ACCESS_GUARD\n";
// G. Active custom template cannot suppress NON-FISCAL disclaimer.
$std=$templates->installStandard('customer',$actor);$templates->activate((int)$std['package_id'],$actor);
$a3=$settlements->account($sid);
$custom=printWith($settlements,$sid,$a3,'r196-preview-template-007',$actor);
$payload=queuePayload($pdo,(int)$custom['job_id']);
ensure(str_contains((string)($payload['template']['footer']??''),'رسید پرداخت نیست'),'TEMPLATE_WARNS');
ensure(!isset($payload['settlement_id']),'CUSTOM_TEMPLATE_NO_SETTLEMENT');
echo "PASS_R196_CUSTOM_TEMPLATE_WARNING_PRESERVED\n";
// H. No direct post-payment bill; completed entire balance means cannot mint a nonfinal slip.
$pdo->prepare('UPDATE table_sessions SET service_detached_at=NOW() WHERE id=?')->execute([$sid]);
rejected(fn()=>printWith($settlements,$sid,$a3,'r196-preview-detached-008',$actor),'followup_detached');
echo "PASS_R196_DETACHED_ACCOUNT_GUARD\n";
ensure(countQ($pdo,'SELECT COUNT(*) FROM settlement_records')===0,'STILL_NO_SETTLEMENT');
ensure(countQ($pdo,'SELECT COUNT(*) FROM financial_periods')===0,'STILL_NO_PERIOD');
ensure(countQ($pdo,'SELECT COUNT(*) FROM audit_log WHERE action=?',['settlement.open_account_preview_requested'])===3,'AUDIT_PER_NEW_PRINT_ONLY');
// I. A table with a genuinely settled PART of its account still receives a clearly marked
// preliminary bill with gross, already-paid and remaining totals; no new financial document.
[$pt,$ps,$po]=fixture($pdo,$uid,250);
addOrder($pdo,$uid,$pt,$ps,300,false,'partial-other');
$beforePartial=$settlements->account($ps);
$firstId=(int)$beforePartial['items'][0]['id'];
$paidResult=$settlements->settle([
 'session_id'=>$ps,'expected_session_id'=>$ps,
 'expected_remaining_total'=>(int)$beforePartial['remaining_total'],
 'expected_signature'=>(string)$beforePartial['signature'],
 'destination'=>'direct','mode'=>'itemized','selection'=>[['order_item_id'=>$firstId,'quantity'=>1]],
 'tenders'=>[['type'=>'cash','amount'=>250]],'print_receipt'=>false,'request_id'=>'r196-ci-partial-pay-009'
],$actor);
ensure((int)($paidResult['remaining_total']??-1)===300,'PARTIAL_SETTLEMENT_REMAINDER');
$afterPartial=$settlements->account($ps);
ensure($afterPartial['paid_total']===250&&$afterPartial['remaining_total']===300,'PARTIAL_ACCOUNT_RECALCULATED');
$receiptsBefore=countQ($pdo,'SELECT COUNT(*) FROM settlement_records');
$periodSeq=$pdo->query('SELECT COALESCE(SUM(next_invoice_sequence),0) FROM financial_periods')->fetchColumn();
$partial=printWith($settlements,$ps,$afterPartial,'r196-preview-partial-009',$actor);
$p=queuePayload($pdo,(int)$partial['job_id']);
ensure($p['total']===550&&$p['document_role']==='account_preview','PARTIAL_FULL_ACCOUNT_SHOWN');
ensure(str_contains((string)$p['footer'],'۲۵۰')&&str_contains((string)$p['footer'],'۳۰۰'),'PARTIAL_PAID_AND_REMAINING_DISCLOSED');
ensure(($p['invoice_number']??null)===''&&!isset($p['settlement_id']),'PARTIAL_PREVIEW_NON_FISCAL');
ensure(countQ($pdo,'SELECT COUNT(*) FROM settlement_records')===$receiptsBefore,'PARTIAL_NO_EXTRA_SETTLEMENT');
ensure((string)$pdo->query('SELECT COALESCE(SUM(next_invoice_sequence),0) FROM financial_periods')->fetchColumn()===(string)$periodSeq,'PARTIAL_NO_INVOICE_SEQUENCE');
echo "PASS_R196_PARTIAL_SETTLEMENT_GROSS_PAID_REMAINING_NO_FINANCIAL_SIDE_EFFECT\n";
echo "PASS_R196_REAL_MARIADB_81_MIGRATIONS_9_SCENARIOS\n";
