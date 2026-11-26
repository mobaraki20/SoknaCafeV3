<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]);if(!$root||basename($root)!=='local')exit(2);
require $root.'/bootstrap.php';require_once $root.'/src/Setup/BrowserSetupService.php';
function ck($ok,$n){if(!$ok)throw new RuntimeException($n);echo "PASS $n\n";}
function reject(callable $fn,string $code){try{$fn();}catch(Throwable $e){ck(($e->errorCode??'')===$code,'rejected '.$code);return;}throw new RuntimeException('unexpected success '.$code);}
$server=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',getenv('QA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$server->exec('CREATE DATABASE sokna_cp150b CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dir=sys_get_temp_dir().'/sokna-cp150b-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);$setup=new \Sokna\Local\Setup\BrowserSetupService(dirname($root),$root);
$status=$setup->installNew(['data_dir'=>$dir.'/data','db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_cp150b','user'=>'root','pass'=>getenv('QA_DB_PASSWORD')],'admin_user'=>'qa-target','admin_password'=>'synthetic-qa-admin-not-real','cafe_name'=>'QA CP150B','table_count'=>5]);ck($status['success']??false,'fixture');
$cfg=require dirname($root).'/config.php';$core=sokna_local_bootstrap($cfg);$pdo=$core->database();$actor=$pdo->query("SELECT id,role FROM users WHERE username='qa-target'")->fetch(PDO::FETCH_ASSOC);
$pdo->exec("UPDATE users SET active=0,display_name=CONCAT('QA inactive ',id),username=CONCAT('qa-inactive-',id) WHERE username<>'qa-target'");$pdo->exec("UPDATE personnel SET display_name=CONCAT('QA seed ',id),job_title=NULL,notes=NULL,personnel_code=NULL");
$pdo->exec("INSERT INTO menus(menu_key,name,status) VALUES('qa150b-menu','QA menu','active')");$menu=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories(category_key,name) VALUES('qa150b-cat','QA category')");$cat=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id) VALUES(?,?)')->execute([$menu,$cat]);
$pdo->prepare("INSERT INTO items(item_code,category_id,name,price,preparation_station) VALUES('QA150B-item',?,'QA150B item',100000,'hot_bar')")->execute([$cat]);$item=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menu,$item]);
$pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES('qa-bar',?,'QA bar','operator',1)")->execute([password_hash('synthetic-qa-member',PASSWORD_DEFAULT)]);$bar=['id'=>(int)$pdo->lastInsertId(),'role'=>'operator'];$pdo->prepare("INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,'preparation',1)")->execute([$bar['id']]);$pdo->prepare("INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,'bar')")->execute([$bar['id']]);
$tables=array_map('intval',$pdo->query('SELECT id FROM cafe_tables ORDER BY id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN));
$order=fn($table,$qty,$tag)=>$core->staffQuickOrders()->commit(['table_id'=>$table,'expected_session_id'=>0,'request_token'=>'qa150b-order-token-'.$tag,'items'=>[['id'=>$item,'quantity'=>$qty]]],$actor);
$results=[];function group($name,$fn){global $results;try{$fn();$results[$name]=true;echo "GROUP PASS $name\n";}catch(Throwable $e){$results[$name]=false;echo 'GROUP FAIL '.$name.' '.get_class($e).' '.$e->getMessage()."\n";}}
$tax=$core->tax();$settle=$core->settlements();
group('FIN-L222 actual historical order and receipt after new rate',function()use($tax,$actor,$pdo,$order,$settle,$tables){
 $tax->createRateVersion(['rate_bps'=>1000,'effective_from'=>'2020-01-01 00:00:00'],$actor);$tax->setEnabled(true,$actor);
 $old=$order($tables[0],2,'historical');$sid=(int)$old['session_id'];$oid=(int)$old['order_id'];$line=$pdo->query('SELECT id,tax_rate_bps_snapshot,tax_rate_version_id FROM order_items WHERE order_id='.$oid)->fetch(PDO::FETCH_ASSOC);ck((int)$line['tax_rate_bps_snapshot']===1000,'actual old order ten percent');
 $a=$settle->account($sid);ck($a['remaining_total']===220000&&$a['remaining_tax']===20000,'old account tax');
 $args=['session_id'=>$sid,'expected_session_id'=>$sid,'expected_remaining_total'=>$a['remaining_total'],'expected_signature'=>$a['signature'],'mode'=>'itemized','destination'=>'direct','selection'=>[['order_item_id'=>(int)$line['id'],'quantity'=>1]],'request_id'=>'qa150b-historical-payment','print_receipt'=>false];$r=$settle->review($args,$actor);ck($r['total']===110000&&$r['tax']===10000,'actual historic payment tax');$args['tenders']=[['type'=>'cash','amount'=>$r['total']]];$settle->settle($args,$actor);
 $read=fn()=>$pdo->query('SELECT invoice_snapshot_json,tax_amount,total FROM settlement_records WHERE session_id='.$sid.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$receipts=$read();ck(count($receipts)===1&&(int)$receipts[0]['tax_amount']===10000&&(int)$receipts[0]['total']===110000,'receipt persisted');
 $tax->createRateVersion(['rate_bps'=>2000,'effective_from'=>'2021-01-01 00:00:00'],$actor);$new=$order($tables[1],1,'new-rate');$n=$settle->account((int)$new['session_id']);ck($n['remaining_total']===120000&&$n['remaining_tax']===20000,'new actual account twenty percent');
 $a=$settle->account($sid);ck($a['paid_total']===110000&&$a['remaining_total']===110000&&$a['remaining_tax']===10000,'old account retains original rate');ck($receipts===$read(),'historical receipt byte identical');ck($line===$pdo->query('SELECT id,tax_rate_bps_snapshot,tax_rate_version_id FROM order_items WHERE order_id='.$oid)->fetch(PDO::FETCH_ASSOC),'historical order snapshot unchanged');
});
group('PREP-L122 actual cancellation print enqueue failure',function()use($pdo,$order,$tables,$core,$bar,$actor){
 $o=$order($tables[2],1,'cancel-failure');$oid=(int)$o['order_id'];$core->preparation()->claim(['order_id'=>$oid,'area'=>'bar'],$bar);
 $pdo->exec("CREATE TRIGGER qa150b_cancel_fault BEFORE INSERT ON print_jobs FOR EACH ROW BEGIN IF NEW.job_type='preparation_cancellation' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA synthetic print enqueue failure'; END IF; END");
 $args=['order_id'=>$oid,'request_id'=>'qa150b-cancel-print-failure','reason'=>'QA synthetic cancellation'];try{$r=$core->orderStaffActions()->cancelWholeOrder($args,$actor);}finally{$pdo->exec('DROP TRIGGER qa150b_cancel_fault');}
 ck($r['status']==='cancelled'&&$r['preparation_notice_status']==='failed'&&$r['preparation_notice_job_id']===null,'actual print failure does not roll back cancellation');
 $row=$pdo->query('SELECT preparation_notice_status,preparation_dispatched,preparation_notice_job_id FROM whole_order_cancellations WHERE order_id='.$oid)->fetch(PDO::FETCH_ASSOC);ck($row['preparation_notice_status']==='failed'&&(int)$row['preparation_dispatched']===1&&$row['preparation_notice_job_id']===null,'failure persisted');ck($pdo->query('SELECT status FROM orders WHERE id='.$oid)->fetchColumn()==='cancelled','order status persisted');ck((int)$pdo->query("SELECT COUNT(*) FROM print_jobs WHERE job_type='preparation_cancellation' AND entity_id='".$oid."'")->fetchColumn()===0,'no phantom print job');
 foreach([$core->preparation()->feed($bar),$core->preparation()->publicProjection()] as $f){ck(!in_array($oid,array_column($f['orders'],'id'),true)&&in_array($oid,array_column($f['cancellations'],'id'),true),'separate cancellation feed');}
 reject(fn()=>$core->preparation()->claim(['order_id'=>$oid,'area'=>'bar'],$bar),'order_not_confirmed');$again=$core->orderStaffActions()->cancelWholeOrder($args,$actor);ck($again['idempotent']===true&&$again['preparation_notice_status']==='failed','same request stable');ck((int)$pdo->query('SELECT COUNT(*) FROM whole_order_cancellations WHERE order_id='.$oid)->fetchColumn()===1,'one cancellation row');
});
file_put_contents(dirname($root).'/cp150b-summary.json',json_encode($results,JSON_THROW_ON_ERROR));exit(count($results)===2&&!in_array(false,$results,true)?0:1);