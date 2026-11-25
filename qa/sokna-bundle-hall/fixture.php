$mode=getenv('SOKNA_Z_MODE');$tmp=getenv('RUNNER_TEMP');$pair=json_decode(file_get_contents($tmp.'/z-pair.json'),true,512,JSON_THROW_ON_ERROR);
$cfg=require $tmp.'/config.php';$cfg['public']['base_url']=$pair['base'];$cfg['public']['shared_secret']=$pair['secret'];
$remote=\Sokna\Local\Core\Bootstrap::fromArray($cfg);$client=$remote->publicEdgeSyncClient();$relay=$remote->publicEdgeRelay();
$q=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_ci_public_z;charset=utf8mb4','root',getenv('SOKNA_CI_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pubOne=function($sql,$args=[])use($q){$s=$q->prepare($sql);$s->execute($args);return $s->fetchColumn();};
$staffDef=['username'=>'qa-z-floor','display_name'=>'QA floor','active'=>true,'capabilities'=>['orders_floor','remote_access'],'password'=>'QA-Floor-Initial-2026'];
$staffId=(int)$core->adminControls()->saveUser($staffDef,$user)['id'];$staffDef['id']=$staffId;
$otherDef=['username'=>'qa-z-second','display_name'=>'QA second','active'=>true,'capabilities'=>['orders_floor','remote_access'],'password'=>'QA-Second-Initial-2026'];
$otherId=(int)$core->adminControls()->saveUser($otherDef,$user)['id'];
$localOnly=['username'=>'qa-z-local','display_name'=>'QA local','active'=>true,'capabilities'=>['orders_floor'],'password'=>'1234'];$localId=(int)$core->adminControls()->saveUser($localOnly,$user)['id'];
$clock=new ReflectionMethod($remote->publicEdgePublisher(),'authProjectionPayload');$snapshot=fn()=>$clock->invoke($remote->publicEdgePublisher());
$old=$snapshot();$sync=fn($s)=>$client->postRaw('/api/v1/local/auth-projections',$s,5);
$http=function(string $path,?array $data=null,string $cookie='',array $extra=[],bool $form=false)use($pair){
 $h=[];$headers=['Accept: application/json,text/html'];if($cookie!=='')$headers[]='Cookie: '.$cookie;
 if($data!==null)$headers[]=$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json';
 $headers=array_merge($headers,$extra);$c=curl_init($pair['base'].$path);
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$h){$pos=strpos($line,':');if($pos!==false)$h[strtolower(substr($line,0,$pos))]=trim(substr($line,$pos+1));return strlen($line);}]);
 if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$form?http_build_query($data):json_encode($data,JSON_THROW_ON_ERROR)]);
 $raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$err=curl_error($c);curl_close($c);if(!is_string($raw))throw new RuntimeException('HTTP transport '.$err);
 return [$status,json_decode($raw,true),$h,$raw];
};
$server=null;
$start=function()use(&$server,$tmp,$pair,$a){$server=proc_open([PHP_BINARY,'-S','127.0.0.1:18082','-t',$tmp.'/public',getenv('SOKNA_Z_ROUTER')],[0=>['file','/dev/null','r'],1=>['file',$tmp.'/z-server.log','a'],2=>['file',$tmp.'/z-server.log','a']],$pipes);$a(is_resource($server),'server start');for($i=0;$i<60;$i++){ $s=@fsockopen('127.0.0.1',18082,$errno,$errstr,0.2);if(is_resource($s)){fclose($s);return;}usleep(100000);}throw new RuntimeException('server not ready');};
$stop=function()use(&$server){if(is_resource($server)){proc_terminate($server);proc_close($server);$server=null;}};
$login=function(string $name,string $password)use($http,$pair){$r=$http('/staff/login',['installation_id'=>$pair['id'],'username'=>$name,'password'=>$password],'',[],true);return [$r[0],explode(';',$r[2]['set-cookie']??'')[0],$r[2]];};
$who=fn($cookie)=>$http('/api/staff/ui-preferences',null,$cookie)[0];
$ex=fn()=>$relay->syncRealtimeExchange(5,4,(int)(microtime(true)*1000)+15000);
$envelope=fn($id,$kind,$payload)=>['request_id'=>$id,'kind'=>$kind,'created_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+120),'payload'=>$payload];
$send=fn($env,$cookie)=>$http('/api/staff/realtime',$env,$cookie);
$result=fn($id,$cookie)=>$http('/api/staff/realtime/result?request_id='.rawurlencode($id),null,$cookie);
// Uses the existing isolated MariaDB / paired Public HTTP fixture. Never a production host.
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_MULTI_AUDIT')!=='1')throw new RuntimeException('isolated CI only');
$loginName='ci_fin_admin';$pw=bin2hex(random_bytes(18));$newPw=bin2hex(random_bytes(18));
$core->adminControls()->saveUser(['id'=>$admin,'username'=>$loginName,'display_name'=>'QA Workspace','password'=>$pw,'active'=>true],$user);
$limitedName='qa-inventory-no-cost';$limitedPw=bin2hex(random_bytes(18));
$core->adminControls()->saveUser(['username'=>$limitedName,'display_name'=>'QA limited stock','password'=>$limitedPw,'active'=>true,'capabilities'=>['inventory_view','remote_access']],$user);
$assignment=$core->businessClock()->assignment();$day=$assignment['business_date'];
$p->prepare('UPDATE table_sessions SET business_date=?,business_shift_key=?,business_shift_label=?,business_cutoff_snapshot=? WHERE id=?')->execute([$day,$assignment['shift_key'],$assignment['shift_label'],$assignment['cutoff'],$session]);
// A real catalogue product with a preparation station; no mock orders or browser response interception.
$chosen=null;foreach($core->orderCatalog()->staffCatalogRows() as $v){if(!$v['is_bundle']&&(int)$v['price']>0&&in_array($v['preparation_station']??'',['hot_bar','cold_bar','kitchen'],true)){$chosen=$v;break;}}
$a(is_array($chosen),'preparation product exists');
$commitOrder=fn($token)=>$core->orders()->commit(['source'=>'staff','table_id'=>$table,'session_id'=>$session,'client_token'=>$token,'actor_user_id'=>$admin,'items'=>[['id'=>$chosen['id'],'quantity'=>1]]]);
$o1=$commitOrder('ci-workspace-pending-order-0001');$o2=$commitOrder('ci-workspace-received-order-0002');
$core->inventoryOrders()->processPending();
$area=\Sokna\Local\Domain\Preparation\PreparationService::areaForPreparationStation((string)$chosen['preparation_station']);
$prepId=(int)$core->adminControls()->saveUser(['username'=>'qa-workspace-preparation','display_name'=>'QA prep','password'=>bin2hex(random_bytes(16)),'active'=>true,'capabilities'=>['preparation'],'preparation_areas'=>['bar','kitchen']],$user)['id'];
$core->preparation()->claim(['order_id'=>$o2['order_id'],'area'=>$area],['id'=>$prepId]);

// Two independent real orders; each includes two equal-price choices.
$eligible=array_values(array_filter($catalog,fn($c)=>!$c['is_bundle']&&$c['id']!==$item['id']&&$c['id']!==$chosen['id']&&$c['price']>0&&$c['takeaway_allowed']));
$a(count($eligible)>=3,'bundle fixture products');[$parent,$choiceA,$choiceB]=$eligible;
$bid=(int)$parent['id'];$ca=(int)$choiceA['id'];$cb=(int)$choiceB['id'];
$p->prepare('INSERT INTO item_bundles(bundle_item_id,choice_label,choice_min,choice_max) VALUES(?,?,?,?)')->execute([$bid,'QA Hall choice',1,1]);
$p->prepare("INSERT INTO item_bundle_components(bundle_item_id,component_item_id,mode,quantity,price_delta,sort_order) VALUES(?,?,'fixed',2,0,1),(?,?,'choice',1,0,2),(?,?,'choice',1,0,3)")->execute([$bid,(int)$item['id'],$bid,$ca,$bid,$cb]);
$bundleCases=[];
foreach(['local','public'] as $host){
 $o=$core->orders()->commit(['source'=>'staff','table_id'=>$table,'session_id'=>$session,'client_token'=>'ci-hall-bundle-'.$host.'-0001','actor_user_id'=>$admin,'items'=>[['id'=>$bid,'quantity'=>3,'bundle_choice_item_id'=>$ca],['id'=>$bid,'quantity'=>2,'bundle_choice_item_id'=>$cb]]]);
 $first=(int)$o['lines'][0]['order_item_id'];$second=(int)$o['lines'][1]['order_item_id'];
 $a($core->orderStaffActions()->adjustItemQuantity(['order_item_id'=>$first,'quantity'=>3,'fulfillment_quantities'=>['dine_in'=>2,'takeaway'=>1],'reason'=>'QA split','request_id'=>'ci-hall-bundle-split-'.$host.'-0001'],$user)['success'],'real split');
 $bundleCases[$host]=['first'=>$first,'second'=>$second,'order'=>(int)$o['order_id']];
}
$core->inventoryOrders()->processPending();
$before=$core->settlements()->account($session);$a($before['remaining_total']>0,'real open account');
// Fixture represents a clean, already-bound Local web tree; use normal application authentication and APIs.
file_put_contents($tmp.'/install.lock',json_encode(['format'=>'sokna-install-lock-v3','installation_id'=>$pair['id']],JSON_THROW_ON_ERROR));
file_put_contents($root.'/.sokna-installation.json',json_encode(['format'=>'sokna-local-installation-binding-v1','installation_id'=>$pair['id'],'web_version'=>trim(file_get_contents($root.'/VERSION.txt'))],JSON_THROW_ON_ERROR));
file_put_contents($tmp.'/config.php',"<?php\nreturn ".var_export($cfg,true).";\n",LOCK_EX);
$localServer=proc_open([PHP_BINARY,'-S','127.0.0.1:18083','-t',$root.'/public'],[0=>['file','/dev/null','r'],1=>['file',$tmp.'/local-browser-server.log','a'],2=>['file',$tmp.'/local-browser-server.log','a']],$pipes);
$a(is_resource($localServer),'Local HTTP server');
$browser=null;
try{
 $start();
 $a($sync($snapshot())['status']===200,'ordered authentication publication');
 $builder=$remote->publicProjectionBuilder();$publisher=$remote->publicEdgePublisher();$keys=$builder->remoteModelKeys();
 $publisher->seedProjectionInvalidations($keys,'qa.multiaudit');
 foreach($keys as $key){
   $models=$builder->remoteModelsFor([$key]);$a(count($models)===1&&$models[0]['model_key']===$key,'build '.$key);
   $r=$publisher->syncProjectionModelsV2(1000,1,12,null,[$key]);$a(($r['success']??false)===true,'publish '.$key.' '.json_encode($r));
   echo 'PASS actual signed model '.$key."\n";
 }
 $ex();
 [$sc,$cookie]=$login($loginName,$pw);$a($sc===303,'admin Public auth');
 foreach($keys as $key){if($key==='guest_operational')continue;$r=$http('/api/staff/read?model='.$key,null,$cookie);$a($r[0]===200&&is_array($r[1]['payload']??null),'read '.$key.' '.json_encode(array_slice($r,0,2)));}
 echo "PASS all staff models over authenticated HTTP\n";
 $path=$tmp.'/multi-browser-fixture.json';file_put_contents($path,json_encode(['public'=>$pair['base'],'local'=>'http://127.0.0.1:18083','installation_id'=>$pair['id'],'username'=>$loginName,'password'=>$pw,'new_password'=>$newPw,'limited_name'=>$limitedName,'limited_password'=>$limitedPw,'table_id'=>$table,'session_id'=>$session,'pending_order'=>(int)$o1['order_id'],'received_order'=>(int)$o2['order_id'],'product'=>$chosen['name'],'initial_total'=>$before['remaining_total'],'bundle_cases'=>$bundleCases],JSON_THROW_ON_ERROR),LOCK_EX);chmod($path,0600);
 $browser=proc_open(['python3',getenv('SOKNA_MULTI_BROWSER_SCRIPT'),$path,$tmp.'/browser-evidence'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
 $a(is_resource($browser),'browser launch');$deadline=microtime(true)+600;
 while(true){$status=proc_get_status($browser);if(!$status['running'])break;if(microtime(true)>$deadline)throw new RuntimeException('browser timeout');try{$relay->syncRealtimeExchange(5,12,(int)(microtime(true)*1000)+22000);$relayErrors=0;}catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){$relayErrors=($relayErrors??0)+1;echo "Transient isolated relay failure count ".$relayErrors."\n";if($relayErrors>=3)throw $e;}if(is_file($tmp.'/multi-auth-sync-request')&&!is_file($tmp.'/multi-auth-sync-done')){$a($sync($snapshot())['status']===200,'auth publication after verified ACK');touch($tmp.'/multi-auth-sync-done');}usleep(200000);}
 $exit=$status['exitcode'];proc_close($browser);$browser=null;unlink($path);
 $a($exit===0,'browser cases must all pass');
 foreach($bundleCases as $host=>$c){
 $a((int)$one('SELECT quantity FROM order_items WHERE id=?',[$c['first']])===1,'dine-in quantity '.$host);
 $a((int)$one("SELECT SUM(quantity) FROM order_items WHERE bundle_distribution_origin_id=? AND fulfillment_mode='takeaway'",[$c['first']])===2,'takeaway quantity '.$host);
 $a((int)$one('SELECT quantity FROM order_items WHERE id=?',[$c['second']])===2,'independent other choice '.$host);
 $a((int)$one('SELECT SUM(quantity) FROM order_items WHERE order_id=?',[$c['order']])===5,'neutral total '.$host);
}
$a($core->settlements()->account($session)['remaining_total']===$before['remaining_total'],'neutral split preserves account amount');
 echo "PASS authenticated browser and canonical Local readback\n";
}finally{if(is_resource($browser)){proc_terminate($browser);proc_close($browser);}if(is_resource($localServer)){proc_terminate($localServer);proc_close($localServer);}$stop();}
