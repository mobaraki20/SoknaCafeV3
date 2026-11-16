<?php
declare(strict_types=1);
require __DIR__.'/ZipArchivePolyfill.php';
$base=dirname(__DIR__);
$root=__DIR__.'/fixture_public';
$storage=$root.'/storage';
require_once $root.'/src/Emergency/PublicUpdateException.php';
require_once $root.'/src/Update/RoutinePublicUpdateService.php';
require_once $root.'/src/Update/IndependentEmergencyRoutineBridge.php';
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
use Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge;
use Sokna\PublicEdge\Emergency\PublicUpdateException;
$appliedPath=$storage.'/test-applied.json';
$readApplied=static function()use($appliedPath):array {return (array)json_decode((string)file_get_contents($appliedPath),true);};
$pending=static function()use($root,$readApplied):array {
 $applied=array_flip($readApplied());
 $catalog=array_map(fn($f)=>basename($f,'.sql'),glob($root.'/database/migrations/*.sql')?:[]);
 $out=array_values(array_filter($catalog,fn($f)=>!isset($applied[$f])));sort($out);return $out;
};
$migrate=static function()use($pending,$appliedPath,$readApplied,$storage):array {
 $done=[];foreach($pending() as $version){
  // Controlled one-time mid-plan failure: 0009 and 0010 become persisted.
  if($version==='0011_public_move_intake_pause'&&is_file($storage.'/fail-once')){
   unlink($storage.'/fail-once');throw new RuntimeException('SIMULATED_OUTAGE_BEFORE_0011_DDL');
  }
  $applied=$readApplied();$applied[]=$version;sort($applied);file_put_contents($appliedPath,json_encode($applied));$done[]=$version;
 }
 return $done;
};
$health=static function()use($root,$pending,$readApplied):array {
 return ['ok'=>true,'database'=>'ready','emergency_ready'=>true,'pending_migrations'=>count($pending()),
  'applied_migrations'=>count($readApplied()),'catalog_migrations'=>count(glob($root.'/database/migrations/*.sql')),
  'version'=>trim((string)file_get_contents($root.'/VERSION.txt'))];
};
$routine=new RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json',static fn():bool=>true,$migrate,null,$pending);
$bridge=new IndependentEmergencyRoutineBridge($routine,$storage,$pending,$health);
$action=$argv[1]??'';
$orig=realpath((getenv('SOKNA_INPUT_ROOT')?:'/mnt/data/public-go-live-audit').'/SOKNA-R262-PUBLIC-35ID-CUMULATIVE-QA-NOT-FOR-INSTALL.zip');
$bridgePatch=realpath($base.'/output/SOKNA-R265-PUBLIC-35IF-EMERGENCY-BRIDGE-CODE-ONLY-QA.zip');
$final=realpath($base.'/output/SOKNA-R266-PUBLIC-35IG-SCHEMA-MOVE-CUMULATIVE-QA.zip');
$assert=static function(bool $cond,string $msg):void {if(!$cond)throw new RuntimeException('FAIL:'.$msg);};
try{
 switch($action){
  case 'reject_old':
   $bridge->stageUploadedZip($orig,'qa');
   try {$bridge->activateStaged('qa');throw new RuntimeException('FAIL: OLD_BRIDGE_SHOULD_HAVE_REJECTED');}
   catch(PublicUpdateException $e){$assert($e->errorCode==='emergency_schema_mutation_not_qualified','wrong code '.$e->errorCode);}
   $assert($routine->currentVersion()==='3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate','old version changed');
   break;
  case 'patch_activate':
   $bridge->stageUploadedZip($bridgePatch,'qa');
   $bridge->activateStaged('qa');
   $assert((bool)$routine->snapshot()['pending_verification'],'patch not pending');
   break;
  case 'patch_commit':
   $bridge->commitPending('qa');
   $assert(($routine->snapshot()['active_version']??'')==='3.0.0-g3.6-workspace.35if-r265-emergency-schema-bridge','patch not committed');
   $assert(count($readApplied())===8,'patch mutated db');
   break;
  case 'final_activate':
   $bridge->stageUploadedZip($final,'qa');
   $bridge->activateStaged('qa');
   $assert(count($pending())===3,'three migrations not present');
   $assert((bool)$routine->snapshot()['pending_verification'],'final not pending');
   break;
  case 'fail_commit':
   file_put_contents($storage.'/fail-once','1');
   try{$bridge->commitPending('qa');throw new RuntimeException('FAIL:EXPECTED_FAILURE');}
   catch(PublicUpdateException $e){$assert($e->errorCode==='migration_failed','wrong failure '.$e->errorCode);}
   $s=$routine->snapshot();$assert(is_array($s['db_forward_fence']??null),'no forward fence');
   $assert(is_array($s['pending_verification']??null),'pending lost');
   $assert(count($readApplied())===10,'expected partial applied 10');
   $assert(is_file($routine->maintenanceLockPath()),'maintenance gate not held');
   break;
  case 'resume_commit':
   $bridge->commitPending('qa');
   $s=$routine->snapshot();$assert(($s['active_version']??'')==='3.0.0-g3.6-workspace.35ig-r266-public-move-schema-recovery','final version mismatch');
   $assert(($s['pending_verification']??null)===null,'still pending');
   $assert(($s['db_forward_fence']??null)===null,'fence not resolved');
   $assert(count($readApplied())===11,'11 migrations not applied');
   $assert(!is_file($routine->maintenanceLockPath()),'maintenance gate not cleared');
   $assert(($s['lkg_recovery_id']??'')==='','rollback recovery must be unavailable after migration');
   break;
  default: throw new RuntimeException('unknown action');
 }
 $s=$routine->snapshot();echo json_encode(['ok'=>true,'action'=>$action,'current'=>$s['current_version'],'active'=>$s['active_version'],'pending_migrations'=>$pending(),'applied'=>count($readApplied()),'fence'=>is_array($s['db_forward_fence']??null)],JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,$e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(2);}
