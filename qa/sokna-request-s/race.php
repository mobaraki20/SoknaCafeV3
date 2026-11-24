// Parent has created fixture and opened two independent worker processes.
$kind=getenv('SOKNA_RACE_MODE');$same=str_starts_with($kind,'same');$full=str_ends_with($kind,'full');
$otherAdmin=$run("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'admin',1)",['ci_second_admin',password_hash('fixture-only',PASSWORD_DEFAULT),'QA second admin']);
$o=$order('ci-race-order-0001',4);$core->inventoryOrders()->processPending();
$line=(int)$o['lines'][0]['order_item_id'];$svc=$core->settlements();$ac=$svc->account($session);
$req=['session_id'=>$session,'expected_session_id'=>$session,'expected_remaining_total'=>$ac['remaining_total'],'expected_signature'=>$ac['signature'],'mode'=>$full?'full':'itemized','destination'=>'direct','selection'=>$full?[]:[['order_item_id'=>$line,'quantity'=>1]],'request_id'=>'ci-race-payment-0001','print_receipt'=>false];
$review=$svc->review($req,$user);$amount=(int)$review['total'];$req['tenders']=['pos'=>$amount];
$req2=$req;if(!$same)$req2['request_id']='ci-race-payment-0002';
$dir=sys_get_temp_dir().'/sokna-race-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$worker=<<<'WORKER'
<?php
$root=getenv('RUNNER_TEMP').'/local';require $root.'/bootstrap.php';$core=sokna_local_bootstrap(require dirname($root).'/config.php');$input=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
file_put_contents($argv[2],'ready');$limit=microtime(true)+30;
while(!is_file($argv[3])){if(microtime(true)>$limit)throw new RuntimeException('barrier timeout');usleep(10000);}
try{$r=$core->settlements()->settle($input['request'],['id'=>$input['actor']]);$out=['status'=>'ok','id'=>(int)$r['settlement_id'],'idempotent'=>(bool)$r['idempotent']];}
catch(\Sokna\Local\Domain\Finance\SettlementException $e){$out=['status'=>'blocked','code'=>$e->errorCode];}
echo json_encode($out,JSON_THROW_ON_ERROR)."\n";
WORKER;
file_put_contents("$dir/worker.php",$worker);
$processes=[];
foreach([[$req,$admin],[$req2,$otherAdmin]] as $i=>$input){
  file_put_contents("$dir/input$i",json_encode(['request'=>$input[0],'actor'=>$input[1]],JSON_THROW_ON_ERROR));
  $proc=proc_open([PHP_BINARY,"$dir/worker.php","$dir/input$i","$dir/ready$i","$dir/go"],[0=>['pipe','r'],1=>['file',"$dir/out$i",'w'],2=>['file',"$dir/err$i",'w']],$pipes);
  $a(is_resource($proc),'worker start');fclose($pipes[0]);$processes[]=$proc;
}
$limit=microtime(true)+30;
while(!is_file("$dir/ready0")||!is_file("$dir/ready1")){if(microtime(true)>$limit)throw new RuntimeException('workers not ready');usleep(10000);}
$p->beginTransaction();$lock=$p->prepare('SELECT id FROM table_sessions WHERE id=? FOR UPDATE');$lock->execute([$session]);$lock->fetchColumn();touch("$dir/go");usleep(300000);$p->commit();
$results=[];
foreach($processes as $i=>$proc){$exit=proc_close($proc);$a($exit===0,'worker error '.file_get_contents("$dir/err$i"));$results[]=json_decode(trim(file_get_contents("$dir/out$i")),true,512,JSON_THROW_ON_ERROR);}
$a((int)$one("SELECT COUNT(*) FROM settlement_records WHERE session_id=? AND status='completed'",[$session])===1,'one payment only '.json_encode($results));
$a((int)$one("SELECT SUM(total) FROM settlement_records WHERE session_id=? AND status='completed'",[$session])===$amount,'exact ledger');
$a((int)$one("SELECT SUM(t.amount) FROM settlement_tender_allocations t JOIN settlement_records r ON r.id=t.settlement_id WHERE r.session_id=?",[$session])===$amount,'exact tender');
if($same){$a($results[0]['status']==='ok'&&$results[1]['status']==='ok'&&$results[0]['id']===$results[1]['id']&&$results[0]['idempotent']!==$results[1]['idempotent'],'same request must replay '.json_encode($results));}
else{$ok=array_values(array_filter($results,fn($r)=>$r['status']==='ok'));$blocked=array_values(array_filter($results,fn($r)=>$r['status']==='blocked'));$a(count($ok)===1&&count($blocked)===1&&in_array($blocked[0]['code'],$full?['session_closed']:['account_changed'],true),'stale request must fail '.json_encode($results));}
if($full)$a((string)$one('SELECT status FROM table_sessions WHERE id=?',[$session])==='closed','closed once');
else $a($svc->account($session)['remaining_total']===$ac['remaining_total']-$amount,'remaining exact');
echo json_encode(['status'=>'PASS','mode'=>$kind,'two_independent_users'=>true,'one_receipt'=>true,'exact_tender'=>true,'results'=>$results],JSON_THROW_ON_ERROR)."\n";
