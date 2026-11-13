<?php
declare(strict_types=1);
/** Disposable CI only. Absolutely never connect to any real Sokna database. */
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R200_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('R200_DISPOSABLE_CI_ONLY');
$web=(string)getenv('SOKNA_R200_WEB');
$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$tmp||!$web||!is_file($web.'/bootstrap.php')||!str_starts_with(realpath($web)?:'', $tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('R200_DISPOSABLE_WEB_ONLY');
$ciRoot=dirname($web);$ciData=$ciRoot.'/Data';$ciMedia=$ciData.'/guest-media';
if(!is_dir($ciMedia)&&!mkdir($ciMedia,0700,true)&&!is_dir($ciMedia))throw new RuntimeException('R200_MEDIA_DIR');
if(file_exists($ciRoot.'/config.php')||is_link($ciRoot.'/config.php'))throw new RuntimeException('R200_REFUSE_EXISTING_CONFIG');
file_put_contents($ciRoot.'/config.php','<?php return '.var_export(['app'=>['data_dir'=>$ciData]],true).';');
require $web.'/bootstrap.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('SOKNA_R200_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS sokna_r200_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE sokna_r200_disposable');
$migrations=new \Sokna\Local\Core\Migrations($pdo,$web.'/database/migrations');
$migrations->migrate('0059_menu_business_reconciliation_v4');
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')->execute(['qa.r200',password_hash('only-in-CI',PASSWORD_DEFAULT),'QA Admin','admin']);
$uid=(int)$pdo->lastInsertId();$actor=['id'=>$uid,'role'=>'admin'];
$icons=new \Sokna\Local\Domain\Sellables\CategoryIconLibrary($web.'/resources/default-content/v1/category-icons.json');
$seeder=new \Sokna\Local\Domain\Sellables\DefaultContentSeeder($pdo,$icons,$web.'/resources/default-content/v1',$ciMedia);
if(($seeder->seed($uid)['status']??'')!=='complete')throw new RuntimeException('R200_SEED_INCOMPLETE');
$migrations->migrate();
$ledger=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if($ledger!==81)throw new RuntimeException('R200_REAL_MIGRATIONS_'.$ledger);
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
$inv=new \Sokna\Local\Domain\Inventory\InventoryService($pdo,$id,$caps);
$inventoryOrders=new \Sokna\Local\Domain\Inventory\InventoryOrderService($pdo,$inv);
$prepAccess=new \Sokna\Local\Domain\Preparation\PreparationAccessService($pdo,$id,$caps);
$prep=new \Sokna\Local\Domain\Preparation\PreparationService($pdo,$prepAccess,$clock);
$orderActions=new \Sokna\Local\Domain\Orders\OrderStaffActionService($pdo,$id,$caps,$inventoryOrders,$printing,$prep);
function ensure(bool $ok,string $code):void{if(!$ok)throw new RuntimeException('R200_ASSERT_'.$code);}
function countQ(PDO $pdo,string $sql,array $args=[]):int{$q=$pdo->prepare($sql);$q->execute($args);return (int)$q->fetchColumn();}
function fixture(PDO $pdo,int $uid,int $price=250,bool $pending=false):array{
 static $n=0;++$n;
 $pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active) VALUES(?,?,?,?,1)')->execute(['R200 Table '.$n,$n,'R200-T-'.$n,'R200-TOKEN-'.$n]);$table=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,CURDATE(),'day','QA','05:00')")->execute(['R200-S-'.$n,$table,$table,$uid]);$sid=(int)$pdo->lastInsertId();
 $oid=addOrder($pdo,$uid,$table,$sid,$price,$pending,$n.'-1');
 return [$table,$sid,$oid];
}
function addOrder(PDO $pdo,int $uid,int $table,int $sid,int $price,bool $pending,string $key):int{
 $status=$pending?'pending_approval':'accounted';
 $pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,accepted_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff',?,?,?, ?,CURDATE(),'day','QA','05:00')")
  ->execute(['R200-ORDER-'.$key,'R200-CLIENT-'.$key,$table,$sid,$status,$price,$uid,random_int(100000,999999)]);
 $oid=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,fulfillment_mode,preparation_station,line_total,item_note) VALUES(?,NULL,?, ?,1,1,'dine_in','none',?,?)")
  ->execute([$oid,'آیتم آزمایشی '.$key,$price,$price,'بدون شکر']);
 return $oid;
}
function queuePayload(PDO $pdo,int $jobId):array{$q=$pdo->prepare('SELECT payload_json FROM print_jobs WHERE id=?');$q->execute([$jobId]);$p=json_decode((string)$q->fetchColumn(),true);if(!is_array($p))throw new RuntimeException('R200_BAD_PAYLOAD');return $p;}
function printWith(\Sokna\Local\Domain\Finance\SettlementService $service,int $sid,array $a,string $rid,array $user):array{
 return $service->printOpenAccount($sid,(int)$a['remaining_total'],(string)$a['signature'],$rid,$user);
}
function rejected(callable $f,string $expected):void{
 try{$f();}catch(\Sokna\Local\Domain\Finance\SettlementException $e){ensure($e->errorCode===$expected,'REJECT_'.$expected.'_GOT_'.$e->errorCode);return;}
 throw new RuntimeException('R200_EXPECTED_REJECTION_'.$expected);
}

