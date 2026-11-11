<?php
declare(strict_types=1);
// Isolated build gate; no production host or connection is accessed.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true') exit(87);
if ($argc !== 5) throw new RuntimeException('Usage public_package local_source public_R162_source public_R163_source');
[$script,$zip,$local,$publicOld,$publicNew] = $argv;
require_once $local.'/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once $local.'/src/Domain/PublicEdge/PublicUpdatePackagePreflight.php';
require_once $publicOld.'/src/Update/RoutinePublicUpdateService.php';
use Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
function need(bool $pass,string $message):void {if(!$pass)throw new RuntimeException($message);echo "PASS $message\n";}
$base='3.0.0-g3.6-workspace.35hr-r160-golive-reset-transport-qa';
$target='3.0.0-g3.6-workspace.35hs-r161-cumulative-ui-post-golive-qa';
$tree='2434850306c0c43f801674a4a8d624a9c80e16a9ef3ba3d772f7f3ec5766c5e5';
$targetTree='eff26613898d8d9ff04d463a2bac8545e8a8f93bd077cd5a2ac914e0ab35ae13';
$state=['routine_update'=>['current_version'=>$base,'active_version'=>$base,'managed_tree_sha256'=>$tree,'managed_tree_matches_expected'=>true]];
$inspection=PublicUpdatePackagePreflight::inspect($zip,$state);
need($inspection['version']===$target && $inspection['mode']==='legacy_exact_full' && $inspection['base_version']===$base,'PUBLIC_35HR_TO_35HS_EXACT_PREFLIGHT');
$wrong=$state;$wrong['routine_update']['managed_tree_sha256']=str_repeat('a',64);
try{PublicUpdatePackagePreflight::inspect($zip,$wrong);throw new RuntimeException('WRONG_SOURCE_WAS_ACCEPTED');}
catch(PublicEdgeSyncException $e){need($e->errorCode==='public_baseline_mismatch','WRONG_PUBLIC_BASE_FAIL_CLOSED');}
$service=new RoutinePublicUpdateService($publicOld,sys_get_temp_dir().'/sokna-r163-public-readonly','/nonexistent-trust-readonly.json');
need($service->managedTreeDigest()===$tree,'REAL_R162_PUBLIC_RECEIVER_SOURCE');
$reflection=new ReflectionMethod(RoutinePublicUpdateService::class,'verifyZip');
$verified=$reflection->invoke($service,$zip);$manifest=$verified['manifest'];
need($manifest['version']===$target && $manifest['target']['managed_tree_sha256']===$targetTree,'REAL_PUBLIC_RECEIVER_TARGET');
need((int)($manifest['provenance']['file_count']??0)===225 && count($manifest['files'])>0 && count($manifest['deletes'])===0,'PUBLIC_FULL_SOURCE_PRESERVED_AND_DELTA_SAFE');
need(is_file($publicNew.'/src/Maintenance/GoLiveResetEngine.php'),'GOLIVE_RESET_PUBLIC_ENGINE_PRESENT');