<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true')exit(87);
[$script,$zipHL,$zipHK,$zipHN,$local,$publicHN]=$argv;
require_once $local.'/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once $local.'/src/Domain/PublicEdge/PublicUpdatePackagePreflight.php';
require_once $publicHN.'/src/Update/RoutinePublicUpdateService.php';
use Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
function need($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$target='3.0.0-g3.6-workspace.35ho-r158-scroll-dialog-source-qa';
$spec=[
 [$zipHL,'3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa','0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'],
 [$zipHK,'3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa','0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'],
 [$zipHN,'3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa','690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97']
];
foreach($spec as [$zip,$ver,$sha]){
 $status=['routine_update'=>['current_version'=>$ver,'active_version'=>$ver,'managed_tree_sha256'=>$sha,'managed_tree_matches_expected'=>true]];
 $res=PublicUpdatePackagePreflight::inspect($zip,$status);
 need($res['version']===$target&&$res['mode']==='legacy_exact_full'&&$res['base_version']===$ver,'PUBLIC_EXACT_BASE_'.substr($ver,23,9));
 $wrong=$status;$wrong['routine_update']['managed_tree_sha256']=str_repeat('a',64);
 try{PublicUpdatePackagePreflight::inspect($zip,$wrong);throw new RuntimeException('UNKNOWN_BASE_ACCEPTED');}
 catch(PublicEdgeSyncException $e){need($e->errorCode==='public_baseline_mismatch','PUBLIC_UNKNOWN_TREE_FAIL_CLOSED');}
}
$svc=new RoutinePublicUpdateService($publicHN,sys_get_temp_dir().'/sokna-r159-safe-receiver','/nonexistent-trust-readonly.json');
need($svc->managedTreeDigest()==='690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97','R157_PUBLIC_SOURCE_MATCH');
$verify=new ReflectionMethod(RoutinePublicUpdateService::class,'verifyZip');
$verified=$verify->invoke($svc,$zipHN);
$m=$verified['manifest'];
need($m['version']===$target&&$m['target']['managed_tree_sha256']==='6b404a3e9386e8015a27d69348f3b9900d39b12536c439832bd23d206328c025','R158_PUBLIC_RECEIVER_MATCH');
need(count($m['files'])>0,'R158_PUBLIC_RECEIVER_NONEMPTY');
