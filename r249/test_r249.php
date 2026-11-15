<?php
declare(strict_types=1);
// Genuine PHP ZipArchive + existing R171 Routine/Independent Emergency bridge.
// Uses disposable filesystem and fake DB health only; NOT a live MariaDB acceptance test.
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
use Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge;
use Sokna\PublicEdge\Emergency\PublicUpdateException;
$D=getenv('R249_QA_FIXTURE');if(!is_string($D)||$D==='')throw new RuntimeException('R249_QA_FIXTURE required');
$R=__DIR__;
$source=$D.'/r171';$root=$D.'/run-public';$storage=$D.'/run-storage';
$pkg=$D.'/SOKNA-R249-PUBLIC-35IC-CUMULATIVE-35HK-35HT-GATED.zip';
require_once $D.'/public-update-exception.php';
require_once $D.'/r171-service.php';
require_once $D.'/r171-bridge.php';
function qa(bool $v,string $label):void{if(!$v)throw new RuntimeException('FAIL_'.$label);echo 'PASS_'.$label."\n";}
function rm_tree(string $dir):void{if(!is_dir($dir))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
function cp_tree(string $from,string $to):void{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $f){$p=$to.'/'.substr($f->getPathname(),strlen($from)+1);if($f->isDir()){if(!is_dir($p))mkdir($p,0700,true);}else{if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);copy($f->getPathname(),$p);}}}
qa(is_file($pkg),'r249_file_exists');qa(hash_file('sha256',$pkg)==='5056cea5e2171c8e6c6d204f817da66202a667ed7d16e3e5293ba4f20039d546','r249_exact_zip_sha');
$zip=new ZipArchive();qa($zip->open($pkg)===true,'zip_open');$m=json_decode((string)$zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
$zip->close();
qa(($m['version']??null)==='3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate','version_35ic');
qa(count($m['compatibility']['supported_baselines']??[])===2,'two_exact_allowlisted_baselines');
qa(($m['provenance']['production_host_qualified']??null)===false,'live_qualification_not_falsely_claimed');
rm_tree($root);rm_tree($storage);mkdir($root,0700,true);mkdir($storage,0700,true);cp_tree($source,$root);
mkdir($root.'/src/Emergency',0700,true);copy($D.'/public-update-exception.php',$root.'/src/Emergency/PublicUpdateException.php');
file_put_contents($root.'/emergency.php','<?php // immutable stable Emergency fixture'."\n");
file_put_contents($root.'/config.php',"<?php return ['app'=>['base_path'=>'/public']];\n");
file_put_contents($root.'/resources/update-trust-v1.json','{}');
$emergencyHash=hash_file('sha256',$root.'/emergency.php');$recoveryHash=hash_file('sha256',$root.'/recovery.php');
$version=trim((string)file_get_contents($root.'/VERSION.txt'));
qa($version==='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat','exact_source_35ht');
$accepted=array_values(array_filter($m['compatibility']['supported_baselines'],static fn($a)=>($a['version']??'')===$version));
qa(count($accepted)===1,'r171_registered_once');
mkdir($storage.'/routine-update-v2',0700,true);
$state=['format'=>'sokna-public-routine-update-state-v2','schema_version'=>2,'active_version'=>$version,
 'active_managed_tree_sha256'=>$accepted[0]['managed_tree_sha256'],
 'previous_version'=>'','lkg_recovery_id'=>'','staged'=>null,'pending_verification'=>null,'activation_journal'=>null,
 'db_forward_fence'=>['transaction_id'=>'20261020-legacy','policy'=>'forward_recovery_only','pinned'=>'must-preserve'],'history'=>[]];
file_put_contents($storage.'/routine-update-v2/state.json',json_encode($state,JSON_THROW_ON_ERROR));
$svc=new RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json',static fn():bool=>true,static fn():array=>[],null,static fn():array=>[]);
$bridge=new IndependentEmergencyRoutineBridge($svc,$storage,static fn():array=>[],static function()use($root):array{return ['ok'=>true,'database'=>'ready','emergency_ready'=>true,'pending_migrations'=>0,'version'=>trim((string)file_get_contents($root.'/VERSION.txt'))];});
qa(($bridge->snapshot()['managed_tree_matches_expected']??null)===true,'real_r171_tree_matches_state');
qa(($bridge->snapshot()['forward_only_protected']??null)===true,'prior_forward_fence_detected');
$stage=$bridge->stageUploadedZip($pkg,'r249_ci');
qa(($stage['strict_multibaseline_v1']??null)===true,'stage_strict_compatible');
qa(($stage['version']??null)===$m['version'],'stage_target_version');
qa(trim(file_get_contents($root.'/VERSION.txt'))===$version,'stage_does_not_change_live_code');
$activated=$bridge->activateStaged('r249_ci');
qa(is_array($activated['pending_verification']??null),'activation_pending_not_committed');
qa(trim(file_get_contents($root.'/VERSION.txt'))===$m['version'],'target_code_active');
qa(($activated['managed_tree_sha256']??null)===$m['target_managed_tree_sha256'],'pending_target_tree_readback');
qa(($activated['managed_tree_matches_expected']??null)===false,'pending_not_misreported_committed');
qa(hash_file('sha256',$root.'/emergency.php')===$emergencyHash,'emergency_stable_unchanged');
qa(hash_file('sha256',$root.'/recovery.php')===$recoveryHash,'recovery_owner_unchanged');
qa((require $root.'/config.php')['app']['base_path']==='/public','subdirectory_config_preserved');
qa(count(glob($root.'/database/migrations/*.sql'))===8,'eight_migrations_retained');
$committed=$bridge->commitPending('r249_ci');
qa(($committed['active_version']??null)===$m['version'],'commit_new_active_version');
qa(($committed['pending_verification']??null)===null,'commit_clears_pending');
qa(($committed['managed_tree_matches_expected']??null)===true,'committed_tree_verified');
qa(($committed['db_forward_fence']['pinned']??null)==='must-preserve','historic_db_fence_preserved');
qa(($committed['lkg_recovery_id']??null)==='','historic_fence_does_not_fake_rollback');
try{$bridge->rollback('r249_ci');throw new RuntimeException('UNSAFE_ROLLBACK_ALLOWED');}catch(PublicUpdateException $e){qa($e->errorCode==='emergency_rollback_not_qualified','rollback_explicitly_prohibited');}
echo "PASS_R249_DUAL_BASE_MANIFEST_R171_REAL_PHP_ROUTINE_FORWARD_ONLY\n";