function settleAccount($settlements,int $sid,array $actor,string $requestId,?int $orderItemId=null,int $total=0):array {
 $a=$settlements->account($sid);
 $mode=$orderItemId===null?'full':'itemized';
 $amount=$orderItemId===null?(int)$a['remaining_total']:$total;
 return $settlements->settle([
  'session_id'=>$sid,'expected_session_id'=>$sid,'expected_remaining_total'=>(int)$a['remaining_total'],
  'expected_signature'=>(string)$a['signature'],'destination'=>'direct','mode'=>$mode,
  ...($orderItemId===null?[]:['selection'=>[['order_item_id'=>$orderItemId,'quantity'=>1]]]),
  'tenders'=>[['type'=>'cash','amount'=>$amount]],'print_receipt'=>false,'request_id'=>$requestId
 ],$actor);
}
function state(PDO $pdo,string $table,int $id):string{
 $q=$pdo->prepare("SELECT status FROM {$table} WHERE id=?");$q->execute([$id]);return (string)$q->fetchColumn();
}
// A: A single fully paid order: the real reversal issues R and reopens the account,
// but does NOT cancel the order. No premature status mutation is safe.
[$table,$sid,$order]=fixture($pdo,$uid,250);
$paid=settleAccount($settlements,$sid,$actor,'r200-paid-a');$receipt=(int)$paid['settlement_id'];
ensure(state($pdo,'orders',$order)==='completed'&&state($pdo,'table_sessions',$sid)==='closed','A_PAID_CLOSE');
$originalQ=$pdo->prepare('SELECT invoice_number FROM settlement_records WHERE id=?');$originalQ->execute([$receipt]);
$invoice=(string)$originalQ->fetchColumn();ensure(str_starts_with($invoice,'I-'),'A_INVOICE_NUMBER');
$rev=$settlements->reverse($receipt,'QA only refund reason','r200-refund-a',$actor);
$reversalCount=countQ($pdo,"SELECT COUNT(*) FROM settlement_records WHERE reverses_settlement_id=? AND status='reversal'",[$receipt]);
ensure($reversalCount===1,'A_REVERSAL_ONCE');
ensure(state($pdo,'table_sessions',$sid)==='active'&&state($pdo,'orders',$order)==='accounted','A_REOPEN_NOT_CANCEL');
$q=$pdo->prepare("SELECT invoice_number FROM settlement_records WHERE reverses_settlement_id=? AND status='reversal'");$q->execute([$receipt]);
ensure(str_starts_with((string)$q->fetchColumn(),'R-'),'A_REFUND_DOCUMENT_PREFIX');
ensure($invoice!==''&&str_starts_with($invoice,'I-'),'A_ORIGINAL_IMMUTABLE');
echo "PASS_R200_REAL_SINGLE_RECEIPT_REVERSAL_REOPENS_NOT_CANCELS\n";
// B: replay of reversal does not create a second R or a second refund allocation.
$rev2=$settlements->reverse($receipt,'QA only refund reason','r200-refund-a',$actor);
ensure(countQ($pdo,"SELECT COUNT(*) FROM settlement_records WHERE reverses_settlement_id=? AND status='reversal'",[$receipt])===1,'B_NO_DUP_REFUND');
echo "PASS_R200_REVERSAL_IDEMPOTENT\n";
// C: source order cancel correctly refuses any historical receipt, even reversed.
try{$orderActions->cancelUnsettledStaffOrder(['order_id'=>$order,'request_id'=>'r200-postpay-cancel-c','reason'=>'QA duplicate charge'],$actor);throw new RuntimeException('R200_UNEXPECTED_CANCEL');}
catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ensure($e->errorCode==='settlement_exists','C_FINANCE_GUARD_CODE');}
ensure(state($pdo,'orders',$order)==='accounted'&&countQ($pdo,'SELECT COUNT(*) FROM order_item_adjustments WHERE order_id=?',[$order])===0,'C_NOT_MUTATED');
echo "PASS_R200_POSTSETTLEMENT_ORDER_CANCEL_FAILS_CLOSED\n";
// D: multi-order ONE receipt contains lines from both orders. No single-order
// refund may call reverse() on that receipt without changing the other order.
[$t2,$s2,$o2]=fixture($pdo,$uid,250);$o3=addOrder($pdo,$uid,$t2,$s2,300,false,'r200-mixed');
$multi=settleAccount($settlements,$s2,$actor,'r200-paid-mixed');$mid=(int)$multi['settlement_id'];
ensure(countQ($pdo,'SELECT COUNT(DISTINCT order_id) FROM settlement_record_lines WHERE settlement_id=?',[$mid])===2,'D_MIXED_LINES');
ensure((int)$multi['total_amount']===550,'D_MIXED_AMOUNT');
ensure(state($pdo,'orders',$o2)==='completed'&&state($pdo,'orders',$o3)==='completed','D_BOTH_PAID');
echo "PASS_R200_MULTIORDER_RECEIPT_CANNOT_REVERSE_SINGLE_ORDER\n";
// E: two independent partial receipts must reverse LIFO; refunding earlier one
// before later one is a financial conflict, not a retry candidate.
[$t3,$s3,$o4]=fixture($pdo,$uid,250);$o5=addOrder($pdo,$uid,$t3,$s3,300,false,'r200-lifo');
$a=$settlements->account($s3);$firstItem=(int)$a['items'][0]['id'];
$p1=settleAccount($settlements,$s3,$actor,'r200-lifo-first',$firstItem,250);
$p2=settleAccount($settlements,$s3,$actor,'r200-lifo-second');
try{$settlements->reverse((int)$p1['settlement_id'],'QA out of order','r200-lifo-wrong',$actor);throw new RuntimeException('R200_UNEXPECTED_EARLY_REFUND');}
catch(\Sokna\Local\Domain\Finance\SettlementStateConflict $e){ensure($e->errorCode==='later_settlement_exists','E_LIFO_GUARD_CODE');}
ensure(countQ($pdo,"SELECT COUNT(*) FROM settlement_records WHERE status='reversal' AND session_id=?",[$s3])===0,'E_NO_PARTIAL_MUTATION');
echo "PASS_R200_PARTIAL_RECEIPT_LIFO_REFUND_GUARD\n";
// R200: Complete cash reversal already exists; explicit physical cash refund confirmation
// permits only THIS single-order receipt to be voided, with no financial mutations.
$refundNumber=(string)$pdo->query("SELECT invoice_number FROM settlement_records WHERE reverses_settlement_id={$receipt} AND status='reversal'")->fetchColumn();
function expectCancelReject(\Sokna\Local\Domain\Orders\OrderStaffActionService $service,array $payload,array $actor,string $code,bool $refundMode=false):void{
 try{$service->cancelUnsettledStaffOrder($payload,$actor,$refundMode);}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ensure($e->errorCode===$code,'CANCEL_GUARD_'.$code.'_GOT_'.$e->errorCode);return;}
 throw new RuntimeException('R200_EXPECTED_CANCEL_REJECT_'.$code);
}
$payload=['order_id'=>$order,'reason'=>'تصحیح سفارش اشتباه پس از برگشت کامل وجه','request_id'=>'r200-refund-final-a','refund_document_number'=>$refundNumber];
expectCancelReject($orderActions,[...$payload,'cash_refund_physically_confirmed'=>true],$actor,'settlement_exists');
expectCancelReject($orderActions,$payload,$actor,'settlement_exists');
try{$orderActions->cancelUnsettledStaffOrder($payload,$actor,true);throw new RuntimeException('R200_MISSING_CASH_ACK_ACCEPTED');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){ensure($e->errorCode==='cash_refund_confirmation_required','F_REQUIRE_PHYSICAL_REFUND_ATTESTATION');}
ensure(state($pdo,'orders',$order)==='accounted','F_NO_CHANGE_WHEN_NO_CASH_ATTESTATION');
expectCancelReject($orderActions,[...$payload,'cash_refund_physically_confirmed'=>true,'refund_document_number'=>'R-wrong'],$actor,'refund_document_mismatch',true);
$financeBefore=countQ($pdo,'SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$sid]);
$done=$orderActions->cancelUnsettledStaffOrder([...$payload,'cash_refund_physically_confirmed'=>true],$actor,true);
ensure(($done['success']??false)===true&&($done['status']??'')==='cancelled'&&($done['refund_document_number']??'')===$refundNumber,'F_FULL_REFUNDED_CASH_CANCEL_WORKS');
ensure(state($pdo,'orders',$order)==='cancelled'&&state($pdo,'table_sessions',$sid)==='closed','F_CANCEL_CLOSES_EMPTY_SESSION');
ensure(countQ($pdo,'SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$sid])===$financeBefore,'F_NO_NEW_FINANCIAL_DOCS');
ensure(countQ($pdo,'SELECT COUNT(*) FROM order_status_history WHERE order_id=? AND to_status=\'cancelled\'',[$order])===1,'F_CANCELLED_ONCE');
$again=$orderActions->cancelUnsettledStaffOrder([...$payload,'cash_refund_physically_confirmed'=>true],$actor,true);
ensure(($again['idempotent']??false)===true,'F_IDEMPOTENT_CANCEL');
echo "PASS_R200_SINGLE_ORDER_CONFIRMED_CASH_REFUND_CANCELS_WITHOUT_EXTRA_LEDGER\n";
// A mixed receipt has already been paid; reversing it still cannot authorize
// cancellation of one included order, even with correct R and cash attestation.
$mixedRefund=$settlements->reverse($mid,'QA cash refund mixed receipt','r200-reverse-mixed',$actor);
$mixNo=(string)$pdo->query("SELECT invoice_number FROM settlement_records WHERE reverses_settlement_id={$mid} AND status='reversal'")->fetchColumn();
expectCancelReject($orderActions,[
 'order_id'=>$o2,'reason'=>'mixed cannot target one order','request_id'=>'r200-mixed-refund-deny',
 'cash_refund_physically_confirmed'=>true,'refund_document_number'=>$mixNo
],$actor,'refund_mixed_order_receipt',true);
ensure(state($pdo,'orders',$o2)==='accounted'&&state($pdo,'orders',$o3)==='accounted','G_MIXED_ORDERS_PRESERVED');
echo "PASS_R200_MIXED_ORDER_RECEIPT_REFUNDED_STILL_BLOCKED\n";
// Multi-receipt histories remain unsupported even if all payments are later reversed.
$settlements->reverse((int)$p2['settlement_id'],'QA reverse latest','r200-reverse-p2',$actor);
$settlements->reverse((int)$p1['settlement_id'],'QA reverse earliest','r200-reverse-p1',$actor);
$multipleNo=(string)$pdo->query("SELECT invoice_number FROM settlement_records WHERE reverses_settlement_id=".(int)$p2['settlement_id']." AND status='reversal'")->fetchColumn();
expectCancelReject($orderActions,[
 'order_id'=>$o4,'reason'=>'multi receipt blocked','request_id'=>'r200-multi-refund-deny',
 'cash_refund_physically_confirmed'=>true,'refund_document_number'=>$multipleNo
],$actor,'refund_history_ambiguous',true);
echo "PASS_R200_MULTIRECEIPT_FINANCE_FAIL_CLOSED\n";
echo "PASS_R200_REAL_MARIADB_81_MIGRATIONS_5_LEGACY_GATES_PLUS_3_REFUNDED_CASH_GATES\n";
