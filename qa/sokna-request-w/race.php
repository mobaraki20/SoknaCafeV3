$mode=getenv('SOKNA_W_MODE');
$o=$order('ci-w-race-order-0001',4);$core->inventoryOrders()->processPending();
$oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];$price=(int)$item['price'];
$svc=$core->settlements();$act=$core->orderStaffActions();
$second=\Sokna\Local\Core\Bootstrap::fromArray(require dirname($root).'/config.php');$p2=$second->database();$a($p!==$p2,'independent connections');
$p2->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$other=$run("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)",['ci_w_other',password_hash('fixture-only',PASSWORD_DEFAULT),'QA second']);
$pay=function()use($svc,$session,$line,$user,$a,$price){$ac=$svc->account($session);$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>'itemized','destination'=>'direct','request_id'=>'ci-w-payment-0001','selection'=>[['order_item_id'=>$line,'quantity'=>2]],'print_receipt'=>false];$preview=$svc->review($req,$user);$a((int)$preview['total']===2*$price,'payment amount');$req['tenders']=['cash'=>$price,'pos'=>$price];return $svc->settle($req,$user);};
$target=$oid;
if($mode==='grant'){$B=$order('ci-w-other-round-0002',3);$core->inventoryOrders()->processPending();$target=(int)$B['order_id'];$paid=$pay();}
$p2->beginTransaction();
$q=$p2->prepare($mode==='grant'?'SELECT COUNT(*) FROM remaining_account_discount_grants WHERE session_id=?':"SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND status='completed'");$q->execute([$session]);$a((int)$q->fetchColumn()===0,'snapshot initially empty');
if($mode==='grant')$svc->setDiscount($session,'fixed',6000,$user,$svc->account($session)['signature']);else $paid=$pay();
$q->execute([$session]);$a((int)$q->fetchColumn()===0,'stale snapshot reproduced');
$id=(int)$paid['settlement_id'];$receipt=$rows('SELECT * FROM settlement_records WHERE id=?',[$id]);$lines=$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$id]);$tenders=$rows('SELECT * FROM settlement_tender_allocations WHERE settlement_id=? ORDER BY tender_type',[$id]);
if($mode==='canonical'){
    $s2=$second->settlements();$req=['scope'=>'all','session_id'=>$session,'expected_order_ids'=>[$oid],'request_id'=>'ci-w-cancel-all-0001','reason'=>'QA cancellation with concurrent payment'];
    $r=$second->orderStaffActions()->cancelSelection($req,['id'=>$other],static fn($o,$s,$r,$reason,$actor)=>$s2->refundOrderPaymentsTx($o,$s,$r,$reason,$actor),static fn($s)=>$s2->accountTx($s));
    $a($r['success']===true,'canonical cancellation');$p2->commit();
    $r=$act->cancelSelection($req,['id'=>$other],static fn($o,$s,$r,$reason,$actor)=>$svc->refundOrderPaymentsTx($o,$s,$r,$reason,$actor),static fn($s)=>$svc->accountTx($s));$a($r['idempotent']===true,'replay');
    $a((string)$one('SELECT status FROM table_sessions WHERE id=?',[$session])==='closed','session closed');
    $a((string)$one('SELECT status FROM orders WHERE id=?',[$oid])==='cancelled','order cancelled');
    $a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND status='reversal'",[$session])===1,'single credit');
    $a((int)$one("SELECT SUM(CASE WHEN status='completed' THEN total ELSE -total END) FROM settlement_records WHERE session_id=?",[$session])===0,'net zero');
    $a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND status='completed' AND total=0 AND closes_session=1",[$session])===1,'zero closure');
    foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']],'inventory restored');
}else{
    $accepted=false;$code=null;
    try{$r=$second->orderStaffActions()->cancelWholeOrder(['order_id'=>$target,'request_id'=>'ci-w-cancel-unsafe-0001','reason'=>'QA stale cancellation'],['id'=>$other]);$accepted=($r['success']??false)===true;}
    catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$code=$e->errorCode;}
    finally{if($p2->inTransaction())$p2->rollBack();}
    if($mode==='baseline')$a($accepted&&$code===null,'baseline did not reproduce '.json_encode($code));
    else $a(!$accepted&&$code===($mode==='grant'?'discount_grant_present':'order_has_payment'),'current guard '.json_encode([$accepted,$code]));
    $a((string)$one('SELECT status FROM orders WHERE id=?',[$target])==='accounted','order remains intact');
    $a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND status='reversal'",[$session])===0,'no credit');
    $a((int)$one('SELECT COUNT(*) FROM whole_order_cancellations WHERE order_id=?',[$target])===0,'no cancelled ledger');
}
$a($rows('SELECT * FROM settlement_records WHERE id=?',[$id])===$receipt&&$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$id])===$lines&&$rows('SELECT * FROM settlement_tender_allocations WHERE settlement_id=? ORDER BY tender_type',[$id])===$tenders,'original immutable');
echo json_encode(['status'=>'PASS','mode'=>$mode,'old_snapshot_proven'=>true,'original_immutable'=>true,'baseline_defect_reproduced'=>$mode==='baseline','canonical_credit_closed'=>$mode==='canonical','blocked_unsafe'=>in_array($mode,['paid','grant'],true)],JSON_THROW_ON_ERROR)."\n";
