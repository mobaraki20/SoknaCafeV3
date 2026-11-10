<?php
declare(strict_types=1);
// Real native updater + native SQL in disposable MariaDB. No HTTP/auth/stable-owner claim.
if(getenv('GITHUB_ACTIONS')!=='true' || getenv('SOKNA_PUBLIC_QA_DISPOSABLE')!=='YES')throw new RuntimeException('DISPOSABLE_GITHUB_CI_ONLY');
function need(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
// Support the native Windows Local/MariaDB QA host without changing the Linux baseline.
// Never accept a remote DB address or a customer database name.
function qaDsn(?string $name=null):string{
 $raw=getenv('SOKNA_PUBLIC_QA_DB_PORT');$port=$raw===false?'3306':$raw;
 if(!preg_match('/^[0-9]{4,5}$/D',$port) || (int)$port<1024 || (int)$port>65535)throw new RuntimeException('DISPOSABLE_DB_PORT_INVALID');
 return 'mysql:host=127.0.0.1;port='.$port.($name===null?'':';dbname='.$name);
}
function qaPass():string{$p=getenv('SOKNA_PUBLIC_QA_DB_PASSWORD');return $p===false?'ci-root-only':$p;}
function db(string $name):PDO{
 if(!preg_match('/^sokna_public_qa_[a-z0-9_]+$/D',$name))throw new RuntimeException('DB_SCOPE');
 return new PDO(qaDsn($name),'root',qaPass(),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function loadNative(string $site):void{
 require_once __DIR__.'/PublicUpdateException.php';
 foreach(['Update/RoutinePublicUpdateService.php','Update/IndependentEmergencyRoutineBridge.php','Core/Migrations.php'] as $p)require_once $site.'/src/'.$p;
}
function providers(string $site,string $database):array{
 $pdo=db($database);
 $m=new Sokna\PublicEdge\Core\Migrations($pdo,$site.'/database/migrations');
 $pending=fn()=>$m->pendingVersions();
 $fresh=function()use($pdo,$m,$site):array{
  // Actual DB/schema/version readback; endpoint authentication is deliberately outside this harness.
  return ['ok'=>(int)$pdo->query('SELECT 1')->fetchColumn()===1,
   'database'=>'ready','pending_migrations'=>count($m->pendingVersions()),
   'emergency_ready'=>is_file($site.'/emergency.php'),
   'version'=>trim((string)file_get_contents($site.'/VERSION.txt'))];
 };
 return [$pdo,$m,$pending,$fresh];
}
function updater(string $site,string $store,string $database,?callable $health=null):array{
 [$pdo,$m,$pending,$fresh]=providers($site,$database);
 $routine=new Sokna\PublicEdge\Update\RoutinePublicUpdateService($site,$store,$store.'/trust.json',
  $health??fn()=>$fresh()['ok'],fn()=>$m->migrate(),null,$pending);
 $bridge=new Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge($routine,$store,$pending,$fresh);
 return [$routine,$bridge,$m,$pdo];
}
if(($argv[1]??'')==='commit'){
 [,,$site,$store,$database,$channel,$expected]=$argv; loadNative($site);
 [$routine,$bridge]=updater($site,$store,$database);
 $s=$channel==='routine'?$routine->commitActivation('fresh-process'):$bridge->commitPending('fresh-process');
 need($s['active_version']===$expected && $s['pending_verification']===null,'fresh process target-code commit');
 exit(0);
}
function reject(callable $work,string $label,?string $code=null):void{
 try{$work();}catch(Sokna\PublicEdge\Emergency\PublicUpdateException $e){
  need($code===null||$e->errorCode===$code,$label.' ['.$e->errorCode.']');return;
 }throw new RuntimeException('FAIL accepted '.$label);
}
function extractPayload(string $archive,string $site):array{
 $z=new ZipArchive();need($z->open($archive)===true,'open exact archive');
 $m=json_decode((string)$z->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);
 foreach($m['files'] as $row){$b=$z->getFromName('payload/'.$row['path']);
  if(!is_string($b)||strlen($b)!==$row['size']||hash('sha256',$b)!==$row['sha256'])throw new RuntimeException('member integrity '.$row['path']);
  $path=$site.'/'.$row['path'];if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$b);
 }
 $z->close();
 // Minimal protected-owner fixture: permits native file-health check, never represents real HTTP/auth.
 if(!is_dir($site.'/src/Emergency'))mkdir($site.'/src/Emergency',0700,true);
 file_put_contents($site.'/src/Emergency/PublicUpdateService.php',"<?php // protected owner fixture, no class\n");
 file_put_contents($site.'/emergency.php',"<?php // protected stable entrypoint fixture\n");
 return $m;
}
function ownerFactory(string $site,string $store):array{
 if(!function_exists('g39_load_owner')){
  $s=file_get_contents($site.'/emergency-next.php');
  $start=strpos($s,'function g39_owner_reject(');$end=strpos($s,'// Redirect only',$start);
  need($start!==false&&$end!==false,'actual native factory extracted');
  eval(substr($s,$start,$end-$start));
 }
 $result=g39_load_owner($site,$store);
 $GLOBALS['sokna_emergency_candidate_healthy']=true;
 return $result;
}
$dir=sys_get_temp_dir().'/sokna-public-two-route-'.bin2hex(random_bytes(4));mkdir($dir,0700,true);
$dbNames=[];$results=[];
$server=new PDO(qaDsn(),'root',qaPass(),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try{
 $target=__DIR__.'/candidate.zip';$targetHash=hash_file('sha256',$target);
 need($targetHash==='1131d89c98e05c9597f372ed1d480e5637c3ad1969bc8733d2a837b644e57584','same immutable Public ZIP');
 $r149=extractPayload(__DIR__.'/r149.zip',$dir.'/old-owner');
 need(ownerFactory($dir.'/old-owner',$dir.'/old-owner-data')['mode']==='legacy_read_only','reproduced stale R149 owner binding');
 $targetM=extractPayload($target,$dir.'/new-owner');
 need(ownerFactory($dir.'/new-owner',$dir.'/new-owner-data')['mode']==='candidate','fixed exact final owner binding');
 loadNative($dir.'/new-owner');
 $sources=['r115'=>'b7d5d2b5cd5a05bb79ee17a997708354ed32813c866a9ed766047a6ccb732433',
  'r126'=>'b6f08ab053120f8bf008129b989faf210ebd850d40fae5e5743a9c11958e44ab'];
 foreach($sources as $base=>$baseHash)foreach(['routine','emergency'] as $channel){
  need(hash_file('sha256',__DIR__.'/'.$base.'.zip')===$baseHash,'exact '.$base.' archive');
  $case=$base.'_'.$channel;$site=$dir.'/'.$case;$store=$dir.'/'.$case.'-store';
  $old=extractPayload(__DIR__.'/'.$base.'.zip',$site);
  foreach(['RoutinePublicUpdateService.php','IndependentEmergencyRoutineBridge.php'] as $name)
   need(hash_file('sha256',$site.'/src/Update/'.$name)===hash_file('sha256',$dir.'/new-owner/src/Update/'.$name),'exact native updater unchanged '.$name);
  $database='sokna_public_qa_'.$case;$dbNames[]=$database;
  $server->exec('CREATE DATABASE `'.$database.'`');
  [$routine,$bridge,$m,$pdo]=updater($site,$store,$database);
  $m->migrate();need($m->pendingVersions()===[],'native source schema fully applied');
  $pdo->exec('CREATE TABLE qa_business_sentinel(id INT PRIMARY KEY, qty INT NOT NULL) ENGINE=InnoDB');
  $qty=$base==='r115'?115:126;$pdo->exec('INSERT INTO qa_business_sentinel VALUES(1,'.$qty.')');
  need($routine->managedTreeDigest()===$old['target_managed_tree_sha256'],'exact installed source tree');
  // A prior verified installed receipt is explicit fixture state, never inferred from an unknown real host.
  mkdir($store.'/routine-update-v2',0700,true);
  file_put_contents($store.'/routine-update-v2/state.json',json_encode([
   'format'=>'sokna-public-routine-update-state-v2','schema_version'=>2,
   'active_version'=>$old['version'],'active_managed_tree_sha256'=>$old['target_managed_tree_sha256'],
   'previous_version'=>'','lkg_recovery_id'=>'','staged'=>null,'pending_verification'=>null,
   'activation_journal'=>null,'db_forward_fence'=>null,'history'=>[]]));
  $stable=hash_file('sha256',$site.'/emergency.php');
  if($channel==='routine')$routine->stageUploadedDeltaZip($target,'qa',true);else $bridge->stageUploadedZip($target,'qa');
  need($routine->snapshot()['staged']['package_sha256']===$targetHash,'both routes stage same outer ZIP digest');
  $lock=fopen($store.'/public-update-lifecycle.lock','c+');
  need(flock($lock,LOCK_EX|LOCK_NB),'test owns actual shared lifecycle lock');
  reject(fn()=>$channel==='routine'?$routine->activateStaged('qa'):$bridge->activateStaged('qa'),'shared lock blocks '.$case,'shared_update_busy');
  need($routine->managedTreeDigest()===$old['target_managed_tree_sha256'],'busy lock leaves exact old code');
  flock($lock,LOCK_UN);fclose($lock);
  if($channel==='emergency'){
   $versions=$m->appliedVersions();$last=end($versions);
   $delete=$pdo->prepare('DELETE FROM schema_migrations WHERE version=?');$delete->execute([$last]);
   need($m->pendingVersions()===[$last],'actual DB ledger reports pending migration');
   reject(fn()=>$bridge->activateStaged(),'Emergency pending DB refuses before mutation','db_forward_recovery_required');
   $restore=$pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');$restore->execute([$last]);
   need($routine->managedTreeDigest()===$old['target_managed_tree_sha256'],'DB rejection leaves exact old code');
  }
  $started=$channel==='routine'?$routine->activateStaged('qa'):$bridge->activateStaged('qa');
  need(is_array($started['pending_verification'])&&$routine->managedTreeDigest()===$targetM['target_managed_tree_sha256'],'activation awaits separate target verification');
  if($channel==='emergency'){
   $stale=new Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge($routine,$store,fn()=>$m->pendingVersions(),fn()=>['ok'=>true,'database'=>'ready','emergency_ready'=>true,'pending_migrations'=>0,'version'=>'wrong']);
   reject(fn()=>$stale->commitPending(),'stale health refuses commit','emergency_target_health_not_qualified');
   need(is_array($routine->snapshot()['pending_verification']),'stale health retains pending proof');
  }
  $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' commit '.implode(' ',array_map('escapeshellarg',[$site,$store,$database,$channel,$targetM['version']]));
  passthru($cmd,$exit);need($exit===0,'fresh separate PHP process committed '.$case);
  [$after,, $targetMigration,$freshPdo]=updater($site,$store,$database);
  need($after->snapshot()['managed_tree_matches_expected']===true&&$after->snapshot()['pending_verification']===null,'durable committed exact target');
  need($targetMigration->pendingVersions()===[]&&(int)$freshPdo->query('SELECT qty FROM qa_business_sentinel WHERE id=1')->fetchColumn()===$qty,'real DB ledger and business sentinel preserved');
  need(hash_file('sha256',$site.'/emergency.php')===$stable,'protected stable owner unchanged');
  reject(fn()=>$after->stageUploadedDeltaZip($target,'qa',true),'equal-version replay rejected');
  if($channel==='routine'){
   $failSite=$dir.'/'.$case.'-health-failure';$failStore=$store.'-failure';
   extractPayload(__DIR__.'/'.$base.'.zip',$failSite);
   [$failed]=updater($failSite,$failStore,$database,fn()=>false);
   $failed->stageUploadedDeltaZip($target,'qa',true);
   reject(fn()=>$failed->activateStaged('qa'),'health failure rejected activation');
   need($failed->currentVersion()===$old['version']&&$failed->managedTreeDigest()===$old['target_managed_tree_sha256'],'health failure restores exact baseline');
   file_put_contents($failSite.'/VERSION.txt',$old['version'].'-unknown');
   reject(fn()=>$failed->stageUploadedDeltaZip($target,'qa',true),'unknown source tree rejected before stage');
  }
  $results[]=['baseline'=>$base,'channel'=>$channel,'source_zip_sha256'=>$baseHash,
   'target_zip_sha256'=>$targetHash,'target_tree'=>$targetM['target_managed_tree_sha256'],
   'fresh_process_commit'=>true,'native_migrations'=>count($targetMigration->appliedVersions()),'business_qty'=>$qty];
 }
 $summary=['format'=>'sokna-public-two-adapter-qa-v1','cases'=>$results,
  'actual_http_auth_and_https_verified'=>false,'protected_stable_entrypoint_delegation_verified'=>false,
  'prior_installed_receipt'=>'EXPLICIT_DISPOSABLE_FIXTURE','physical_cafe_verified'=>false,'production_authorized'=>false];
 file_put_contents(__DIR__.'/two-route-summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
 echo 'SOKNA_PUBLIC_SAME_ZIP_TWO_BASELINES_TWO_ADAPTERS_PASS'.PHP_EOL;
}finally{
 foreach($dbNames as $name)$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);
}
