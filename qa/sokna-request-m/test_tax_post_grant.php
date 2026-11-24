<?php
declare(strict_types=1);
$root=getenv('RUNNER_TEMP').'/local';require $root.'/bootstrap.php';$core=sokna_local_bootstrap(require dirname($root).'/config.php');$p=$core->database();
$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$a=function($ok,$m){if(!$ok)throw new RuntimeException($m);};
$one=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return $s->fetchColumn();};
$rows=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);};
$run=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return (int)$p->lastInsertId();};
$admin=$run("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)",['ci_fin_admin',password_hash('test-only-disposable',PASSWORD_DEFAULT),'QA admin']);
$operator=$run("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'operator',1)",['ci_fin_operator',password_hash('test-only-disposable',PASSWORD_DEFAULT),'QA operator']);
$user=['id'=>$admin];$day=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->modify('-1 day')->format('Y-m-d');
$table=$run("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)",['QA table',65000,'ci-finance-table','ci-finance-token']);
$session=$run("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,opened_by_user_id,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?,?)",['ci-finance-session',$table,$table,$admin,$day,'day','روز','00:00']);
$catalog=$core->orderCatalog()->staffCatalogRows();$item=null;
foreach($catalog as $v)if(!$v['is_bundle']&&$v['price']>10000&&$one("SELECT COUNT(*) FROM inventory_recipe_versions WHERE menu_item_id=? AND status='active'",[$v['id']])>0){$item=$v;break;}
$a(is_array($item),'no approved catalog recipe');
$code=(string)$one('SELECT item_code FROM items WHERE id=?',[$item['id']]);
$ingredients=$rows("SELECT c.inventory_item_id,c.quantity_base,i.base_unit FROM inventory_recipe_components c JOIN inventory_recipe_versions v ON v.id=c.recipe_version_id JOIN inventory_items i ON i.id=c.inventory_item_id WHERE v.menu_item_id=? AND v.status='active' AND c.quantity_base>0",[$item['id']]);
$a(count($ingredients)>0,'no recipe ingredients');
$opening=$core->inventoryCounts()->start(['session_type'=>'opening','title'=>'QA count'],$user);
foreach($ingredients as $i){$l=$rows("SELECT id,updated_at FROM inventory_count_lines WHERE session_id=? AND inventory_item_id=?",[$opening['session_id'],$i['inventory_item_id']])[0];$core->inventoryCounts()->updateLine(['session_id'=>$opening['session_id'],'line_id'=>$l['id'],'expected_version'=>$l['updated_at'],'actual_major'=>'100','opening_total_cost'=>'1000000'],$user);}
$core->inventoryCounts()->finalize($opening['session_id'],$user);
$balance=function($id)use($one){return (int)$one("SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=?",[$id]);};
$initial=[];foreach($ingredients as $i)$initial[$i['inventory_item_id']]=$balance($i['inventory_item_id']);
$order=function($token,$q)use($core,$item,$table,$session,$admin){return $core->orders()->commit(['source'=>'staff','table_id'=>$table,'session_id'=>$session,'client_token'=>$token,'actor_user_id'=>$admin,'items'=>[['id'=>$item['id'],'quantity'=>$q]]]);};


$tax=$core->tax();$tax->createRateVersion(['rate_bps'=>1000,'effective_from'=>''], $user);
$tax->createItemPolicyVersion(['item_id'=>$item['id'],'policy'=>'inherit_default'],$user);$tax->setEnabled(true,$user);
$o=$order('ci-m-tax-order-0001',4);$core->inventoryOrders()->processPending();
$svc=$core->settlements();$line=$o['lines'][0]['order_item_id'];$price=(int)$item['price'];
$roundTax=static fn($net)=>intdiv($net+5,10);
$unit=$price+$roundTax($price);
$acc=$svc->account($session);
$a($acc['subtotal']===4*$price&&$acc['tax']===$roundTax(4*$price),'independent initial tax');
$pay=function($mode,$rid,$sel,$want)use($svc,$session,$user,$a){
    $ac=$svc->account($session);$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>$mode,'destination'=>'direct','request_id'=>$rid,'selection'=>$sel,'print_receipt'=>false];
    $v=$svc->review($req,$user);$a($v['total']===$want,'expected payment '.$rid.' actual '.json_encode($v['total']));$req['tenders']=['pos'=>$want];return $svc->settle($req,$user);
};
$first=$pay('itemized','ci-m-first-pay-001',[['order_item_id'=>$line,'quantity'=>1]],$unit);
$receiptBefore=$rows('SELECT * FROM settlement_records WHERE id=?',[$first['settlement_id']]);
$lineBefore=$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$first['settlement_id']]);
$acc=$svc->account($session);$svc->setDiscount($session,'fixed',3000,$user,$acc['signature']);
$discountUnit=$price-1000+$roundTax($price-1000);
$acc=$svc->account($session);$a($acc['remaining_total']===3*$discountUnit,'grant remainder tax');
$second=$pay('itemized','ci-m-second-pay-001',[['order_item_id'=>$line,'quantity'=>1]],$discountUnit);
$secondBefore=$rows('SELECT * FROM settlement_records WHERE id=?',[$second['settlement_id']]);
$acc=$svc->account($session);$a($acc['remaining_total']===2*$discountUnit&&$acc['paid_total']===$unit+$discountUnit&&$acc['paid_discount']===1000,'post-grant balance');
$third=$pay('full','ci-m-final-pay-001',[],2*$discountUnit);$a($third['closes_session']&&$third['remaining_total']===0,'final closure');
$a($rows('SELECT * FROM settlement_records WHERE id=?',[$first['settlement_id']])===$receiptBefore,'original receipt changed');
$a($rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$first['settlement_id']])===$lineBefore,'original lines changed');
$a($rows('SELECT * FROM settlement_records WHERE id=?',[$second['settlement_id']])===$secondBefore,'post-grant receipt changed');
$a((int)$one("SELECT SUM(total) FROM settlement_records WHERE session_id=? AND status='completed'",[$session])===$unit+3*$discountUnit,'payment total');
$a((int)$one("SELECT SUM(tax_amount) FROM settlement_records WHERE session_id=? AND status='completed'",[$session])===$roundTax($price)+3*$roundTax($price-1000),'tax total');
$a((int)$one("SELECT SUM(discount) FROM settlement_records WHERE session_id=? AND status='completed'",[$session])===3000,'discount total');
echo json_encode(['status'=>'PASS','tax_active'=>true,'first_receipt_immutable'=>true,'post_grant_partial_receipt_immutable'=>true,'tax_and_discount_exact'=>true,'three_payments'=>true,'closure'=>true],JSON_THROW_ON_ERROR)."\n";
