$mode=getenv('SOKNA_V_MODE');
$o=$order('ci-v-adjust-order-0001',4);$core->inventoryOrders()->processPending();
$line=(int)$o['lines'][0]['order_item_id'];$price=(int)$item['price'];
$svc=$core->settlements();$act=$core->orderStaffActions();
$second=\Sokna\Local\Core\Bootstrap::fromArray(require dirname($root).'/config.php');$p2=$second->database();
$a($p!==$p2,'independent PDO');
$p2->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
$other=$run("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)",['ci_v_other',password_hash('fixture-only',PASSWORD_DEFAULT),'QA second']);
$pay=function(int $qty,string $rid)use($svc,$session,$line,$user){$ac=$svc->account($session);$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>'itemized','destination'=>'direct','request_id'=>$rid,'selection'=>[['order_item_id'=>$line,'quantity'=>$qty]],'print_receipt'=>false];$review=$svc->review($req,$user);$req['tenders']=['pos'=>$review['total']];return $svc->settle($req,$user);};
$req=['order_item_id'=>$line,'quantity'=>1,'reason'=>'QA stale snapshot adjustment','request_id'=>'ci-v-adjust-0001'];
if($mode==='grouped')$req['fulfillment_quantities']=['dine_in'=>1,'takeaway'=>0];
$creditCount=fn()=>(int)$one("SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND status='reversal'",[$session]);
$adjustCount=fn()=>(int)$one("SELECT COUNT(*) FROM order_item_adjustments WHERE order_item_id=?",[$line]);
$qty=fn()=>(int)$one('SELECT quantity FROM order_items WHERE id=?',[$line]);
$assertUnchanged=function()use($a,$creditCount,$adjustCount,$qty){$a($creditCount()===0&&$adjustCount()===0&&$qty()===4,'blocked adjustment mutated order or ledger');};
if($mode==='mixed-guard'){
    $svc->setDiscount($session,'percent',10,$user,$svc->account($session)['signature']);
    $pay(1,'ci-v-first-pay-0001');$svc->setDiscount($session,'fixed',6000,$user,$svc->account($session)['signature']);$pay(1,'ci-v-second-pay-0002');
    $before=$svc->account($session);$caught=null;
    try{$act->adjustItemQuantity($req,$user);}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$caught=$e->errorCode;}
    $a($caught==='discount_rebase_unsupported','mixed receipt without provenance must not be guessed '.json_encode($caught));$assertUnchanged();$a($svc->account($session)['signature']===$before['signature'],'account changed');
    echo json_encode(['status'=>'PASS','mode'=>$mode,'unsupported_legacy_mix_blocked'=>true,'no_writes'=>true],JSON_THROW_ON_ERROR)."\n";return;
}
if($mode==='stale-refund'){
    $pay(2,'ci-v-first-pay-0001');
    try{$act->adjustItemQuantity($req,$user);throw new LogicException('missing confirmation');}catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$a($e->errorCode==='refund_confirmation_required','original quote');$req['refund_confirmed']=true;$req['refund_quote']=$e->details['quote'];}
}
$p2->beginTransaction();
$q=$p2->prepare("SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND status='completed'");$q->execute([$session]);$seen=(int)$q->fetchColumn();
$a($seen===($mode==='stale-refund'?1:0),'initial snapshot');
$pay($mode==='stale-refund'?1:2,'ci-v-second-pay-0002');
$q->execute([$session]);$a((int)$q->fetchColumn()===$seen,'old snapshot proven');
$accepted=false;$error=null;$details=[];
try{$r=$second->orderStaffActions()->adjustItemQuantity($req,['id'=>$other]);$accepted=($r['success']??false)===true;}
catch(\Sokna\Local\Domain\Orders\OrderStaffActionException $e){$error=$e->errorCode;$details=$e->details;}
finally{if($p2->inTransaction())$p2->rollBack();}
$assertUnchanged();
if($mode==='baseline'){
    $a($accepted&&$error===null,'baseline stale bypass did not reproduce '.json_encode($error));
    echo json_encode(['status'=>'PASS','mode'=>$mode,'known_defect_reproduced'=>true,'fixture_rolled_back'=>true],JSON_THROW_ON_ERROR)."\n";return;
}
$a(!$accepted&&$error==='refund_confirmation_required','fresh floor must request new confirmation '.json_encode([$accepted,$error,$details]));
$want=$mode==='stale-refund'?2*$price:$price;
$a((int)$details['amount']===$want,'fresh quote correct');
$req['refund_confirmed']=true;$req['refund_quote']=$details['quote'];
$done=$act->adjustItemQuantity($req,['id'=>$other]);$a($done['success']===true&&$act->adjustItemQuantity($req,['id'=>$other])['idempotent']===true,'confirmed adjustment replay');
$core->inventoryOrders()->processPending();
$ac=$svc->account($session);
$a($qty()===1&&$ac['paid_total']===$price&&$ac['remaining_total']===0,'final ledger exact');
$a((int)$one("SELECT SUM(total) FROM settlement_records WHERE session_id=? AND status='reversal'",[$session])===$want,'refund exact');
$a($adjustCount()===1,'one adjustment');
foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']]-(int)$i['quantity_base'],'inventory one unit');
echo json_encode(['status'=>'PASS','mode'=>$mode,'old_snapshot_proven'=>true,'fresh_quote'=>true,'one_adjustment'=>true,'paid_quantity_floor'=>true,'net_ledger_exact'=>true,'inventory_exact'=>true],JSON_THROW_ON_ERROR)."\n";
