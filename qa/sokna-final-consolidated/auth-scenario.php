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
try{
 $start();$legacy=$sync(['projections'=>$old['projections']]);$a($legacy['status']===200,'Public-first legacy initial compatibility');$r=$sync($old);$a($r['status']===200&&$r['body']['ok']===true,'signed auth sync '.json_encode($r));$a((int)$pubOne('SELECT COUNT(*) FROM auth_projections WHERE installation_id=? AND projection_id=?',[$pair['id'],'user:'.$localId])===0,'PIN did not project');
 [$status,$cookie,$headers]=$login($staffDef['username'],$staffDef['password']);$a($status===303&&$cookie!==''&&str_contains($headers['set-cookie'],'Path='.($pair['sub']===''?'/':$pair['sub'].'/')),'real login cookie path');
 $a($who($cookie)===200,'real session');
 $sync($old);$a($who($cookie)===200,'identical signed sync preserves session');
 // Same nonce must be rejected; sign canonical path, independent of public subfolder.
 $path='/api/v1/local/auth-projections';$nonce=bin2hex(random_bytes(16));$ts=(string)time();$raw=json_encode($old,JSON_THROW_ON_ERROR);$sig=hash_hmac('sha256',implode("\n",['sokna-relay-v1','POST',$path,$ts,$nonce,hash('sha256',$raw)]),$pair['secret']);
 $hdr=['X-Sokna-Installation: '.$pair['id'],'X-Sokna-Timestamp: '.$ts,'X-Sokna-Nonce: '.$nonce,'X-Sokna-Signature: '.$sig];
 $a($http($path,$old,'',$hdr)[0]===200,'signed manual request');$a($http($path,$old,'',$hdr)[0]===409,'same nonce replay rejected');
 $bad=$hdr;$bad[2]='X-Sokna-Nonce: '.bin2hex(random_bytes(16));$a($http($path,$old,'',$bad)[0]===401,'tampered HMAC rejected');
 $invalid=$client->postRaw($path,['unexpected'=>[]],5);$a($invalid['status']===400&&$who($cookie)===200,'malformed projection not revoke');
 // Identity collision must roll back the entire snapshot, not overwrite another user.
 $collision=$snapshot();$collision['projections'][1]['username']=$collision['projections'][0]['username'];
 $conflict=$sync($collision);$a($conflict['status']>=400&&$who($cookie)===200,'collision transaction rollback');
 $o=$order('ci-z-realtime-order-0001',4);$core->inventoryOrders()->processPending();$line=(int)$o['lines'][0]['order_item_id'];
 $ex(); // actual signed heartbeat over HTTP
 $forbidden=$send($envelope('ci-z-forbidden-payment-001','settlement.commit',['session_id'=>$session]),$cookie);$a($forbidden[0]===403,'floor cannot settle through Public');
 $id='ci-z-adjust-00000001';$env=$envelope($id,'hall.order_item_adjust',['order_item_id'=>$line,'quantity'=>3,'reason'=>'QA HTTP relay','request_id'=>$id]);
 $r=$send($env,$cookie);$a($r[0]===202,'enqueue allowed '.json_encode(array_slice($r,0,2)));
 $r=$send($env,$cookie);$a($r[0]===200&&$r[1]['deduplicated']===true,'duplicate enqueue');
 touch($tmp.'/z-drop-acks');$r=$ex();$a($r['processed']===1,'relay processed '.json_encode($r));
 $a((int)$one('SELECT quantity FROM order_items WHERE id=?',[$line])===3,'real mutation once');
 $a((int)$one('SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE request_id=?',[$id])===1,'ACK loss durable');
 $stop();$offline=false;try{$client->postRaw('/api/v1/local/realtime/exchange',['protocol'=>2,'max_requests'=>0],2);}catch(\Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException $e){$offline=true;}$a($offline,'actual socket outage');
 $a((int)$one('SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE request_id=?',[$id])===1,'ACK survives outage');
 unlink($tmp.'/z-drop-acks');$start();$ex();$r=$result($id,$cookie);$a($r[0]===200&&$r[1]['state']==='committed','recover ACK '.json_encode(array_slice($r,0,2)));$a((int)$one('SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE request_id=?',[$id])===0,'ACK outbox cleared');$a((int)$one('SELECT COUNT(*) FROM order_item_adjustments WHERE order_item_id=?',[$line])===1,'no duplicate mutation');
 // Queue from stale but still-valid Public projection after Local revokes remote access.
 $staffDef['capabilities']=['orders_floor'];$staffDef['password']='';$core->adminControls()->saveUser($staffDef,$user);
 $a($who($cookie)===200,'public stale projection explicitly retained');
 $id2='ci-z-revoked-adjust-0002';$env2=$envelope($id2,'hall.order_item_adjust',['order_item_id'=>$line,'quantity'=>2,'reason'=>'QA revoked remote','request_id'=>$id2]);$r=$send($env2,$cookie);$a($r[0]===202,'stale Public accepted queue');
 $ex();$r=$result($id2,$cookie);$state=$r[1]['state']??'';$err=$r[1]['error_code']??'';$qty=(int)$one('SELECT quantity FROM order_items WHERE id=?',[$line]);
 if($mode==='baseline')$a($state==='committed'&&$qty===2,'expected baseline defect '.json_encode([$state,$err,$qty]));
 else $a($state==='rejected'&&$err==='remote_access_revoked'&&$qty===3,'revoked remote command blocked '.json_encode([$state,$err,$qty]));
 // Local onsite use remains authorized after remote access removal.
 $localReq=['order_item_id'=>$line,'quantity'=>1,'reason'=>'QA local access retained','request_id'=>'ci-z-local-adjust-0003'];$a($core->orderStaffActions()->adjustItemQuantity($localReq,['id'=>$staffId])['success'],'onsite authority retained');
 $sync($snapshot());$a($who($cookie)===401,'projection removal revokes cookie');
 [$status,$c2]=$login($otherDef['username'],$otherDef['password']);$a($status===303,'second account unaffected');
 $new='QA-Second-New-Password-2026';$core->account()->changePassword(['current_password'=>$otherDef['password'],'new_password'=>$new,'confirm_password'=>$new],['id'=>$otherId]);
 $latest=$snapshot();$sync($latest);$a($who($c2)===401,'password sync revokes old token');$a($login($otherDef['username'],$otherDef['password'])[0]===401,'old password rejected');$a($login($otherDef['username'],$new)[0]===303,'new password works');
 // Reject an old snapshot signed with a fresh nonce and preserve new credentials.
 $stale=$sync($old);$a($stale['status']===409&&$stale['body']['error']==='auth_snapshot_stale','stale ordered snapshot rejected');
 $changed=$latest;$changed['projections'][0]['display_name']='conflicting replay';$conflict=$sync($changed);$a($conflict['status']===409&&$conflict['body']['error']==='auth_snapshot_conflict','same sequence conflict rejected');
 $downgrade=$sync(['projections'=>$latest['projections']]);$a($downgrade['status']===409&&$downgrade['body']['error']==='auth_snapshot_order_required','legacy downgrade rejected');
 $wrongEpoch=$latest;$wrongEpoch['auth_snapshot_epoch']++;$wrongEpoch['auth_snapshot_sequence']++;$wrong=$sync($wrongEpoch);$a($wrong['status']===409&&$wrong['body']['error']==='auth_snapshot_epoch_mismatch','wrong epoch rejected');
 $stop();$start();$again=$sync($latest);$a($again['status']===200,'durable replay after server restart');
 $fresh=$snapshot();$a($fresh['auth_snapshot_sequence']>$latest['auth_snapshot_sequence'],'persisted monotonic sequence');$r=$sync($fresh);$a($r['status']===200,'new ordered snapshot');
 $a($login($otherDef['username'],$new)[0]===303,'authoritative state retained');
 $a($login($otherDef['username'],$otherDef['password'])[0]===401,'old password remains rejected');
 $oldAccepted=false;
 $core->inventoryOrders()->processPending();foreach($ingredients as $i)$a($balance($i['inventory_item_id'])===$initial[$i['inventory_item_id']]-(int)$i['quantity_base'],'exact remaining inventory');
 echo json_encode(['status'=>'PASS','mode'=>$mode,'real_http'=>true,'two_databases'=>true,'signed_replay_denied'=>true,'collision_rollback'=>true,'durable_ack_recovery'=>true,'local_role_preserved'=>true,'remote_revocation_guard'=>$mode!=='baseline','baseline_defect_reproduced'=>$mode==='baseline','stale_snapshot_reaccepted'=>$oldAccepted,'password_changed'=>true,'inventory_exact'=>true],JSON_THROW_ON_ERROR)."\n";
}finally{$stop();}
