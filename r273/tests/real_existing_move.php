<?php
declare(strict_types=1);
/** Real protected-owner HTTP + actual Local R262 SQL + Public 35IG-derived 11 SQL.
 * Never touches a host; only disposable runner MariaDB and HTTPS loopback. */
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('R273_CI_ONLY');
$localRoot=(string)getenv('R273_LOCAL_ROOT');$publicRoot=(string)getenv('R273_PUBLIC_ROOT');
$root=dirname($localRoot);$dataDir=(string)getenv('R273_LOCAL_DATA');$pass=(string)getenv('R273_DB_PASSWORD');
$id=(string)getenv('R273_INSTALLATION_ID');$base='https://r273-public.test:18443/new-public';
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
if($count!==82)throw new RuntimeException('LOCAL_BASELINE_MIGRATIONS_'.$count);
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
$offerPrep=httpOwner($base,'consent_prepare',['challenge'=>$challenge],$cookie);
if($offerPrep['status']!==200)throw new RuntimeException('OWNER_OFFER_CSRF_DENIED');
$offer=httpOwner($base,'offer_existing',['challenge'=>$challenge,'csrf'=>$offerPrep['body']['csrf'],'minimum_next_epoch'=>$before+1],$cookie);
if($offer['status']!==200)throw new RuntimeException('OWNER_OFFER_FAILED '.json_encode($offer));
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
echo json_encode(['ok'=>true,'test'=>'R273_REAL_EXISTING_PAIRED_MOVE_HTTP_OWNERS_DB','migrations_local'=>82,'migrations_public'=>11,'owner_http'=>true,'same_local_verified'=>true,'epoch'=>$epoch,'full_sync_before_cutover'=>true,'guest_intake_active'=>true],JSON_UNESCAPED_SLASHES)."\n";
