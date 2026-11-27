<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]);if(!$root||basename($root)!=='local')exit(2);require $root.'/bootstrap.php';
$cfg=require dirname($root).'/config.php';if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp150'||($cfg['db']['pass']??'')!==getenv('QA_DB_PASSWORD'))exit(2);
$c=sokna_local_bootstrap($cfg);$p=$c->database();$fpath=dirname($root).'/cp144-fixture.json';$f=json_decode(file_get_contents($fpath),true,64,JSON_THROW_ON_ERROR);
function ck($x,$n){if(!$x)throw new RuntimeException($n);echo "PASS $n\n";}
$mode=$argv[2]??'prepare';
if($mode==='sync'){
 $models=$c->publicProjectionBuilder()->remoteModelsFor(['operations']);$r=$c->publicEdgeSyncClient()->postRaw('/api/v1/local/read-models',['models'=>$models],5);
 ck(($r['status']??0)===200&&((int)($r['body']['synced']??0)+(int)($r['body']['unchanged']??0))===1,'actual signed operations sync');
 $h=$c->publicEdgePublisher()->syncHeartbeat(5);ck(($h['success']??false)===true,'actual signed heartbeat');exit;
}
$pw=password_hash('synthetic-member-only',PASSWORD_DEFAULT);
$p->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES('qa-floor',?,'qa-floor','operator',1)")->execute([$pw]);$uid=(int)$p->lastInsertId();
foreach(['orders_floor','remote_access'] as $cap)$p->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)')->execute([$uid,$cap]);
$p->prepare("INSERT INTO remote_user_credentials(user_id,password_hash,credential_version,source_mode) VALUES(?,?,1,'local_password')")->execute([$uid,$pw]);
$floor=['id'=>$uid,'role'=>'operator'];$cashier=['id'=>array_column($f['users'],'id','name')['qa-cashier'],'role'=>'operator'];
foreach($c->publicProjectionBuilder()->authProjections() as $a){if($a['username']==='qa-floor'){$f['users'][]=['id'=>$uid,'name'=>'qa-floor','role'=>'operator','password'=>'synthetic-member-only','caps'=>$a['capabilities'],'areas'=>$a['preparation_areas'],'hash'=>$a['password_hash']];}}
ck(count(array_filter($f['users'],fn($x)=>$x['name']==='qa-floor'))===1,'actual floor auth projection');
$clock=$c->businessClock()->assignment();
foreach(['race','tax'] as $i=>$kind){$sid=$f['hall'][$kind]['session'];$tid=$f['hall'][$kind]['table'];$p->prepare("INSERT INTO orders(public_code,client_token,table_id,session_id,order_source,status,total_amount,business_order_number,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,'guest','pending_approval',100000,?,?,?,?,?)")->execute(['qa-fp3-pending-'.$i,'qa-fp3-token-'.$i,$tid,$sid,29000+$i,$clock['business_date'],$clock['shift_key'],$clock['shift_label'],$clock['cutoff']]);$oid=(int)$p->lastInsertId();$p->prepare("INSERT INTO order_items(order_id,item_id,item_name,unit_price,quantity,ordered_quantity,preparation_station,line_total) VALUES(?,?, 'QA pending',100000,1,1,'hot_bar',100000)")->execute([$oid,$f['tax_item']]);}
$w=$c->orderWorkspace();$s=$c->settlements();$snap=$w->operatorSnapshot($cashier,$s);$rows=array_column($snap['tables'],null,'id');$part=$rows[$f['hall']['partial']['table']];$total=$s->account($f['hall']['partial']['session'])['remaining_total'];
ck($total===90000&&$part['account_total']===200000&&$part['account_remaining_total']===$total,'Local actual net with partial payment and discount');
ck($rows[$f['hall']['race']['table']]['account_remaining_total']===null,'Local mixed pending no invented net');
ck($rows[$f['hall']['tax']['table']]['occupancy_kind']==='pending_guest'&&$rows[$f['hall']['tax']['table']]['pending_order_total']===100000&&$rows[$f['hall']['tax']['table']]['account_remaining_total']===null,'Local guest pending separate amount');
ck(!$p->inTransaction(),'Local read leaves no transaction');
$floorSnap=$w->operatorSnapshot($floor,$s);ck(!array_filter($floorSnap['tables'],fn($t)=>array_key_exists('account_remaining_total',$t)),'Local floor role no finance net');
$raw=$c->publicProjectionBuilder()->remoteModelsFor(['operations'])[0]['payload'];ck($raw['finance_projection']['status']==='ready','real operations projection remains ready with pending sessions');
$proj=array_column($raw['settlement_accounts'],null,'session_id');ck($proj[$f['hall']['partial']['session']]['remaining_total']===$total&&!isset($proj[$f['hall']['race']['session']]),'actual same net and pending excluded Public');
foreach(['qa-floor','qa-cashier'] as $name){$u=array_values(array_filter($f['users'],fn($u)=>$u['name']===$name))[0];$session=['installation_id'=>$f['installation'],'projection_id'=>'user:'.$u['id'],'user_id'=>$u['id'],'capabilities'=>$u['caps'],'role'=>$u['role']];$read=$c->lanStaffGateway()->read($session,'operations')['payload'];if($name==='qa-floor')ck(!isset($read['settlement_accounts'],$read['finance_projection'])&&!array_key_exists('settlement_accounts',$read)&&!array_key_exists('finance_projection',$read),'actual LAN floor role no financial data');else ck(isset($read['settlement_accounts'],$read['finance_projection']),'actual LAN cashier receives authorized projection');}
file_put_contents($fpath,json_encode($f,JSON_THROW_ON_ERROR));
echo "GROUP PASS real Hall balances and LAN permission\n";
