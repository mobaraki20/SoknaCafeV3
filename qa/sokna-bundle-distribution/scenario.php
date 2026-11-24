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
