<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]);if(!$root||basename($root)!=='local')exit(2);require $root.'/bootstrap.php';
$cfg=require dirname($root).'/config.php';if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp150'||($cfg['db']['pass']??'')!==getenv('QA_DB_PASSWORD'))exit(2);
$c=sokna_local_bootstrap($cfg);$p=$c->database();$f=json_decode(file_get_contents(dirname($root).'/cp144-fixture.json'),true,64,JSON_THROW_ON_ERROR);$actor=$p->query("SELECT id,role FROM users WHERE username='qa-target'")->fetch(PDO::FETCH_ASSOC);$s=$c->settlements();$clock=$c->businessClock()->assignment();$item=(int)$f['tax_item'];
function ck($x,$n){if(!$x)throw new RuntimeException($n);echo "PASS $n\n";}
function one(PDO $p,string $q,array $a=[]){$s=$p->prepare($q);$s->execute($a);return $s->fetchColumn();}
function rows(PDO $p,string $q,array $a=[]){$s=$p->prepare($q);$s->execute($a);return $s->fetchAll(PDO::FETCH_ASSOC);}
function reject(callable $fn,string $code){try{$fn();}catch(Throwable $e){ck(($e->errorCode??'')===$code,'rejected '.$code);return;}throw new RuntimeException('unexpected success '.$code);}
function insertOrder(PDO $p,array $clock,int $table,int $session,int $item,int $seq,int $qty=1,string $station='hot_bar',string $status='accounted'):int{
 $p->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'staff',?,?,?,?,?,?,?)")->execute(['qa-fp2-'.$seq,'qa-fp2-'.$seq,$table,$session,$status,100000*$qty,$seq,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);$oid=(int)$p->lastInsertId();$p->prepare('INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,preparation_station,line_total) VALUES(?,?,?,?,?,?,?,?)')->execute([$oid,$item,'QA-FP2-'.$seq,100000,$qty,$qty,$station,100000*$qty]);return $oid;
}
function createSession(PDO $p,array $clock,int $seq):array{$p->prepare('INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES(?,?,?,?)')->execute(['QA table '.$seq,$seq,'qa-fp2-'.$seq,'qa-fp2-t-'.$seq]);$t=(int)$p->lastInsertId();$p->prepare("INSERT INTO table_sessions(public_token,table_id,status,live_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,'active',?,?,?,?,?)")->execute(['qa-fp2-s-'.$seq,$t,$t,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);return [$t,(int)$p->lastInsertId()];}

$fail=0;function group(string $name,callable $fn){global $fail,$p;try{$fn();echo "GROUP PASS $name\n";}catch(Throwable $e){if($p->inTransaction())$p->rollBack();$fail++;echo "GROUP FAIL $name ".get_class($e).' '.$e->getMessage()."\n";}}
group('A09 invoice real MariaDB query',function()use($p,$c){
 $finance=$c->financeWorkspace();
 $id=(int)one($p,'SELECT id FROM settlement_records WHERE request_id=?',['qa160-subscriber-settlement']);
 ck($id>0,'real subscriber settlement exists');
 $r=$finance->searchSettlements(['q'=>'QA160 subscriber']);
 ck(in_array($id,array_map('intval',array_column($r['rows'],'id')),true),'subscriber name finds exact invoice');
 $row=array_column($r['rows'],null,'id')[$id];ck($row['subscriber_name']==='QA160 subscriber','subscriber name in returned record');
 $no=(string)one($p,'SELECT invoice_number FROM settlement_records WHERE id=?',[$id]);
 $fa=strtr($no,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
 ck(in_array($id,array_map('intval',array_column($finance->searchSettlements(['q'=>$fa])['rows'],'id')),true),'Persian invoice number');
 ck($finance->invoiceIdForReference($fa)===$id,'resolve exact invoice');
 ck($finance->searchSettlements(['q'=>'QA160 subscriber','destination'=>'direct'])['pagination']['total']===0,'destination filter');
 ck($finance->searchSettlements(['q'=>'%'])['pagination']['total']===0,'literal wildcard');
 ck($finance->searchSettlements(['q'=>"' OR 1=1 --"])['pagination']['total']===0,'parameterized input');
 $d=$finance->settlementDetail($id);ck((int)$d['record']['id']===$id&&$d['record']['subscriber_name']==='QA160 subscriber','detail matches search');
});
group('A06 cancellation real transaction manual and auto print',function()use($p,$c,$clock,$actor,$item){
 $p->prepare("INSERT INTO inventory_items(item_code,name,base_unit,active) VALUES('qa-a10-stock','QA ingredient','g',1)")->execute();
 $inv=(int)$p->lastInsertId();
 $p->prepare("INSERT INTO inventory_balances(inventory_item_id,quantity_base,average_unit_cost,cost_status) VALUES(?,1000,10,'known')")->execute([$inv]);
 $st=$p->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
 foreach(['module.inventory.enabled'=>'1','inventory_initialized'=>'1','inventory_reconciliation_required'=>'0'] as $k=>$v)$st->execute([$k,$v]);
 $c->inventoryOrders()->saveRecipe($item,[['inventory_item_id'=>$inv,'quantity_base'=>50]],$actor);
 $balance=fn()=>(int)one($p,'SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=?',[$inv]);
 foreach([false,true] as $auto){
  $n=$auto?2:1;$st->execute(['printing.quick.auto_preparation',$auto?'1':'0']);
  [$t,$sid]=createSession($p,$clock,601+$n);$oid=insertOrder($p,$clock,$t,$sid,$item,26000+$n,2);
  $p->beginTransaction();$c->inventoryOrders()->enqueueAccountedTx($oid,(int)$actor['id']);$p->commit();ck($balance()===900,'material consumed '.$n);
  $p->prepare("INSERT INTO order_preparation_claims(order_id,area_key,claimed_by_user_id,item_signature) VALUES(?,'bar',?,?)")->execute([$oid,$actor['id'],str_repeat('a',64)]);
  $args=['order_id'=>$oid,'request_id'=>'qa-a10-cancel-'.$n,'reason'=>'QA synthetic cancellation'];
  $jobs=fn()=>(int)one($p,"SELECT COUNT(*) FROM print_jobs WHERE entity_type='order_cancellation' AND entity_id=?",[(string)$oid]);
  $p->beginTransaction();$r=$c->orderStaffActions()->cancelWholeOrder($args,$actor);ck($balance()===1000,'material returned in transaction '.$n);ck($jobs()===($auto?1:0),'correct cancellation queue '.$n);$p->rollBack();
  ck($jobs()===0&&$balance()===900,'rollback stock and queue '.$n);
  $r=$c->orderStaffActions()->cancelWholeOrder($args,$actor);$rr=$c->orderStaffActions()->cancelWholeOrder($args,$actor);
  ck($jobs()===($auto?1:0)&&$balance()===1000&&($rr['idempotent']??false),'commit and replay '.$n);
  if(!$auto)ck(($r['preparation_notice_status']??'')==='blocked','manual print mode suppresses cancellation slip');
  ck(one($p,'SELECT status FROM orders WHERE id=?',[$oid])==='cancelled','order cancelled '.$n);
 }
});
group('A18 expense real create/reverse',function()use($p,$c,$actor){
 $cat=(string)one($p,'SELECT category_key FROM expense_categories WHERE active=1 ORDER BY category_key LIMIT 1');ck($cat!=='','expense category');
 $a=['category_key'=>$cat,'amount'=>123456,'description'=>'QA A10 expense','occurred_at'=>$c->time()->localNow()->modify('-1 minute')->format('Y-m-d H:i:s'),'source_request_id'=>'qa-a10-expense'];
 $e=$c->expenses();$x=$e->create($a,$actor);$y=$e->create($a,$actor);ck($x['id']===$y['id']&&($y['idempotent']??false),'expense create replay');
 $r=$e->reverse((int)$x['id'],'QA reversal','qa-a10-expense-reverse',$actor);ck((int)$r['id']>0,'expense reversal');
 ck((int)one($p,'SELECT SUM(CASE WHEN status=\'reversal\' THEN -amount ELSE amount END) FROM expenses WHERE id=? OR reverses_expense_id=?',[$x['id'],$x['id']])===0,'net expense zero');
});
echo 'A11_GROUP_FAILURES='.$fail.PHP_EOL;exit($fail?1:0);
