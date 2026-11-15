<?php
declare(strict_types=1);
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
use Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge;
use Sokna\PublicEdge\Emergency\PublicUpdateException;
$root=__DIR__.'/run-public';$storage=__DIR__.'/run-storage';
require_once __DIR__.'/public-update-exception.php';
require_once __DIR__.'/r171-service.php';
require_once __DIR__.'/r171-bridge.php';
function require_ok(bool $ok,string $name):void{if(!$ok)throw new RuntimeException('FAILED_'.$name);echo 'PASS '.$name."\n";}
function rm_tree(string $root):void{if(!is_dir($root))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $x){if($x->isDir())rmdir($x->getPathname());else unlink($x->getPathname());}rmdir($root);}
function cp_tree(string $src,string $dest):void{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $x){$rel=substr($x->getPathname(),strlen($src)+1);$path=$dest.'/'.$rel;if($x->isDir()){if(!is_dir($path))mkdir($path,0755,true);}else{if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);copy($x->getPathname(),$path);}}}
rm_tree($root);rm_tree($storage);mkdir($root,0755,true);mkdir($storage,0755,true);cp_tree(__DIR__.'/r171',$root);
mkdir($root.'/src/Emergency',0755,true);
copy(__DIR__.'/public-update-exception.php',$root.'/src/Emergency/PublicUpdateException.php');
file_put_contents($root.'/emergency.php',"<?php // disposable Emergency fixture only\n");
file_put_contents($root.'/config.php',"<?php return ['app'=>['base_path'=>'/public']];\n");
file_put_contents($root.'/resources/update-trust-v1.json','{}');
$pkg=__DIR__.'/SOKNA-R246-PUBLIC-35IB-FROM-35HT-EMERGENCY-BRIDGE-QA-DO-NOT-INSTALL.zip';
$old=__DIR__.'/SOKNA-R245-PUBLIC-35IA-FROM-EXACT-35HT-GROUP-QA-DO-NOT-INSTALL.zip';
$zip=new ZipArchive();require_ok($zip->open($pkg)===true,'zip_open');$m=json_decode((string)$zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);$zip->close();
require_ok(($m['compatibility']['supported_baselines'][0]['version']??null)===trim(file_get_contents($root.'/VERSION.txt')),'exact_r171_baseline');
require_ok(($m['provenance']['strict_multibaseline_v1']??null)===true,'strict_emergency_protocol');
$realOwnerMeta=json_decode(file_get_contents($root.'/src/Update/emergency-owner-v1.json'),true);
$realOwnerVersion=trim(file_get_contents($root.'/VERSION.txt'));
require_ok(($realOwnerMeta['release_version']??null)!==$realOwnerVersion,'old_live_owner_metadata_mismatch_is_real');
mkdir($storage.'/routine-update-v2',0755,true);
$state=['format'=>'sokna-public-routine-update-state-v2','schema_version'=>2,'active_version'=>$realOwnerVersion,
'active_managed_tree_sha256'=>$m['compatibility']['supported_baselines'][0]['managed_tree_sha256'],'previous_version'=>'',
'lkg_recovery_id'=>'','staged'=>null,'pending_verification'=>null,'activation_journal'=>null,'db_forward_fence'=>null,'history'=>[]];
file_put_contents($storage.'/routine-update-v2/state.json',json_encode($state,JSON_THROW_ON_ERROR));
$svc=new RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json',static fn():bool=>true,static fn():array=>[],null,static fn():array=>[]);
$bridge=new IndependentEmergencyRoutineBridge($svc,$storage,static fn():array=>[],static function()use($root):array{return ['ok'=>true,'database'=>'ready','emergency_ready'=>true,'pending_migrations'=>0,'version'=>trim((string)file_get_contents($root.'/VERSION.txt'))];});
require_ok(($bridge->snapshot()['managed_tree_matches_expected']??null)===true,'source_tree_accepted');
try{$bridge->stageUploadedZip($old,'qa');throw new RuntimeException('OLD_R245_INVALID_PROTOCOL_ACCEPTED');}
catch(PublicUpdateException $e){require_ok($e->errorCode==='public_update_protocol_mismatch','old_r245_rejected_from_emergency');}
$staged=$bridge->stageUploadedZip($pkg,'qa');require_ok(($staged['strict_multibaseline_v1']??null)===true,'r246_staged_with_required_protocol');
require_ok(trim(file_get_contents($root.'/VERSION.txt'))===$realOwnerVersion,'stage_has_not_modified_live_tree');
$activated=$bridge->activateStaged('qa');require_ok(($activated['pending_verification']??null)!==null,'activation_requires_separate_commit');
require_ok(trim(file_get_contents($root.'/VERSION.txt'))===$m['version'],'activation_version');
$committed=$bridge->commitPending('qa');require_ok(($committed['active_version']??null)===$m['version'],'independent_emergency_commit');
require_ok(($committed['managed_tree_matches_expected']??null)===true,'final_tree_readback');
require_ok(($committed['pending_verification']??null)===null,'no_pending_activation');
require_ok(is_file($root.'/emergency.php')&&is_file($root.'/recovery.php')&&is_file($root.'/config.php'),'protected_files_retained');
require_ok((require $root.'/config.php')['app']['base_path']==='/public','base_path_preserved');
require_ok(count(glob($root.'/database/migrations/*.sql'))===8,'eight_migrations_unchanged');
echo 'PASS_R246_INDEPENDENT_EMERGENCY_BRIDGE_SIMULATED_HOST\n';