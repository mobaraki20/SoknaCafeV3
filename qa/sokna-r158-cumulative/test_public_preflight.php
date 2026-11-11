<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true') exit(87);
[$script,$zipHL,$zipHK,$local,$publicHL]=$argv;
require_once $local.'/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once $local.'/src/Domain/PublicEdge/PublicUpdatePackagePreflight.php';
require_once $publicHL.'/src/Update/RoutinePublicUpdateService.php';
use Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
function check($x,$m){if(!$x)throw new RuntimeException($m);echo "PASS $m\n";}
$target='3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa';
foreach ([[$zipHL,'3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa','0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'],[$zipHK,'3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa','0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537']] as [$zip,$ver,$sha]){
 $status=['routine_update'=>['current_version'=>$ver,'active_version'=>$ver,'managed_tree_sha256'=>$sha,'managed_tree_matches_expected'=>true]];
 $result=PublicUpdatePackagePreflight::inspect($zip,$status);
 check($result['version']===$target && $result['mode']==='legacy_exact_full' && $result['base_version']===$ver,'EXACT_PUBLIC_PREFLIGHT_'.substr($ver,25,5));
 $wrong=$status;$wrong['routine_update']['managed_tree_sha256']=str_repeat('a',64);
 try{PublicUpdatePackagePreflight::inspect($zip,$wrong);throw new RuntimeException('UNSAFE_DRIFT_ACCEPTED');}
 catch(PublicEdgeSyncException $e){check($e->errorCode==='public_baseline_mismatch','PUBLIC_WRONG_BASE_FAIL_CLOSED');}
}
$svc=new RoutinePublicUpdateService($publicHL,sys_get_temp_dir().'/sokna-r158-safe-readonly','/nonexistent-trust-readonly.json');
check($svc->managedTreeDigest()==='0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9','EXACT_PUBLIC_35HL_OWNER_TREE');
$ref=new ReflectionMethod(RoutinePublicUpdateService::class,'verifyZip');
$verified=$ref->invoke($svc,$zipHL);$m=$verified['manifest'];
check($m['version']===$target && $m['target']['managed_tree_sha256']==='690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97','REAL_35HL_PUBLIC_RECEIVER_VERIFY');
check(count($m['files'])>0,'REAL_35HL_PUBLIC_RECEIVER_GENERATED_DELTA');