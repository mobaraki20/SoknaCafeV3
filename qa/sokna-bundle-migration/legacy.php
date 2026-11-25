<?php
declare(strict_types=1);
$root=getenv('RUNNER_TEMP').'/local';require $root.'/bootstrap.php';$core=sokna_local_bootstrap(require dirname($root).'/config.php');$p=$core->database();$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$a=function($ok,$m){if(!$ok)throw new RuntimeException($m);};
$one=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return $s->fetchColumn();};
$rows=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);};
$run=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return (int)$p->lastInsertId();};
$path=getenv('RUNNER_TEMP').'/legacy-bundle.json';
$mode=$argv[1]??'';
if($mode==='seed'){
 $admin=$run("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)",['ci_legacy_bundle_admin',password_hash('disposable-test-only',PASSWORD_DEFAULT),'QA legacy']);
 $table=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA legacy table',64999,'ci-legacy-bundle-table','ci-legacy-bundle-token']);
 $clock=$core->businessClock()->assignment();
 $session=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?,?)",['ci-legacy-bundle-session',$table,$table,$admin,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);
 $catalog=array_values(array_filter($core->orderCatalog()->staffCatalogRows(),fn($c)=>!$c['is_bundle']&&$c['price']>0&&$c['takeaway_allowed']));
 $a(count($catalog)>=5,'catalog fixture');[$parent,$fixed,$choice,$normal]=$catalog;$bid=(int)$parent['id'];$cid=(int)$fixed['id'];$choiceId=(int)$choice['id'];
 $p->prepare('INSERT INTO item_bundles(bundle_item_id,choice_label,choice_min,choice_max) VALUES(?,?,?,?)')->execute([$bid,'QA historical',1,1]);
 $p->prepare("INSERT INTO item_bundle_components(bundle_item_id,component_item_id,mode,quantity,price_delta,sort_order) VALUES(?,?,'fixed',2,0,1),(?,?,'choice',1,0,2)")->execute([$bid,$cid,$bid,$choiceId]);
 $commit=fn($token,$items)=>$core->orders()->commit(['source'=>'staff','table_id'=>$table,'session_id'=>$session,'client_token'=>$token,'actor_user_id'=>$admin,'items'=>$items]);
 $o=$commit('ci-legacy-bundle-order-0001',[['id'=>$bid,'quantity'=>3,'bundle_choice_item_id'=>$choiceId]]);$order=(int)$o['order_id'];$rootId=(int)$o['lines'][0]['order_item_id'];
 // Reproduce actual historical 92 schema behavior, before any candidate patch.
 $r=$core->orderStaffActions()->adjustItemQuantity(['order_item_id'=>$rootId,'quantity'=>3,'fulfillment_quantities'=>['dine_in'=>2,'takeaway'=>1],'reason'=>'QA historical split','request_id'=>'ci-legacy-bundle-split-0001'],['id'=>$admin]);$a($r['success'],'legacy split');
 $orphan=(int)$one("SELECT id FROM order_items WHERE order_id=? AND ordered_quantity=0 AND quantity>0",[$order]);$a($orphan>0&&(int)$one('SELECT COUNT(*) FROM order_item_bundle_components WHERE order_item_id=?',[$orphan])===0,'ambiguous historical orphan reproduced');
 $o=$commit('ci-legacy-bundle-order-0002',[['id'=>(int)$normal['id'],'quantity'=>2]]);$normalLine=(int)$o['lines'][0]['order_item_id'];
 $svc=$core->settlements();$ac=$svc->account($session);$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>'itemized','destination'=>'direct','request_id'=>'ci-legacy-bundle-payment-0001','selection'=>[['order_item_id'=>$normalLine,'quantity'=>1]],'print_receipt'=>false];$v=$svc->review($req,['id'=>$admin]);$req['tenders']=['pos'=>$v['total']];$paid=$svc->settle($req,['id'=>$admin]);$receipt=(int)$paid['settlement_id'];
 $snapshot=['admin'=>$admin,'session'=>$session,'table'=>$table,'root'=>$rootId,'orphan'=>$orphan,'order'=>$order,'receipt'=>$receipt,'item_rows'=>$rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$order]),'component_rows'=>$rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$rootId]),'adjustment_rows'=>$rows('SELECT * FROM order_item_adjustments WHERE order_id=? ORDER BY id',[$order]),'receipt_rows'=>$rows('SELECT * FROM settlement_records WHERE id=?',[$receipt]),'receipt_lines'=>$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$receipt]),'ledger'=>$rows('SELECT * FROM schema_migrations ORDER BY version')];
 file_put_contents($path,json_encode($snapshot,JSON_THROW_ON_ERROR));echo "PASS populated baseline legacy fixture\n";exit;
}
$a($mode==='verify','fixture mode');
$old=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$strip=function($rows,$field){foreach($rows as &$r){if(($r[$field]??null)!==null)throw new RuntimeException('migration inferred unknown provenance');unset($r[$field]);}unset($r);return $rows;};
$a($strip($rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$old['order']]),'bundle_distribution_origin_id')===$old['item_rows'],'existing order rows unchanged');
$a($strip($rows('SELECT * FROM order_item_adjustments WHERE order_id=? ORDER BY id',[$old['order']]),'parent_adjustment_id')===$old['adjustment_rows'],'existing adjustments unchanged');
$a($rows('SELECT * FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id',[$old['root']])===$old['component_rows'],'existing components unchanged');
$a($rows('SELECT * FROM settlement_records WHERE id=?',[$old['receipt']])===$old['receipt_rows'],'existing receipt unchanged');
$a($rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$old['receipt']])===$old['receipt_lines'],'existing receipt allocations unchanged');
$ledger=$rows('SELECT * FROM schema_migrations ORDER BY version');$a(count($ledger)===count($old['ledger'])+1&&array_slice($ledger,0,-1)===$old['ledger'],'only one migration appended, history untouched');
foreach([$old['root'],$old['orphan']] as $id){
 foreach([true,false] as $group){
  $data=['order_item_id'=>$id,'quantity'=>2,'reason'=>'QA reject ambiguous','request_id'=>'ci-legacy-reject-'.$id.'-'.($group?'group':'single')];if($group)$data['fulfillment_quantities']=['dine_in'=>1,'takeaway'=>1];
  // Only the orphan must be rejected on the single-row path, the original is a valid historical row.
  if(!$group&&$id===$old['root'])continue;
  try{$core->orderStaffActions()->adjustItemQuantity($data,['id'=>$old['admin']]);throw new RuntimeException('ambiguous historical operation accepted');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='bundle_origin_unknown','unexpected guard '.$e->errorCode);}
 }
}
$a($strip($rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$old['order']]),'bundle_distribution_origin_id')===$old['item_rows'],'failed mutations atomic');
echo "PASS populated upgrade, immutable receipts and explicit unknown historical origin\n";
