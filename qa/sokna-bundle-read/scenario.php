// Disposable real guest submission -> pending edit -> staff acceptance and correction -> actual projections/report.
$baseline=getenv('SOKNA_BUNDLE_MODE')==='baseline';
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
$r=$guest->mutate($ctx+['action'=>'update','order_code'=>$code,'expected_signature'=>$o['edit_signature'],'items'=>$items(2)]);$a($r['success'],'real pending guest reduction');
$o=$read();$a($readCounts($o)===[4,2],'reduced pending guest counts');
$line=(int)$one('SELECT id FROM order_items WHERE order_id=?',[$oid]);$original=$rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$line]);
$a(array_map('intval',array_column($original,'quantity'))===[4,2],'pending immutable snapshot after replacement');
$a($core->orderStaffActions()->changeStatus(['order_id'=>$oid,'status'=>'accounted'],$user)['success'],'real staff approval');
$a($core->orderStaffActions()->adjustItemQuantity(['order_item_id'=>$line,'quantity'=>1,'reason'=>'QA guest readback','request_id'=>'ci-guest-bundle-adjust-0001'],$user)['success'],'staff reduction after approval');
$o=$read();$a($readCounts($o)===($baseline?[4,2]:[2,1]),'Local guest current counts '.json_encode($readCounts($o)));
$projection=function()use($core,$code){$r=$core->publicProjectionBuilder()->remoteModelsFor(['guest_operational']);foreach($r[0]['payload']['orders'] as $o)if($o['public']['order_code']===$code)return $o['public'];throw new RuntimeException('projected order missing');};
$a($readCounts($projection())===($baseline?[4,2]:[2,1]),'Public projection current counts');
$usage=function()use($core,$day){$m=new ReflectionMethod($core->reporting(),'bundleComponentUsageReport');return array_column($m->invoke($core->reporting(),$day,$day),null,'component_item_id');};
$u=$usage();$a($u[$cid]['component_quantity']===($baseline?4:2)&&$u[$choiceId]['component_quantity']===($baseline?2:1),'operational component report');
$a($rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$line])===$original,'original recipe immutable');
if(!$baseline){
 // Deliberately malformed historical fixture, read-only paths must disclose unknown rather than invent a count.
 $p->beginTransaction();try{
 $p->prepare('UPDATE order_items SET ordered_quantity=0 WHERE id=?')->execute([$line]);
 $o=$read();$a($readCounts($o)===[null,null]&&$o['items'][0]['bundle_components'][0]['quantity_quality']==='unknown','Local unknown provenance');
 $o=$projection();$a($readCounts($o)===[null,null]&&$o['items'][0]['bundle_components'][0]['quantity_quality']==='unknown','Public unknown provenance');
 $u=$usage();$a($u[$cid]['component_quantity']===null&&$u[$cid]['quantity_quality']==='unknown'&&$u[$cid]['unknown_snapshot_count']===1,'unknown usage no fabricated count');
 }finally{$p->rollBack();}
 $a($readCounts($read())===[2,1],'original basis restored');
}
echo json_encode(['status'=>'PASS','baseline_defect_reproduced'=>$baseline,'pending_guest_reduction'=>true,'current_local_guest'=>!$baseline,'current_public_projection'=>!$baseline,'current_usage'=>!$baseline,'unknown_provenance'=>!$baseline,'immutable_components'=>true],JSON_THROW_ON_ERROR)."\n";
