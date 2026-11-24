<?php
declare(strict_types=1);
$root=getenv('RUNNER_TEMP').'/local';require $root.'/bootstrap.php';$core=sokna_local_bootstrap(require dirname($root).'/config.php');$p=$core->database();
$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$a=function($ok,$m){if(!$ok)throw new RuntimeException($m);};
$one=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return $s->fetchColumn();};
$rows=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);};
$run=function($sql,$args=[])use($p){$s=$p->prepare($sql);$s->execute($args);return (int)$p->lastInsertId();};

$dir=getenv('RUNNER_TEMP').'/prep-test';
$mode=$argv[1]??'setup';
if($mode==='setup'){
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


$worker2=$run("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'operator',1)",['ci_prep_two',password_hash('fixture-only-test',PASSWORD_DEFAULT),'QA operator 2']);
$o=$order('ci-j-preparation-order-01',4);$core->inventoryOrders()->processPending();
$oid=(int)$o['order_id'];$line=(int)$o['lines'][0]['order_item_id'];
$area=\Sokna\Local\Domain\Preparation\PreparationService::areaForPreparationStation((string)$one('SELECT preparation_station FROM order_items WHERE id=?',[$line]));
$a(is_string($area),'preparation area');
foreach([$operator,$worker2] as $u){$run("INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,'preparation',1)",[$u]);$run("INSERT INTO user_preparation_areas(user_id,area_key) VALUES(?,?)",[$u,$area]);}
try{$core->preparation()->claim(['order_id'=>$oid,'area'=>$area],$user);throw new LogicException('admin mutated claim');}catch(\Sokna\Local\Domain\Preparation\PreparationException $e){$a($e->errorCode==='forbidden_area','admin monitor only');}
mkdir($dir);
file_put_contents($dir.'/state.json',json_encode(compact('admin','operator','worker2','oid','line','area','initial','ingredients'),JSON_THROW_ON_ERROR));
echo "PASS seed preparation order with two authorized operators\n";exit;
}
$s=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);
$prep=$core->preparation();$act=$core->orderStaffActions();$user=['id'=>$s['admin']];
if($mode==='claim'||$mode==='ack'){
    $idx=(int)$argv[2];$stage=$argv[3];$id=$mode==='claim'?$s['oid']:(int)$s['pending'][$stage]['id'];
    $u=['id'=>$idx===0?$s['operator']:$s['worker2']];
    file_put_contents("$dir/ready-$stage-$idx",'ready');
    $deadline=microtime(true)+15;while(!is_file("$dir/go-$stage")){if(microtime(true)>$deadline)throw new RuntimeException('barrier timeout');usleep(10000);}
    $r=$mode==='claim'?$prep->claim(['order_id'=>$id,'area'=>$s['area']],$u):$prep->acknowledgeAdjustment(['adjustment_id'=>$id],$u);
    file_put_contents("$dir/result-$stage-$idx.json",json_encode($r,JSON_THROW_ON_ERROR));exit;
}
$results=function($stage)use($dir){return array_map(fn($i)=>json_decode(file_get_contents("$dir/result-$stage-$i.json"),true,512,JSON_THROW_ON_ERROR),[0,1]);};
$unique=function($r)use($a){$a(count(array_filter($r,fn($v)=>$v['duplicate']===false))===1&&count(array_filter($r,fn($v)=>$v['duplicate']===true))===1,'one mutation and one duplicate');};
if($mode==='adjust'){
    $r=$results('claim');$unique($r);$a($r[0]['claimed_by_user_id']===$r[1]['claimed_by_user_id'],'shared claim owner');
    $a((int)$one('SELECT COUNT(*) FROM order_preparation_claims WHERE order_id=?',[$s['oid']])===1,'one claim row');
    $a((int)$one("SELECT COUNT(*) FROM audit_log WHERE action='preparation.claimed' AND entity_id=?",[(string)$s['oid']])===1,'one claim audit');
    foreach([3,5] as $i=>$q)$a($act->adjustItemQuantity(['order_item_id'=>$s['line'],'quantity'=>$q,'reason'=>'QA consecutive corrections','request_id'=>'ci-j-prep-adjust-000'.$i],$user)['success'],'correction');
    $core->inventoryOrders()->processPending();
    $s['pending']=$rows("SELECT id,from_quantity,to_quantity FROM order_item_adjustments WHERE order_id=? AND preparation_ack_status='pending' ORDER BY id",[$s['oid']]);
    $x=$s['pending'];$a(count($x)===2&&(int)$x[0]['from_quantity']===4&&(int)$x[0]['to_quantity']===3&&(int)$x[1]['from_quantity']===3&&(int)$x[1]['to_quantity']===5,'two ordered adjustments');
    try{$prep->acknowledgeAdjustment(['adjustment_id'=>(int)$x[1]['id']],['id'=>$s['worker2']]);throw new LogicException('FIFO bypass');}catch(\Sokna\Local\Domain\Preparation\PreparationException $e){$a($e->errorCode==='preparation_adjustment_out_of_order','FIFO');}
    file_put_contents($dir.'/state.json',json_encode($s,JSON_THROW_ON_ERROR));echo "PASS concurrent claim, consecutive changes, FIFO gate\n";exit;
}
if($mode==='verify'){
    foreach([0,1] as $stage)$unique($results((string)$stage));
    $a((int)$one("SELECT COUNT(*) FROM order_item_adjustments WHERE order_id=? AND preparation_ack_status='acknowledged'",[$s['oid']])===2,'two ACKs');
    $balance=function($id)use($one){return (int)$one('SELECT quantity_base FROM inventory_balances WHERE inventory_item_id=?',[$id]);};
    foreach($s['ingredients'] as $i)$a($balance($i['inventory_item_id'])===$s['initial'][$i['inventory_item_id']]-(int)$i['quantity_base']*5,'stock after corrections');
    $r=$act->cancelWholeOrder(['order_id'=>$s['oid'],'request_id'=>'ci-j-cancel-after-claim-01','reason'=>'QA cancellation after claim'],$user);$a($r['success'],'cancel after claim');
    foreach($s['ingredients'] as $i)$a($balance($i['inventory_item_id'])===$s['initial'][$i['inventory_item_id']],'stock restored after claim');
    echo json_encode(['status'=>'PASS','independent_connections'=>true,'shared_claim'=>true,'fifo'=>true,'concurrent_ack'=>true,'stock_restored_after_claim'=>true],JSON_THROW_ON_ERROR)."\n";exit;
}
throw new RuntimeException('Unknown test stage');
