<?php
declare(strict_types=1);

require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalMac.php';
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveIdentityVerifier.php';
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalLedger.php';
require dirname(__DIR__).'/apps/public/src/Security/PublicMoveAttestationIssuer.php';

use Sokna\Local\Domain\PublicEdge\PublicMoveApprovalLedger as L;
use Sokna\Local\Domain\PublicEdge\PublicMoveIdentityVerifier as V;
use Sokna\Local\Domain\PublicEdge\PublicMoveApprovalMac as Mac;
use Sokna\PublicEdge\Security\PublicMoveAttestationIssuer as Issuer;

$dsn = getenv('SOKNA_R253_TEST_DSN');
if (!is_string($dsn) || $dsn === '') throw new RuntimeException('MariaDB test database DSN is mandatory; no fallback to production');
$user = (string)getenv('SOKNA_R253_TEST_USER');
$password = (string)getenv('SOKNA_R253_TEST_PASSWORD');
$db = new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('SET SESSION innodb_lock_wait_timeout = 2');
$sql = file_get_contents(__DIR__.'/approval-ledger-schema-fixture.sql');
if (!is_string($sql)) throw new RuntimeException('fixture schema missing');
foreach (explode(';',$sql) as $statement) if (trim($statement)!=='') $db->exec($statement);
$ledger = new L($db);
$checks=0;
function ok(bool $condition,string $desc): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAILED: '.$desc);
    $checks++;
    echo 'PASS '.$desc."\n";
}
function denied(callable $action,string $desc): void {
    try { $action(); } catch (RuntimeException|InvalidArgumentException $e) { ok(true,$desc); return; }
    throw new RuntimeException('EXPECTED_DENIAL: '.$desc);
}
$now=1800000000;
$localKey=random_bytes(32);
function ticket(array $challenge,string $targetId,int $now,string $localKey): array {
    $kp=sodium_crypto_sign_keypair();
    $secret=sodium_crypto_sign_secretkey($kp);
    $envelope=Issuer::issue($challenge,$challenge['target_base_url'],$targetId,$secret,$now);
    $g=Mac::seal([
        'provenance'=>'authenticated_target_admin_session',
        'status'=>'approved',
        'approval_receipt_id'=>'confirmed-'.bin2hex(random_bytes(8)),
        'expires_at'=>$now+120,
        'public_key_b64'=>$envelope['payload']['public_key_b64'],
        'target_instance_id'=>$targetId,
        'target_base_url'=>$challenge['target_base_url'],
        'local_installation_id'=>$challenge['local_installation_id'],
        'operation_id'=>$challenge['operation_id'],
        'nonce'=>$challenge['nonce'],
    ],$localKey);
    return [$envelope,$g];
}
$ch=V::challenge('operation-alpha-001','local-alpha-001','public-old-alpha','https://old.alpha.example','https://target.alpha.example/new',$now);
$a=$ledger->begin($ch,$now);
ok($a['status']==='issued' && $a['safe_to_cutover']===false,'begin does not switch traffic');
ok($ledger->begin($ch,$now)['status']==='issued','same operation idempotent');
$changed=$ch;$changed['target_base_url']='https://other.alpha.example';
denied(fn()=>$ledger->begin($changed,$now),'same operation cannot overwrite challenge');
$ch2=V::challenge('operation-alpha-002','local-alpha-001','public-old-alpha','https://old.alpha.example','https://target2.alpha.example',$now);
denied(fn()=>$ledger->begin($ch2,$now),'same Local cannot start concurrent migration');
$badNonce=$ch;$badNonce['operation_id']='operation-alpha-003';
denied(fn()=>$ledger->begin($badNonce,$now),'nonce unique prevents replay under another operation');
[$env,$grant]=ticket($ch,'public-new-alpha',$now,$localKey);
$approved=$ledger->acceptVerifiedApproval($ch,$env,$grant,$now+1,$localKey);
ok($approved['status']==='owner_approved' && $approved['nonce_claimed']===false,'valid crypto proof recorded pending claim');
ok($ledger->acceptVerifiedApproval($ch,$env,$grant,$now+1,$localKey)['status']==='owner_approved','retry same approval idempotent');
$wrong=$grant;$wrong['nonce']='000000000000000000000000000000000000000000000000';
denied(fn()=>$ledger->acceptVerifiedApproval($ch,$env,$wrong,$now+1,$localKey),'tampered grant rejected');
denied(fn()=>$ledger->acceptVerifiedApproval($ch,$env,$grant,$now+1,random_bytes(32)),'different customer approval key rejected');
[$differentEnv,$differentGrant]=ticket($ch,'public-new-alpha',$now,$localKey);
denied(fn()=>$ledger->acceptVerifiedApproval($ch,$differentEnv,$differentGrant,$now+1,$localKey),'conflicting second approval receipt rejected');
$oldStatus=$ledger->status('local-alpha-001','operation-alpha-001');
ok($oldStatus['status']==='owner_approved' && !isset($oldStatus['nonce']),'readback excludes nonce and secret');
denied(fn()=>$ledger->claimOnce('local-alpha-001','operation-alpha-001',str_repeat('f',48),$now+2),'invalid nonce fails closed');
$claimed=$ledger->claimOnce('local-alpha-001','operation-alpha-001',$ch['nonce'],$now+2);
ok($claimed['status']==='claimed' && $claimed['nonce_claimed']===true && $claimed['safe_to_cutover']===false,'claim once still cannot cut over');
denied(fn()=>$ledger->claimOnce('local-alpha-001','operation-alpha-001',$ch['nonce'],$now+3),'claim cannot be replayed');
denied(fn()=>$ledger->acceptVerifiedApproval($ch,$env,$grant,$now+3,$localKey),'claimed operation refuses new approval');
denied(fn()=>$ledger->cancelBeforeClaim('local-alpha-001','operation-alpha-001'),'claimed operation cannot be casually canceled');
denied(fn()=>$ledger->claimOnce('local-other-002','operation-alpha-001',$ch['nonce'],$now+3),'wrong Local cannot claim');

