<?php
declare(strict_types=1);
$root=dirname(__DIR__);
foreach([
 'apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalMac.php',
 'apps/local-web/src/Domain/PublicEdge/PublicMoveIdentityVerifier.php',
 'apps/public/src/Security/PublicMoveOwnerConsentGuard.php',
 'apps/public/src/Security/PublicMoveAttestationIssuer.php',
 'apps/public/src/Security/PublicMoveInstanceKeyStore.php',
 'apps/public/src/Security/PublicMoveOwnerBootstrapService.php',
 'apps/public/src/Security/PublicMoveIndependentOwnerBridge.php',
 'apps/public/src/Http/PublicMoveOwnerHttpAdapter.php'
] as $f)require $root.'/'.$f;
use Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService as S;
use Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore as K;
use Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge as B;
use Sokna\PublicEdge\Http\PublicMoveOwnerHttpAdapter as H;
use Sokna\Local\Domain\PublicEdge\PublicMoveIdentityVerifier as V;
$dsn=getenv('SOKNA_R255_TEST_DSN');if(!$dsn)throw new RuntimeException('Must use disposable MariaDB');
$db=new PDO($dsn,getenv('SOKNA_R255_TEST_USER')?:'root',getenv('SOKNA_R255_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['public_move_owner_sessions','public_move_owner_accounts','public_move_owner_setup_claims','public_move_owner_slots'] as $t)$db->exec('DROP TABLE IF EXISTS '.$t);
$sql=preg_replace('/^--.*$/m','',(string)file_get_contents(__DIR__.'/owner-bootstrap-schema-fixture.sql'));
foreach(explode(';',$sql) as $stmt)if(trim($stmt)!=='')$db->exec($stmt);
$checks=0;function checkH(bool $ok,string $msg):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$msg);$checks++;echo "PASS $msg\n";}
function replyH(array $r):array{return json_decode($r['body'],true,16,JSON_THROW_ON_ERROR);}
function cleanupH(string $path):void{foreach(scandir($path)?:[] as $f){if($f==='.'||$f==='..')continue;$p=$path.'/'.$f;if(is_dir($p)&&!is_link($p))cleanupH($p);else @unlink($p);}@rmdir($path);}
$now=time();$base='https://host.customer-one.example/public-zone';$instance='public-target-tenant-A01';
$svc=new S($db);$setup=$svc->stageClaim($instance,$now);
$temp=sys_get_temp_dir().'/r255-owner-http-'.bin2hex(random_bytes(6));mkdir($temp,0700);mkdir($temp.'/web',0700);mkdir($temp.'/secure',0700);
try {
 $keys=new K($temp.'/secure',$temp.'/web');$keys->provision($instance,true);
 $bridge=new B($svc,$keys,$base,$instance,random_bytes(32));$http=new H($svc,$bridge,$instance,$base);
 $h=['Origin'=>'https://host.customer-one.example','Sec-Fetch-Site'=>'same-origin'];
 $bad=$http->handle('setup',['Origin'=>'https://evil.example','Sec-Fetch-Site'=>'cross-site'],json_encode(['setup_token'=>$setup['setup_token'],'password'=>'secure-passphrase-123']),$now);
 checkH($bad['status']===403,'cross-site setup rejected');
 checkH($svc->initialized($instance)===false,'cross-site setup has no side effects');
 $bad=$http->handle('setup',$h,json_encode(['setup_token'=>bin2hex(random_bytes(32)),'password'=>'secure-passphrase-123']),$now);
 checkH($bad['status']===403,'invalid setup secret rejected');
 $good=$http->handle('setup',$h,json_encode(['setup_token'=>$setup['setup_token'],'password'=>'secure-passphrase-123']),$now);
 checkH($good['status']===200 && replyH($good)['status']==='owner_created','first owner setup via authenticated installer claim');
 checkH(!isset($good['headers']['Set-Cookie'])&&!str_contains($good['body'],$setup['setup_token']),'setup does not return secret or token');
 checkH($http->handle('setup',$h,json_encode(['setup_token'=>$setup['setup_token'],'password'=>'another-secure-pass']),$now)['status']===403,'setup cannot reset a registered owner');
 $bad=$http->handle('login',$h,json_encode(['password'=>'wrong']),$now);
 checkH($bad['status']===401,'invalid login refused');
 $login=$http->handle('login',$h,json_encode(['password'=>'secure-passphrase-123']),$now);
 $cookie=$login['headers']['Set-Cookie']??'';
 checkH($login['status']===200&&str_contains($cookie,'Secure; HttpOnly; SameSite=Strict'),'owner login creates secure cookie');
 checkH(str_contains($cookie,'Path=/public-zone/') && !str_contains($login['body'],'secure-passphrase'),'cookie scoped to Public base subfolder, password not exposed');
 $token=explode(';',explode('=',$cookie,2)[1],2)[0];
 checkH($svc->resolve($token,$now+1)['public_instance_id']===$instance,'login token exists server-side');
 $challenge=V::challenge('move-http-tenant-01','local-independent-A01','','',$base,$now);
 $prepare=$http->handle('consent_prepare',$h+['Cookie'=>'sokna_public_owner='.$token],json_encode(['challenge'=>$challenge]),$now);
 checkH($prepare['status']===200,'owner prepare works with real authenticated cookie');
 $csrf=replyH($prepare)['csrf'];
 checkH(strlen($csrf)>35 && replyH($prepare)['safe_to_cutover']===false,'CSRF token and no cutover');
 $sign=$http->handle('consent_sign',$h+['Cookie'=>'sokna_public_owner='.$token],json_encode(['challenge'=>$challenge,'csrf'=>$csrf]),$now+1);
 checkH($sign['status']===200&&replyH($sign)['proof']['owner_grant_issued']===false,'owner signs evidence but does not mint Local approval');
 checkH(replyH($sign)['safe_to_cutover']===false&&replyH($sign)['owner_grant_issued']===false,'HTTP never authorizes cutover');
 $other=$challenge;$other['operation_id']='move-http-tenant-02';
 checkH($http->handle('consent_sign',$h+['Cookie'=>'sokna_public_owner='.$token],json_encode(['challenge'=>$other,'csrf'=>$csrf]),$now+1)['status']===403,'consent CSRF cannot be reused for another operation');
 checkH($http->handle('consent_prepare',$h,json_encode(['challenge'=>$challenge]),$now)['status']===403,'no owner cookie cannot request authorization');
 checkH($http->handle('consent_prepare',['Origin'=>'https://attacker.example','Sec-Fetch-Site'=>'cross-site','Cookie'=>'sokna_public_owner='.$token],json_encode(['challenge'=>$challenge]),$now)['status']===403,'authenticated cookie cannot bypass same-origin policy');
 checkH($http->handle('consent_prepare',$h+['Cookie'=>'sokna_public_owner='.$token],json_encode(['challenge'=>$challenge]),$now+181)['status']===403,'expired challenge fails closed');
 $logout=$http->handle('logout',$h+['Cookie'=>'sokna_public_owner='.$token],'{}',$now+1);
 checkH($logout['status']===200 && str_contains($logout['headers']['Set-Cookie']??'','Max-Age=0'),'logout clears browser cookie');
 checkH($http->handle('consent_prepare',$h+['Cookie'=>'sokna_public_owner='.$token],json_encode(['challenge'=>$challenge]),$now+2)['status']===403,'logout revokes owner session in database');
 checkH($http->handle('not_a_route',$h,'{}',$now)['status']===404,'unknown endpoint is not exposed');
}finally{cleanupH($temp);}
echo "R255_OWNER_HTTP_CONTRACT_PASS count=$checks\n";