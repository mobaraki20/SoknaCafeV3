<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true') exit(87);
if ($argc !== 8) throw new RuntimeException('Expected 5 public bridges, local source, Public 35HP source');
[$script,$zipHL,$zipHK,$zipHN,$zipHO,$zipHP,$local,$publicHP] = $argv;
require_once $local.'/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once $local.'/src/Domain/PublicEdge/PublicUpdatePackagePreflight.php';
require_once $publicHP.'/src/Update/RoutinePublicUpdateService.php';
use Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
function need(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label); echo "PASS $label\n";}
$target='3.0.0-g3.6-workspace.35hr-r160-golive-reset-transport-qa';
$rows=[
 [$zipHL,'3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa','0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9'],
 [$zipHK,'3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa','0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537'],
 [$zipHN,'3.0.0-g3.6-workspace.35hn-r157-guest-order-list-source-qa','690177b3f2deb11ba7237c09a7284435d86790547e97cd118e2bb4ba64faec97'],
 [$zipHO,'3.0.0-g3.6-workspace.35ho-r158-scroll-dialog-source-qa','6b404a3e9386e8015a27d69348f3b9900d39b12536c439832bd23d206328c025'],
 [$zipHP,'3.0.0-g3.6-workspace.35hp-r159-guest-cards-visual-qa','9ee516c58cf87e8b003651308211889377b56857cbcd0268ecfc76d1a6600b4f']
];
foreach($rows as [$zip,$version,$tree]) {
 $state=['routine_update'=>['current_version'=>$version,'active_version'=>$version,'managed_tree_sha256'=>$tree,'managed_tree_matches_expected'=>true]];
 $res=PublicUpdatePackagePreflight::inspect($zip,$state);
 need($res['version']===$target && $res['mode']==='legacy_exact_full' && $res['base_version']===$version,'PUBLIC_EXACT_PREFLIGHT_'.substr($version,25,4));
 $invalid=$state; $invalid['routine_update']['managed_tree_sha256']=str_repeat('a',64);
 try {PublicUpdatePackagePreflight::inspect($zip,$invalid);throw new RuntimeException('UNSAFE_BASE_ACCEPTED');}
 catch(PublicEdgeSyncException $e) {need($e->errorCode==='public_baseline_mismatch','REJECT_UNKNOWN_PUBLIC_SOURCE');}
}
$service=new RoutinePublicUpdateService($publicHP,sys_get_temp_dir().'/sokna-r162-safe-readonly','/nonexistent-trust-readonly.json');
need($service->managedTreeDigest()==='9ee516c58cf87e8b003651308211889377b56857cbcd0268ecfc76d1a6600b4f','35HP_REAL_RECEIVER_SOURCE');
$reflection=new ReflectionMethod(RoutinePublicUpdateService::class,'verifyZip');
$verified=$reflection->invoke($service,$zipHP);$manifest=$verified['manifest'];
need($manifest['version']===$target && $manifest['target']['managed_tree_sha256']==='2434850306c0c43f801674a4a8d624a9c80e16a9ef3ba3d772f7f3ec5766c5e5','35HR_REAL_RECEIVER_TARGET');
need(count($manifest['files'])>0,'35HP_NONEMPTY_DELTA');