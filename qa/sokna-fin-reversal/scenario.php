
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

$newSession=function($key,$n,$status='active')use($run,$admin,$day){
 $t=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA '.$key,$n,'ci-fin-'.$key.'-table','ci-fin-'.$key.'-token']);
 $s=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,?,?,?,?,?)",['ci-fin-'.$key.'-session',$t,$status,$t,$admin,$day,'day','روز','00:00']);return [$t,$s];
};
$put=function($t,$s,$token,$q=3,$source='staff')use($core,$item,$admin){return $core->orders()->commit(['source'=>$source,'table_id'=>$t,'session_id'=>$s,'client_token'=>$token,'actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>$q]]]);};
$case('legacy unknown tender remains unknown after exact full reversal',function()use($newSession,$put,$svc,$user,$a,$one,$rows,$cancel,$core,$balances){
 [$t,$s]=$newSession('legacy',64997);$before=$balances();$o=$put($t,$s,'ci-fin-legacy-order-0001');$oid=(int)$o['order_id'];$core->inventoryOrders()->processPending();
 $ac=$svc->account($s);$paid=$svc->settle(['session_id'=>$s,'expected_session_id'=>$s,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>'full','destination'=>'direct','request_id'=>'ci-fin-legacy-pay-0001','print_receipt'=>false],$user,true);
 $a($paid['tender_capture_status']==='legacy_missing'&&$paid['tenders']===[],'fixture legacy no tender');
 $rev=$svc->reverse((int)$paid['settlement_id'],'QA legacy exact reversal','ci-fin-legacy-reverse-0001',$user);$a($rev['tender_capture_status']==='legacy_missing'&&$rev['tenders']===[],'legacy not invented');
 $a($cancel($s,[$oid],'ci-fin-legacy-cancel-0001')['success'],'cancel');$core->inventoryOrders()->processPending();$a($balances()===$before,'stock restored');
 $a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='closed','closed');$a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===2,'two immutable receipts only');
 $a((int)$one('SELECT COUNT(*) FROM settlement_tender_allocations t JOIN settlement_records r ON r.id=t.settlement_id WHERE r.session_id=?',[$s])===0,'no fabricated tender');
});
$case('mismatched reversal fails closed atomically',function()use($newSession,$put,$svc,$user,$a,$one,$p,$cancel,$core,$balances,$pay){
 [$t,$s]=$newSession('mismatch',64996);$o=$put($t,$s,'ci-fin-mismatch-order-0001');$oid=(int)$o['order_id'];$core->inventoryOrders()->processPending();
 $paid=$pay($s,'ci-fin-mismatch-pay-0001');$rev=$svc->reverse((int)$paid['settlement_id'],'QA mismatch reversal','ci-fin-mismatch-reverse-0001',$user);
 // Disposable corruption fixture: existing full refund method amount altered, original immutable.
 $p->prepare('UPDATE settlement_tender_allocations SET amount=amount-1 WHERE settlement_id=?')->execute([(int)$rev['settlement_id']]);$before=$balances();
 try{$cancel($s,[$oid],'ci-fin-mismatch-cancel-0001');throw new RuntimeException('mismatched tender allowed');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='reversed_history_mismatch','unexpected conflict '.$e->errorCode);}
 $core->inventoryOrders()->processPending();$a($balances()===$before,'stock rollback');$a($one('SELECT status FROM orders WHERE id=?',[$oid])==='accounted','order rollback');$a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='active','session remains open');$a((int)$one('SELECT COUNT(*) FROM whole_order_cancellations WHERE order_id=?',[$oid])===0,'no cancellation ledger');
});
$case('ordinary active payment still uses existing credit and zero closure',function()use($newSession,$put,$svc,$a,$one,$core,$balances,$pay,$cancel){
 [$t,$s]=$newSession('activepay',64995);$before=$balances();$o=$put($t,$s,'ci-fin-activepay-order-0001');$oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];$core->inventoryOrders()->processPending();$pay($s,'ci-fin-activepay-pay-0001','itemized',[['order_item_id'=>$line,'quantity'=>1]]);
 $a($cancel($s,[$oid],'ci-fin-activepay-cancel-0001')['success'],'cancel active paid');$core->inventoryOrders()->processPending();$a($balances()===$before,'stock restored');$a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='closed','closed');
 $a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND partial_reversal_source_id IS NOT NULL",[$s])===1,'one exact credit');
 $a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===3,'original credit and zero closure');
});
$case('mixed accounted and pending guest cancellation',function()use($newSession,$put,$a,$one,$core,$balances,$cancel){
 [$t,$s]=$newSession('mixedpending',64994);$before=$balances();$a1=$put($t,$s,'ci-fin-mixed-accounted-0001');$g=$put($t,$s,'ci-fin-mixed-guest-0001',1,'guest');$oid=(int)$a1['order_id'];$gid=(int)$g['order_id'];$core->inventoryOrders()->processPending();$a(in_array($one('SELECT status FROM orders WHERE id=?',[$gid]),['new','pending_approval'],true),'pending fixture');
 $a($cancel($s,[$oid,$gid],'ci-fin-mixed-cancel-0001')['success'],'cancel mixed');$core->inventoryOrders()->processPending();$a($balances()===$before,'only actual booked stock returned');$a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='closed','closed');$a((int)$one("SELECT COUNT(*) FROM orders WHERE session_id=? AND status='cancelled'",[$s])===2,'both cancelled');$a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===0,'no receipts');
});
$case('active draft preserves table occupancy during unpaid cancellation',function()use($newSession,$put,$core,$a,$one,$cancel,$item,$user,$balances){
 [$t,$s]=$newSession('draft',64993);$before=$balances();$o=$put($t,$s,'ci-fin-draft-order-0001');$oid=(int)$o['order_id'];$core->inventoryOrders()->processPending();
 $draft=$core->tableDrafts()->save(['table_id'=>$t,'expected_session_id'=>$s,'expected_version'=>0,'items'=>[['id'=>$item['id'],'quantity'=>1]]],$user);$a($draft['success'],'real draft');
 $a($cancel($s,[$oid],'ci-fin-draft-cancel-0001')['success'],'cancel order');$core->inventoryOrders()->processPending();$a($balances()===$before,'stock restored');$a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='active','draft retains session');$a((int)$one("SELECT COUNT(*) FROM table_drafts WHERE table_id=? AND state='active'",[$t])===1,'draft unchanged');
});