// Other customer is isolated by its own slot despite alpha remaining claimed.
$beta=V::challenge('operation-beta-001','local-beta-002','','','https://portal.beta.example/store',$now);
ok($ledger->begin($beta,$now)['status']==='issued','different customer may start independently');
denied(fn()=>$ledger->claimOnce('local-beta-002','operation-beta-001',$beta['nonce'],$now+3),'unapproved operation cannot be claimed');
ok($ledger->cancelBeforeClaim('local-beta-002','operation-beta-001')['status']==='canceled','unclaimed operation canceled');
$beta2=V::challenge('operation-beta-002','local-beta-002','','','https://different.beta.example',$now);
ok($ledger->begin($beta2,$now)['status']==='issued','released slot may start a new operation');
[$betEnv,$betGrant]=ticket($beta2,'public-beta-001',$now,$localKey);
ok($ledger->acceptVerifiedApproval($beta2,$betEnv,$betGrant,$now+1,$localKey)['status']==='owner_approved','second customer approval works');
denied(fn()=>$ledger->claimOnce('local-beta-002','operation-beta-002',$beta2['nonce'],$now+181),'expired grant cannot be claimed');
denied(fn()=>$ledger->acceptVerifiedApproval($beta2,$betEnv,$betGrant,$now+181,$localKey),'expired grant cannot be accepted');

// A fresh DB connection sees the durable operation state; browser or PHP process restart is safe.
$connection2=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$ledger2=new L($connection2);
ok($ledger2->status('local-beta-002','operation-beta-002')['status']==='owner_approved','durable state visible after fresh connection');
denied(fn()=>$ledger2->begin($beta,$now+1),'canceled operation id cannot silently reactivate');

// Many customers, arbitrary domains and paths; no customer is hard-coded into ledger.
for ($i=0;$i<8;$i++) {
    $id='local-fixture-'.sprintf('%03d',$i);
    $op='operation-fixture-'.sprintf('%03d',$i);
    $request=V::challenge($op,$id,'','',"https://dest{$i}.examples.test/route{$i}",$now);
    // .test domains are accepted here as logical fixtures and never actually fetched.
    ok($ledger->begin($request,$now)['status']==='issued','independent tenant '.$i.' registered');
    [$e,$g]=ticket($request,'public-fixture-'.sprintf('%03d',$i),$now,$localKey);
    ok($ledger->acceptVerifiedApproval($request,$e,$g,$now+2,$localKey)['status']==='owner_approved','independent tenant '.$i.' owner proof');
    ok($ledger->claimOnce($id,$op,$request['nonce'],$now+3)['nonce_claimed']===true,'independent tenant '.$i.' nonce claimed once');
}

echo 'R253_MARIADB_LEDGER_PASS checks='.$checks."\n";
