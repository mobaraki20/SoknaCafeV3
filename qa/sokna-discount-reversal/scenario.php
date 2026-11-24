
$clock=$core->businessClock()->assignment();$day=$clock['business_date'];$svc=$core->settlements();$actions=$core->orderStaffActions();
$balances=fn()=>array_map($balance,array_combine(array_keys($initial),array_keys($initial)));
$pay=function($s,$rid,$mode='full',$lines=[])use($svc,$user){$a=$svc->account($s);$r=['session_id'=>$s,'expected_session_id'=>$s,'expected_remaining_total'=>$a['remaining_total'],'expected_signature'=>$a['signature'],'mode'=>$mode,'destination'=>'direct','request_id'=>$rid,'selection'=>$lines,'print_receipt'=>false];$v=$svc->review($r,$user);$r['tenders']=$v['total']>0?['pos'=>$v['total']]:[];return $svc->settle($r,$user);};
$fail=[];
foreach([
 ['initial-percent','percent',10,null,0,false],
 ['initial-fixed','fixed',1000,null,0,false],
 ['grant-percent',null,0,'percent',10,false],
 ['grant-fixed',null,0,'fixed',1000,false],
 ['mixed-percent-fixed','percent',10,'fixed',1000,true],
 ['mixed-fixed-percent','fixed',1000,'percent',10,true],
 ['grant-zero',null,0,'percent',100,true],
] as $n=>$case){
 [$key,$type,$value,$gt,$gv,$close]=$case;
 try{
 $before=$balances();$t=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA '.$key,64800+$n,'ci-disc-'.$key.'-t','ci-disc-'.$key.'-token']);
 $s=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?,?)",['ci-disc-'.$key.'-session',$t,$t,$admin,$day,$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);
 $o=$core->orders()->commit(['source'=>'staff','table_id'=>$t,'session_id'=>$s,'client_token'=>'ci-disc-'.$key.'-order-0001','actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>3]]]);
 $oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];$core->inventoryOrders()->processPending();
 if($type!==null)$svc->setDiscount($s,$type,$value,$user,$svc->account($s)['signature']);
 $ids=[];
 if($gt!==null){$r=$pay($s,'ci-disc-'.$key.'-pay1-0001','itemized',[['order_item_id'=>$line,'quantity'=>1]]);$ids[]=(int)$r['settlement_id'];$svc->setDiscount($s,$gt,$gv,$user,$svc->account($s)['signature']);}
 if($gt===null||$close){$r=$pay($s,'ci-disc-'.$key.'-pay2-0001');$ids[]=(int)$r['settlement_id'];}
 $snap=[];foreach($ids as $i)$snap[$i]=[$one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$i]),$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$i]),$rows('SELECT * FROM settlement_tender_allocations WHERE settlement_id=? ORDER BY id',[$i])];
 foreach(array_reverse($ids) as $i)$svc->reverse($i,'QA full refund','ci-disc-'.$key.'-rev-'.$i,$user);
 echo 'CHECK '.$key.' reversed all receipts '.count($ids)."\n";
 $a($svc->account($s)['paid_total']===0,'paid total zero');
 $r=$actions->cancelSelection(['session_id'=>$s,'scope'=>'all','expected_order_ids'=>[$oid],'request_id'=>'ci-disc-'.$key.'-cancel-0001','reason'=>'QA full reversal cancel'],$user,fn($oid,$sid,$rid,$why,$actor)=>$svc->refundOrderPaymentsTx($oid,$sid,$rid,$why,$actor),fn($sid)=>$svc->accountTx($sid));
 $a($r['success'],'cancel success');$core->inventoryOrders()->processPending();
 $a($balances()===$before,'inventory restored');$a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='closed','table closed');
 $a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===2*count($ids),'no duplicate financial documents');
 foreach($snap as $i=>$old)$a($old===[$one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$i]),$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$i]),$rows('SELECT * FROM settlement_tender_allocations WHERE settlement_id=? ORDER BY id',[$i])],'immutable original '.$i);
 echo 'PASS '.$key."\n";
 }catch(Throwable $e){$fail[]=$key;echo 'FAIL '.$key.' '.get_class($e).' '.$e->getMessage()."\n";}
}

