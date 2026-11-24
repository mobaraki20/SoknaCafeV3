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

$o=$order('ci-d-ten-items-0001',10);$core->inventoryOrders()->processPending();
$svc=$core->settlements();$line=$o['lines'][0]['order_item_id'];$price=(int)$item['price'];
$acc=$svc->account($session);
$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$acc['remaining_total'],'expected_signature'=>$acc['signature'],'mode'=>'itemized','destination'=>'direct','request_id'=>'ci-d-partial-001','selection'=>[['order_item_id'=>$line,'quantity'=>2]],'print_receipt'=>false];
$v=$svc->review($req,$user);$req['tenders']=['pos'=>$v['total']];$paid=$svc->settle($req,$user);$a(!$paid['closes_session'],'premature closure');
$receipt=$rows('SELECT * FROM settlement_records WHERE session_id=? ORDER BY id',[$session]);
$receiptLines=$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$paid['settlement_id']]);
$act=$core->orderStaffActions();
$adjust=['order_item_id'=>$line,'quantity'=>8,'fulfillment_quantities'=>['dine_in'=>6,'takeaway'=>2],'reason'=>'اصلاح سهم پرداخت نشده','request_id'=>'ci-d-adjust-001'];
$r=$act->adjustItemQuantity($adjust,$user);$a($r['success'],'group adjustment');
$core->inventoryOrders()->processPending();
$acc=$svc->account($session);$a($acc['subtotal']===$price*8 && $acc['remaining_total']===$price*6 && $acc['paid_total']===$price*2,'eight/two paid totals');
foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']]-(int)$i['quantity_base']*8,'stock after group adjustment');
$a($act->adjustItemQuantity($adjust,$user)['idempotent']===true,'adjust replay');
$r=$act->adjustItemQuantity(['order_item_id'=>$line,'quantity'=>5,'reason'=>'اصلاح متوالی','request_id'=>'ci-d-adjust-002'],$user);
$a($r['success'],'single adjustment');$core->inventoryOrders()->processPending();
$acc=$svc->account($session);$a($acc['subtotal']===$price*7&&$acc['remaining_total']===$price*5,'seven/two paid totals');
foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']]-(int)$i['quantity_base']*7,'stock after single adjustment');
$before=$rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$o['order_id']]);
foreach([['order_item_id'=>$line,'quantity'=>1,'request_id'=>'ci-d-denied-1'],['order_item_id'=>$line,'quantity'=>7,'fulfillment_quantities'=>['dine_in'=>1,'takeaway'=>6],'request_id'=>'ci-d-denied-2']] as $bad){
    try{$act->adjustItemQuantity($bad,$user);throw new LogicException('paid floor accepted');}
    catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='paid_quantity_floor','unexpected rejection '.$e->errorCode);}
    $a($rows('SELECT * FROM order_items WHERE order_id=? ORDER BY id',[$o['order_id']])===$before,'denied correction changed order');
}
$a($rows('SELECT * FROM settlement_records WHERE session_id=? ORDER BY id',[$session])===$receipt,'original receipt changed');
$a($rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$paid['settlement_id']])===$receiptLines,'original receipt lines changed');
$projection=$core->orderWorkspace()->publicHallProjection();
$projected=$projection['accounts'][(string)$table]['lines'];
$a(array_sum(array_column($projected,'paid_quantity'))===2,'Public paid quantity missing');
$final=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$acc['remaining_total'],'expected_signature'=>$acc['signature'],'mode'=>'full','destination'=>'direct','request_id'=>'ci-d-final-001','print_receipt'=>false];
$v=$svc->review($final,$user);$final['tenders']=['pos'=>$v['total']];$done=$svc->settle($final,$user);
$a($done['closes_session']&&$done['remaining_total']===0,'final settlement');
echo json_encode(['status'=>'PASS','initial_quantity'=>10,'paid_quantity'=>2,'final_quantity'=>7,'immutable_receipts'=>true,'paid_floor_rejected'=>true,'consecutive_adjustments'=>true,'inventory'=>true,'public_projection'=>true,'final_settlement'=>true],JSON_THROW_ON_ERROR)."\n";