$case('pending-only order releases pending table without inventory or receipt',function()use($newSession,$put,$a,$one,$core,$balances,$cancel){
 [$t,$s]=$newSession('pendingonly',64992,'pending');$before=$balances();$g=$put($t,$s,'ci-fin-pendingonly-0001',1,'guest');$id=(int)$g['order_id'];
 $a($cancel($s,[$id],'ci-fin-pendingonly-cancel-0001')['success'],'cancel pending');$core->inventoryOrders()->processPending();
 $a($balances()===$before,'no phantom stock');$a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='closed','pending table closed');$a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===0,'no receipt');
});
$case('detached followup retains financial account and forbids Hall cancellation',function()use($newSession,$put,$svc,$a,$one,$core,$balances,$user,$cancel){
 [$t,$s]=$newSession('followup',64991);$o=$put($t,$s,'ci-fin-followup-order-0001');$oid=(int)$o['order_id'];$core->inventoryOrders()->processPending();$before=$balances();
 $a($svc->markRecoveryRequired(['session_id'=>$s,'destination'=>'direct','code'=>'network_failed','status'=>503],$user)['eligible'],'recovery marker');
 $ac=$svc->account($s);$r=$svc->detachForFollowup(['session_id'=>$s,'expected_session_id'=>$s,'expected_signature'=>$ac['signature'],'expected_remaining_total'=>$ac['remaining_total'],'reason'=>'QA followup'],$user);$a($r['detached'],'detached');
 try{$cancel($s,[$oid],'ci-fin-followup-cancel-0001');throw new RuntimeException('detached Hall cancel accepted');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='session_changed','unexpected conflict '.$e->errorCode);}
 $a($svc->account($s)['remaining_total']===$ac['remaining_total'],'financial amount unchanged');$a($one('SELECT live_table_guard FROM table_sessions WHERE id=?',[$s])===null,'table remains released');$a($balances()===$before,'no stock change');
});
$case('late served line remains outside ordinary cancellation',function()use($newSession,$put,$core,$p,$item,$admin,$a,$one,$balances,$user,$svc,$actions){
 [$t,$s]=$newSession('late',64990);$o=$put($t,$s,'ci-fin-late-normal-0001');$oid=(int)$o['order_id'];
 $p->beginTransaction();try{$late=$core->orders()->commitLateServedTx(['source'=>'staff','table_id'=>$t,'session_id'=>$s,'client_token'=>'ci-fin-late-served-0001','actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>1]]]);$p->commit();}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
 $id=(int)$late['order_id'];$core->inventoryOrders()->processPending();$before=$balances();
 $a($one('SELECT accounting_origin FROM orders WHERE id=?',[$id])==='late_served','late fixture');
 try{$actions->cancelSelection(['session_id'=>$s,'scope'=>'round','order_id'=>$id,'request_id'=>'ci-fin-late-cancel-0001','reason'=>'QA late guard'],$user,fn($oid,$sid,$rid,$why,$actor)=>$svc->refundOrderPaymentsTx($oid,$sid,$rid,$why,$actor),fn($sid)=>$svc->accountTx($sid));throw new RuntimeException('late served cancellation accepted');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='order_changed','unexpected conflict '.$e->errorCode);}
 $a($one('SELECT status FROM orders WHERE id=?',[$id])==='accounted','late still accounted');$a($balances()===$before,'no stock change');
});
$a(!$fail,'finance regression failures: '.implode('; ',$fail));
