<?php
declare(strict_types=1);

require dirname(__DIR__) . '/apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
require dirname(__DIR__) . '/apps/local-web/src/Domain/PublicEdge/PublicMoveApprovalMac.php';
require dirname(__DIR__) . '/apps/local-web/src/Domain/PublicEdge/PublicMoveIdentityVerifier.php';
require dirname(__DIR__) . '/apps/public/src/Security/PublicMoveAttestationIssuer.php';

use Sokna\Local\Domain\PublicEdge\PublicMoveIdentityVerifier as V;
use Sokna\Local\Domain\PublicEdge\PublicMoveDecisionEngine as D;
use Sokna\Local\Domain\PublicEdge\PublicMoveApprovalMac as MAC;
use Sokna\PublicEdge\Security\PublicMoveAttestationIssuer as I;

$checks = 0;
$localApprovalKey = random_bytes(32);
function ok(bool $v, string $message): void {
    global $checks;
    if (!$v) throw new RuntimeException('FAIL: '.$message);
    $checks++;
    echo "PASS: $message\n";
}
function rejects(callable $fn, string $message): void {
    try { $fn(); }
    catch (InvalidArgumentException) { ok(true,$message); return; }
    throw new RuntimeException('FAIL: expected denial: '.$message);
}
function grant(array $challenge, array $envelope, int $expires): array {
    global $localApprovalKey;
    return MAC::seal([
        'provenance'=>'authenticated_target_admin_session',
        'status'=>'approved',
        'approval_receipt_id'=>'owner-confirm-'.bin2hex(random_bytes(8)),
        'expires_at'=>$expires,
        'public_key_b64'=>$envelope['payload']['public_key_b64'],
        'target_instance_id'=>$envelope['payload']['target_instance_id'],
        'target_base_url'=>$challenge['target_base_url'],
        'local_installation_id'=>$challenge['local_installation_id'],
        'operation_id'=>$challenge['operation_id'],
        'nonce'=>$challenge['nonce'],
    ], $localApprovalKey);
}
$now = 1800000000;
$pairA=sodium_crypto_sign_keypair(); $secretA=sodium_crypto_sign_secretkey($pairA);
$pairB=sodium_crypto_sign_keypair(); $secretB=sodium_crypto_sign_secretkey($pairB);
$ch=V::challenge('move-1042','local-B72','public-old-11','https://old.a-cafe.example','https://new.a-cafe.example/app',$now);
$env=I::issue($ch,'https://new.a-cafe.example/app','public-new-22',$secretA,$now);
$g=grant($ch,$env,$now+120);
$p=V::verify($ch,$env,$g,$now+1,$localApprovalKey);
ok($p['verified']===true && $p['destination_ownership_approved']===true,'signed attestation with independently owner-approved key');
ok($p['safe_to_cutover']===false && $p['nonce_claim_required']===true && $p['read_only']===true,'attestation never grants cutover or claims nonce');
ok($p['local_installation_id']==='local-B72' && $p['target_base_url']==='https://new.a-cafe.example/app','source and target bound to proof');
$health=['ok'=>true,'component'=>'public-edge','database'=>'ready','pending_migrations'=>0,'version'=>'release-dynamic.3'];
$classification=D::classify($ch['source_base_url'],$ch['target_base_url'],'resync_required',['target_health'=>$health,'identity_proof'=>$p]);
ok($classification['route']==='independent_destination' && $classification['next_step']==='plan_safe_handoff' && $classification['safe_to_cutover']===false,'integrates with read-only decision classifier');

