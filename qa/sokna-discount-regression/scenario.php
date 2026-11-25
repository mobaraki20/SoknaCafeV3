
$clock=$core->businessClock()->assignment();$day=$clock['business_date'];$svc=$core->settlements();$actions=$core->orderStaffActions();
$balances=fn()=>array_map($balance,array_combine(array_keys($initial),array_keys($initial)));
$pay=function($s,$rid,$mode='full',$lines=[])use($svc,$user){$a=$svc->account($s);$r=['session_id'=>$s,'expected_session_id'=>$s,'expected_remaining_total'=>$a['remaining_total'],'expected_signature'=>$a['signature'],'mode'=>$mode,'destination'=>'direct','request_id'=>$rid,'selection'=>$lines,'print_receipt'=>false];$v=$svc->review($r,$user);$r['tenders']=$v['total']>0?['pos'=>$v['total']]:[];return $svc->settle($r,$user);};
$fail=[];
foreach(['tax-reversed','revised-grant','change-after-reversal'] as $n=>$key){
 try{
 if($key==='tax-reversed'){$tax=$core->tax();$tax->createRateVersion(['rate_bps'=>1000,'effective_from'=>''],$user);$tax->createItemPolicyVersion(['item_id'=>$item['id'],'policy'=>'inherit_default'],$user);$tax->setEnabled(true,$user);}else{$core->tax()->setEnabled(false,$user);}
 $before=$balances();$t=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA '.$key,64680+$n,'ci-fin-reg-'.$key.'-t','ci-fin-reg-'.$key.'-token']);
 $s=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?,?)",['ci-fin-reg-'.$key.'-session',$t,$t,$admin,$day,$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);
 $o=$core->orders()->commit(['source'=>'staff','table_id'=>$t,'session_id'=>$s,'client_token'=>'ci-fin-reg-'.$key.'-order-0001','actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>4]]]);$oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];$core->inventoryOrders()->processPending();
 $r=$pay($s,'ci-fin-reg-'.$key.'-pay1-0001','itemized',[['order_item_id'=>$line,'quantity'=>1]]);$id=(int)$r['settlement_id'];$price=(int)$item['price'];
 $snap=$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$id]);$svc->setDiscount($s,'fixed',3000,$user,$svc->account($s)['signature']);
 if($key==='revised-grant'){
 $preview=$svc->previewRemainingDiscountCorrection($s,'fixed',6000,$user);
 $v=$svc->adjustRemainingDiscount($s,'fixed',6000,$preview['expected_signature'],'QA authorized correction','ci-fin-reg-revise-0001',$user);
 $a($v['discount_amount']===6000,'manager revision committed');
 }
 $beforeRefund=$svc->account($s);
 $svc->reverse($id,'QA full refund','ci-fin-reg-'.$key.'-reverse-0001',$user);
 $ac=$svc->account($s);$a($ac['paid_total']===0,'paid zero');$a($ac['total']===$beforeRefund['total'],'total preserved');
 if($key==='tax-reversed'){
 $roundTax=fn($v)=>intdiv($v+5,10);
 $a($ac['discount']===3000&&$ac['tax']===$roundTax($price)+3*$roundTax($price-1000),'tax and discount preserved');
 }
 if($key==='change-after-reversal'){
 // Existing supported correction route requires unchanged paid cohort. No direct
 // change to original invoice discount while a remaining grant exists.
 try{$svc->setDiscount($s,'fixed',4000,$user,$ac['signature']);throw new RuntimeException('unsafe direct discount accepted');}catch(\Sokna\Local\Domain\Finance\SettlementStateConflict $e){$a($e->errorCode==='remaining_discount_exists','explicit original grant protection '.$e->errorCode);}
 try{$svc->previewRemainingDiscountCorrection($s,'fixed',4000,$user);throw new RuntimeException('changed payment cohort accepted');}catch(\Sokna\Local\Domain\Finance\SettlementStateConflict $e){$a($e->errorCode==='paid_after_discount_grant','explicit review '.$e->errorCode);}
 $ac=$svc->account($s);$a($ac['discount']===3000&&$ac['total']===$beforeRefund['total'],'immutable discount after rejected attempts');
 }
 if($key==='revised-grant')$a($ac['discount']===6000,'revised grant preserved');
 $r=$pay($s,'ci-fin-reg-'.$key.'-pay2-0001');$a($r['closes_session'],'repaid closes');$svc->reverse((int)$r['settlement_id'],'QA refund again','ci-fin-reg-'.$key.'-reverse2-0001',$user);
 $a($rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$id])===$snap,'original receipt immutable');
 $c=$actions->cancelSelection(['session_id'=>$s,'scope'=>'all','expected_order_ids'=>[$oid],'request_id'=>'ci-fin-reg-'.$key.'-cancel-0001','reason'=>'QA refund cancel'],$user,fn($oid,$sid,$rid,$why,$actor)=>$svc->refundOrderPaymentsTx($oid,$sid,$rid,$why,$actor),fn($sid)=>$svc->accountTx($sid));$a($c['success'],'cancel');
 $core->inventoryOrders()->processPending();$a($balances()===$before,'stock restored');$a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===4,'no extra receipt');
 echo 'PASS '.$key."\n";
 }catch(Throwable $e){$fail[]=$key;echo 'FAIL '.$key.' '.get_class($e).' '.$e->getMessage()."\n";}
}
$a(!$fail,'regression failed '.implode(',',$fail));
