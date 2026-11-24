// Appended to exact disposable MariaDB fixture; actual order, stock, preparation, receipt and readback.
$mode=getenv('SOKNA_BUNDLE_MODE');$baseline=$mode==='baseline';
$clock=$core->businessClock()->assignment();$day=$clock['business_date'];
$p->prepare('UPDATE table_sessions SET business_date=?,business_shift_key=?,business_shift_label=?,business_cutoff_snapshot=? WHERE id=?')->execute([$day,$clock['shift_key'],$clock['shift_label'],$clock['cutoff'],$session]);
$parent=null;$choice=null;
foreach($catalog as $c){if($c['is_bundle']||$c['id']===$item['id']||$c['price']<1)continue;if($parent===null){$parent=$c;continue;}if($c['id']!==$parent['id']&&in_array($c['preparation_station'],['hot_bar','cold_bar','kitchen'],true)){$choice=$c;break;}}
$a($parent!==null&&$choice!==null,'disposable catalog fixtures');
$bid=(int)$parent['id'];$cid=(int)$item['id'];$choiceId=(int)$choice['id'];
$p->prepare('INSERT INTO item_bundles(bundle_item_id,choice_label,choice_min,choice_max) VALUES(?,?,?,?)')->execute([$bid,'QA choice',1,1]);
$p->prepare("INSERT INTO item_bundle_components(bundle_item_id,component_item_id,mode,quantity,price_delta,sort_order) VALUES(?,?,'fixed',2,0,1),(?,?,'choice',1,0,2)")->execute([$bid,$cid,$bid,$choiceId]);
$o=$core->orders()->commit(['source'=>'staff','table_id'=>$table,'session_id'=>$session,'client_token'=>'ci-bundle-ratio-order-0001','actor_user_id'=>$admin,'items'=>[['id'=>$bid,'quantity'=>3,'bundle_choice_item_id'=>$choiceId]]]);
$line=(int)$o['lines'][0]['order_item_id'];$oid=(int)$o['order_id'];$core->inventoryOrders()->processPending();
$original=$rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$line]);$a(array_map('intval',array_column($original,'quantity'))===[6,3],'immutable original totals');
$print=new ReflectionMethod($core->printing(),'preparationRowsForOrder');
$counts=function($n,$expected)use($core,$print,$oid,$item,$choice,$a){
 $out=$print->invoke($core->printing(),$oid);$map=[];foreach($out as $r)$map[$r['item_name']]=(int)$r['quantity'];
 $a(($map[$item['name']]??0)===$expected*2&&($map[$choice['name']]??0)===$expected,'print quantities '.json_encode($map));
 $feed=$core->preparation()->publicProjection();$actual=[];foreach($feed['orders'] as $o)if((int)$o['id']===$oid)foreach($o['areas'] as $area)foreach($area['items'] as $r)$actual[explode(' — از ترکیب',$r['item_name'])[0]]=(int)$r['quantity'];
 $a(($actual[$item['name']]??0)===$expected*2&&($actual[$choice['name']]??0)===$expected,'preparation quantities '.json_encode($actual));
};
$counts(3,3);
$adjust=fn($n,$rid)=>$core->orderStaffActions()->adjustItemQuantity(['order_item_id'=>$line,'quantity'=>$n,'reason'=>'QA bundle quantity','request_id'=>$rid],$user);
$a($adjust(2,'ci-bundle-adjust-0000001')['success'],'reduce real order');$counts(2,$baseline?3:2);
$svc=$core->settlements();$pay=function($mode,$rid,$selection)use($svc,$session,$user,$a){$ac=$svc->account($session);$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>$mode,'destination'=>'direct','request_id'=>$rid,'selection'=>$selection,'print_receipt'=>false];$q=$svc->review($req,$user);$req['tenders']=['pos'=>$q['total']];return $svc->settle($req,$user);};
$r=$pay('itemized','ci-bundle-item-pay-0001',[['order_item_id'=>$line,'quantity'=>1]]);$rid=(int)$r['settlement_id'];
$raw=$one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$rid]);$snap=json_decode($raw,true,512,JSON_THROW_ON_ERROR);$components=$snap['items'][0]['bundle_components'];$a(array_column($components,'quantity')===($baseline?[6,3]:[2,1]),'paid-one component counts');
$a($adjust(4,'ci-bundle-adjust-0000002')['success'],'increase real order');$counts(4,$baseline?3:4);
$a($adjust(1,'ci-bundle-adjust-0000003')['success'],'reduce to paid floor');$counts(1,$baseline?3:1);
$a($one('SELECT invoice_snapshot_json FROM settlement_records WHERE id=?',[$rid])===$raw,'paid historical receipt immutable');$a($rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$line])===$original,'original bundle snapshot immutable');
$core->inventoryOrders()->processPending();
// Check tracked fixed-component inventory against original recipe. The choice may also use the same ingredient;
// derive its quantity from the immutable accounted recipe snapshot (never today's catalog).
$ev=$one("SELECT payload_json FROM inventory_order_events WHERE order_id=? AND event_type='accounted'",[$oid]);$ev=json_decode($ev,true,512,JSON_THROW_ON_ERROR);$per=[];
foreach($ev['recipe_snapshot'] as $rs){$ratio=(int)$rs['bundle_units_per_parent'];foreach($rs['components'] as $c)if(($c['opening_eligible_at_order']??false)===true)$per[(int)$c['inventory_item_id']]=($per[(int)$c['inventory_item_id']]??0)+$ratio*(int)$c['quantity_base'];}
foreach($initial as $id=>$n)$a($balance($id)===$n-($per[$id]??0),'stock exact for tracked ingredient '.$id);
echo json_encode(['status'=>'PASS','baseline_defect_reproduced'=>$baseline,'order_quantity_changes'=>true,'preparation_quantities'=>!$baseline,'print_quantities'=>!$baseline,'one_unit_receipt'=>!$baseline,'immutable_receipt'=>true,'immutable_recipe'=>true,'stock_exact'=>true],JSON_THROW_ON_ERROR)."\n";