$mut=$ch; $mut['target_base_url']='https://evil.a-cafe.example/app';
rejects(fn()=>V::verify($mut,$env,$g,$now+1,$localApprovalKey),'destination URL alteration rejected');
$mut=$ch; $mut['source_base_url']='https://another-old.example';
rejects(fn()=>V::verify($mut,$env,$g,$now+1,$localApprovalKey),'source URL alteration rejected');
$mut=$ch; $mut['local_installation_id']='local-B73';
rejects(fn()=>V::verify($mut,$env,$g,$now+1,$localApprovalKey),'other customer/local id rejected');
$mut=$ch; $mut['operation_id']='move-1043';
rejects(fn()=>V::verify($mut,$env,$g,$now+1,$localApprovalKey),'other migration id rejected');
$mut=$ch; $mut['source_public_instance_id']='public-old-12';
rejects(fn()=>V::verify($mut,$env,$g,$now+1,$localApprovalKey),'source public identity alteration rejected');
$mut=$ch; $mut['nonce']=bin2hex(random_bytes(24));
rejects(fn()=>V::verify($mut,$env,$g,$now+1,$localApprovalKey),'old signed reply against new nonce rejected');
$mut=$ch; $mut['expires_at']=$now+2;
rejects(fn()=>V::verify($mut,$env,$g,$now+3,$localApprovalKey),'short changed challenge invalid');
rejects(fn()=>V::verify($ch,$env,$g,$now+180,$localApprovalKey),'expired challenge rejected');
rejects(fn()=>V::verify($ch,$env,$g,$now-70,$localApprovalKey),'challenge issued in distant future rejected');
$mut=$env; $mut['signature_b64']=base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES));
rejects(fn()=>V::verify($ch,$mut,$g,$now+1,$localApprovalKey),'forged signature rejected');
$mut=$env; $mut['payload']['target_instance_id']='other-public-100';
rejects(fn()=>V::verify($ch,$mut,$g,$now+1,$localApprovalKey),'target identity modification rejected');
$mut=$env; $mut['payload']['public_key_b64']=base64_encode(sodium_crypto_sign_publickey($pairB));
rejects(fn()=>V::verify($ch,$mut,$g,$now+1,$localApprovalKey),'public key substitution rejected');
$mut=$env; $mut['payload']['extra']='untrusted';
rejects(fn()=>V::verify($ch,$mut,$g,$now+1,$localApprovalKey),'unknown signed field rejected');
$mut=$env; $mut['signature_b64']='%%%%';
rejects(fn()=>V::verify($ch,$mut,$g,$now+1,$localApprovalKey),'invalid signature encoding rejected');
rejects(fn()=>V::verify($ch,$env,[],$now+1,$localApprovalKey),'self-signed URL without independent approval rejected');

$unauthenticated=$g; unset($unauthenticated['authorization_mac_b64']);
rejects(fn()=>V::verify($ch,$env,$unauthenticated,$now+1,$localApprovalKey),'owner-approved label without Local MAC rejected');
$fakeLocalKey=random_bytes(32);
rejects(fn()=>V::verify($ch,$env,$g,$now+1,$fakeLocalKey),'grant MAC from other Local installation rejected');
$forged=$g; $forged['provenance']='authenticated_target_admin_session'; $forged['approval_receipt_id']='invented-owner-ack';
rejects(fn()=>V::verify($ch,$env,$forged,$now+1,$localApprovalKey),'edited owner grant cannot forge authenticated approval');

