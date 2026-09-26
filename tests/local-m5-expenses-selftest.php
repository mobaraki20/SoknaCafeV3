<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Expenses\ExpenseException;
use Sokna\Local\Domain\Finance\FinancialPeriodIdentityService;

function m58_fail(string $message): never { fwrite(STDERR,$message.PHP_EOL); exit(1); }
function m58_assert(bool $condition,string $message): void { if(!$condition)m58_fail($message); }

$core=sokna_local_bootstrap([
    'app'=>['timezone'=>'Asia/Tehran','data_dir'=>sys_get_temp_dir().'/sokna-v3-m58-'.bin2hex(random_bytes(4))],
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
foreach(['financial_periods','expense_categories','expenses','deferred_work_receipts','deferred_review_items'] as $table)
    m58_assert(in_array($table,$tables,true),"missing {$table}");
foreach([] as $later)
    m58_assert(!in_array($later,$tables,true),"M5.8 pulled later owner {$later} forward");

m58_assert(FinancialPeriodIdentityService::gregorianToJalali(2026,7,25)===[1405,5,3],'Gregorian→Jalali identity drifted');
m58_assert(FinancialPeriodIdentityService::jalaliToGregorian(1405,5,3)===[2026,7,25],'Jalali→Gregorian identity drifted');
$bounds=FinancialPeriodIdentityService::boundsForDate('2026-07-25');
m58_assert((int)$bounds['jalali_year']===1405,'financial period Jalali year drifted');
m58_assert('2026-07-25'>=$bounds['start_date']&&'2026-07-25'<=$bounds['end_date'],'period bounds do not cover target date');

$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
    ->execute(['m58-admin',password_hash('x',PASSWORD_DEFAULT),'M58 Admin','admin']);
$adminId=(int)$pdo->lastInsertId();
$admin=['id'=>$adminId,'username'=>'m58-admin','display_name'=>'M58 Admin','role'=>'admin','active'=>1];
$pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)')
    ->execute(['m58-operator',password_hash('x',PASSWORD_DEFAULT),'M58 Operator','operator']);
$operatorId=(int)$pdo->lastInsertId();
$operator=['id'=>$operatorId,'username'=>'m58-operator','display_name'=>'M58 Operator','role'=>'operator','active'=>1];

$occurred='2026-07-25 12:00:00';
$e1=$core->expenses()->create([
    'category_key'=>'other','amount'=>1000,'description'=>'first',
    'occurred_at'=>$occurred,'source_request_id'=>'local:expense:m58-1',
],$admin);
m58_assert(empty($e1['idempotent'])&&(int)$e1['id']>0,'expense create failed');
$periodId=(int)$e1['financial_period_id'];
$period=(array)$pdo->query("SELECT * FROM financial_periods WHERE id={$periodId}")->fetch(PDO::FETCH_ASSOC);
m58_assert($period['status']==='open'&&'2026-07-25'>=$period['start_date']&&'2026-07-25'<=$period['end_date'],'expense did not attach to covering open period');

$retry=$core->expenses()->create([
    'category_key'=>'other','amount'=>1000,'description'=>'changed description allowed by historical idempotency',
    'occurred_at'=>$occurred,'source_request_id'=>'local:expense:m58-1',
],$admin);
m58_assert(!empty($retry['idempotent'])&&(int)$retry['id']===(int)$e1['id'],'expense retry did not replay canonical row');
$conflict=false;
try{$core->expenses()->create([
    'category_key'=>'other','amount'=>1001,'occurred_at'=>$occurred,'source_request_id'=>'local:expense:m58-1',
],$admin);}catch(ExpenseException $e){$conflict=$e->errorCode==='idempotency_conflict';}
m58_assert($conflict,'expense source_request_id accepted different business payload');

$rev=$core->expenses()->reverse((int)$e1['id'],'duplicate entry','local:expense:m58-1-rev',$admin);
m58_assert(empty($rev['idempotent']),'expense reversal did not create append-only row');
$rows=$pdo->query("SELECT id,status,reverses_expense_id FROM expenses WHERE id IN(".(int)$e1['id'].",".(int)$rev['id'].") ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
m58_assert(count($rows)===2&&$rows[0]['status']==='committed'&&$rows[1]['status']==='reversal'&&(int)$rows[1]['reverses_expense_id']===(int)$e1['id'],'reversal mutated/deleted original expense');
$revRetry=$core->expenses()->reverse((int)$e1['id'],'another retry reason','local:expense:m58-1-rev-2',$admin);
m58_assert(!empty($revRetry['idempotent'])&&(int)$revRetry['id']===(int)$rev['id'],'second reversal was not idempotent by original row');

$e2=$core->expenses()->create([
    'category_key'=>'services','amount'=>2000,'occurred_at'=>'2026-07-26 10:00:00','source_request_id'=>'local:expense:m58-2',
],$admin);
$correct=$core->expenses()->correct((int)$e2['id'],[
    'category_key'=>'maintenance','amount'=>2500,'occurred_at'=>'2026-07-26 10:00:00','description'=>'corrected',
],'wrong amount','local:expense-correct:m58-2',$admin);
m58_assert((int)$correct['reversal_id']>0&&(int)$correct['replacement_id']>0,'expense correction did not create reversal + replacement');
$original2=(array)$pdo->query("SELECT status FROM expenses WHERE id=".(int)$e2['id'])->fetch(PDO::FETCH_ASSOC);
m58_assert($original2['status']==='committed','correction edited original expense');

$e3=$core->expenses()->create([
    'category_key'=>'rent','amount'=>3000,'occurred_at'=>'2026-07-27 10:00:00','source_request_id'=>'local:expense:m58-3',
],$admin);
$failedCorrection=false;
try{$core->expenses()->correct((int)$e3['id'],[
    'category_key'=>'missing','amount'=>3500,'occurred_at'=>'2026-07-27 10:00:00',
],'bad replacement','local:expense-correct:m58-3',$admin);}
catch(ExpenseException $e){$failedCorrection=$e->errorCode==='invalid_category';}
m58_assert($failedCorrection,'invalid correction replacement did not fail');
$rollbackReversal=(int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE reverses_expense_id=".(int)$e3['id'])->fetchColumn();
m58_assert($rollbackReversal===0,'failed correction left a partial reversal behind');

$openEnv=[
    'request_id'=>'m58-expense-open-1','kind'=>'expense.create',
    'created_at'=>'2026-07-28T11:00:00+03:30','occurred_at'=>'2026-07-28T11:00:00+03:30',
    'actor_projection_id'=>'user:'.$adminId,
    'payload'=>['category_key'=>'utilities','amount'=>400,'description'=>'remote open'],
];
$d1=$core->expenseDeferred()->dispatch('m58-install',$openEnv);
$d2=$core->expenseDeferred()->dispatch('m58-install',$openEnv);
m58_assert($d1['state']==='committed'&&empty($d1['idempotent'])&&$d2['state']==='committed'&&!empty($d2['idempotent']),'Deferred open-period expense lost exactly-once receipt semantics');
$remoteCount=(int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE source_request_id='deferred:m58-expense-open-1'")->fetchColumn();
m58_assert($remoteCount===1,'Deferred expense retry created duplicate expense rows');

$summary=$core->expenses()->periodSummary($periodId);
m58_assert($summary['net_amount']===5900,'expense net summary does not reconcile committed/reversal rows');

$pdo->prepare("UPDATE financial_periods SET status='closed',closed_at=NOW(),closed_by_user_id=? WHERE id=?")
    ->execute([$adminId,$periodId]);

$closedCreate=false;
try{$core->expenses()->create([
    'category_key'=>'other','amount'=>500,'occurred_at'=>'2026-07-29 10:00:00','source_request_id'=>'local:expense:m58-closed',
],$admin);}catch(ExpenseException $e){$closedCreate=$e->errorCode==='closed_financial_period';}
m58_assert($closedCreate,'normal expense mutation entered a closed period');

$closedReverse=false;
try{$core->expenses()->reverse((int)$e3['id'],'closed reverse','local:expense:m58-closed-rev',$admin);}
catch(ExpenseException $e){$closedReverse=$e->errorCode==='closed_financial_period';}
m58_assert($closedReverse,'normal reversal entered a closed period');

$lateEnv=[
    'request_id'=>'m58-expense-late-1','kind'=>'expense.create',
    'created_at'=>'2026-09-26T12:00:00+03:30','occurred_at'=>'2026-07-30T11:00:00+03:30',
    'actor_projection_id'=>'user:'.$adminId,
    'payload'=>['category_key'=>'other','amount'=>600,'description'=>'late remote'],
];
$late=$core->expenseDeferred()->dispatch('m58-install',$lateEnv);
m58_assert($late['state']==='needs_review'&&$late['error_code']==='closed_financial_period','closed-period Deferred expense did not become review');
$lateRetry=$core->expenseDeferred()->dispatch('m58-install',$lateEnv);
m58_assert($lateRetry['state']==='needs_review'&&!empty($lateRetry['idempotent']),'late review retry did not replay durable receipt');
$lateBefore=(int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE source_request_id='deferred:m58-expense-late-1'")->fetchColumn();
m58_assert($lateBefore===0,'late Deferred expense mutated closed period before approval');

$reviewId=(int)($late['result']['review_id']??0);
m58_assert($reviewId>0,'late expense review row missing');
$reviewRow=(array)$pdo->query("SELECT financial_period_id,review_type,state FROM deferred_review_items WHERE id={$reviewId}")->fetch(PDO::FETCH_ASSOC);
m58_assert((int)$reviewRow['financial_period_id']===$periodId&&$reviewRow['review_type']==='late_correction'&&$reviewRow['state']==='pending','late expense review lost period identity');

$approved=$core->expenseDeferred()->resolveReview($reviewId,'approve','manager approved late correction',$admin);
m58_assert($approved['state']==='committed','approved late expense did not commit');
$lateAfter=(int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE source_request_id='deferred:m58-expense-late-1'")->fetchColumn();
m58_assert($lateAfter===1,'approved late expense did not create exactly one row');

$deniedEnv=$openEnv;
$deniedEnv['request_id']='m58-expense-denied-1';
$deniedEnv['actor_projection_id']='user:'.$operatorId;
$denied=$core->expenseDeferred()->dispatch('m58-install',$deniedEnv);
m58_assert($denied['state']==='rejected'&&$denied['error_code']==='permission_denied','Deferred expense did not revalidate Local admin authority');

$publicDeferred=(string)file_get_contents(dirname(__DIR__).'/apps/public/src/Deferred/DeferredService.php');
m58_assert(str_contains($publicDeferred,"'expense.create' => 'expense.create.defer'"),'Public Deferred expense capability contract drifted');

$expenseSource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Expenses/ExpenseService.php');
m58_assert(!preg_match('/inventory_movements|inventory_supply_receipts/i',$expenseSource),'Expenses duplicated Inventory/Supply purchase cost authority');
$periodSource=(string)file_get_contents(dirname(__DIR__).'/apps/local-web/src/Domain/Finance/FinancialPeriodIdentityService.php');
m58_assert(!str_contains($periodSource,'closePeriod')&&!str_contains($periodSource,'settlement_records'),'M5.8 prerequisite pulled Financial Period close/Settlement authority forward');

fwrite(STDOUT,"Local M5.8 Expenses/period-identity self-test: OK\n");
