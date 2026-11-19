<?php
declare(strict_types=1);
// CI and offline QA only: no actual host, no DB, no HTTP, no config writes.
require dirname(__DIR__).'/source/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require dirname(__DIR__).'/source/src/Domain/PublicEdge/PublicMoveFailureDiagnostic.php';
require dirname(__DIR__).'/source/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
require dirname(__DIR__).'/source/src/Domain/PublicEdge/PublicMoveUiInspectionService.php';
use Sokna\Local\Domain\PublicEdge\PublicMoveFailureDiagnostic as D;
use Sokna\Local\Domain\PublicEdge\PublicMoveUiInspectionService as U;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException as E;
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('QA_FAIL_'.$name);}
function failure(Throwable $e,string $action='public_move_confirm',array $pending=['status'=>'none','active'=>false],string $binding='not_target'):array{
 return D::classify($e,$action,$pending,$binding);
}
$r=failure(new RuntimeException('owner_receipt_signature_invalid'));
check($r['code']==='public_move_owner_proof_invalid'&&$r['safe_to_cutover']===false,'invalid_signed_receipt_blocked');
$r=failure(new RuntimeException('move_challenge_not_active'));
check($r['code']==='public_move_challenge_expired'&&$r['recovery_policy']==='restart_owner_challenge','expired_preclaim');
$r=failure(new RuntimeException('move_challenge_not_active'),'public_move_finish',['active'=>true,'status'=>'sync_pending'],'target');
check($r['recovery_policy']==='resume_existing_operation'&&$r['local_connection_preserved']===false,'never_restart_while_committed');
$r=failure(new RuntimeException('another_replacement_in_progress'));
check($r['code']==='public_move_recovery_required','multiple_operations_recovery');
$r=failure(new RuntimeException('local_admin_for_installation_required'));
check($r['code']==='public_move_local_admin_session_required','local_admin_scope');
$r=failure(new RuntimeException('existing_destination_identity_or_pairing_mismatch'));
check($r['code']==='public_move_destination_identity_mismatch','tenant_mismatch');
$r=failure(new E('public_http_failed','wrong endpoint',404),'public_move_finish');
check($r['code']==='public_move_destination_http_404','public_404_distinct');
$secret='https://customer.invalid/private?token=secret-example';
$r=failure(new RuntimeException($secret));
check($r['code']==='public_move_unclassified_failure'&&!str_contains(json_encode($r),$secret)&&D::safeReason(new RuntimeException($secret))==='unclassified','no_secret_leak');
$healthGood=['ok'=>true,'component'=>'public-edge','database'=>'ready','pending_migrations'=>0,'version'=>'35ij'];
$probes=0;
$test=new U('https://old.example','resync_required',function()use(&$probes){$probes++;throw new RuntimeException('SAME_INSTANCE_MUST_NOT_RUN_IN_RESYNC');},fn()=> $healthGood);
$actual=$test->inspect('https://new.example/public');
check($actual['route']==='awaiting_owner'&&$actual['reason_code']==='target_ready_owner_identity_unproven'&&$probes===0&&$actual['safe_to_cutover']===false,'resync_owner_health_not_identity');
$test=new U('https://old.example','resync_required',function(){throw new RuntimeException('SHOULD_NOT_CALL');},fn()=>['ok'=>true,'component'=>'public-edge','database'=>'unavailable','pending_migrations'=>0,'version'=>'35ij']);
$actual=$test->inspect('https://new.example/public');
check($actual['route']==='blocked'&&$actual['reason_code']==='target_not_ready','bad_health_blocked');
$test=new U('https://old.example','active',function(){throw new RuntimeException('unexpected path contains secret');},fn()=> $healthGood);
$actual=$test->inspect('https://new.example/public');
check($actual['route']==='blocked'&&$actual['reason_code']==='same_instance_preflight_failed','unexpected_preflight_fail_closed');
$test=new U('https://old.example','active',fn()=>['ok'=>true,'target_health_verified'=>true,'public_instance_id'=>'public-test-1','current_base_url'=>'https://old.example','target_base_url'=>'https://new.example/public'],fn()=> $healthGood);
$actual=$test->inspect('https://new.example/public');
check($actual['route']==='ready_for_review'&&$actual['safe_to_cutover']===false,'real_signed_same_instance_only_preview');
$test=new U('https://old.example','active',fn()=>['ok'=>true,'target_health_verified'=>true,'public_instance_id'=>'public-test-1','current_base_url'=>'https://old.example','target_base_url'=>'https://wrong.example'],fn()=> $healthGood);
$actual=$test->inspect('https://new.example/public');
check($actual['route']==='blocked'&&$actual['reason_code']==='signed_preflight_incomplete','signed_id_mismatch_blocked');
$test=new U('https://old.example','active',fn()=>throw new E('public_origin_instance_mismatch','different identity',409),fn()=> $healthGood);
$actual=$test->inspect('https://new.example/public');
check($actual['route']==='awaiting_owner'&&$actual['safe_to_cutover']===false,'owner_recheck_only_on_ready_target');
$test=new U('https://old.example','active',fn()=>throw new E('public_origin_instance_mismatch','different identity',409),fn()=>throw new E('public_http_failed','not found',404));
$actual=$test->inspect('https://new.example/public');
check($actual['route']==='blocked'&&$actual['reason_code']==='target_http_404','health_404_never_misreported_as_owner');
echo "R300_PUBLIC_MOVE_LOCAL_DIAGNOSTICS_AND_SAFE_DISCOVERY_13_CASES_PASS\n";