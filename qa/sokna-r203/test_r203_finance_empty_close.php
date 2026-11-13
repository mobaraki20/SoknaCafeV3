<?php
declare(strict_types=1);
// Reuse R202 disposable-only MariaDB settlement fixtures, then test no-line closure.
require __DIR__.'/test_r202_finance_real_db.php';
$changes=new \Sokna\Local\Domain\Orders\TableChangeService($pdo,$id,$caps,new \Sokna\Local\Core\Observability($data));
function emptyFixture(PDO $pdo,int $uid):array{
 static $n=0;++$n;$number=50+$n;
 $pdo->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token,active) VALUES(?,?,?,?,1)')->execute(['Empty QA '.$n,$number,'R203-E-'.$n,'R203-E-TOKEN-'.$n]);$table=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,CURDATE(),'day','QA','05:00')")->execute(['R203-E-S-'.$n,$table,$table,$uid]);$sid=(int)$pdo->lastInsertId();return [$table,$sid];
}
function closeE($service,int $table,int $sid,array $user,string $rid):array{return $service->closeEmpty(['table_id'=>$table,'expected_session_id'=>$sid,'request_id'=>$rid],$user);}
[$et,$es]=emptyFixture($pdo,$uid);
$a=closeE($changes,$et,$es,$actor,'qa-empty-203001');ok(($a['closed']??false)===true&&($a['idempotent']??true)===false,'EMPTY_CLOSE_OK');
ok((string)scalar($pdo,'SELECT status FROM table_sessions WHERE id=?',[$es])==='closed','EMPTY_CLOSED');
ok(scalar($pdo,'SELECT live_table_guard FROM table_sessions WHERE id=?',[$es])===null,'EMPTY_FREED_GUARD');
ok((int)scalar($pdo,'SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$es])===0,'EMPTY_NO_FAKE_SETTLEMENT');
ok((int)scalar($pdo,'SELECT COUNT(*) FROM audit_log WHERE action=? AND entity_id=?',['table_session.closed_empty','qa-empty-203001'])===1,'EMPTY_AUDIT');
$b=closeE($changes,$et,$es,$actor,'qa-empty-203001');ok(($b['idempotent']??false)===true,'EMPTY_REPLAY_SAFE');
echo "PASS_R203_EMPTY_CLOSE_NO_FINANCIAL_SIDE_EFFECT_AND_REPLAY\n";
[$et2,$es2]=emptyFixture($pdo,$uid);
try{closeE($changes,$et2,$es+7777,$actor,'qa-empty-203002');throw new RuntimeException('FAIL_R203_BAD_SESSION_ACCEPTED');}catch(\Sokna\Local\Domain\Orders\TableChangeException $e){ok($e->errorCode==='session_changed','SESSION_GUARD');}
ok((string)scalar($pdo,'SELECT status FROM table_sessions WHERE id=?',[$es2])==='active','SESSION_UNCHANGED');
try{closeE($changes,$et2,$es2,$actor,'qa-empty-203001');throw new RuntimeException('FAIL_R203_REQUEST_ID_REUSE_ACCEPTED');}catch(\Sokna\Local\Domain\Orders\TableChangeException $e){ok($e->errorCode==='request_id_conflict','REQUEST_ID_REUSE');}
echo "PASS_R203_STALE_SESSION_AND_REQUEST_COLLISION_BLOCKED\n";
[$et3,$es3]=emptyFixture($pdo,$uid);
$pdo->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,accepted_by_user_id,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff','accounted',0,?,?,CURDATE(),'day','QA','05:00')")->execute(['R203-E-ORD','R203-E-ORDER-CLIENT',$et3,$es3,$uid,955055]);
try{closeE($changes,$et3,$es3,$actor,'qa-empty-203003');throw new RuntimeException('FAIL_R203_ACCOUNTED_ZERO_ORDER_ACCEPTED');}catch(\Sokna\Local\Domain\Orders\TableChangeException $e){ok($e->errorCode==='empty_close_order_exists','ORDER_ZERO_GUARD');}
$pdo->prepare("UPDATE orders SET status='cancelled' WHERE session_id=?")->execute([$es3]);
$c=closeE($changes,$et3,$es3,$actor,'qa-empty-203003');ok(($c['closed']??false)===true,'CANCELLED_ONLY_ALLOWED');
echo "PASS_R203_ZERO_PRICE_ORDER_BLOCKED_BUT_CANCELLED_HISTORY_PRESERVED\n";
[$et4,$es4]=emptyFixture($pdo,$uid);
$pdo->prepare("INSERT INTO table_drafts(table_id,state,active_table_guard,version,expected_session_id,created_by_user_id) VALUES(?,'active',?,1,?,?)")->execute([$et4,$et4,$es4,$uid]);
try{closeE($changes,$et4,$es4,$actor,'qa-empty-203004');throw new RuntimeException('FAIL_R203_DRAFT_ACCEPTED');}catch(\Sokna\Local\Domain\Orders\TableChangeException $e){ok($e->errorCode==='empty_close_draft_active','DRAFT_GUARD');}
echo "PASS_R203_ACTIVE_DRAFT_BLOCKED\n";
[$et5,$es5]=emptyFixture($pdo,$uid);
$pdo->prepare("INSERT INTO waiter_calls(public_code,client_token,table_id,session_id,status,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'new',CURDATE(),'day','QA','05:00')")->execute(['R203-E-CALL','R203-E-CALL-CLIENT',$et5,$es5]);
try{closeE($changes,$et5,$es5,$actor,'qa-empty-203005');throw new RuntimeException('FAIL_R203_WAITER_ACCEPTED');}catch(\Sokna\Local\Domain\Orders\TableChangeException $e){ok($e->errorCode==='empty_close_waiter_active','WAITER_GUARD');}
echo "PASS_R203_PENDING_WAITER_CALL_BLOCKED\n";
[$et6,$es6]=emptyFixture($pdo,$uid);
// First R202 scenario has a real completed receipt; artificially reopen only in disposable DB
// to prove no empty-account closure can bypass financial history.
$pdo->prepare('UPDATE table_sessions SET status=?,live_table_guard=? WHERE id=?')->execute(['active',(int)scalar($pdo,'SELECT table_id FROM table_sessions WHERE id=?',[$sid]),$sid]);
try{closeE($changes,(int)scalar($pdo,'SELECT table_id FROM table_sessions WHERE id=?',[$sid]),$sid,$actor,'qa-empty-203006');throw new RuntimeException('FAIL_R203_PAID_SESSION_ACCEPTED');}
catch(\Sokna\Local\Domain\Orders\TableChangeException $e){ok(in_array($e->errorCode,['empty_close_order_exists','empty_close_financial_history'],true),'PAID_HISTORY_BLOCKED');}
// A separate financial ledger is never created for the normal empty-close.
ok((int)scalar($pdo,'SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$es6])===0,'NEVER_FAKE_FINANCIAL');
echo "PASS_R203_EMPTY_CLOSE_REAL_MARIADB_GUARDS_COMPLETE\n";
