<?php
declare(strict_types=1);
// Independent CLI integration of the exact helper's filesystem and DB preflight.
// Only the disposable GitHub runner and its dedicated schema are supported.
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||$argc!==3)throw new RuntimeException('CI_ONLY');
[$script,$root,$helper]=$argv;
$runner=realpath(getenv('RUNNER_TEMP'));$root=realpath($root);$helper=realpath($helper);
if(!is_string($runner)||!is_string($root)||!is_string($helper)||!str_starts_with($root,$runner.'/')||!str_starts_with($helper,$runner.'/'))throw new RuntimeException('DISPOSABLE_PATH');
$src=file_get_contents($helper);if(hash('sha256',$src)!=='5f5010dadaaae7cede43b20ca1e4fc8618362d66f9d31ca288ab96361779b88d')throw new RuntimeException('HELPER_PIN');
$a=strpos($src,'function check(');$b=strpos($src,'$root=__DIR__');if($a===false||$b===false)throw new RuntimeException('SOURCE');
$inc=$runner.'/cp107-functions.php';file_put_contents($inc,"<?php\ndeclare(strict_types=1);\n".substr($src,$a,$b-$a));require $inc;
if(!preg_match("/base64_decode\\('([A-Za-z0-9+\\/=]+)',true\\),true,64,JSON_THROW_ON_ERROR\\)/",$src,$match))throw new RuntimeException('PLAN');
$q=json_decode(base64_decode($match[1],true),true,64,JSON_THROW_ON_ERROR);
foreach(['src/Core/Config.php','src/Core/Database.php','src/Core/Migrations.php','src/Emergency/PublicUpdateException.php','src/Update/RoutinePublicUpdateService.php'] as $file)require_once $root.'/'.$file;
$cfg=Sokna\PublicEdge\Core\Config::fromArray(['db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_ci_cp107','user'=>'root','pass'=>getenv('SOKNA_CI_DB_PASS')]]);
$fixtureDb=Sokna\PublicEdge\Core\Database::connect($cfg);
$m=new Sokna\PublicEdge\Core\Migrations($fixtureDb,$root.'/database/migrations');check(count($m->migrate())===11,'FIXTURE_LEDGER');
$fixtureDb->exec("INSERT INTO installations(installation_id) VALUES('cp107-disposable')");
$storage=$root.'/storage';$r=new Sokna\PublicEdge\Update\RoutinePublicUpdateService($root,$storage,$root.'/resources/update-trust-v1.json');
check($r->managedTreeDigest()===$q['before'],'ORIGINAL_TREE');
$state=$storage.'/routine-update-v2/state.json';mkdir(dirname($state),0700,true);
$original=['format'=>'sokna-public-routine-update-state-v2','schema_version'=>2,'active_version'=>$q['version'],'active_managed_tree_sha256'=>$q['before'],'previous_version'=>'fixture-prior','lkg_recovery_id'=>'fixture-prior-lkg','staged'=>null,'pending_verification'=>null,'activation_journal'=>null,'db_forward_fence'=>null,'history'=>[['action'=>'fixture-prior-event']]];
file_put_contents($state,json_encode($original,JSON_THROW_ON_ERROR));
$before=$fixtureDb->query('SELECT version,applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
// Actual concurrent DB lock refusal, then actual acquisition using the helper code.
check((int)$fixtureDb->query("SELECT GET_LOCK('sokna_v3_public_migrations',0)")->fetchColumn()===1,'FIXTURE_LOCK');
try{repairDatabasePreflight($cfg,$q);throw new LogicException('LOCK_NOT_REJECTED');}catch(RuntimeException $e){check($e->getMessage()==='MIGRATION_BUSY','WRONG_LOCK_ERROR');}
$fixtureDb->query("SELECT RELEASE_LOCK('sokna_v3_public_migrations')");
$wrong=$q;$wrong['ledger']=array_slice($q['ledger'],0,10);
try{repairDatabasePreflight($cfg,$wrong);throw new LogicException('LEDGER_NOT_REJECTED');}catch(RuntimeException $e){check($e->getMessage()==='LEDGER_MISMATCH','WRONG_LEDGER_ERROR');}
$db=repairDatabasePreflight($cfg,$q);echo "PASS actual MariaDB advisory lock and exact ledger preflight\n";
$lock=fopen($storage.'/public-update-lifecycle.lock','c+');check(flock($lock,LOCK_EX|LOCK_NB),'LIFECYCLE_LOCK');
try{
 check(repairInspect($root,$storage,$r,$q)['phase']==='new','INITIAL_PHASE');
 repairApply($root,$storage,$r,$q);check(repairInspect($root,$storage,$r,$q)['phase']==='done','FINAL_PHASE');
 $after=file_get_contents($state);repairApply($root,$storage,$r,$q);check(file_get_contents($state)===$after,'IDEMPOTENCE');
 $snap=json_decode($after,true,64,JSON_THROW_ON_ERROR);check($snap['history'][0]===$original['history'][0]&&$snap['active_version']===$q['version']&&$snap['active_managed_tree_sha256']===$q['after']&&$snap['lkg_recovery_id']==='','HISTORY_OR_TREE');
 check($fixtureDb->query('SELECT version,applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC)===$before,'LEDGER_MUTATED');
 check((int)$fixtureDb->query("SELECT COUNT(*) FROM installations WHERE installation_id='cp107-disposable'")->fetchColumn()===1,'MARKER_MUTATED');
 echo "PASS exact owner repair with actual MariaDB lock held, ledger and marker unchanged, repeated apply idempotent\n";
}finally{$db->query("SELECT RELEASE_LOCK('sokna_v3_public_migrations')");flock($lock,LOCK_UN);fclose($lock);}
