<?php
/** Read-only P0 contract checks. No service calls, DB operations, or disk writes. */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/apps/local-web/src/Domain/System/MaintenanceSupportEvidence.php';
use Sokna\Local\Domain\System\MaintenanceSupportEvidence;
$count=0;
function verify(bool $ok,string $label):void{
    global $count; ++$count;
    if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}
    echo "PASS $label\n";
}
$ops=['ok'=>true,'state'=>'idle','active_count'=>0,'blocking_count'=>0,
      'requires_recovery'=>false,'history_available'=>true,'recent_operations'=>[]];
$cap=['ready'=>false,'verification_ready'=>true,'inbox_transport_available'=>true,
      'state'=>'verification_only','code'=>'LOCAL_HOST_PRECONDITIONS_UNPROVEN',
      'host_blockers_available'=>true,
      'host_blockers'=>['ACTIVE_R8_JUNCTION_UNQUALIFIED','PERSISTENT_MACHINE_LOCAL_OVERLAY_DRIFT']];
$summary=MaintenanceSupportEvidence::summarize($cap,$ops);
verify($summary['capabilities']['install_ready']===false,'Stage is not installation');
verify($summary['capabilities']['verification_ready']===true,'Stage independently qualified');
verify($summary['capabilities']['blocking_code']==='LOCAL_HOST_PRECONDITIONS_UNPROVEN','Top-level reason retained');
verify($summary['capabilities']['host_blockers_available']===true,'Signed reasons available');
verify($summary['capabilities']['host_blockers']===$cap['host_blockers'],'Specific reasons reach support');
$cap['host_blockers_available']=false; unset($cap['host_blockers']);
$legacy=MaintenanceSupportEvidence::summarize($cap,$ops);
verify($legacy['capabilities']['host_blockers_available']===false,'Old Supervisor reasons unknown');
verify($legacy['capabilities']['host_blockers']===[],'No fabricated reasons');
$ops['ok']=false;
$broken=MaintenanceSupportEvidence::summarize($cap,$ops);
verify($broken['operations']['blocking_count']===null,'Unavailable journal not falsely idle');
verify($broken['operations']['requires_recovery']===null,'Recovery state unknown on journal failure');
$cap['raw_http_response']='C:\\Windows\\secret.txt';
$redacted=MaintenanceSupportEvidence::summarize($cap,$ops);
verify(!str_contains(json_encode($redacted),'raw_http_response'),'Raw host paths never copied');
verify($redacted['capabilities']['install_ready']===false,'No ready bypass');
echo "PASS $count P0 diagnostic checks\n";
