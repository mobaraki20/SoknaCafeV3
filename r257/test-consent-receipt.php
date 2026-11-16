<?php
declare(strict_types=1);

$root=dirname(__DIR__);
foreach([
'apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php',
'apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalMac.php',
'apps/local-web/src/Domain/PublicEdge/PublicMoveIdentityVerifier.php',
'apps/local-web/src/Domain/PublicEdge/PublicMoveReceiptVerifier.php',
'apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalLedger.php',
'apps/local-web/src/Domain/PublicEdge/PublicMoveLocalApprovalService.php',
'apps/public/src/Security/PublicMoveOwnerConsentGuard.php',
'apps/public/src/Security/PublicMoveAttestationIssuer.php',
'apps/public/src/Security/PublicMoveInstanceKeyStore.php',
'apps/public/src/Security/PublicMoveOwnerBootstrapService.php',
'apps/public/src/Security/PublicMoveIndependentOwnerBridge.php',
'apps/public/src/Security/PublicMoveConsentReceiptStore.php',
] as $p)require_once $root.'/'.$p;

use Sokna\Local\Domain\PublicEdge\PublicMoveIdentityVerifier as V;
use Sokna\Local\Domain\PublicEdge\PublicMoveReceiptVerifier as RV;
use Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger as Ledger;
use Sokna\Local\Domain\PublicEdge\PublicMoveLocalApprovalService as LocalApproval;
use Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService as Owner;
use Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore as Keys;
use Sokna\PublicEdge\Security\PublicMoveIndependentOwnerBridge as Bridge;
use Sokna\PublicEdge\Security\PublicMoveConsentReceiptStore as Receipt;

