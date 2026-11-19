<?php
declare(strict_types=1);
/** Real protected-owner HTTP + actual Local R262 SQL + Public 35IG-derived 11 SQL.
 * Never touches a host; only disposable runner MariaDB and HTTPS loopback. */
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R300_QA_ONLY')!=='YES')throw new RuntimeException('R300_DISPOSABLE_CI_ONLY');
$localRoot=(string)getenv('R273_LOCAL_ROOT');$publicRoot=(string)getenv('R273_PUBLIC_ROOT');
$root=dirname($localRoot);$dataDir=(string)getenv('R273_LOCAL_DATA');$pass=(string)getenv('R273_DB_PASSWORD');
$id=(string)getenv('R273_INSTALLATION_ID');$basePath=getenv('R273_PUBLIC_BASE_PATH');$basePath=$basePath===false?'/new-public':$basePath;if(!in_array($basePath,['','/new-public'],true))throw new RuntimeException('INVALID_QA_PUBLIC_BASE_PATH');$base='https://r273-public.test:18443'.$basePath;
if(!is_file($localRoot.'/bootstrap.php')||!is_file($publicRoot.'/install.lock'))throw new RuntimeException('R273_REAL_SOURCE_MISSING');
require $localRoot.'/bootstrap.php';
$oldUrl='https://offline-old-public.example';$original=[
 'app'=>['timezone'=>'Asia/Tehran','data_dir'=>$dataDir],
 'installation'=>['id'=>$id],
 'db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_r273_local','charset'=>'utf8mb4','user'=>'root','pass'=>$pass],
 'public'=>['base_url'=>$oldUrl,'shared_secret'=>str_repeat('f',64)],
];
file_put_contents($root.'/config.php',"<?php\nreturn ".var_export($original,true).";\n");
$core=sokna_local_bootstrap($original);
$first=$core->migrations()->migrate('0059_menu_business_reconciliation_v4');
$core->defaultContentSeeder()->seed();
$next=$core->migrations()->migrate();$count=count($first)+count($next);
if($count!==83)throw new RuntimeException('LOCAL_R299_MIGRATIONS_'.$count);
$pdo=$core->database();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('r273-menu','R273 Demo Menu','active',10)");$menu=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO categories(category_key,name,sort_order) VALUES('r273-category','R273 QA',10)");$category=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id) VALUES(?,?)')->execute([$menu,$category]);
$pdo->prepare("INSERT INTO items(item_code,category_id,name,description,price,available,preparation_station) VALUES(?,?,'QA Tea','Test',150000,1,'bar')")->execute(['R273-ITEM',$category]);$item=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menu,$item]);
$pdo->exec("INSERT INTO cafe_tables(name,table_number,code,access_token) VALUES('R273 Cafe',7,'R273-7','r273-table-token-for-qa')");
$ledger=new \Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger($pdo);
$keys=(new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalKeyStore($dataDir,$localRoot.'/public'))->keys();
$session=bin2hex(random_bytes(32));
$resolver=static fn(string $token):?array=>hash_equals($session,$token)?['authenticated'=>true,'role'=>'admin','installation_id'=>$id]:null;
$approval=new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalApprovalService($ledger,$resolver,$keys['mac'],$keys['csrf']);
$flow=new \Sokna\Local\Domain\PublicEdge\PublicMoveLocalFlow($ledger,$approval,$id);
$start=$flow->begin($base,$oldUrl,'',time());$op=(string)$start['operation_id'];$challenge=$start['challenge'];
function httpOwner(string $base,string $name,array $payload,string $cookie=''):array{
 $headers="Origin: https://r273-public.test:18443\r\nSec-Fetch-Site: same-origin\r\nContent-Type: application/json\r\nAccept: application/json\r\n";
 if($cookie!=='')$headers.='Cookie: '.$cookie."\r\n";
 $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'timeout'=>12,'ignore_errors'=>true]]);
 $raw=@file_get_contents($base.'/api/v1/owner/'.$name,false,$ctx);
 $lines=$http_response_header??[];$status=0;$set='';
 foreach($lines as $h){if(preg_match('~^HTTP/\S+\s+(\d+)~',$h,$m))$status=(int)$m[1];if(stripos($h,'Set-Cookie:')===0)$set=trim(substr($h,11));}
 $body=is_string($raw)?json_decode($raw,true):null;if(!is_array($body))throw new RuntimeException('OWNER_HTTP_INVALID '.$name.' status='.$status);
 return ['status'=>$status,'body'=>$body,'cookie'=>$set];
}
// Legacy already-paired Public did not necessarily initialize an independent
// owner during the original install. Exercise authenticated STAFF upgrade over
// actual HTTP before attempting the ordinary owner consent/cutover workflow.
// All changes below are confined to the disposable runner MariaDB.
$publicLegacyDb=new PDO('mysql:host=127.0.0.1;dbname='.(string)getenv('R273_PUBLIC_DB').';charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$publicLock=json_decode((string)file_get_contents($publicRoot.'/install.lock'),true);
$publicInstanceId=(string)($publicLock['public_instance_id']??'');
if($publicInstanceId==='')throw new RuntimeException('PUBLIC_INSTANCE_LOCK_ID_MISSING');
$publicLegacyDb->prepare('DELETE FROM public_move_owner_sessions WHERE public_instance_id=?')->execute([$publicInstanceId]);
$publicLegacyDb->prepare('DELETE FROM public_move_owner_accounts WHERE public_instance_id=?')->execute([$publicInstanceId]);
$ownerCount=$publicLegacyDb->prepare('SELECT COUNT(*) FROM public_move_owner_accounts WHERE public_instance_id=?');
$ownerCount->execute([$publicInstanceId]);
if((int)$ownerCount->fetchColumn()!==0)throw new RuntimeException('LEGACY_OWNERLESS_FIXTURE_NOT_ESTABLISHED');
$publicLegacyDb->prepare("INSERT INTO auth_projections(installation_id,projection_id,username,display_name,role,password_hash,capabilities_json,active) VALUES(?,?,'qa-r273-admin','QA legacy authorized staff','admin',?,'[\"*\"]',1)")
    ->execute([$id,'r273-legacy-staff-admin',password_hash('qa-only-legacy-273', PASSWORD_DEFAULT)]);
$staffToken=bin2hex(random_bytes(32));
$publicLegacyDb->prepare('INSERT INTO public_sessions(installation_id,projection_id,token_hash,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 MINUTE))')
    ->execute([$id,'r273-legacy-staff-admin',hash('sha256',$staffToken)]);
$upgradeNoStaff=httpOwner($base,'upgrade_preflight',[]);
if($upgradeNoStaff['status']!==403)
    throw new RuntimeException('UNAUTHENTICATED_EXISTING_OWNER_UPGRADE_ALLOWED '.json_encode($upgradeNoStaff));
$staffCookie='sokna_staff='.$staffToken;
$upgradePrep=httpOwner($base,'upgrade_preflight',[], $staffCookie);
if($upgradePrep['status']!==200||
   ($upgradePrep['body']['result']['status']??'')!=='owner_setup_available')
    throw new RuntimeException('EXISTING_OWNER_UPGRADE_PREFLIGHT_FAILED '.json_encode($upgradePrep));
$badUpgrade=httpOwner($base,'upgrade_activate',[
    'csrf'=>'invalid','new_owner_password'=>'test-owner-only-strong-273'], $staffCookie);
if($badUpgrade['status']!==403)
    throw new RuntimeException('INVALID_OWNER_UPGRADE_CSRF_ACCEPTED '.json_encode($badUpgrade));
$beforeOwnerUpgrade=$publicLegacyDb->prepare('SELECT authority_epoch,authority_state,order_intake_enabled FROM installations WHERE installation_id=?');
$beforeOwnerUpgrade->execute([$id]);$beforeOwnerState=$beforeOwnerUpgrade->fetch(PDO::FETCH_ASSOC);
$activatedOwner=httpOwner($base,'upgrade_activate',[
    'csrf'=>$upgradePrep['body']['result']['csrf'],
    'new_owner_password'=>'test-owner-only-strong-273'], $staffCookie);
if($activatedOwner['status']!==200||
   ($activatedOwner['body']['result']['owner_initialized']??false)!==true)
    throw new RuntimeException('AUTHENTICATED_EXISTING_OWNER_ACTIVATION_FAILED '.json_encode($activatedOwner));
$ownerCount->execute([$publicInstanceId]);
$beforeOwnerUpgrade->execute([$id]);
if((int)$ownerCount->fetchColumn()!==1||
   $beforeOwnerUpgrade->fetch(PDO::FETCH_ASSOC)!==$beforeOwnerState)
    throw new RuntimeException('OWNER_ACTIVATION_MUTATED_PAIRING_OR_INTAKE');
echo 'R273_EXISTING_OWNER_UPGRADE_HTTP_PASS'.PHP_EOL;
$invalid=httpOwner($base,'login',['password'=>'wrong']);if($invalid['status']!==401)throw new RuntimeException('WRONG_PASSWORD_NOT_REJECTED');
$login=httpOwner($base,'login',['password'=>'test-owner-only-strong-273']);
if($login['status']!==200||$login['cookie']==='')throw new RuntimeException('REAL_OWNER_LOGIN_FAILED '.json_encode($login));
$cookie=explode(';',$login['cookie'])[0];
// Negative-path QA on the same real HTTP owner adapter and MariaDB.
// An anonymous caller or a caller without a valid CSRF must never receive
// an owner consent receipt, even when the challenge is otherwise valid.
$anonymous=httpOwner($base,'consent_prepare',['challenge'=>$challenge]);
if($anonymous['status']>=200 && $anonymous['status']<300)
    throw new RuntimeException('ANONYMOUS_OWNER_CONSENT_ALLOWED');
$unauthorizedSign=httpOwner($base,'consent_sign',['challenge'=>$challenge,'csrf'=>'invalid-csrf'], $cookie);
if($unauthorizedSign['status']>=200 && $unauthorizedSign['status']<300)
    throw new RuntimeException('INVALID_CSRF_OWNER_CONSENT_ALLOWED');
echo 'R273_NEGATIVE_OWNER_ANONYMOUS_CSRF_DENIAL_PASS'.PHP_EOL;
$prep=httpOwner($base,'consent_prepare',['challenge'=>$challenge],$cookie);
if($prep['status']!==200)throw new RuntimeException('REAL_OWNER_PREP_FAILED '.json_encode($prep));
$csrf=(string)($prep['body']['csrf']??'');
$signed=httpOwner($base,'consent_sign',['challenge'=>$challenge,'csrf'=>$csrf],$cookie);
if($signed['status']!==200)throw new RuntimeException('REAL_OWNER_CONSENT_FAILED '.json_encode($signed));
$body=$signed['body'];if(($body['move_kind']??'')!=='existing_paired'||!empty($body['pairing_code']))throw new RuntimeException('WRONG_EXISTING_OWNER_CONTRACT');
$attestation=(array)($body['proof']['attestation']??[]);$receipt=(array)($body['receipt_envelope']??[]);
if(!$attestation||!$receipt)throw new RuntimeException('SIGNED_OWNER_APPROVAL_MISSING');
$setup=json_decode((string)file_get_contents($base.'/setup/api.php?action=status'),true);
if(($setup['setup']['paired']??false)!==true||($setup['setup']['installation_id']??'')!==$id)throw new RuntimeException('TARGET_NOT_SAME_EXISTING_LOCAL');
$before=$core->publicAuthorityState()->epoch();
if(($core->publicAuthorityState()->phase())==='active')echo "R273_LOCAL_WAS_ACTIVE\n";
$confirmed=$flow->confirm($op,$session,$attestation,$receipt,time());
if(($confirmed['status']??'')!=='owner_approved')throw new RuntimeException('LOCAL_ADMIN_APPROVAL_NOT_STORED');
// Protect MIG-018/019: both realtime and deferred guest work block the
// independently authorized Public owner's forward-only authority offer.
// This is a real MariaDB test against the byte-pinned Public release.
// No guest work is deleted or reset by the *production* transfer path.
$publicGuardDb=new PDO('mysql:host=127.0.0.1;dbname='.(string)getenv('R273_PUBLIC_DB').';charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$getGuardState=static function()use($publicGuardDb,$id):array{
    $q=$publicGuardDb->prepare('SELECT authority_epoch,authority_state,remote_enabled,order_intake_enabled FROM installations WHERE installation_id=?');
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('MISSING_PUBLIC_GUARD_ROW');
    return $row;
};
$guardBefore=$getGuardState();
$publicGuardDb->prepare("INSERT INTO realtime_requests(installation_id,request_id,request_hash,kind,actor_projection_id,envelope_json,state,expires_at) VALUES(?,?,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','guest_order.submit','r273-test','{}','queued',DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")
    ->execute([$id,'r273-pending-realtime-test']);
$guardedPrep=httpOwner($base,'consent_prepare',['challenge'=>$challenge],$cookie);
if($guardedPrep['status']!==200)throw new RuntimeException('PENDING_REALTIME_PREP_FAILED');
$guardedOffer=httpOwner($base,'offer_existing',['challenge'=>$challenge,'csrf'=>$guardedPrep['body']['csrf'],'minimum_next_epoch'=>$before+1],$cookie);
if($guardedOffer['status']!==409||$getGuardState()!==$guardBefore)
    throw new RuntimeException('PENDING_REALTIME_GUEST_WORK_NOT_FENCED '.json_encode($guardedOffer));
// Leave queued realtime request until owner explicitly confirms Public-only discard.
$publicGuardDb->prepare("INSERT INTO deferred_work(installation_id,request_id,request_hash,kind,actor_projection_id,envelope_json,state,occurred_at) VALUES(?,?,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','guest_order.submit','r273-test','{}','pending_sync',UTC_TIMESTAMP())")
    ->execute([$id,'r273-pending-deferred-test']);
$guardedPrep=httpOwner($base,'consent_prepare',['challenge'=>$challenge],$cookie);
if($guardedPrep['status']!==200)throw new RuntimeException('PENDING_DEFERRED_PREP_FAILED');
$guardedOffer=httpOwner($base,'offer_existing',['challenge'=>$challenge,'csrf'=>$guardedPrep['body']['csrf'],'minimum_next_epoch'=>$before+1],$cookie);
if($guardedOffer['status']!==409||$getGuardState()!==$guardBefore)
    throw new RuntimeException('PENDING_DEFERRED_GUEST_WORK_NOT_FENCED '.json_encode($guardedOffer));
// Leave pending deferred request until owner explicitly confirms Public-only discard.
echo 'R273_NEGATIVE_PENDING_GUEST_WORK_PASS'.PHP_EOL;
// Rejected destination ownership and expired challenge must not be
// interpreted as permission to mutate the already-paired Public authority.
$foreignChallenge=$challenge;
$foreignChallenge['local_installation_id']='foreign-cafe-qa-273';
$foreignPrep=httpOwner($base,'consent_prepare',['challenge'=>$foreignChallenge],$cookie);
if($foreignPrep['status']===200){
    $foreignOffer=httpOwner($base,'offer_existing',[
        'challenge'=>$foreignChallenge,
        'csrf'=>$foreignPrep['body']['csrf'],
        'minimum_next_epoch'=>$before+1,
    ],$cookie);
    if($foreignOffer['status']!==409)
        throw new RuntimeException('FOREIGN_LOCAL_OWNER_OFFER_ACCEPTED '.json_encode($foreignOffer));
}else if($foreignPrep['status']<400||$foreignPrep['status']>=500){
    throw new RuntimeException('FOREIGN_LOCAL_PREFLIGHT_UNEXPECTED '.json_encode($foreignPrep));
}
if($getGuardState()!==$guardBefore)
    throw new RuntimeException('FOREIGN_OWNER_CHECK_MUTATED_AUTHORITY');
$expiredChallenge=$challenge;
$expiredChallenge['issued_at']=time()-900;
$expiredChallenge['expires_at']=$expiredChallenge['issued_at']+300;
$expired=httpOwner($base,'consent_prepare',['challenge'=>$expiredChallenge],$cookie);
if($expired['status']<400||$expired['status']>=500||$getGuardState()!==$guardBefore)
    throw new RuntimeException('EXPIRED_OWNER_CHALLENGE_ACCEPTED '.json_encode($expired));
echo 'R273_NEGATIVE_FOREIGN_EXPIRED_OWNER_PASS'.PHP_EOL;
// Do not claim deletion can safely undo a command currently leased to Local.
$publicGuardDb->prepare("INSERT INTO realtime_requests(installation_id,request_id,request_hash,kind,actor_projection_id,envelope_json,state,lease_expires_at,expires_at) VALUES(?,?,'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc','guest_order.submit','r275-test','{}','claimed',DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))")
    ->execute([$id,'r275-active-lease-test']);
$activePrep=httpOwner($base,'consent_prepare',['challenge'=>$challenge],$cookie);
$blocked=httpOwner($base,'offer_existing',['challenge'=>$challenge,'csrf'=>$activePrep['body']['csrf'],
    'minimum_next_epoch'=>$before+1,'discard_pending_guest_work'=>true],$cookie);
if($blocked['status']!==409||($blocked['body']['error']??'')!=='destination_claim_active'||
   $getGuardState()!==$guardBefore)throw new RuntimeException('ACTIVE_LEASE_DISCARD_NOT_BLOCKED '.json_encode($blocked));
$publicGuardDb->prepare("UPDATE realtime_requests SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 SECOND) WHERE installation_id=? AND request_id=?")
    ->execute([$id,'r275-active-lease-test']);
echo 'R275_ACTIVE_LEASE_PRESERVED_THEN_SAFE_RETRY_PASS'.PHP_EOL;
$offerPrep=httpOwner($base,'consent_prepare',['challenge'=>$challenge],$cookie);
if($offerPrep['status']!==200)throw new RuntimeException('OWNER_OFFER_CSRF_DENIED');
$offer=httpOwner($base,'offer_existing',['challenge'=>$challenge,'csrf'=>$offerPrep['body']['csrf'],
    'minimum_next_epoch'=>$before+1,'discard_pending_guest_work'=>true],$cookie);
if($offer['status']!==200)throw new RuntimeException('OWNER_DISCARD_OFFER_FAILED '.json_encode($offer));
$checkState=$publicGuardDb->prepare("SELECT state,error_code FROM realtime_requests WHERE installation_id=? AND request_id=?");
$checkState->execute([$id,'r273-pending-realtime-test']);$rt=$checkState->fetch(PDO::FETCH_ASSOC);
if(($rt['state']??'')!=='cancelled'||($rt['error_code']??'')!=='owner_confirmed_public_move_discard')
    throw new RuntimeException('OWNER_DISCARD_REALTIME_INCOMPLETE');
$checkState=$publicGuardDb->prepare("SELECT state,error_code FROM deferred_work WHERE installation_id=? AND request_id=?");
$checkState->execute([$id,'r273-pending-deferred-test']);$df=$checkState->fetch(PDO::FETCH_ASSOC);
if(($df['state']??'')!=='rejected'||($df['error_code']??'')!=='owner_confirmed_public_move_discard')
    throw new RuntimeException('OWNER_DISCARD_DEFERRED_INCOMPLETE');
echo 'R275_OWNER_CONFIRMED_PUBLIC_ONLY_DISCARD_PASS'.PHP_EOL;
$code=(string)($offer['body']['owner_authority_code']??'');$epoch=(int)($offer['body']['owner_next_epoch']??0);
if(!preg_match('/^[A-F0-9]{8}(?:-[A-F0-9]{8}){2}$/D',$code)||$epoch<=$before)throw new RuntimeException('OWNER_OFFER_INVALID');
if(($cfg=require $root.'/config.php')['public']['base_url']!==$oldUrl)throw new RuntimeException('CONFIG_CHANGED_BEFORE_REMOTE_PROOF');
$move=new \Sokna\Local\Domain\PublicEdge\PublicMoveSimpleReplacement($core,$ledger,$id,$dataDir);
$started=$move->start($op,$code,1,'existing_paired',$epoch);
if(($started['status']??'')!=='sync_pending')throw new RuntimeException('RECEIPT_NOT_USED_TO_START');
if((require $root.'/config.php')['public']['base_url']!==$oldUrl)throw new RuntimeException('OLD_CONFIG_CHANGED_PRE_SYNC');
$done=false;
for($n=0;$n<12;$n++){
 $result=$move->finish($op);
 echo 'R273_FINISH_'.$n.'='.json_encode(['status'=>$result['status']??'','media_remaining'=>$result['media_remaining']??null],JSON_UNESCAPED_SLASHES)."\n";
 if(($result['completed']??false)===true){$done=true;break;}
 if(!in_array($result['status']??'',['sync_retry','activation_retry'],true))throw new RuntimeException('UNEXPECTED_STAGE_'.json_encode($result));
}
if(!$done)throw new RuntimeException('EXISTING_MOVE_DID_NOT_COMPLETE');
$new=require $root.'/config.php';if(($new['public']['base_url']??'')!==$base)throw new RuntimeException('NEW_CONFIG_NOT_COMMITTED');
$health=json_decode((string)file_get_contents($base.'/health'),true);
if(($health['database']??'')!=='ready'||($health['pending_migrations']??-1)!==0)throw new RuntimeException('NEW_PUBLIC_HEALTH_FAILED');
$check=new PDO('mysql:host=127.0.0.1;dbname='.(string)getenv('R273_PUBLIC_DB').';charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$check->prepare('SELECT authority_epoch,authority_state,remote_enabled,order_intake_enabled FROM installations WHERE installation_id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
if($row['authority_state']!=='active'||(int)$row['order_intake_enabled']!==1||(int)$row['authority_epoch']!==$epoch)throw new RuntimeException('TARGET_ACTIVATION_NOT_VERIFIED '.json_encode($row));
if($move->status()['active']??false)throw new RuntimeException('PENDING_LOCAL_OPERATION_NOT_CLEARED');
// MIG-020: a ready flag is not enough. Submit an actual guest command via
// the byte-pinned Public HTTP endpoint, consume it with the real Local relay,
// and verify exactly one committed guest order despite a duplicate delivery.
$connected=\Sokna\Local\Core\Bootstrap::fromArray($new);
$guestPayload=[
 'installation_id'=>$id,
 'table_token'=>'r273-table-token-for-qa',
 'device_token'=>'r273-guest-device-00001',
 'client_token'=>'r273-guest-client-token-00000001',
 'items'=>[['id'=>$item,'quantity'=>1,'expected_price'=>150000]],
];
$guestJson=json_encode($guestPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$guestContext=stream_context_create(['http'=>[
 'method'=>'POST',
 'header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",
 'content'=>$guestJson,'timeout'=>12,'ignore_errors'=>true,
]]);
$beforeGuest=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_source='guest'")->fetchColumn();
$rawGuest=@file_get_contents($base.'/api/guest/order',false,$guestContext);
$guestReply=is_string($rawGuest)?json_decode($rawGuest,true):null;
if(!is_array($guestReply)||($guestReply['success']??null)!==true){
  throw new RuntimeException('R273_REAL_GUEST_HTTP_NOT_ACCEPTED '.json_encode($guestReply));
}
$guestAfter=$beforeGuest;
for($guestAttempt=0;$guestAttempt<4;$guestAttempt++){
  $connected->publicEdgeRelay()->syncRealtimeOnly(5,5);
  $guestAfter=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_source='guest'")->fetchColumn();
  if($guestAfter>$beforeGuest)break;
}
if($guestAfter!==$beforeGuest+1)
  throw new RuntimeException('R273_GUEST_COMMAND_NOT_DELIVERED_ONCE before='.$beforeGuest.' after='.$guestAfter);
$secondRaw=@file_get_contents($base.'/api/guest/order',false,$guestContext);
$secondReply=is_string($secondRaw)?json_decode($secondRaw,true):null;
$connected->publicEdgeRelay()->syncRealtimeOnly(5,5);
$afterDuplicate=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_source='guest'")->fetchColumn();
if($afterDuplicate!==$beforeGuest+1)
  throw new RuntimeException('R273_DUPLICATE_GUEST_COMMAND_CREATED_SECOND_ORDER '.json_encode($secondReply));
echo 'R273_REAL_POST_MOVE_GUEST_ORDER_ONCE_PASS'.PHP_EOL;
echo json_encode(['ok'=>true,'test'=>'R273_REAL_EXISTING_PAIRED_MOVE_HTTP_OWNERS_DB','migrations_local'=>82,'migrations_public'=>11,'owner_http'=>true,'same_local_verified'=>true,'epoch'=>$epoch,'full_sync_before_cutover'=>true,'guest_intake_active'=>true],JSON_UNESCAPED_SLASHES)."\n";
