<?php
declare(strict_types=1);
/* Public R126 -> R149 exact full ZIP routine E2E on disposable local filesystem. */
if (getenv('SOKNA_PUBLIC_QA_DISPOSABLE') !== 'YES') throw new RuntimeException('DISPOSABLE_ONLY');
if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP_ZIP_NOT_INSTALLED');
$root = __DIR__;
$base = $root.'/r126.zip';$update=$root.'/r149.zip';
if (!is_file($base)||!is_file($update)) throw new RuntimeException('MISSING_EXACT_BINARIES');
if(hash_file('sha256',$base)!=='b6f08ab053120f8bf008129b989faf210ebd850d40fae5e5743a9c11958e44ab') throw new RuntimeException('R126_SHA');
if(hash_file('sha256',$update)!=='8bcd797b25584d122733242ab16c5b2679503f62aea4d5bca97d4458db52d913') throw new RuntimeException('R149_SHA');
$baseZip=new ZipArchive();$baseZip->open($base);$a=json_decode((string)$baseZip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
$updateZip=new ZipArchive();$updateZip->open($update);$b=json_decode((string)$updateZip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach([$a,$b] as $package){foreach($package['files'] as $f){
 $raw=$package=== $a ? $baseZip->getFromName('payload/'.$f['path']) : $updateZip->getFromName('payload/'.$f['path']);
 if(!is_string($raw) || strlen($raw)!==$f['size'] ||hash('sha256',$raw)!==$f['sha256']) throw new RuntimeException('ZIP_FILE_INTEGRITY');
}}
$expectedBaseTree=(string)$a['target_managed_tree_sha256'];$expectedTargetTree=(string)$b['target_managed_tree_sha256'];
$allow=$b['compatibility']['supported_baselines']??[];
if(count($allow)!==29)throw new RuntimeException('29_BASELINES');
if(count(array_filter($allow,fn($row)=>$row['version']===$a['version'] && $row['managed_tree_sha256']===$expectedBaseTree))!==1)throw new RuntimeException('CURRENT_R126_MISSING_ALLOWLIST');
if($a['version']===$b['version']||!str_contains($a['version'],'35hg')||!str_contains($b['version'],'35hh'))throw new RuntimeException('BASE_TARGET_VERSION');
// Above alias is only for load compatibility; PublicUpdateException needs public errorCode, so create actual class in file below.
require_once $root.'/PublicUpdateException.php';
require_once $root.'/public/RoutinePublicUpdateService.php';
function need(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('FAIL '.$message);echo 'PASS '.$message.PHP_EOL;}
function fixture(ZipArchive $zip,string $folder):void{
 if(is_dir($folder)){ $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($folder);}
 mkdir($folder,0700,true);
 for($i=0;$i<$zip->numFiles;$i++){$name=$zip->getNameIndex($i);if(!str_starts_with($name,'payload/')||str_ends_with($name,'/'))continue;
  $rel=substr($name,8);$p=$folder.'/'.$rel;if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);file_put_contents($p,$zip->getFromIndex($i));}
 file_put_contents($folder.'/emergency.php',"<?php // stable emergency owner, untouched\n");
}
$dir=sys_get_temp_dir().'/sokna-public-r149-fixture-'.bin2hex(random_bytes(4));mkdir($dir,0700,true);
try{
 $site=$dir.'/live';$store=$dir.'/data';fixture($baseZip,$site);
 $updater=new Sokna\PublicEdge\Update\RoutinePublicUpdateService($site,$store,$dir.'/trust.json');
 need($updater->managedTreeDigest()===$expectedBaseTree,'baseline R126 exact managed tree');
 $stage=$updater->stageUploadedDeltaZip($update,'ci',true);
 need(($stage['version']??'')===$b['version'],'full ZIP strict 29-base stage');
 $started=$updater->activateStaged('ci');
 need($updater->currentVersion()===$b['version'] && $updater->managedTreeDigest()===$expectedTargetTree,'full ZIP exact target activation');
 need(is_array($started['pending_verification']??null),'pending external verification required');
 $committed=$updater->commitActivation('ci');
 need(($committed['active_version']??'')===$b['version'] && ($committed['active_managed_tree_sha256']??'')===$expectedTargetTree,'routine commit complete');
 need(!is_file($updater->maintenanceLockPath()),'routine maintenance lock released');
 need(trim((string)file_get_contents($site.'/emergency.php'))==='<?php // stable emergency owner, untouched','stable emergency path unchanged');
 // New test for safe failure: fresh baseline, fail health after applying delta and enforce preimage restore.
 $site2=$dir.'/failed';fixture($baseZip,$site2);
 $updater2=new Sokna\PublicEdge\Update\RoutinePublicUpdateService($site2,$dir.'/fail-data',$dir.'/trust.json',fn()=>false);
 need($updater2->managedTreeDigest()===$expectedBaseTree,'failure baseline exact');
 $updater2->stageUploadedDeltaZip($update,'ci',true);
 try{$updater2->activateStaged('ci');throw new RuntimeException('FAIL expected health rejection');}
 catch(Sokna\PublicEdge\Emergency\PublicUpdateException $e){need(true,'health failure triggers rollback');}
 need($updater2->currentVersion()===$a['version'] && $updater2->managedTreeDigest()===$expectedBaseTree,'health failure restored baseline exact');
 // Unknown tampered source (one managed path) must not even stage.
 $site3=$dir.'/unknown';fixture($baseZip,$site3);file_put_contents($site3.'/VERSION.txt',$a['version'].'-wrong');
 $updater3=new Sokna\PublicEdge\Update\RoutinePublicUpdateService($site3,$dir.'/no-data',$dir.'/trust.json');
 try{$updater3->stageUploadedDeltaZip($update,'ci',true);throw new RuntimeException('FAIL accepted unknown baseline');}
 catch(Sokna\PublicEdge\Emergency\PublicUpdateException $e){need(true,'unknown baseline rejected before stage');}
 echo 'SOKNA_PUBLIC_R149_UNIFIED_FULL_ZIP_ROUTINE_E2E_PASS'.PHP_EOL;
}finally{
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}