foreach(['multiple-original','repay','corrupt-origin'] as $n=>$key){
 try{
 $before=$balances();$t=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA '.$key,64750+$n,'ci-disc-'.$key.'-t','ci-disc-'.$key.'-token']);
 $s=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?,?)",['ci-disc-'.$key.'-session',$t,$t,$admin,$day,$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);
 $o=$core->orders()->commit(['source'=>'staff','table_id'=>$t,'session_id'=>$s,'client_token'=>'ci-disc-'.$key.'-order-0001','actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>4]]]);$oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];$core->inventoryOrders()->processPending();
 $svc->setDiscount($s,'fixed',1000,$user,$svc->account($s)['signature']);
 $p1=$pay($s,'ci-disc-'.$key.'-pay1-0001','itemized',[['order_item_id'=>$line,'quantity'=>1]]);$ids=[(int)$p1['settlement_id']];
 if($key==='multiple-original'){$p2=$pay($s,'ci-disc-'.$key.'-pay2-0001','itemized',[['order_item_id'=>$line,'quantity'=>1]]);$ids[]=(int)$p2['settlement_id'];}
 $svc->setDiscount($s,'percent',10,$user,$svc->account($s)['signature']);$start=$svc->account($s);$grant=$rows('SELECT * FROM remaining_account_discount_grants WHERE session_id=?',[$s]);$gLines=$rows('SELECT * FROM remaining_account_discount_lines WHERE grant_id=? ORDER BY order_item_id',[(int)$grant[0]['id']]);
 foreach(array_reverse($ids) as $i){$v=$svc->reverse($i,'QA full refund','ci-disc-'.$key.'-rev-'.$i,$user);$now=$svc->account($s);$a($now['total']===$start['total']&&$now['discount']===$start['discount'],'aggregate invoice unchanged after each reversal');}
 $a($svc->account($s)['paid_total']===0,'no remaining payment');
 if($key==='corrupt-origin'){
   $rid=(int)$one('SELECT id FROM settlement_records WHERE reverses_settlement_id=?',[$ids[0]]);
   $p->prepare('UPDATE settlement_tender_allocations SET amount=amount-1 WHERE settlement_id=?')->execute([$rid]);
   try{$svc->account($s);throw new RuntimeException('corrupted receipt pair accepted');}catch(\Sokna\Local\Domain\Finance\SettlementStateConflict $e){$a($e->errorCode==='reversed_history_mismatch','strict mismatch '.$e->errorCode);}
   $a($one('SELECT status FROM orders WHERE id=?',[$oid])==='accounted','unchanged order');echo "PASS corrupt-origin rejected\n";continue;
 }
 if($key==='repay'){
   $v=$pay($s,'ci-disc-repay-new-item-0001','itemized',[['order_item_id'=>$line,'quantity'=>1]]);$ids[]=(int)$v['settlement_id'];$now=$svc->account($s);$a($now['total']===$start['total'],'repayment amount preserved');
   $v=$pay($s,'ci-disc-repay-new-full-0001');$ids[]=(int)$v['settlement_id'];$a($v['closes_session'],'repaid closes');
   foreach(array_reverse(array_slice($ids,1)) as $i)$svc->reverse($i,'QA repay reversal','ci-disc-repay-again-'.$i,$user);
   $a($svc->account($s)['remaining_total']===$start['total'],'reopened amount preserved after second refund');
 }
 $r=$actions->cancelSelection(['session_id'=>$s,'scope'=>'all','expected_order_ids'=>[$oid],'request_id'=>'ci-disc-'.$key.'-cancel-0001','reason'=>'QA full reversal cancel'],$user,fn($oid,$sid,$rid,$why,$actor)=>$svc->refundOrderPaymentsTx($oid,$sid,$rid,$why,$actor),fn($sid)=>$svc->accountTx($sid));
 $a($r['success'],'cancel');$core->inventoryOrders()->processPending();$a($balances()===$before,'stock restored');
 $a($one('SELECT status FROM table_sessions WHERE id=?',[$s])==='closed','closed');$a((int)$one('SELECT COUNT(*) FROM settlement_records WHERE session_id=?',[$s])===2*count($ids),'no duplicate receipt');
 $a($rows('SELECT * FROM remaining_account_discount_grants WHERE session_id=?',[$s])===$grant&&$rows('SELECT * FROM remaining_account_discount_lines WHERE grant_id=? ORDER BY order_item_id',[(int)$grant[0]['id']])===$gLines,'original grant immutable');
 echo 'PASS '.$key."\n";
 }catch(Throwable $e){$fail[]=$key;echo 'FAIL '.$key.' '.get_class($e).' '.$e->getMessage()."\n";}
}
$a(!$fail,'failed discount reversal cases '.implode(',',$fail));
