// Disposable real guest submission -> pending edit -> staff acceptance and correction -> actual projections/report.

$clock=$core->businessClock()->assignment();$day=$clock['business_date'];
$p->prepare('UPDATE table_sessions SET business_date=?,business_shift_key=?,business_shift_label=?,business_cutoff_snapshot=? WHERE id=?')->execute([$day,$clock['shift_key'],$clock['shift_label'],$clock['cutoff'],$session]);
$eligible=[];
foreach($catalog as $c){
 if($c['is_bundle']||$c['price']<1)continue;
 $p->beginTransaction();try{$core->orderCatalog()->snapshotRowsTx($core->orderCatalog()->normalizeRows([['id'=>$c['id'],'quantity'=>1,'expected_price'=>$c['price']]],false),'guest');$eligible[]=$c;}catch(\Sokna\Local\Domain\Orders\OrderCommitException $e){}finally{$p->rollBack();}
 if(count($eligible)===3)break;
}
$a(count($eligible)===3,'three orderable guest catalog fixtures');[$parent,$fixed,$choice]=$eligible;
$bid=(int)$parent['id'];$cid=(int)$fixed['id'];$choiceId=(int)$choice['id'];
$p->prepare('INSERT INTO item_bundles(bundle_item_id,choice_label,choice_min,choice_max) VALUES(?,?,?,?)')->execute([$bid,'QA choice',1,1]);
$p->prepare("INSERT INTO item_bundle_components(bundle_item_id,component_item_id,mode,quantity,price_delta,sort_order) VALUES(?,?,'fixed',2,0,1),(?,?,'choice',1,0,2)")->execute([$bid,$cid,$bid,$choiceId]);
$guest=$core->guestOrders();$ctx=['table_token'=>'ci-finance-token','session_token'=>'ci-finance-session','device_token'=>'ci-guest-bundle-read-0001','client_token'=>'ci-guest-bundle-order-0001'];
$items=fn($q)=>[['id'=>$bid,'quantity'=>$q,'expected_price'=>(int)$parent['price'],'bundle_choice_item_id'=>$choiceId]];
$r=$guest->submit($ctx+['items'=>$items(3)]);$a($r['success']&&$r['status']==='pending_approval','real guest submit');
$code=$r['order_code'];$oid=(int)$one('SELECT id FROM orders WHERE public_code=?',[$code]);
$read=function()use($guest,$ctx,$code,$a){foreach($guest->list($ctx)['orders'] as $o)if($o['order_code']===$code)return $o;throw new RuntimeException('guest order missing');};
$readCounts=fn($o)=>array_column($o['items'][0]['bundle_components'],'quantity');
$o=$read();$a($readCounts($o)===[6,3],'initial guest counts');
$r=$guest->mutate($ctx+['action'=>'update','order_code'=>$code,'expected_signature'=>$o['edit_signature'],'items'=>$items(3)]);$a($r['success'],'real pending guest update');
$o=$read();$a($readCounts($o)===[6,3],'pending guest counts');
$line=(int)$one('SELECT id FROM order_items WHERE order_id=?',[$oid]);$original=$rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$line]);
$a(array_map('intval',array_column($original,'quantity'))===[6,3],'pending immutable snapshot after replacement');
$a($core->orderStaffActions()->changeStatus(['order_id'=>$oid,'status'=>'accounted'],$user)['success'],'real staff approval');

$projection=function()use($core,$code){$r=$core->publicProjectionBuilder()->remoteModelsFor(['guest_operational']);foreach($r[0]['payload']['orders'] as $o)if($o['public']['order_code']===$code)return $o['public'];throw new RuntimeException('projected order missing');};
$usage=function()use($core,$day){$m=new ReflectionMethod($core->reporting(),'bundleComponentUsageReport');return array_column($m->invoke($core->reporting(),$day,$day),null,'component_item_id');};
$assertRead=function($d,$t)use($read,$projection,$a,$choiceId){foreach([$read(),$projection()] as $o){$by=[];foreach($o['items'] as $l)if((int)$l['quantity']>0){$mode=$l['fulfillment_mode'];$a((int)$l['bundle_choice_item_id']===$choiceId,'choice identity');$a(array_column($l['bundle_components'],'quantity_quality')===['exact_snapshot','exact_snapshot'],'exact source quality');$by[$mode]=[(int)$l['quantity'],array_column($l['bundle_components'],'quantity')];}$a(($by['dine_in']??null)===($d>0?[$d,[2*$d,$d]]:null),'dine-in read counts');$a(($by['takeaway']??null)===($t>0?[$t,[2*$t,$t]]:null),'takeaway read counts');}};
$assertReport=function($qty)use($usage,$a,$cid,$choiceId){$u=$usage();$a($u[$cid]['component_quantity']===2*$qty&&$u[$choiceId]['component_quantity']===$qty,'report quantity equals actual split');$a($u[$cid]['quantity_quality']==='exact_snapshot'&&$u[$choiceId]['quantity_quality']==='exact_snapshot','report quality');};
$adjust=fn($d,$t,$rid)=>$core->orderStaffActions()->adjustItemQuantity(['order_item_id'=>$line,'quantity'=>$d+$t,'fulfillment_quantities'=>['dine_in'=>$d,'takeaway'=>$t],'reason'=>'QA guest distribution','request_id'=>$rid],$user);
$assertRead(3,0);$assertReport(3);
$a($adjust(2,1,'ci-guest-split-adjust-0001')['success'],'guest split');$assertRead(2,1);$assertReport(3);echo "PASS current guest split Local, Public projection, report\n";
$a($adjust(1,1,'ci-guest-split-adjust-0002')['success'],'guest reduction');$assertRead(1,1);$assertReport(2);echo "PASS split reduction parity\n";
$a($adjust(0,3,'ci-guest-split-adjust-0003')['success'],'guest all takeaway increase');$assertRead(0,3);$assertReport(3);echo "PASS zero original current quantity and three derived units\n";
$a($rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$line])===$original,'original snapshot immutable');
$a($adjust(3,0,'ci-guest-split-adjust-0004')['success'],'return original mode');$assertRead(3,0);$assertReport(3);echo "PASS remerge and original snapshot preserved\n";
