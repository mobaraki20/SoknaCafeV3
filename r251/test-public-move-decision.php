<?php
declare(strict_types=1);
require dirname(__DIR__).'/apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php';
use Sokna\Local\Domain\PublicEdge\PublicMoveDecisionEngine as D;

$healthy=['ok'=>true,'component'=>'public-edge','database'=>'ready','pending_migrations'=>0,'version'=>'fixture-release-9.2.1'];
$old='https://legacy.cafe-alpha.example';$new='https://fresh.cafe-alpha.example/site';
$checks=0;
function check(bool $pass,string $label): void { global $checks; if (!$pass) throw new RuntimeException('FAIL '.$label); ++$checks; echo "PASS $label\n"; }
function plan(string $target,string $phase='active',array $evidence=[],string $source='https://legacy.cafe-alpha.example'): array {return D::classify($source,$target,$phase,$evidence);}
$invalid=['','http://example.com','https://user:pass@example.com','https://example.com/?q=1','https://example.com/#x','https://example.com/%2e%2e/pass','https://localhost/path','https://127.0.0.1','https://example.internal','https://example.com/\\windows','https://example.com/hello%0a'];
foreach($invalid as $i=>$value) check(plan($value)['reason_code']==='invalid_target','invalid_'.$i);
check(D::canonicalUrl($new.'/')===$new,'canonical_trailing_slash');
check(D::canonicalUrl('HTTPS://FRESH.CAFE-ALPHA.EXAMPLE:443/site/')===$new,'canonical_https_default_port');
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
// Cross-customer fixtures: domains, versions, paths and authority phases are NOT product constants.
// Each pair simulates a DIFFERENT installation/customer, with unrelated instance identities.
$deployments=[
 ['https://old.bistro-beta.example','https://new.bistro-beta.example','active'],
 ['https://legacy.coffee-gamma.example/site','https://host.coffee-gamma.example/public-v3','resync_required'],
 ['https://old.tenant-delta.example','https://another.tenant-delta.example/edge','reenroll_required'],
 ['https://public.epsilon.example/v1','https://www.epsilon.example/v2','active'],
 ['https://legacy.zeta.example','https://fresh.zeta.example/hosted/menu','resync_required'],
];
foreach ($deployments as $i=>$fixture) {
    [$source,$target,$phase]=$fixture;
    $n=$i+1;
    check(D::canonicalUrl($target.'/')===$target,'generic_url_'.$n);
    check(plan($target,$phase,[],source:$source)['reason_code']==='health_not_checked','generic_no_health_'.$n);
    $proof=['verified'=>true,'source_instance_id'=>'old-'.$n,'target_instance_id'=>'new-'.$n];
    $e=['target_health'=>['ok'=>true,'component'=>'public-edge','database'=>'ready','pending_migrations'=>0,'version'=>'release-'.($i+4).'.'.($i+5)],'identity_proof'=>$proof];
    $a=plan($target,$phase,$e,source:$source);
    check($a['route']==='independent_destination' && $a['next_step']==='approve_ownership' && $a['safe_to_cutover']===false,'generic_independent_'.$n);
    $e['identity_proof']['destination_ownership_approved']=true;
    $a=plan($target,$phase,$e,source:$source);
    check($a['next_step']==='plan_safe_handoff' && $a['safe_to_cutover']===false,'generic_approved_not_live_'.$n);
    $e['identity_proof']['target_instance_id']='old-'.$n;
    $a=plan($target,'active',$e,source:$source);
    check($a['route']==='same_instance_rebind','generic_same_instance_'.$n);
}
echo "R251_DISCOVERY_READONLY_TESTS_PASS count=$checks\\n";
