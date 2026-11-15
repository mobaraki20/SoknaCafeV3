<?php
declare(strict_types=1);
require dirname(__DIR__).'/apps/public/src/Auth/PublicSessionStore.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveOwnerConsentGuard.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveInstanceKeyStore.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveAttestationIssuer.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveOwnerSessionBridge.php';

use Sokna\PublicEdge\Auth\PublicSessionStore as S;
use Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore as K;
use Sokna\PublicEdge\Security\PublicMoveOwnerSessionBridge as B;

$dsn=getenv('SOKNA_R254_TEST_DSN');if(!$dsn)throw new RuntimeException('Must run isolated MariaDB in CI');
$db=new PDO($dsn,getenv('SOKNA_R254_TEST_USER')?:'root',getenv('SOKNA_R254_TEST_PASSWORD')?:'',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['public_sessions','auth_projections','installations'] as $t)$db->exec("DROP TABLE IF EXISTS `$t`");
$db->exec("CREATE TABLE installations(installation_id VARCHAR(128) PRIMARY KEY,active TINYINT NOT NULL,remote_enabled TINYINT NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE auth_projections(installation_id VARCHAR(128),projection_id VARCHAR(128),role VARCHAR(32),display_name VARCHAR(100),capabilities_json TEXT,preparation_areas_json TEXT,active TINYINT,PRIMARY KEY(installation_id,projection_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE public_sessions(installation_id VARCHAR(128),projection_id VARCHAR(128),token_hash CHAR(64) PRIMARY KEY,expires_at TIMESTAMP(6) NOT NULL) ENGINE=InnoDB");
function insertInstallation(PDO $db,string $id,string $role='admin',string $projection='user:1'):void {
 $db->prepare('INSERT INTO installations VALUES(?,1,1)')->execute([$id]);
 $db->prepare('INSERT INTO auth_projections VALUES(?,?,?, ?, ?, ?,1)')->execute([$id,$projection,$role,'Manager','[]','[]']);
}
function chall(string $local,string $target,int $now,string $op='move-cross-001'):array {return ['contract'=>'sokna-public-move-attestation-v1','operation_id'=>$op,'local_installation_id'=>$local,'source_public_instance_id'=>'','source_base_url'=>'','target_base_url'=>$target,'nonce'=>bin2hex(random_bytes(24)),'issued_at'=>$now,'expires_at'=>$now+180];}
$checks=0;function ok(bool $v,string $label):void {global $checks;if(!$v)throw new RuntimeException('FAIL '.$label);++$checks;echo "PASS $label\n";}
function denied(callable $fn,string $label):void {try{$fn();}catch(InvalidArgumentException|RuntimeException $e){ok(true,$label);return;}throw new RuntimeException('FAIL expected denial '.$label);}
function clearRoot(string $path):void {foreach(scandir($path)?:[] as $f){if($f==='.'||$f==='..')continue;$x=$path.'/'.$f;if(is_dir($x)&&!is_link($x))clearRoot($x);else @unlink($x);}@rmdir($path);}
$localA='local-tenant-A01';$localB='local-tenant-B02';$staff='local-tenant-C03';$fresh='local-tenant-D04';
insertInstallation($db,$localA);insertInstallation($db,$localB);insertInstallation($db,$staff,'staff');
$db->prepare('INSERT INTO installations VALUES(?,1,1)')->execute([$fresh]);
$sessions=new S($db);$tokA=$sessions->issue($localA,'user:1')['token'];$tokB=$sessions->issue($localB,'user:1')['token'];$tokC=$sessions->issue($staff,'user:1')['token'];
$now=time();$root=sys_get_temp_dir().'/sokna-r254-bridge-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/web',0700);mkdir($root.'/secure',0700);
try {
 $vault=new K($root.'/secure',$root.'/web');$instanceA='public-owner-A01';$secret=$vault->provision($instanceA,true);
 $baseA='https://destination-cafe-a.example/subfolder';$bridge=new B($sessions,$vault,$baseA,$instanceA,random_bytes(32));
 $challenge=chall($localA,$baseA,$now);
 $csrf=$bridge->issueCsrf($tokA,$challenge,$now);ok(is_string($csrf)&&strlen($csrf)>30,'CSRF issued from real stored admin session');
 $proof=$bridge->attest($tokA,$challenge,$csrf,'https://destination-cafe-a.example','same-origin',$now+1);
 ok(($proof['owner_session_authenticated']??null)===true&&$proof['safe_to_cutover']===false,'real session permits attestation not cutover');
 $env=$proof['attestation'];ok(sodium_crypto_sign_verify_detached(base64_decode($env['signature_b64'],true),json_encode($env['payload'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),base64_decode($env['payload']['public_key_b64'],true)),'real target protected key signs attestation');
 ok($proof['owner_grant_issued']===false && $proof['nonce_claimed']===false,'no remote proof treated as Local owner grant');
 denied(fn()=> $bridge->issueCsrf($tokB,$challenge,$now),'session from different tenant cannot request CSRF');
 denied(fn()=> $bridge->attest($tokB,$challenge,$csrf,'https://destination-cafe-a.example','same-origin',$now+1),'session from different tenant cannot sign');
 denied(fn()=> $bridge->attest($tokA,$challenge,'wrong','https://destination-cafe-a.example','same-origin',$now+1),'CSRF must come from same real session');
 denied(fn()=> $bridge->attest($tokA,$challenge,$csrf,'https://evil-cafe.example','same-origin',$now+1),'foreign origin denied');
 denied(fn()=> $bridge->attest($tokA,$challenge,$csrf,'https://destination-cafe-a.example','cross-site',$now+1),'cross-site browser request denied');
 denied(fn()=> $bridge->attest($tokA,$challenge,$csrf,'https://destination-cafe-a.example','same-origin',$now+181),'expired consent denied');
 denied(fn()=> $bridge->issueCsrf($tokC,chall($staff,$baseA,$now),$now),'staff role cannot request owner csrf');
 denied(fn()=> $bridge->issueCsrf('missing-cookie',chall($fresh,$baseA,$now),$now),'fresh unpaired Public has no projected owner session');
 denied(fn()=> $bridge->issueCsrf($tokA,chall($localB,$baseA,$now),$now),'cross-tenant challenge denied');
 $sessions->revoke($tokA);denied(fn()=> $bridge->issueCsrf($tokA,$challenge,$now),'revoked token denied by real session resolver');
 $tokA=$sessions->issue($localA,'user:1')['token'];$csrf=$bridge->issueCsrf($tokA,$challenge,$now);
 $db->prepare('UPDATE auth_projections SET active=0 WHERE installation_id=?')->execute([$localA]);
 denied(fn()=> $bridge->attest($tokA,$challenge,$csrf,'https://destination-cafe-a.example','same-origin',$now+1),'inactive admin projection rejected');
 $db->prepare('UPDATE auth_projections SET active=1 WHERE installation_id=?')->execute([$localA]);
 $db->prepare('UPDATE installations SET remote_enabled=0 WHERE installation_id=?')->execute([$localA]);
 denied(fn()=> $bridge->attest($tokA,$challenge,$csrf,'https://destination-cafe-a.example','same-origin',$now+1),'deactivated installation denied');
 $db->prepare('UPDATE installations SET remote_enabled=1 WHERE installation_id=?')->execute([$localA]);
 $db->prepare('UPDATE public_sessions SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?')->execute([hash('sha256',$tokA)]);
 denied(fn()=> $bridge->attest($tokA,$challenge,$csrf,'https://destination-cafe-a.example','same-origin',$now+1),'expired server session denied');
 $bridgeMissing=new B($sessions,new K($root.'/secure',$root.'/web'),$baseA,'public-not-provisioned',random_bytes(32));
 $tokB2=$sessions->issue($localB,'user:1')['token'];$cB=chall($localB,$baseA,$now);$cBtoken=$bridgeMissing->issueCsrf($tokB2,$cB,$now);
 denied(fn()=> $bridgeMissing->attest($tokB2,$cB,$cBtoken,'https://destination-cafe-a.example','same-origin',$now+1),'missing protected destination key fails closed');
} finally {clearRoot($root);}
echo "R254_REAL_SESSION_BRIDGE_PASS count=$checks\n";
