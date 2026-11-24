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
$a($act->adjustItemQuantity(['order_item_id'=>$other['lines'][0]['order_item_id'],'quantity'=>0,'reason'=>'حذف مانده پرداخت نشده','request_id'=>'ci-f2-unpaid-remove'],$user)['success'],'unpaid removal');$core->inventoryOrders()->processPending();
$adj=['order_item_id'=>$line,'quantity'=>0,'reason'=>'حذف عدد پرداخت شده باقی مانده','request_id'=>'ci-f-adjust-002'];
$q=$quote($adj);$a($q['amount']===$price,'second refund quote');$r=$act->adjustItemQuantity($adj+['refund_confirmed'=>true,'refund_quote'=>$q['quote']],$user);$a($r['success'],'single refund adjustment');
$core->inventoryOrders()->processPending();
$acc=$svc->account($session);$a($acc['subtotal']===0&&$acc['paid_total']===0&&$acc['remaining_total']===0&&$acc['fully_credited_empty']===true,'two refunds net');
$a($rows('SELECT * FROM settlement_records WHERE id=?',[$paid['settlement_id']])===$receipt,'original receipt changed');
$a($rows('SELECT * FROM settlement_record_lines WHERE settlement_id=? ORDER BY id',[$paid['settlement_id']])===$receiptLines,'original receipt lines changed');
$a((int)$one("SELECT SUM(total) FROM settlement_records WHERE status='reversal'")===2*$price,'refund sum');
$a((int)$one("SELECT SUM(t.amount) FROM settlement_tender_allocations t JOIN settlement_records r ON r.id=t.settlement_id WHERE r.status='reversal' AND t.tender_type='pos'")===$price,'pos refund sum');
$a((int)$one("SELECT SUM(t.amount) FROM settlement_tender_allocations t JOIN settlement_records r ON r.id=t.settlement_id WHERE r.status='reversal' AND t.tender_type='cash'")===$price,'cash refund sum');
foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']],'inventory net after refunds');
$projection=$core->orderWorkspace()->publicHallProjection();$a(empty($projection['accounts'][(string)$table]['lines']),'net paid projection');
$final=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$acc['remaining_total'],'expected_signature'=>$acc['signature'],'mode'=>'full','destination'=>'direct','request_id'=>'ci-f-final-001','print_receipt'=>false];$v=$svc->review($final,$user);$a($v['closure_only']===true&&$v['total']===0,'zero closure preview');$final['tenders']=[];$done=$svc->settle($final,$user);$a($done['closes_session']&&$done['total_amount']===0,'zero financial closure');$a((int)$one('SELECT COUNT(*) FROM settlement_tender_allocations WHERE settlement_id=?',[$done['settlement_id']])===0,'phantom tender');$a((string)$one('SELECT status FROM table_sessions WHERE id=?',[$session])==='closed','close table');$history=\Sokna\Local\Domain\Reporting\PublicInvoiceHistorySnapshot::build($p,(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d'));$a(count(array_filter($history['rows'],fn($r)=>$r['reversal_kind']==='item_partial'))===2,'history labels');
$models=$core->publicProjectionBuilder()->remoteModelsFor(['reports']);$report=$models[0]['payload'];
$a(($report['invoice_history_archive']['status']??null)==='available','actual invoice projection unavailable');
$a(($report['item_sales_archive']['status']??null)==='available','actual item sales projection unavailable');
$a(($report['dashboard_activity']['status']??null)==='available','actual operational activity projection unavailable');
echo json_encode(['report_projection'=>true,'status'=>'PASS','partial_refund_twice'=>true,'immutable_receipt'=>true,'quote_required'=>true,'stale_quote_rejected'=>true,'mixed_tenders'=>true,'inventory'=>true,'projection'=>true,'fully_refunded_empty_closure'=>true,'no_new_payment'=>true,'history_kind'=>true],JSON_THROW_ON_ERROR)."\n";
