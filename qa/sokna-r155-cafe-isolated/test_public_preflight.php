<?php
declare(strict_types=1);
/* Public R155 real owner preflight on published exact 35hk baseline.
 * No production connection, no HTTP mutation, all work on disposable files.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true') exit(87);
[$script,$zip,$local,$publicBase]=$argv;
require_once $local.'/src/Domain/PublicEdge/PublicEdgeSyncException.php';
require_once $local.'/src/Domain/PublicEdge/PublicUpdatePackagePreflight.php';
require_once $publicBase.'/src/Update/RoutinePublicUpdateService.php';
use Sokna\Local\Domain\PublicEdge\PublicUpdatePackagePreflight;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException;
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
function need($v,$m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
$baseVersion='3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa';
$baseHash='0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537';
$targetHash='0c7a2f3f5986a0b1cbe5df80e8eaa9d2c5381ad5208c6e7c96fc1496121666f9';
$targetVersion='3.0.0-g3.6-workspace.35hl-r155-guest-order-source-qa';
$status=['routine_update'=>['current_version'=>$baseVersion,'active_version'=>$baseVersion,
    'managed_tree_sha256'=>$baseHash,'managed_tree_matches_expected'=>true]];
need(trim(file_get_contents($publicBase.'/VERSION.txt'))===$baseVersion,'LIVE_BASE_R153_VERSION_MATCH');
$inspect=PublicUpdatePackagePreflight::inspect($zip,$status);
need($inspect['version']===$targetVersion && $inspect['base_version']===$baseVersion && $inspect['mode']==='legacy_exact_full','LOCAL_PUBLIC_PREFLIGHT_ACTUAL_PHP_PASS');
try {
    $wrong=$status;$wrong['routine_update']['managed_tree_sha256']=str_repeat('a',64);
    PublicUpdatePackagePreflight::inspect($zip,$wrong);
    throw new RuntimeException('PUBLIC_WRONG_BASE_UNEXPECTEDLY_ACCEPTED');
} catch (PublicEdgeSyncException $e) {
    need($e->errorCode==='public_baseline_mismatch','PUBLIC_BASE_DRIFT_FAIL_CLOSED');
}
$svc=new RoutinePublicUpdateService($publicBase,sys_get_temp_dir().'/r155-safe-public-qa','/nonexistent-trust-readonly.json');
need($svc->managedTreeDigest()===$baseHash,'REAL_PUBLIC_BASE_TREE_MATCHES_CAFE');
$ref=new ReflectionMethod(RoutinePublicUpdateService::class,'verifyZip');
$verified=$ref->invoke($svc,$zip);
$m=$verified['manifest'];
need($m['version']===$targetVersion && $m['target']['managed_tree_sha256']===$targetHash,'REAL_PUBLIC_OWNER_FULL_PACKAGE_VERIFIER_PASS');
need(count($m['files'])>=1,'REAL_PUBLIC_OWNER_GENERATED_DELTA');
need($verified['signature_status']==='operator_selected_hash_verified','PUBLIC_KEYLESS_OPERATOR_PACKAGE_HASH_VERIFIED');
