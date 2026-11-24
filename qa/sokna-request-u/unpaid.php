$mode=getenv('SOKNA_U_MODE');
$o=$order('ci-u-unpaid-0001',4);$core->inventoryOrders()->processPending();
$oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];
$svc=$core->settlements();$act=$core->orderStaffActions();
if($mode!=='unclaimed'){
    $area=\Sokna\Local\Domain\Preparation\PreparationService::areaForPreparationStation((string)$one('SELECT preparation_station FROM order_items WHERE id=?',[$line]));
    $a(is_string($area),'valid prep area');
    $run("INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,'preparation',1)",[$operator]);
    $run("INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,?)",[$operator,$area]);
    $core->preparation()->claim(['order_id'=>$oid,'area'=>$area],['id'=>$operator]);
}
if($mode==='waiter'){
    $call=$run("INSERT INTO waiter_calls(public_code,client_token,table_id,session_id,status,active_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'new',?,?, 'day','روز','00:00')",['qa-u-call','qa-u-call-token',$table,$session,$table,$day]);
}
$req=['scope'=>'all','expected_order_ids'=>[$oid],'session_id'=>$session,'request_id'=>'ci-u-cancel-all-0001','reason'=>'QA unpaid final cancellation'];
$cancel=fn()=>$act->cancelSelection($req,$user,static fn($o,$s,$r,$reason,$actor)=>$svc->refundOrderPaymentsTx($o,$s,$r,$reason,$actor),static fn($s)=>$svc->accountTx($s));
$r=$cancel();$a($r['success']===true&&$cancel()['idempotent']===true,'cancel and replay');
$a((string)$one('SELECT status FROM orders WHERE id=?',[$oid])==='cancelled','order cancelled');
$a((int)$one('SELECT COUNT(*) FROM whole_order_cancellations WHERE order_id=?',[$oid])===1,'one cancellation ledger');
if($mode==='waiter'){
    $a((string)$one('SELECT status FROM table_sessions WHERE id=?',[$session])==='active','active call keeps session open');
    $a((string)$one('SELECT status FROM waiter_calls WHERE id=?',[$call])==='new','call preserved');
    try{$core->tableChanges()->closeEmpty(['table_id'=>$table,'expected_session_id'=>$session,'request_id'=>'ci-u-close-0001'],$user);throw new LogicException('active call closed');}catch(\Sokna\Local\Domain\Orders\TableChangeException $e){$a($e->errorCode==='empty_close_waiter_active','call guard');}
    $run("UPDATE waiter_calls SET status='completed',active_table_guard=NULL,completed_at=NOW() WHERE id=?",[$call]);
    $close=$core->tableChanges()->closeEmpty(['table_id'=>$table,'expected_session_id'=>$session,'request_id'=>'ci-u-close-0001'],$user);
    $a($close['closed']===true,'manual closure after resolved call');
}
$a((string)$one('SELECT status FROM table_sessions WHERE id=?',[$session])==='closed','closed');
$a($one('SELECT live_table_guard FROM table_sessions WHERE id=?',[$session])===null,'table released');
$a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$session])===0,'no phantom receipt');
foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']],'stock restored');
if($mode!=='unclaimed')$a((int)$one('SELECT COUNT(*) FROM order_preparation_claims WHERE order_id=?',[$oid])===1,'claim preserved');
echo json_encode(['status'=>'PASS','mode'=>$mode,'closed'=>true,'no_financial_receipt'=>true,'inventory_restored'=>true,'replay_safe'=>true,'active_call_preserved'=>$mode==='waiter'],JSON_THROW_ON_ERROR)."\n";
