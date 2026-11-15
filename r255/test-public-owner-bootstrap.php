<?php
declare(strict_types=1);
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalMac.php';
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveIdentityVerifier.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveOwnerConsentGuard.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveAttestationIssuer.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveInstanceKeyStore.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveOwnerBootstrapService.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveIndependentOwnerBridge.php';

use Sokna\Local\Domain\PublicEdge\PublicMoveIdentityVerifier as V;
use Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService as S;
use Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore as K;
use Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge as B;

$dsn=getenv('SOKNA_R255_TEST_DSN');
if (!$dsn) throw new RuntimeException('Disposable real MariaDB required; never run on production');
$db=new PDO($dsn,getenv('SOKNA_R255_TEST_USER')?:'root',getenv('SOKNA_R255_TEST_PASSWORD')?:'',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['public_move_owner_sessions','public_move_owner_accounts','public_move_owner_setup_claims',
 'public_move_owner_slots'] as $table) $db->exec('DROP TABLE IF EXISTS '.$table);
$sql=(string)file_get_contents(__DIR__.'/owner-bootstrap-schema-fixture.sql');
foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') $db->exec($statement);
$checks=0;
function ok255(bool $v,string $name):void{global $checks;if(!$v)throw new RuntimeException('FAIL '.$name);$checks++;echo "PASS $name\n";}
function denied255(callable $fn,string $name):void {
 try{$fn();}catch(InvalidArgumentException|RuntimeException){ok255(true,$name);return;}
 throw new RuntimeException('FAIL expected denial '.$name);
}
function cleanup255(string $path):void{foreach(scandir($path)?:[] as $f){if($f==='.'||$f==='..')continue;$x=$path.'/'.$f;if(is_dir($x)&&!is_link($x))cleanup255($x);else @unlink($x);}@rmdir($path);}
$svc=new S($db);$now=time();
$a='public-independent-owner-A01';$b='public-independent-owner-B02';$c='public-independent-owner-C03';
$local='local-independent-A01';
$claimA=$svc->stageClaim($a,$now);
$claimB=$svc->stageClaim($b,$now);
ok255(strlen($claimA['setup_token'])===64 && $claimA['expires_at']===$now+900,'trusted setup generates random short-lived claim');
ok255($svc->initialized($a)===false && $svc->initialized($b)===false,'fresh Public is not implicitly owned');
denied255(fn()=>$svc->stageClaim($a,$now),'active claim cannot be replaced');
denied255(fn()=>$svc->initialize($a,'1234','long-secure-password-A01',$now),'claim must be random full-length');
denied255(fn()=>$svc->initialize($a,bin2hex(random_bytes(32)),'long-secure-password-A01',$now),'incorrect claim denied');
denied255(fn()=>$svc->initialize($a,$claimA['setup_token'],'short',$now),'weak owner password denied');
ok255($svc->initialize($a,$claimA['setup_token'],'long-secure-password-A01',$now)['owner_created']===true,'first owner registered after trusted claim');
ok255($svc->initialized($a)===true && $svc->initialized($b)===false,'owner scoped to Public instance A only');
denied255(fn()=>$svc->initialize($a,$claimA['setup_token'],'different-new-password-A01',$now),'setup claim cannot be replayed');
denied255(fn()=>$svc->stageClaim($a,$now+1),'initialized owner cannot be overwritten from setup');
ok255($svc->initialize($b,$claimB['setup_token'],'long-secure-password-B02',$now)['must_login']===true,'independent Public B owner setup succeeds');
ok255($svc->login($a,'wrong-password',$now)['ok']===false,'bad owner password rejected');
$loginA=$svc->login($a,'long-secure-password-A01',$now);$tokenA=$loginA['token'];
$loginB=$svc->login($b,'long-secure-password-B02',$now);$tokenB=$loginB['token'];
ok255(preg_match('/^[a-f0-9]{64}$/D',$tokenA)===1 && $loginA['expires_at']===$now+1800,'owner session random, short-lived and server-side');
ok255($svc->resolve($tokenA,$now+1)['public_instance_id']===$a,'server resolves Public A, not Local identity');
ok255($svc->resolve($tokenB,$now+1)['public_instance_id']===$b,'server resolves Public B separately');
ok255($svc->resolve($tokenA,$now+1800)===null,'owner session expires');
ok255($svc->resolve('arbitrary-browser-claim',$now)===null,'untrusted browser role cannot resolve');
$root=sys_get_temp_dir().'/sokna-r255-bootstrap-'.bin2hex(random_bytes(8));
mkdir($root,0700);mkdir($root.'/web',0700);mkdir($root.'/secure',0700);
try {
 $keys=new K($root.'/secure',$root.'/web');
 $keys->provision($a,true);$keys->provision($b,true);
 $base='https://new.any-cafe.example/independent';
 $bridge=new B($svc,$keys,$base,$a,random_bytes(32));
 $challenge=V::challenge('move-owner-001',$local,'','',$base,$now);
 $csrf=$bridge->issueCsrf($tokenA,$challenge,$now);
 ok255(strlen($csrf)>=40,'CSRF is bound to real owner session and immutable Local challenge');
 $p=$bridge->attest($tokenA,$challenge,$csrf,'https://new.any-cafe.example','same-origin',$now+1);
 ok255($p['owner_session_authenticated']===true && $p['safe_to_cutover']===false,'independent Public owner may attest without any Local projection');
 ok255($p['owner_public_instance_id']===$a && $p['local_installation_id']===$local,'Public owner identity distinct from Local request identity');
 ok255($p['owner_grant_issued']===false && $p['nonce_claimed']===false,'no Local grant or nonce claim from Public proof');
 $env=$p['attestation'];$pub=base64_decode($env['payload']['public_key_b64'],true);$sig=base64_decode($env['signature_b64'],true);
 ok255(sodium_crypto_sign_verify_detached($sig,json_encode($env['payload'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$pub),'Ed25519 target statement signed by protected per-Public key');
 denied255(fn()=>$bridge->issueCsrf($tokenB,$challenge,$now),'owner from different Public instance cannot obtain token');
 denied255(fn()=>$bridge->attest($tokenB,$challenge,$csrf,'https://new.any-cafe.example','same-origin',$now+1),'owner from other customer cannot attest');
 denied255(fn()=>$bridge->attest($tokenA,$challenge,'bad','https://new.any-cafe.example','same-origin',$now+1),'CSRF spoof rejected');
 denied255(fn()=>$bridge->attest($tokenA,$challenge,$csrf,'https://attacker.example','same-origin',$now+1),'cross-origin request rejected');
 denied255(fn()=>$bridge->attest($tokenA,$challenge,$csrf,'https://new.any-cafe.example','cross-site',$now+1),'cross-site Fetch-Site rejected');
 denied255(fn()=>$bridge->attest($tokenA,$challenge,$csrf,'https://new.any-cafe.example','same-origin',$now+181),'expired Local challenge rejected');
 $changed=$challenge;$changed['target_base_url']='https://other.any-cafe.example/public';
 denied255(fn()=>$bridge->attest($tokenA,$changed,$csrf,'https://new.any-cafe.example','same-origin',$now+1),'destination substitution rejected');
 $changed=$challenge;$changed['operation_id']='move-owner-002';
 denied255(fn()=>$bridge->attest($tokenA,$changed,$csrf,'https://new.any-cafe.example','same-origin',$now+1),'operation CSRF reuse rejected');
 $changed=$challenge;$changed['local_installation_id']='local-another-B02';
 denied255(fn()=>$bridge->attest($tokenA,$changed,$csrf,'https://new.any-cafe.example','same-origin',$now+1),'Local request identity changed rejected');
 $svc->logout($tokenA);
 denied255(fn()=>$bridge->attest($tokenA,$challenge,$csrf,'https://new.any-cafe.example','same-origin',$now+1),'owner logout immediately revokes consent');
 denied255(fn()=>$bridge->issueCsrf('deadbeef',V::challenge('move-fresh-001','local-fresh-001','','',$base,$now),$now),'no owner cannot approve fresh Public');
 $claimC=$svc->stageClaim($c,$now,180);
 denied255(fn()=>$svc->initialize($c,$claimC['setup_token'],'long-secure-password-C03',$now+180),'expired setup claim denied');
 $claimD=$svc->stageClaim('public-independent-owner-D04',$now);
 for($i=0;$i<5;$i++)denied255(fn()=>$svc->initialize('public-independent-owner-D04',bin2hex(random_bytes(32)),'long-secure-password-D04',$now),'wrong setup attempt counted');
 denied255(fn()=>$svc->initialize('public-independent-owner-D04',$claimD['setup_token'],'long-secure-password-D04',$now),'locked setup cannot be brute-forced');
 $wrong='public-independent-owner-E05';$e=$svc->stageClaim($wrong,$now);
 ok255($svc->initialize($wrong,$e['setup_token'],'long-secure-password-E05',$now)['owner_created']===true,'another Public owner bootstrapped');
 for($i=0;$i<5;$i++)ok255($svc->login($wrong,'incorrect-password',$now)['ok']===false,'owner brute-force attempt denied '.($i+1));
 ok255($svc->login($wrong,'long-secure-password-E05',$now)['ok']===false,'password lockout even for correct secret');
 ok255($svc->login($wrong,'long-secure-password-E05',$now+901)['ok']===true,'lockout expires without account deletion');
}finally{cleanup255($root);}
echo "R255_PUBLIC_OWNER_BOOTSTRAP_PASS count=$checks\n";