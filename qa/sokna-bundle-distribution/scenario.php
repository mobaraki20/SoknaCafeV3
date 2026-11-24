// Exact frozen source, disposable DB: assert intended distribution invariants.
// Independent orders isolate derived-row provenance and equal-price choice grouping.
$clock=$core->businessClock()->assignment();
$p->prepare('UPDATE table_sessions SET business_date=?,business_shift_key=?,business_shift_label=?,business_cutoff_snapshot=? WHERE id=?')->execute([$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff'],$session]);
$eligible=array_values(array_filter($catalog,fn($c)=>!$c['is_bundle']&&$c['id']!==$item['id']&&$c['price']>0&&$c['takeaway_allowed']));
$a(count($eligible)>=3,'three disposable staff fixtures required');
[$parent,$choiceA,$choiceB]=$eligible;$bid=(int)$parent['id'];$fixedId=(int)$item['id'];$ca=(int)$choiceA['id'];$cb=(int)$choiceB['id'];
$p->prepare('INSERT INTO item_bundles(bundle_item_id,choice_label,choice_min,choice_max) VALUES(?,?,?,?)')->execute([$bid,'QA distribution',1,1]);
$p->prepare("INSERT INTO item_bundle_components(bundle_item_id,component_item_id,mode,quantity,price_delta,sort_order) VALUES(?,?,'fixed',2,0,1),(?,?,'choice',1,0,2),(?,?,'choice',1,0,3)")->execute([$bid,$fixedId,$bid,$ca,$bid,$cb]);
$commit=fn($token,$lines)=>$core->orders()->commit(['source'=>'staff','table_id'=>$table,'session_id'=>$session,'client_token'=>$token,'actor_user_id'=>$admin,'items'=>$lines]);
$line=fn($choice,$quantity)=>['id'=>$bid,'quantity'=>$quantity,'bundle_choice_item_id'=>$choice,'fulfillment_mode'=>'dine_in'];
$componentRows=fn($id)=>$rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$id]);
$adjust=fn($id,$dine,$take,$rid)=>$core->orderStaffActions()->adjustItemQuantity(['order_item_id'=>$id,'quantity'=>$dine+$take,'fulfillment_quantities'=>['dine_in'=>$dine,'takeaway'=>$take],'reason'=>'QA distribution','request_id'=>$rid],$user);
$failures=[];$check=function($ok,$message)use(&$failures){echo ($ok?'PASS ':'FAIL ').$message."\n";if(!$ok)$failures[]=$message;};
$o=$commit('ci-bundle-distribution-order-0001',[$line($ca,3)]);$id=(int)$o['lines'][0]['order_item_id'];$oid=(int)$o['order_id'];$core->inventoryOrders()->processPending();$before=$componentRows($id);
$r=$adjust($id,2,1,'ci-bundle-distribution-adjust-0001');$a($r['success'],'distribution operation accepted');
$derived=$rows("SELECT id,quantity,ordered_quantity FROM order_items WHERE order_id=? AND fulfillment_mode='takeaway' AND quantity>0",[$oid]);
$a(count($derived)===1&&(int)$derived[0]['quantity']===1,'one takeaway row');
$derivedId=(int)$derived[0]['id'];$after=$componentRows($derivedId);
$check($componentRows($id)===$before,'original component snapshot immutable');
$check(count($after)===2,'derived row retains fixed and selected component identities');
$print=new ReflectionMethod($core->printing(),'preparationRowsForOrder');
$actual=$print->invoke($core->printing(),$oid);
$totals=[];foreach($actual as $v){$key=(string)$v['item_name'];$totals[$key]=($totals[$key]??0)+(int)$v['quantity'];}
$check(($totals[$item['name']]??0)===6&&($totals[$choiceA['name']]??0)===3,'preparation print preserves six fixed and three chosen units across modes');
echo 'DERIVED '.json_encode(['rows'=>$derived,'component_count'=>count($after),'print_totals'=>$totals],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
$o=$commit('ci-bundle-distribution-order-0002',[$line($ca,3),$line($cb,2)]);
$aid=(int)$o['lines'][0]['order_item_id'];$bid2=(int)$o['lines'][1]['order_item_id'];$oid2=(int)$o['order_id'];
$a((int)$one('SELECT unit_price FROM order_items WHERE id=?',[$aid])===(int)$one('SELECT unit_price FROM order_items WHERE id=?',[$bid2]),'equal-price fixture');
$bBefore=$componentRows($bid2);
$r=$adjust($aid,2,1,'ci-bundle-distribution-adjust-0002');$a($r['success'],'second distribution operation accepted');
$other=(int)$one('SELECT quantity FROM order_items WHERE id=?',[$bid2]);
$check($other===2&&$componentRows($bid2)===$bBefore,'unrelated equal-price choice retains its two units and snapshot');
$check((int)$one('SELECT SUM(quantity) FROM order_items WHERE order_id=?',[$oid2])===5,'total order units remain five after neutral distribution');
echo 'CHOICE '.json_encode(['other_quantity'=>$other,'total_quantity'=>(int)$one('SELECT SUM(quantity) FROM order_items WHERE order_id=?',[$oid2])],JSON_THROW_ON_ERROR)."\n";
$a(!$failures,'distribution invariant failures: '.implode('; ',$failures));

// Follow-up lifecycle gate, independent assertions even when a specific case fails.
$case=function($name,$fn)use(&$failures){try{$fn();echo 'PASS CASE '.$name."\n";}catch(Throwable $e){$failures[]=$name;echo 'FAIL CASE '.$name.' '.get_class($e).' '.$e->getMessage()."\n";}};
$core->inventoryOrders()->processPending();
$ev=json_decode($one("SELECT payload_json FROM inventory_order_events WHERE order_id=? AND event_type='accounted'",[$oid]),true,512,JSON_THROW_ON_ERROR);
$per=[];foreach($ev['recipe_snapshot'] as $rs){$ratio=(int)$rs['bundle_units_per_parent'];foreach($rs['components'] as $c)if(($c['opening_eligible_at_order']??false)===true)$per[(int)$c['inventory_item_id']]=($per[(int)$c['inventory_item_id']]??0)+$ratio*(int)$c['quantity_base'];}
$a(count($per)>0,'tracked ingredients exist');
$balances=fn()=>array_map($balance,array_combine(array_keys($per),array_keys($per)));
$case('group stock delta from derived anchor',function()use($adjust,$derivedId,$core,$balances,$per,$a){$old=$balances();$a($adjust($derivedId,2,2,'ci-distribution-stock-group-0001')['success'],'group increase');$core->inventoryOrders()->processPending();$new=$balances();foreach($per as $id=>$q)$a($new[$id]===$old[$id]-$q,'group stock mismatch '.$id);});
$case('single derived row stock delta',function()use($core,$derivedId,$balances,$per,$a,$user,$one){$old=$balances();$q=(int)$one('SELECT quantity FROM order_items WHERE id=?',[$derivedId]);$a($core->orderStaffActions()->adjustItemQuantity(['order_item_id'=>$derivedId,'quantity'=>$q+1,'reason'=>'QA derived single','request_id'=>'ci-distribution-stock-single-0001'],$user)['success'],'single derived increase');$core->inventoryOrders()->processPending();$new=$balances();foreach($per as $id=>$n)$a($new[$id]===$old[$id]-$n,'single stock mismatch '.$id.' actual '.($old[$id]-$new[$id]).' expected '.$n);});
$receiptId=null;$receiptRaw=null;
$case('one derived unit payment snapshot',function()use($core,$session,$derivedId,$user,$one,$a,&$receiptId,&$receiptRaw){$svc=$core->settlements();$ac=$svc->account($session);$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>'itemized','destination'=>'direct','request_id'=>'ci-distribution-payment-0001','selection'=>[['order_item_id'=>$derivedId,'quantity'=>1]],'print_receipt'=>false];$v=$svc->review($req,$user);$req['tenders']=['pos'=>$v['total']];$r=$svc->settle($req,$user);$receiptId=(int)$r['settlement_id'];$receiptRaw=$one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$receiptId]);$snap=json_decode($receiptRaw,true,512,JSON_THROW_ON_ERROR);$a(array_column($snap['items'][0]['bundle_components'],'quantity')===[2,1],'derived paid components');});
$case('group correction after itemized payment preserves receipt',function()use($adjust,$id,$derivedId,$one,$a,&$receiptId,&$receiptRaw){$a($receiptId!==null,'payment fixture required');$d=(int)$one('SELECT quantity FROM order_items WHERE id=?',[$id]);$t=(int)$one('SELECT quantity FROM order_items WHERE id=?',[$derivedId]);$a($d>0&&$t>=1,'valid paid floor');$a($adjust($id,$d-1,$t,'ci-distribution-after-pay-0001')['success'],'post payment group correction');$a($one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$receiptId])===$receiptRaw,'immutable receipt');});
$case('multi-area claim and adjustment acknowledgement',function()use($core,$p,$commit,$line,$ca,$fixedId,$user,$adjust,$a,$rows,$one){
 // Explicit fixture station assignment, not a product rule: one bar and one kitchen component.
 $p->prepare("UPDATE items SET preparation_station='hot_bar' WHERE id=?")->execute([$fixedId]);
 $p->prepare("UPDATE items SET preparation_station='kitchen' WHERE id=?")->execute([$ca]);
 $o=$commit('ci-distribution-multi-area-0001',[$line($ca,3)]);$order=(int)$o['order_id'];$id=(int)$o['lines'][0]['order_item_id'];
 $prepId=(int)$core->adminControls()->saveUser(['username'=>'qa-distribution-prep','display_name'=>'QA prep','password'=>bin2hex(random_bytes(16)),'active'=>true,'capabilities'=>['preparation'],'preparation_areas'=>['bar','kitchen']],$user)['id'];
 foreach(['bar','kitchen'] as $area)$a($core->preparation()->claim(['order_id'=>$order,'area'=>$area],['id'=>$prepId])['success'],'claim '.$area);
 $a($adjust($id,2,1,'ci-distribution-multi-adjust-0001')['success'],'neutral change after both claims');
 $pending=$rows("SELECT id,area_key,preparation_ack_status FROM order_item_adjustments WHERE order_id=? AND preparation_ack_status='pending' ORDER BY id",[$order]);
 echo 'MULTIAREA '.json_encode($pending,JSON_THROW_ON_ERROR)."\n";
 $a(count($pending)===2&&count(array_unique(array_column($pending,'area_key')))===2,'both areas must independently receive acknowledgement');
 foreach($pending as $r)$a($core->preparation()->acknowledgeAdjustment(['adjustment_id'=>(int)$r['id']],['id'=>$prepId])['success'],'ack '.$r['area_key']);
});
$a(!$failures,'extended lifecycle failures: '.implode('; ',$failures));
