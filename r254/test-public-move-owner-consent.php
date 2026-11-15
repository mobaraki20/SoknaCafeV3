<?php
declare(strict_types=1);
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveOwnerConsentGuard.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveInstanceKeyStore.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveAttestationIssuer.php';

use Sokna\PublicEdge\Security\PublicMoveOwnerConsentGuard as G;
use Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore as K;
use Sokna\PublicEdge\Security\PublicMoveAttestationIssuer as I;

$checks=0;
function ok(bool $value,string $label): void {global $checks;if(!$value)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function denied(callable $fn,string $label):void {try{$fn();}catch(InvalidArgumentException|RuntimeException $e){ok(true,$label);return;}throw new RuntimeException('DENIAL_MISSING '.$label);}
function ch(string $operation,string $local,string $target,int $now):array{return [
  'contract'=>'sokna-public-move-attestation-v1','operation_id'=>$operation,'local_installation_id'=>$local,'source_public_instance_id'=>'',
  'source_base_url'=>'','target_base_url'=>$target,'nonce'=>bin2hex(random_bytes(24)),'issued_at'=>$now,'expires_at'=>$now+180,
];}
function purge(string $dir):void {if(!is_dir($dir))return;foreach(scandir($dir)?:[] as $v){if($v==='.'||$v==='..')continue;$f=$dir.'/'.$v;if(is_dir($f)&&!is_link($f))purge($f);else @unlink($f);}@rmdir($dir);}
$now=1800000000;$localId='local-independent-aa12';$operation='move-independent-aa12';$target='https://my-new-cafe.example/edge';
$challenge=ch($operation,$localId,$target,$now);
$token=bin2hex(random_bytes(32));$serverKey=random_bytes(32);
$owner=['installation_id'=>$localId,'projection_id'=>'user:42','role'=>'admin','capabilities'=>[]];
$csrf=G::csrf($challenge,$token,$serverKey);
$a=G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5);
ok($a['owner_session_authenticated']===true && $a['approval_record_created']===false,'authenticated admin consent preflight');
ok($a['safe_to_cutover']===false && $a['nonce_claimed']===false,'guard does not cutover or consume nonce');
ok($a['target_base_url']===$target && $a['local_installation_id']===$localId,'owner consent scoped to installation');
$other=$owner;$other['installation_id']='local-other-999';denied(fn()=>G::authorize($challenge,$other,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'other Local installation rejected');
$other=$owner;$other['role']='staff';denied(fn()=>G::authorize($challenge,$other,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'non-admin staff rejected');
$other=$owner;$other['role']='manager';denied(fn()=>G::authorize($challenge,$other,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'non-admin manager rejected');
$other=$owner;$other['projection_id']='';denied(fn()=>G::authorize($challenge,$other,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'missing projection rejected');
denied(fn()=>G::authorize($challenge,null,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'unpaired Public no session is blocked');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://different-cafe.example',$target,$serverKey,$now+5),'cross-origin request rejected');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example.evil.com',$target,$serverKey,$now+5),'origin suffix injection rejected');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'http://my-new-cafe.example',$target,$serverKey,$now+5),'insecure request origin rejected');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example:8443',$target,$serverKey,$now+5),'different port rejected');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example/edge',$target,$serverKey,$now+5),'origin with path rejected');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5,'cross-site'),'cross-site fetch rejected');
denied(fn()=>G::authorize($challenge,$owner,$token,'forged','https://my-new-cafe.example',$target,$serverKey,$now+5),'forged CSRF rejected');
denied(fn()=>G::authorize($challenge,$owner,bin2hex(random_bytes(32)),$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'CSRF bound to session');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example',$target,random_bytes(32),$now+5),'CSRF bound to server key');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example','https://my-new-cafe.example/other',$serverKey,$now+5),'CSRF and consent bound to target path');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+180),'expired consent rejected');
denied(fn()=>G::authorize($challenge,$owner,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now-60),'future consent rejected');
$other=$challenge;$other['nonce']=bin2hex(random_bytes(24));denied(fn()=>G::authorize($other,$owner,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'nonce substitution invalidates CSRF');
$other=$challenge;$other['operation_id']='move-other-op';denied(fn()=>G::authorize($other,$owner,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'operation substitution invalidates CSRF');
$other=$challenge;$other['local_installation_id']='local-other-999';denied(fn()=>G::authorize($other,$owner,$token,$csrf,'https://my-new-cafe.example',$target,$serverKey,$now+5),'local id substitution rejected');
denied(fn()=>G::csrf($challenge,'weak-token',$serverKey),'weak session token rejected');
denied(fn()=>G::csrf($challenge,$token,'short'),'weak server secret rejected');
// Key provisioning and integrity: isolated outside the document root, never via public files.
$root=sys_get_temp_dir().'/sokna-r254-'.bin2hex(random_bytes(8));@mkdir($root,0700,true);
$web=$root.'/public';$storage=$root.'/protected';@mkdir($web,0700);@mkdir($storage,0700);
$vault=new K($storage,$web);$instanceId='public-unique-cafe-17';
try {
  denied(fn()=> $vault->provision($instanceId,false),'key provisioning needs independently authorized owner');
  $secret=$vault->provision($instanceId,true);
  ok(strlen($secret)===SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,'Ed25519 secret valid');
  ok(hash_equals($secret,$vault->load($instanceId)),'private key persists on protected storage');
  ok(hash_equals($secret,$vault->provision($instanceId,true)),'duplicate provisioning does not rotate key');
  $otherSecret=$vault->provision('public-other-cafe-18',true);
  ok(!hash_equals($secret,$otherSecret),'independent customer has different key');
  $env=I::issue($challenge,$target,$instanceId,$secret,$now+5);
  ok(sodium_crypto_sign_verify_detached(base64_decode($env['signature_b64'],true),json_encode($env['payload'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),base64_decode($env['payload']['public_key_b64'],true)),'persisted key signs public attestation');
  $files=glob($storage.'/move-identity-keys/*.key');ok(count($files)===2,'key stored outside webroot');
  ok(count(glob($web.'/*.key'))===0,'key never written to public directory');
  if(PHP_OS_FAMILY!=='Windows')ok((fileperms($files[0])&077)===0,'stored key has owner-only permissions');
  denied(fn()=> (new K($web,$web))->provision('public-new-cafe',true),'webroot key storage rejected');
  denied(fn()=> $vault->load('public-missing-cafe'),'missing key is not invented during read');
  denied(fn()=> $vault->provision('../escape',true),'path traversal instance identifier rejected');
  if(PHP_OS_FAMILY!=='Windows'){
    $file=$storage.'/move-identity-keys/'.hash('sha256',$instanceId).'.key';chmod($file,0644);
    denied(fn()=> $vault->load($instanceId),'loose private key permissions fail closed');chmod($file,0600);
    $linked=$storage.'/move-identity-keys/'.hash('sha256','public-link-cafe').'.key';symlink($file,$linked);
    denied(fn()=> $vault->load('public-link-cafe'),'symlinked key rejected');unlink($linked);
  }
  foreach(['https://coffee-beta.example','https://coffee-gamma.example/subfolder','https://cafe-delta.example/deep/dir','https://epsilon-cafe.example:8443/edge'] as $i=>$endpoint) {
    $c=ch('move-other-'.($i+10),'local-other-'.($i+10),$endpoint,$now);
    $t=bin2hex(random_bytes(32));$k=random_bytes(32);
    $who=['installation_id'=>$c['local_installation_id'],'projection_id'=>'admin:'.($i+10),'role'=>'admin'];
    $csrf2=G::csrf($c,$t,$k);
    $p=parse_url($endpoint);$origin='https://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
    $z=G::authorize($c,$who,$t,$csrf2,$origin,$endpoint,$k,$now+1);
    ok($z['safe_to_cutover']===false&&$z['target_base_url']===$endpoint,'arbitrary host/path consent '.$i);
  }
} finally {purge($root);}
echo "R254_PUBLIC_OWNER_CONSENT_TESTS_PASS count=$checks\n";
