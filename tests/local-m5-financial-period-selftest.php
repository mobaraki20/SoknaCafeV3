<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Finance\FinancialPeriodException;

function m59_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m59_assert(bool $condition,string $message): void { if(!$condition)m59_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m59-'.bin2hex(random_bytes(4))],
    'db'=>[
        'host'=>(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),
        'port'=>(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
        'name'=>(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),
        'charset'=>'utf8mb4',
        'user'=>(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna'),
        'pass'=>(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),
    ],
]);
$core->migrations()->migrate();
$pdo=$core->database();

$tables=array_map('strval',$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
m59_assert(in_array('financial_period_close_overrides',$tables,true),'Financial Period override table missing');
foreach(['print_jobs'] as $later)
    m59_assert(!in_array($later,$tables,true),"M5.9 pulled later owner {$later} forward");

$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
    ->execute(['m59-admin',password_hash('x',PASSWORD_DEFAULT),'M59 Admin','admin']);
$adminId=(int)$pdo->lastInsertId();
$admin=['id'=>$adminId,'username'=>'m59-admin','display_name'=>'M59 Admin','role'=>'admin','active'=>1];
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
    ->execute(['m59-operator',password_hash('x',PASSWORD_DEFAULT),'M59 Operator','operator']);
$operatorId=(int)$pdo->lastInsertId();
$operator=['id'=>$operatorId,'username'=>'m59-operator','display_name'=>'M59 Operator','role'=>'operator','active'=>1];

$tail=$core->financialPeriods()->issueDocumentNumber('2027-03-21 02:00:00',$operatorId,'I');
m59_assert($tail['business_date']==='2027-03-20','after-midnight financial document escaped operational business date');
m59_assert($tail['invoice_number']==='I-1405-000001','fiscal-boundary invoice number drifted');

$reversal=$core->financialPeriods()->issueDocumentNumber('2027-03-21 02:30:00',$operatorId,'R');
m59_assert($reversal['invoice_number']==='R-1405-000002','invoice/reversal numbers did not share one monotonic period sequence');

$next=$core->financialPeriods()->issueDocumentNumber('2027-03-21 05:00:00',$operatorId,'I');
m59_assert($next['business_date']==='2027-03-21'&&$next['invoice_number']==='I-1406-000001','post-cutoff document did not move to next Jalali period sequence');

$pdo->beginTransaction();
$oldPeriod=$core->financialPeriodIdentity()->forDateTx('2026-03-20',$adminId);
$pdo->commit();
$oldPeriodId=(int)$oldPeriod['id'];
m59_assert((string)$oldPeriod['status']==='open','historical test period not open');

$unknown=$core->financialPeriods()->closePreflight($oldPeriodId,[
    'paired'=>true,'known'=>false,'error'=>'public_unreachable'
]);
m59_assert(in_array('public_unknown',$unknown['blockers'],true),'paired unknown Public state did not block close preflight');
m59_assert(empty($unknown['preflight_ready'])&&empty($unknown['final_close_allowed']),'unknown Public state incorrectly allowed close');

$receiptEnvelope=json_encode([
    'request_id'=>'m59-review-source','kind'=>'expense.create','occurred_at'=>'2026-03-20T12:00:00+03:30'
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$pdo->prepare(
    "INSERT INTO deferred_work_receipts(installation_id,request_id,request_hash,kind,actor_projection_id,actor_user_id,occurred_at,envelope_json,state,result_json,error_code,financial_period_id)
     VALUES('m59-install','m59-review-source',SHA2('m59-review-source',256),'expense.create',?,?,'2026-03-20 12:00:00',?,'needs_review','{}','closed_financial_period',?)"
)->execute(['user:'.$adminId,$adminId,$receiptEnvelope,$oldPeriodId]);
$receiptId=(int)$pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO deferred_review_items(receipt_id,review_type,financial_period_id,reason_code,message,state)
     VALUES(?,'late_correction',?,'closed_financial_period','review','pending')"
)->execute([$receiptId,$oldPeriodId]);

$localBlocked=$core->financialPeriods()->closePreflight($oldPeriodId,[
    'paired'=>false,'known'=>true,'counts'=>[],'blocking'=>0
]);
m59_assert($localBlocked['local_pending_reviews']===1&&in_array('local_pending_reviews',$localBlocked['blockers'],true),'Local pending review did not block period preflight');

$pdo->prepare("UPDATE deferred_review_items SET state='rejected',resolved_by_user_id=?,resolved_at=NOW() WHERE receipt_id=?")
    ->execute([$adminId,$receiptId]);
$ready=$core->financialPeriods()->closePreflight($oldPeriodId,[
    'paired'=>true,'known'=>true,
    'counts'=>['pending_sync'=>0,'needs_review'=>0,'committed'=>2,'rejected'=>1],
    'blocking'=>0
]);
m59_assert(!empty($ready['period_ended'])&&!empty($ready['preflight_ready']),'clean period did not pass M5.9 preflight');
m59_assert(!empty($ready['final_close_allowed'])&&$ready['final_close_blocker']==='','clean preflight did not expose final-close readiness after Settlement migration');

$override=$core->financialPeriods()->recordCloseOverride(
    $oldPeriodId,'Public checked manually',['paired'=>true,'known'=>false,'error'=>'public_unreachable'],$admin
);
m59_assert((int)$override['override_id']>0,'Admin close override evidence was not persisted');
$overrideCount=(int)$pdo->query("SELECT COUNT(*) FROM financial_period_close_overrides WHERE id=".(int)$override['override_id'])->fetchColumn();
$auditCount=(int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='financial_period.deferred_override' AND entity_id='{$oldPeriodId}'")->fetchColumn();
m59_assert($overrideCount===1&&$auditCount===1,'close override evidence/audit is incomplete');

$operatorDenied=false;
try{$core->financialPeriods()->recordCloseOverride(
    $oldPeriodId,'not allowed',['paired'=>true,'known'=>false],$operator
);}catch(FinancialPeriodException $e){$operatorDenied=$e->errorCode==='forbidden';}
m59_assert($operatorDenied,'non-admin user recorded Financial Period close override');

$status=(string)$pdo->query("SELECT status FROM financial_periods WHERE id={$oldPeriodId}")->fetchColumn();
m59_assert($status==='open','Financial Period preflight mutated period status before canonical close owner was invoked');

$publicDeferred=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Deferred/DeferredService.php');
m59_assert(str_contains($publicDeferred,'function periodStatus')||str_contains($publicDeferred,'function periodStatus('),'Public Deferred period-status contract missing');
m59_assert(str_contains($publicDeferred,"'pending_sync'")&&str_contains($publicDeferred,"'needs_review'"),'Public period status lost blocking-state vocabulary');

$service=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Finance/FinancialPeriodService.php');
m59_assert(!preg_match("/UPDATE\s+financial_periods\s+SET\s+status\s*=\s*['\\\"]closed/i",$service),'M5.9 implemented final close before Settlement owner');
m59_assert(!preg_match('/settlement_records|settlement_record_lines/i',$service),'Financial Period numbering/preflight service duplicated Settlement SQL owner');

fwrite(STDOUT,"Local M5.9 Financial Period numbering/preflight self-test: OK\n");
