
$clock=$core->businessClock()->assignment();$day=$clock['business_date'];
$p->prepare('UPDATE table_sessions SET business_date=?,business_shift_key=?,business_shift_label=?,business_cutoff_snapshot=? WHERE id=?')->execute([$day,$clock['shift_key'],$clock['shift_label'],$clock['cutoff'],$session]);
$svc=$core->settlements();$actions=$core->orderStaffActions();
$balances=fn()=>array_map($balance,array_combine(array_keys($initial),array_keys($initial)));
$cancel=fn($sid,$ids,$rid)=>$actions->cancelSelection(['session_id'=>$sid,'scope'=>'all','expected_order_ids'=>$ids,'request_id'=>$rid,'reason'=>'QA full reversal cancel'],$user,fn($oid,$s,$r,$why,$actor)=>$svc->refundOrderPaymentsTx($oid,$s,$r,$why,$actor),fn($s)=>$svc->accountTx($s));
$pay=function($sid,$rid,$mode='full',$lines=[])use($svc,$user){$a=$svc->account($sid);$r=['session_id'=>$sid,'expected_session_id'=>$sid,'expected_remaining_total'=>$a['remaining_total'],'expected_signature'=>$a['signature'],'mode'=>$mode,'destination'=>'direct','request_id'=>$rid,'selection'=>$lines,'print_receipt'=>false];$v=$svc->review($r,$user);$r['tenders']=['pos'=>$v['total']];return $svc->settle($r,$user);};
$fail=[];$case=function($n,$f)use(&$fail){try{$f();echo 'PASS '.$n."\n";}catch(Throwable $e){$fail[]=$n;echo 'FAIL '.$n.' '.get_class($e).' '.$e->getMessage()."\n";}};
$case('full payment then full reversal then cancel all',function()use($order,$core,$svc,$actions,$session,$user,$a,$one,$rows,$pay,$cancel,$balances,$initial){
 $o=$order('ci-fin-full-reversal-0001',3);$oid=(int)$o['order_id'];$core->inventoryOrders()->processPending();
 $a($balances()!==$initial,'consumed stock');$paid=$pay($session,'ci-fin-full-payment-0001');$id=(int)$paid['settlement_id'];$a($paid['closes_session'],'paid closed');
 $immutable=$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$id]);$snap=$one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$id]);
 $r=$svc->reverse($id,'QA full refund','ci-fin-full-reverse-0001',$user);$rev=(int)$r['settlement_id'];$a($rev>0&&$r['total_amount']===$paid['total_amount'],'full refund');
 $a($svc->account($session)['paid_total']===0,'no surviving payment');
 $result=$cancel($session,[$oid],'ci-fin-full-cancel-0001');$a($result['success'],'cancel after reversal');
 $core->inventoryOrders()->processPending();$a($balances()===$initial,'all inventory restored');
 $a($one('SELECT status FROM orders WHERE id=?',[$oid])==='cancelled','cancelled order');
 $a($one('SELECT status FROM table_sessions WHERE id=?',[$session])==='closed','empty account closed');
 $a($one('SELECT live_table_guard FROM table_sessions WHERE id=?',[$session])===null,'table released');
 $a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$session])===2,'no unnecessary new receipt');
 $a($rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$id])===$immutable&&$one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$id])===$snap,'original receipt immutable');
 $a($cancel($session,[$oid],'ci-fin-full-cancel-0001')['idempotent'],'retry idempotent');
});
$case('itemized payment full reversal then cancel all',function()use($core,$run,$table,$admin,$day,$item,$svc,$user,$a,$one,$pay,$cancel,$balances){
 $t=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA partial',64998,'ci-reverse-partial-t','ci-reverse-partial-token']);
 $s=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?,?)",['ci-reverse-partial-session',$t,$t,$admin,$day,'day','روز','00:00']);
 $before=$balances();$o=$core->orders()->commit(['source'=>'staff','table_id'=>$t,'session_id'=>$s,'client_token'=>'ci-reverse-partial-0001','actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>3]]]);$oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];$core->inventoryOrders()->processPending();
 $r=$pay($s,'ci-reverse-partial-pay-0001','itemized',[['order_item_id'=>$line,'quantity'=>1]]);$a(!$r['closes_session'],'itemized open');$svc->reverse((int)$r['settlement_id'],'QA itemized refund','ci-reverse-partial-refund-0001',$user);
 $a($cancel($s,[$oid],'ci-reverse-partial-cancel-0001')['success'],'cancel after itemized reversal');$core->inventoryOrders()->processPending();$a($balances()===$before,'stock restored');$a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='closed','closed');$a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===2,'no extra receipt');
});
$a(!$fail,'finance regression failures: '.implode('; ',$fail));
