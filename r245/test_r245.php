<?php
declare(strict_types=1);
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
$base=__DIR__;$root=$base.'/runtime-site';$storage=$base.'/runtime-private';
require_once __DIR__.'/public-update-exception.php';
require_once __DIR__.'/r171-service.php';
function check(string $name,bool $yes):void{if(!$yes)throw new RuntimeException('FAILED_'.$name);echo 'PASS '.$name."\n";}
function copy_tree(string $src,string $dst):void{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $f){$rel=substr($f->getPathname(),strlen($src)+1);$to=$dst.'/'.$rel;if($f->isDir()){if(!is_dir($to))mkdir($to,0755,true);}else{if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);copy($f->getPathname(),$to);}}}
function clear_dir(string $path):void{if(!is_dir($path))return;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f) $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($path);}
foreach([$root,$storage] as $p)clear_dir($p);
mkdir($root,0755,true);mkdir($storage,0755,true);
copy_tree($base.'/r171',$root);
mkdir($root.'/src/Emergency',0755,true);
copy(__DIR__.'/public-update-exception.php',$root.'/src/Emergency/PublicUpdateException.php');
mkdir($root.'/storage',0755,true); // private storage is excluded by routine updater
file_put_contents($root.'/config.php',"<?php return ['app'=>['base_path'=>'/public']];\n");
file_put_contents($root.'/resources/update-trust-v1.json',json_encode(['format'=>'sokna-update-trust-v1','signature_policy'=>'not_required_for_operator_upload']));
$trustedOwner=$root.'/src/Update/EmergencyOwnerCandidate.php';
$ownerBefore=hash_file('sha256',$trustedOwner);
$recoveryBefore=hash_file('sha256',$root.'/recovery.php');
$configBefore=hash_file('sha256',$root.'/config.php');
$trustBefore=hash_file('sha256',$root.'/resources/update-trust-v1.json');
$original=(string)file_get_contents($root.'/VERSION.txt');
$pkg=$base.'/SOKNA-R245-PUBLIC-35IA-FROM-EXACT-35HT-GROUP-QA-DO-NOT-INSTALL.zip';
$manifest=json_decode((string)file_get_contents($base.'/candidate-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$svc=function()use($root,$storage):RoutinePublicUpdateService{return new RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json',static fn():bool=>true,static fn():array=>[],null,static fn():array=>[]);};
$service=$svc();
try {
 $snap=$service->snapshot();
 check('exact_initial_version',$snap['current_version']==='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat');
 check('exact_r171_tree',$snap['managed_tree_sha256']===$manifest['compatibility']['from_managed_tree_sha256']);
 // Valid source must be checked BEFORE uploading. On a modified source, fail closed.
 file_put_contents($root.'/VERSION.txt','3.0.0-g3.6-workspace.35ho-r158-test');
 try{$service->stageUploadedDeltaZip($pkg,'r245-qa');throw new RuntimeException('INVALID_BASE_WAS_ACCEPTED');}catch(Sokna\PublicEdge\Emergency\PublicUpdateException $e){check('bad_version_fenced',in_array($e->errorCode,['base_version_mismatch','downgrade_or_reinstall_forbidden'],true));}
 file_put_contents($root.'/VERSION.txt',$original);
 file_put_contents($root.'/assets/scds/guest.js',(string)file_get_contents($root.'/assets/scds/guest.js').'\n// unexpected drift\n');
 try{$service->stageUploadedDeltaZip($pkg,'r245-qa');throw new RuntimeException('MODIFIED_TREE_WAS_ACCEPTED');}catch(Sokna\PublicEdge\Emergency\PublicUpdateException $e){check('bad_tree_fenced',in_array($e->errorCode,['base_tree_mismatch','base_not_qualified'],true));}
 // Restore the original R171 payload and stage the true matching source.
 clear_dir($root);mkdir($root,0755,true);copy_tree($base.'/r171',$root);
 mkdir($root.'/src/Emergency',0755,true);copy(__DIR__.'/public-update-exception.php',$root.'/src/Emergency/PublicUpdateException.php');
 mkdir($root.'/storage',0755,true);file_put_contents($root.'/config.php',"<?php return ['app'=>['base_path'=>'/public']];\n");
 file_put_contents($root.'/resources/update-trust-v1.json',json_encode(['format'=>'sokna-update-trust-v1','signature_policy'=>'not_required_for_operator_upload']));
 $service=$svc();$st=$service->stageUploadedDeltaZip($pkg,'r245-qa');
 check('stage_version_matches',(string)$st['version']===$manifest['version']);
 $snap=$service->snapshot();check('stage_has_not_mutated_tree',$snap['current_version']==='3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat');
 $act=$service->activateStaged('r245-qa');$now=$service->snapshot();
 check('activation_version',$now['current_version']===$manifest['version']);
 check('activation_tree',$now['managed_tree_sha256']===$manifest['target_managed_tree_sha256']);
 check('db_migrations_unchanged',count(glob($root.'/database/migrations/*.sql'))===8);
 $fin=$service->commitPending('r245-qa');$after=$service->snapshot();
 check('commit_accepted',$after['active_version']===$manifest['version']);
 check('pending_cleared',empty($after['pending_verification'])&&empty($after['activation_journal']));
 check('owner_candidate_protected',hash_file('sha256',$trustedOwner)===$ownerBefore);
 check('recovery_owner_protected',hash_file('sha256',$root.'/recovery.php')===$recoveryBefore);
 check('config_protected',hash_file('sha256',$root.'/config.php')===$configBefore);
 check('trust_protected',hash_file('sha256',$root.'/resources/update-trust-v1.json')===$trustBefore);
 $meta=json_decode(file_get_contents($root.'/src/Update/emergency-owner-v1.json'),true);
 check('owner_metadata_matches_release',$meta['release_version']===$manifest['version']&&$meta['sha256']===hash_file('sha256',$trustedOwner));
 check('base_path_is_untouched',(require $root.'/config.php')['app']['base_path']==='/public');
 echo 'R245_EXACT_R171_TO_TARGET_PHP_ROUTINE_STAGE_ACTIVATE_COMMIT_PASS'."\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).' '.($e->errorCode??$e->getCode()).' '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()."\n");exit(1);}