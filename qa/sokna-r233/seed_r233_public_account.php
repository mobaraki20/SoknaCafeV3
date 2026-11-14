<?php
declare(strict_types=1);
// Disposable-ONLY integration fixture: real full Local and Public MariaDB installations.
if (getenv('GITHUB_ACTIONS')!=='true' || getenv('SOKNA_R233_DISPOSABLE_ONLY')!=='YES') throw new RuntimeException('REFUSE_NON_CI');
$tmp=realpath((string)getenv('RUNNER_TEMP'));$web=realpath((string)getenv('SOKNA_R213_WEB'));$pub=realpath((string)getenv('SOKNA_R228_PUBLIC'));
if (!$tmp||!$web||!$pub||!str_starts_with($web,$tmp.DIRECTORY_SEPARATOR)||!str_starts_with($pub,$tmp.DIRECTORY_SEPARATOR)) throw new RuntimeException('REFUSE_NON_TEMP');
$mode=(string)($argv[1]??'');
function fixtureRead(string $name):array {global $tmp;return json_decode((string)file_get_contents($tmp.'/'.$name),true,512,JSON_THROW_ON_ERROR);}
function fixtureWrite(string $name,array $data):void {global $tmp;$path=$tmp.'/'.$name;file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX);@chmod($path,0600);}
function expect(bool $ok,string $msg):void {if(!$ok) throw new RuntimeException('FAIL_R233_HTTP_'.$msg);echo 'PASS_R233_HTTP_'.$msg."\n";}
if(in_array($mode,['local-seed','local-relay','local-reproject'],true)){
 require_once $web.'/bootstrap.php';
 $cfg=require dirname($web).'/config.php';
 $core=\sokna_local_bootstrap($cfg);$pdo=$core->database();$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 if($mode==='local-seed'){
    $old='R233-Temporary-CI-Password-'.bin2hex(random_bytes(8));
    $new='R233-Temporary-CI-New-'.bin2hex(random_bytes(8));
    $uname='r233_http_'.bin2hex(random_bytes(5));
    $q=$pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,'operator',1)");
    $q->execute([$uname,password_hash($old,PASSWORD_DEFAULT),'R233 QA Temporary Staff']);$uid=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO remote_user_credentials(user_id,password_hash,credential_version,source_mode) VALUES(?,?,1,'local_password')")->execute([$uid,password_hash($old,PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,'remote_access',1)")->execute([$uid]);
    $rows=$core->publicProjectionBuilder()->authProjections();
    expect(in_array('user:'.$uid,array_column($rows,'projection_id'),true),'LOCAL_REMOTE_USER_PROJECTED');
    fixtureWrite('r233-http-credentials.json',['uid'=>$uid,'old'=>$old,'new'=>$new,'username'=>$uname]);
    fixtureWrite('r233-http-auth-projections.json',['rows'=>$rows]);
    return;
 }
 if($mode==='local-relay'){
    $pair=fixtureRead('r228-pairing.json');$login=fixtureRead('r233-http-credentials.json');$cmd=fixtureRead('r233-http-command.json');
    $cfg['installation']['id']=$pair['installation'];$cfg['public']['base_url']='http://127.0.0.1:18082';$cfg['public']['shared_secret']=$pair['secret'];
    $core=\sokna_local_bootstrap($cfg);$pdo=$core->database();
    $r=$core->publicEdgeRelay()->syncRealtimeExchange(8,5,(int)floor(microtime(true)*1000)+12000);
    expect((int)($r['processed']??0)===1,'LOCAL_RELAY_REAL_HTTP_PASSWORD_DISPATCH');
    $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$login['uid']]);
    expect(password_verify($login['new'],(string)$q->fetchColumn()),'LOCAL_PASSWORD_CHANGED_FROM_PUBLIC_HTTP');
    $q=$pdo->prepare("SELECT password_hash FROM remote_user_credentials WHERE user_id=? AND source_mode='local_password'");$q->execute([$login['uid']]);
    expect(password_verify($login['new'],(string)$q->fetchColumn()),'LOCAL_REMOTE_CREDENTIAL_ATOMIC_CHANGE');
    $q=$pdo->prepare('SELECT result_json FROM lan_staff_realtime_receipts WHERE request_id=?');$q->execute([$cmd['request_id']]);$memo=(string)$q->fetchColumn();
    expect($memo!==''&&!str_contains($memo,$login['old'])&&!str_contains($memo,$login['new']),'REAL_HTTP_LOCAL_RECEIPT_NEVER_EXPOSES_PASSWORD');
    $r2=$core->publicEdgeRelay()->syncRealtimeExchange(8,3,(int)floor(microtime(true)*1000)+8000);
    $q->execute([$cmd['request_id']]);expect($memo===(string)$q->fetchColumn(),'LOCAL_RETRY_NO_ADDITIONAL_PASSWORD_CHANGE');
    return;
 }
 if($mode==='local-reproject'){
    $login=fixtureRead('r233-http-credentials.json');$rows=$core->publicProjectionBuilder()->authProjections();
    $matched=array_values(array_filter($rows,static fn(array $v):bool=>($v['projection_id']??'')==='user:'.$login['uid']));
    expect(count($matched)===1&&password_verify($login['new'],(string)$matched[0]['password_hash']),'NEW_PUBLIC_PASSWORD_PROJECTION_PREPARED');
    fixtureWrite('r233-http-auth-projections.json',['rows'=>$rows]);return;
 }
}
if(in_array($mode,['public-seed','public-reproject','public-outage','public-online','public-check'],true)){
 require_once $pub.'/bootstrap.php';
 $cfg=require $pub.'/config.php';$core=\sokna_public_bootstrap($cfg);$pdo=$core->database();
 $pair=fixtureRead('r228-pairing.json');$installation=$pair['installation'];
 if($mode==='public-seed'||$mode==='public-reproject'){
    $count=$core->authProjections()->sync($installation,fixtureRead('r233-http-auth-projections.json')['rows']);
    $login=fixtureRead('r233-http-credentials.json');expect($count>=1,'PUBLIC_AUTH_PROJECTIONS_SYNCED');
    $q=$pdo->prepare('SELECT password_hash FROM auth_projections WHERE installation_id=? AND projection_id=?');$q->execute([$installation,'user:'.$login['uid']]);
    $nowHash=(string)$q->fetchColumn();expect(password_verify($mode==='public-seed'?$login['old']:$login['new'],$nowHash),'PUBLIC_PASSWORD_HASH_EXPECTED');
    if($mode==='public-seed'){
       $core->connectivity()->heartbeat($installation,'R233-CI','ready');
       $session=$core->publicSessions()->issue($installation,'user:'.$login['uid']);
       fixtureWrite('r233-http-session.json',['token'=>$session['token']]);
       expect(is_array($core->publicSessions()->resolve($session['token'])),'PUBLIC_REAL_USER_SESSION_ISSUED');
    }else{
       $session=fixtureRead('r233-http-session.json');
       expect($core->publicSessions()->resolve((string)$session['token'])===null,'OLD_PUBLIC_SESSION_REVOKED_AFTER_PASSWORD_CHANGE');
       $result=$core->loginService()->login($installation,$login['username'],$login['new'],'127.0.0.1','r233-qa-new-login');
       expect(($result['ok']??false)===true,'NEW_PASSWORD_ACCEPTED_BY_PUBLIC_LOGIN');
       $fail=$core->loginService()->login($installation,$login['username'],$login['old'],'127.0.0.1','r233-qa-old-login');
       expect(($fail['ok']??true)===false,'OLD_PASSWORD_REJECTED_BY_PUBLIC_LOGIN');
    }
    return;
 }
 if($mode==='public-outage'){
   $pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 SECOND) WHERE installation_id=?')->execute([$installation]);
   expect(($core->connectivity()->status($installation,45)['local_fresh']??true)===false,'PUBLIC_LOCAL_DISCONNECT_DETECTED');return;
 }
 if($mode==='public-online'){
   $core->connectivity()->heartbeat($installation,'R233-CI','ready');
   expect(($core->connectivity()->status($installation,45)['local_fresh']??false)===true,'PUBLIC_LOCAL_RECONNECTED');return;
 }
 if($mode==='public-check'){
   $login=fixtureRead('r233-http-credentials.json');$cmd=fixtureRead('r233-http-command.json');
   $q=$pdo->prepare('SELECT envelope_json,state FROM realtime_requests WHERE installation_id=? AND request_id=?');$q->execute([$installation,$cmd['request_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
   expect(is_array($row),'PUBLIC_PASSWORD_QUEUE_ROW_EXISTS');$env=(string)$row['envelope_json'];
   expect(!str_contains($env,$login['old'])&&!str_contains($env,$login['new']),'PUBLIC_QUEUED_PASSWORDS_ENCRYPTED_AT_REST');
   expect($row['state']==='committed','PUBLIC_PASSWORD_ACK_COMMITTED');return;
 }
}
throw new RuntimeException('UNKNOWN_R233_HTTP_PHASE_'.$mode);