<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Emergency {
 class PublicUpdateException extends \RuntimeException {
  public readonly string $errorCode;
  public function __construct(string $errorCode,string $message='',int $http=409,array $details=[]) {
   $this->errorCode=$errorCode; parent::__construct($message===''?$errorCode:($errorCode.': '.$message),$http);
  }
 }
}
namespace {
 if(count($argv)!==6)throw new \RuntimeException('usage: root package expected_version expected_tree expected_delta');
 [$_,$root,$pkg,$expectedVersion,$expectedTree,$expectedDelta]=$argv;
 require $root.'/src/Update/RoutinePublicUpdateService.php';
 $storage=dirname($root).'/storage-'.basename($root);
 if(!is_dir($storage))mkdir($storage,0700,true);
 // Stable Emergency/Recovery owners are preinstalled on actual Public. They are
 // NOT shipped inside a managed full snapshot, so a disposable fixture uses
 // immutable stub files to assert they never change during Routine upgrade.
 foreach(['emergency.php','src/Recovery/StableRecoveryService.php'] as $path){
  $target=$root.'/'.$path;
  if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
  if(!file_exists($target))file_put_contents($target,'<?php /* immutable QA stable owner */');
 }
 $stableSha=hash_file('sha256',$root.'/emergency.php');
 $service=new \Sokna\PublicEdge\Update\RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json',static fn()=>true);
 $tree=$service->managedTreeDigest();
 if($tree!==$expectedTree||$service->currentVersion()!==$expectedVersion)throw new \RuntimeException('R152_PUBLIC_BASELINE_BYTES_NOT_EXACT: '.$tree);
 $rp=new \ReflectionMethod($service,'assertUnifiedFullSourceCompatibility');
 $zip=new \ZipArchive(); if($zip->open($pkg)!==true)throw new \RuntimeException('CANDIDATE_ZIP_UNOPENABLE');
 $m=json_decode((string)$zip->getFromName('manifest.json'),true,flags:JSON_THROW_ON_ERROR);$zip->close();
 if(count($m['compatibility']['supported_baselines'])!==31)throw new \RuntimeException('NOT_31_EXACT_BASELINES');
 foreach([['version'=>$expectedVersion,'tree'=>str_repeat('f',64)],['version'=>'3.0.0-g3.6-workspace.35ga-unknown','tree'=>$expectedTree]] as $bad){
  try{$rp->invoke($service,$m['compatibility'],$bad['version'],$bad['tree'],$m['version']);throw new \RuntimeException('UNKNOWN_BASELINE_ACCEPTED');}
  catch(\Sokna\PublicEdge\Emergency\PublicUpdateException $e){if($e->errorCode!=='base_not_qualified')throw $e;}
 }
 $stage=$service->stageUploadedDeltaZip($pkg,'qa-test',true);
 if(($stage['version']??'')!==$m['version'])throw new \RuntimeException('STAGE_VERSION_MISMATCH');
 if($service->currentVersion()!==$expectedVersion||$service->managedTreeDigest()!==$tree)throw new \RuntimeException('STAGE_MUTATED_LIVE_FILES');
 $snap=$service->snapshot();
 $staged=(array)($snap['staged']??[]);
 if(($staged['strict_multibaseline_v1']??false)!==true)throw new \RuntimeException('NO_STRICT_MULTIBASELINE_PROTOCOL');
 $target=$storage.'/routine/staged/'.$m['version'].'/'.($staged['package_sha256']??'');
 // Inspect the normalized delta indirectly if storage layout differs.
 $candidateManifest=null;foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storage,\FilesystemIterator::SKIP_DOTS)) as $f){
  if($f->getFilename()==='manifest.json'){
   $try=json_decode((string)file_get_contents($f->getPathname()),true);
   if(($try['format']??'')==='sokna-public-delta-v2'){$candidateManifest=$try;break;}
  }
 }
 if(!is_array($candidateManifest)||count($candidateManifest['files'])!==(int)$expectedDelta||count($candidateManifest['deletes'])!==0)throw new \RuntimeException('NORMALIZED_DELTA_WRONG');
 $service->activateStaged('qa-test');
 if($service->currentVersion()!==$m['version']||$service->managedTreeDigest()!==$m['target_managed_tree_sha256'])throw new \RuntimeException('ACTIVATION_TARGET_IDENTITY_WRONG');
 $service->commitActivation('qa-test');
 $post=$service->snapshot();
 if(($post['active_version']??'')!==$m['version']||($post['pending_verification']??null)!==null||($post['activation_journal']??null)!==null||($post['db_forward_fence']??null)!==null)throw new \RuntimeException('PUBLIC_COMMIT_NOT_CLEAN');
 if(hash_file('sha256',$root.'/emergency.php')!==$stableSha)throw new \RuntimeException('STABLE_EMERGENCY_OWNER_MUTATED');
 echo 'PASS PUBLIC_NATIVE_PHP_ZIP_'.(int)$expectedDelta.'_FILE_DELTA_FROM_'.basename($root)."\n";
}
