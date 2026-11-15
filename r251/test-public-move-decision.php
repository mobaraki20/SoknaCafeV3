<?php
declare(strict_types=1);
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
use Sokna\Local\Domain\PublicEdge\PublicMoveDecisionEngine as D;

$healthy=['ok'=>true,'component'=>'public-edge','database'=>'ready','pending_migrations'=>0,'version'=>'3.0.0.35ic'];
$old='https://www.soknaspace.ir';$new='https://www.soknagroup.ir/public';
$checks=0;
function check(bool $pass,string $label): void { global $checks; if (!$pass) throw new RuntimeException('FAIL '.$label); ++$checks; echo "PASS $label\n"; }
function plan(string $target,string $phase='active',array $evidence=[],string $source='https://www.soknaspace.ir'): array {return D::classify($source,$target,$phase,$evidence);}
$invalid=['','http://example.com','https://user:pass@example.com','https://example.com/?q=1','https://example.com/#x','https://example.com/%2e%2e/pass','https://localhost/path','https://127.0.0.1','https://example.internal','https://example.com/\\windows','https://example.com/hello%0a'];
foreach($invalid as $i=>$value) check(plan($value)['reason_code']==='invalid_target','invalid_'.$i);
check(D::canonicalUrl($new.'/')===$new,'canonical_trailing_slash');
check(D::canonicalUrl('HTTPS://WWW.SOKNAGROUP.IR:443/public/')===$new,'canonical_https_default_port');
check(D::canonicalUrl('https://example.com:8443/public')==='https://example.com:8443/public','custom_https_port');
$a=plan($old.'/');check($a['route']==='same_destination' && $a['safe_to_cutover']===false,'no_change');
$a=plan($new,'resync_required');check($a['reason_code']==='health_not_checked' && $a['writes_performed']===false,'resync_no_health_no_write');
$a=plan($new,'resync_required',['target_health'=>$healthy]);check($a['reason_code']==='identity_not_proven' && $a['next_step']==='prove_destination_identity','health_not_identity');
$bad=$healthy;$bad['pending_migrations']=2;check(plan($new,'active',['target_health'=>$bad])['reason_code']==='target_not_ready','migrations_block');
$bad=$healthy;$bad['database']='down';check(plan($new,'active',['target_health'=>$bad])['reason_code']==='target_not_ready','db_block');
$bad=$healthy;$bad['component']='different';check(plan($new,'active',['target_health'=>$bad])['reason_code']==='target_not_ready','not_public_edge');
$ev=['target_health'=>$healthy,'identity_proof'=>['verified'=>true,'source_instance_id'=>'old-a','target_instance_id'=>'new-b']];
$a=plan($new,'resync_required',$ev);check($a['next_step']==='approve_ownership' && $a['route']==='independent_destination','independent_requires_ownership');
$ev['identity_proof']['destination_ownership_approved']=true;
$a=plan($new,'resync_required',$ev);check($a['next_step']==='plan_safe_handoff' && $a['safe_to_cutover']===false,'approved_requires_safe_handoff');
$ev['identity_proof']['target_instance_id']='old-a';
$a=plan($new,'resync_required',$ev);check($a['reason_code']==='same_instance_recovery_pending','same_instance_recovery');
$a=plan($new,'active',$ev);check($a['route']==='same_instance_rebind' && $a['safe_to_cutover']===false,'same_instance_rebind');
$ev['identity_proof']['verified']=false;
check(plan($new,'active',$ev)['reason_code']==='identity_not_proven','unverified_proof_rejected');
check(plan($new,'broken',$ev)['reason_code']==='local_state_unknown','unknown_phase_block');
check(plan($new,'active',['target_health'=>$healthy],source:'')['current_base_url']===null,'first_install_detected');
foreach(['active','resync_required','reenroll_required'] as $phase){$a=plan($new,$phase,['target_health'=>$healthy]);check($a['local_connection_preserved']===true && $a['safe_to_cutover']===false,'no_write_'.$phase);}
echo "R251_DISCOVERY_READONLY_TESTS_PASS count=$checks\n";
