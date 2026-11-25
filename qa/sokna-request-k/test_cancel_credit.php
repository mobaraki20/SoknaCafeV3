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


$o=$order('ci-f-ten-items-0001',10);$other=$order('ci-f-other-items-0001',3);$core->inventoryOrders()->processPending();
$svc=$core->settlements();$line=$o['lines'][0]['order_item_id'];$price=(int)$item['price'];
$acc=$svc->account($session);$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$acc['remaining_total'],'expected_signature'=>$acc['signature'],'mode'=>'itemized','destination'=>'direct','request_id'=>'ci-f-partial-001','selection'=>[['order_item_id'=>$line,'quantity'=>2]],'print_receipt'=>false];
$v=$svc->review($req,$user);$req['tenders']=['pos'=>$price,'cash'=>$price];$paid=$svc->settle($req,$user);$a(!$paid['closes_session'],'premature closure');
$receipt=$rows('SELECT * FROM settlement_records WHERE id=?',[$paid['settlement_id']]);
$receiptLines=$rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$paid['settlement_id']]);
$act=$core->orderStaffActions();
$quote=function($adj)use($act,$user,$a){try{$act->adjustItemQuantity($adj,$user);throw new LogicException('unconfirmed credit accepted');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='refund_confirmation_required','unexpected error '.$e->errorCode.' '.$e->getMessage());return $e->details;}};
$adj=['order_item_id'=>$line,'quantity'=>1,'fulfillment_quantities'=>['dine_in'=>1,'takeaway'=>0],'reason'=>'اصلاح و برگشت یک عدد پرداخت شده','request_id'=>'ci-f-adjust-001'];
$q=$quote($adj);$a($q['amount']===$price,'refund quote amount');$a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE status='reversal'")===0,'preview posted refund');
try{$act->adjustItemQuantity($adj+['refund_confirmed'=>true,'refund_quote'=>str_repeat('0',64)],$user);throw new LogicException('bad quote accepted');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='refund_confirmation_required','wrong stale quote rejection');}
$adj['refund_confirmed']=true;$adj['refund_quote']=$q['quote'];
$r=$act->adjustItemQuantity($adj,$user);$a($r['success'],'group refund adjustment');
$core->inventoryOrders()->processPending();
$acc=$svc->account($session);$a($acc['subtotal']===$price*4&&$acc['paid_total']===$price&&$acc['remaining_total']===$price*3,'net paid after refund');
$a($act->adjustItemQuantity($adj,$user)['idempotent']===true,'refund replay');
$a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE status='reversal'")===1,'duplicate refund');

$cancel=['session_id'=>$session,'order_id'=>$o['order_id'],'scope'=>'round','request_id'=>'ci-k-whole-cancel-001','reason'=>'QA cancel after item credit'];
$refund=static fn(int $orderId,int $sessionId,string $requestId,string $reason,array $actor):array=>$svc->refundOrderPaymentsTx($orderId,$sessionId,$requestId,$reason,$actor);
$validate=static fn(int $sessionId):array=>$svc->accountTx($sessionId);
$r=$act->cancelSelection($cancel,$user,$refund,$validate);
$a($r['success']&&$r['cancelled_order_ids']===[(int)$o['order_id']],'cancel correct order');
$core->inventoryOrders()->processPending();
$acc=$svc->account($session);
$a($acc['subtotal']===$price*3&&$acc['paid_total']===0&&$acc['remaining_total']===$price*3,'remaining other order');
$a($act->cancelSelection($cancel,$user,$refund,$validate)['idempotent']===true,'cancel replay');
$a($rows('SELECT * FROM settlement_records WHERE id=?',[$paid['settlement_id']])===$receipt,'original receipt changed');
$a($rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$paid['settlement_id']])===$receiptLines,'original receipt lines changed');
$a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE status='reversal'")===2,'two distinct credits');
$a((int)$one("SELECT SUM(total) FROM settlement_records WHERE status='reversal'")===2*$price,'no overcredit');
$a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE status='reversal' AND partial_reversal_order_id=?",[$o['order_id']])===1,'one order credit');
foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']]-(int)$i['quantity_base']*3,'inventory only surviving order');
$final=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$acc['remaining_total'],'expected_signature'=>$acc['signature'],'mode'=>'full','destination'=>'direct','request_id'=>'ci-k-final-001','print_receipt'=>false,'tenders'=>['pos'=>$price*3]];
$done=$svc->settle($final,$user);$a($done['closes_session']&&$done['total_amount']===$price*3,'settle surviving order');
echo json_encode(['status'=>'PASS','whole_order_cancel_after_item_credit'=>true,'only_remaining_paid_quantity_credited'=>true,'immutable_receipts'=>true,'inventory_restored'=>true,'other_order_preserved'=>true,'idempotent'=>true],JSON_THROW_ON_ERROR)."\n";