$mut=$g; $mut['provenance']='claimed_by_remote_public';
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'self-declared remote ownership rejected');
$mut=$g; $mut['public_key_b64']=base64_encode(sodium_crypto_sign_publickey($pairB));
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'wrong owner-approved key rejected');
$mut=$g; $mut['target_instance_id']='public-other-22';
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'wrong owner-approved installation rejected');
$mut=$g; $mut['target_base_url']='https://another.a-cafe.example/app';
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'owner approval for different URL rejected');
$mut=$g; $mut['local_installation_id']='local-other-19';
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'owner approval cannot cross customer boundary');
$mut=$g; $mut['operation_id']='move-other';
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'owner approval cannot cross operation boundary');
$mut=$g; $mut['nonce']=bin2hex(random_bytes(24));
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'owner approval cannot cross nonce boundary');
$mut=$g; $mut['expires_at']=$now;
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'expired owner approval rejected');
$mut=$g; $mut['expires_at']=$now+400;
rejects(fn()=>V::verify($ch,$env,$mut,$now+1,$localApprovalKey),'owner approval cannot outlive signed challenge');
rejects(fn()=>I::issue($ch,'https://different.a-cafe.example','public-new-22',$secretA,$now),'issuer cannot sign another destination URL');
rejects(fn()=>I::issue($ch,$ch['target_base_url'],'public-new-22',$secretB,$now+185),'issuer refuses expired challenge');
rejects(fn()=>V::challenge('move-1044','local-B72','','','http://untrusted.example',$now),'remote HTTP refused');
rejects(fn()=>V::challenge('move-1044','local-B72','','','https://127.0.0.1',$now),'loopback cannot be public transfer target');
rejects(fn()=>V::challenge('move-1044','local-B72','','','https://new.example/%2e%2e/config',$now),'path traversal refused');
rejects(fn()=>V::challenge('move-1044','local-B72','','','https://new.example',$now,601),'overlong challenge refused');
rejects(fn()=>V::challenge('move-1044','local-B72','','','https://new.example',$now,10),'too-short challenge refused');
rejects(fn()=>V::challenge('bad','local-B72','','','https://new.example',$now),'weak operation id refused');
rejects(fn()=>V::challenge('move-1044','bad','','','https://new.example',$now),'weak installation id refused');
rejects(fn()=>V::challenge('move-1044','local-B72','','https://new.example/','https://new.example',$now),'self transition rejected');

// The same protocol is exercised with unrelated customers, paths, and versions.
$fixtures=[
 ['old.bistro-beta.example','new.bistro-beta.example/edge','local-bistro-0001','pub-bistro-0001','pub-bistro-0002'],
 ['legacy.coffee-gamma.example/site','target.coffee-gamma.example/v2','local-gamma-0002','pub-gamma-0001','pub-gamma-0002'],
 ['origin.delta.example','central.delta.example/store','local-delta-0003','pub-delta-0001','pub-delta-0002'],
 ['old.epsilon.example/public','fresh.epsilon.example/','local-epsilon-0004','pub-epsilon-0001','pub-epsilon-0002'],
 ['a.zeta.example','b.zeta.example/path/deep','local-zeta-0005','pub-zeta-0001','pub-zeta-0002'],
 ['old.eta.example/subfolder','new.eta.example','local-eta-0006','pub-eta-0001','pub-eta-0002'],
 ['old.theta.example','new.theta.example:8443/edge','local-theta-0007','pub-theta-0001','pub-theta-0002'],
 ['old.iota.example/v1','new.iota.example/new','local-iota-0008','pub-iota-0001','pub-iota-0002'],
];
foreach ($fixtures as $idx=>$x) {
    [$from,$to,$local,$sourceId,$targetId]=$x;
    $source='https://'.$from; $target=rtrim('https://'.$to,'/');
    $request=V::challenge('migration-'.$idx.'-phase', $local, $sourceId, $source, $target, $now);
    $pair=sodium_crypto_sign_keypair();$sk=sodium_crypto_sign_secretkey($pair);
    $reply=I::issue($request,$target,$targetId,$sk,$now);
    $approval=grant($request,$reply,$now+140);
    $proof=V::verify($request,$reply,$approval,$now+15,$localApprovalKey);
    ok($proof['target_base_url']===$target && $proof['local_installation_id']===$local && !$proof['safe_to_cutover'],'generic cross-customer fixture '.$idx);
    $notForThisCustomer=$approval;$notForThisCustomer['local_installation_id']='local-not-the-one';
    rejects(fn()=>V::verify($request,$reply,$notForThisCustomer,$now+15,$localApprovalKey),'generic cross-tenant isolation '.$idx);
}
echo 'R252_PUBLIC_MOVE_ATTESTATION_TESTS_PASS count='.$checks."\n";