$dsn=getenv('SOKNA_R257_TEST_DSN');
if(!$dsn)throw new RuntimeException('Must use disposable MariaDB');
$db=new PDO($dsn,(string)(getenv('SOKNA_R257_TEST_USER')?:'root'),(string)(getenv('SOKNA_R257_TEST_PASSWORD')?:''),
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('SET SESSION innodb_lock_wait_timeout=2');
foreach(['public_move_auth_operations','public_move_auth_slots','public_move_owner_receipts',
'public_move_owner_sessions','public_move_owner_accounts','public_move_owner_setup_claims','public_move_owner_slots'] as $t)
 $db->exec('DROP TABLE IF EXISTS '.$t);
foreach(['r255/owner-bootstrap-schema-fixture.sql','r253/approval-ledger-schema-fixture.sql','r257/receipt-schema-fixture.sql'] as $path){
 $sql=preg_replace('/^--.*$/m','',(string)file_get_contents($root.'/'.$path));
 foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')$db->exec($statement);
}
$checks=0;
function okR(bool $value,string $name):void{global $checks;if(!$value)throw new RuntimeException('FAIL '.$name);$checks++;echo "PASS $name\n";}
function denyR(callable $fn,string $name):void{
 try{$fn();}catch(InvalidArgumentException|RuntimeException){okR(true,$name);return;}
 throw new RuntimeException('EXPECTED REJECTION '.$name);
}
function cleanR(string $p):void {
 foreach(scandir($p)?:[] as $n){if($n==='.'||$n==='..')continue;$x=$p.'/'.$n;if(is_dir($x)&&!is_link($x))cleanR($x);else @unlink($x);}
 @rmdir($p);
}
$now=time();$public='public-restaurant-amber-001';$local='local-restaurant-amber-001';
$base='https://amber.example/edge';$old='https://old-amber.example';
$op='move-amber-0001';$staffToken=bin2hex(random_bytes(32));
$temp=sys_get_temp_dir().'/r257-receipt-'.bin2hex(random_bytes(8));
mkdir($temp,0700);mkdir($temp.'/www',0700);mkdir($temp.'/secrets',0700);
try {
 $keys=new Keys($temp.'/secrets',$temp.'/www');
 $keys->provision($public,true);
 $owners=new Owner($db);
 $claim=$owners->stageClaim($public,$now);
 $owners->initialize($public,$claim['setup_token'],'long-secure-public-owner-123',$now);
 $login=$owners->login($public,'long-secure-public-owner-123',$now);
 okR($login['ok']===true,'target owner authenticated locally at Public');
 $challenge=V::challenge($op,$local,'public-old-amber-001',$old,$base,$now);
 $bridge=new Bridge($owners,$keys,$base,$public,random_bytes(32));
 $ownerCsrf=$bridge->issueCsrf($login['token'],$challenge,$now);
 $serverProof=$bridge->attest($login['token'],$challenge,$ownerCsrf,'https://amber.example','same-origin',$now+1);
 okR($serverProof['owner_session_authenticated']===true&&!$serverProof['safe_to_cutover'],'server-derived Public owner approval without cutover');
 $store=new Receipt($db,$keys);
 $saved=$store->create($challenge,$serverProof,$public,$now+2);
 okR($saved['already_recorded']===false,'destination receipt durably created');
 $repeat=$store->create($challenge,$serverProof,$public,$now+3);
 okR($repeat['already_recorded']===true,'duplicate operation returns original receipt');
 okR($saved['receipt_envelope']===$repeat['receipt_envelope'],'receipt id and signature immutable across retries');
 okR($store->status($op,$now)['status']==='owner_approved','Public receipt readback without migration');
 $signed=$saved['receipt_envelope'];$env=$saved['attestation'];
 $rProof=RV::verify($challenge,$env,$signed,$now+4);
 okR($rProof['valid_target_receipt']===true&&$rProof['local_admin_confirmed']===false,'receipt signature validates but Local approval still required');
 $tamper=$signed;$tamper['receipt']['nonce']=str_repeat('0',48);
 denyR(fn()=>RV::verify($challenge,$env,$tamper,$now+4),'tampered nonce cannot be used');
 $tamper=$signed;$tamper['receipt']['target_base_url']='https://elsewhere.example';
 denyR(fn()=>RV::verify($challenge,$env,$tamper,$now+4),'tampered destination cannot be used');
 $tamper=$signed;$tamper['signature_b64']=base64_encode(random_bytes(64));
 denyR(fn()=>RV::verify($challenge,$env,$tamper,$now+4),'forged receipt signature rejected');
 $otherChallenge=V::challenge('move-amber-0002',$local,'public-old-amber-001',$old,$base,$now);
 denyR(fn()=>RV::verify($otherChallenge,$env,$signed,$now+4),'receipt cannot authorize second operation');
 $otherChallenge=V::challenge($op,'local-restaurant-blue-002','public-old-blue-002','https://old-blue.example',$base,$now);
 denyR(fn()=>RV::verify($otherChallenge,$env,$signed,$now+4),'receipt cannot move another customer');
 $otherProof=$serverProof;$otherProof['owner_public_instance_id']='public-blue-002';
 denyR(fn()=>$store->create($challenge,$otherProof,$public,$now+4),'server-side approval cannot be cross-tenant');
 $different=$challenge;$different['target_base_url']='https://another.example';
 denyR(fn()=>$store->create($different,$serverProof,$public,$now+4),'existing operation cannot swap target');
 $ledger=new Ledger($db);
 okR($ledger->begin($challenge,$now)['status']==='issued','Local begins one durable operation');
 $localKey=random_bytes(32);$csrfKey=random_bytes(32);
 $admin=['authenticated'=>true,'role'=>'admin','installation_id'=>$local];
 $viewer=['authenticated'=>true,'role'=>'staff','installation_id'=>$local];
 $sessions=[$staffToken=>$admin,'0000000000000000000000000000000000000000000000000000000000000000'=>$viewer];
 $resolver=static fn(string $token)=>$sessions[$token]??null;
 $approval=new LocalApproval($ledger,$resolver,$localKey,$csrfKey);
 denyR(fn()=>$approval->issueCsrf('', $challenge,$env,$signed,$now+4),'missing Local login rejected');
 denyR(fn()=>$approval->issueCsrf(str_repeat('0',64),$challenge,$env,$signed,$now+4),'Local staff cannot approve migration');
 $csrf=$approval->issueCsrf($staffToken,$challenge,$env,$signed,$now+4);
 okR(strlen($csrf)>32,'Local admin receives bound confirmation token');
 denyR(fn()=>$approval->confirm($staffToken,'bad',$challenge,$env,$signed,$now+5),'Local CSRF required');
 $final=$approval->confirm($staffToken,$csrf,$challenge,$env,$signed,$now+5);
 okR($final['status']==='owner_approved'&&$final['safe_to_cutover']===false,'signed Public receipt plus Local admin stored as approved preflight only');
 $retry=$approval->confirm($staffToken,$csrf,$challenge,$env,$signed,$now+6);
 okR($retry['status']==='owner_approved','Local approval retry idempotent');
 okR($ledger->status($local,$op)['status']==='owner_approved','fresh Local DB readback records approval');
 $second=V::challenge('move-amber-0002',$local,'public-old-amber-001',$old,'https://other-amber.example',$now);
 denyR(fn()=>$ledger->begin($second,$now),'Local single-operation slot rejects concurrent move for same installation');
 okR($store->status($op,$challenge['expires_at'])['status']==='expired','expired Public approval never reports active');
 denyR(fn()=>RV::verify($challenge,$env,$signed,$challenge['expires_at']),'expired signed owner proof rejected');
 $claimOnce=$ledger->claimOnce($local,$op,$challenge['nonce'],$now+7);
 okR($claimOnce['nonce_claimed']===true&&$claimOnce['safe_to_cutover']===false,'nonce claimed once but no service cutover');
 denyR(fn()=>$ledger->claimOnce($local,$op,$challenge['nonce'],$now+8),'same nonce cannot be claimed twice');
}finally{cleanR($temp);}
echo "R257_PUBLIC_TO_LOCAL_CONSENT_PASS count=$checks\n";
