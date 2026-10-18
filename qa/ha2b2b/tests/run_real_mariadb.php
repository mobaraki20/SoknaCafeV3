<?php
declare(strict_types=1);
require_once __DIR__.'/../source/PdoMariaDbLabReleaseJournalV2.php';
require_once __DIR__.'/../source/LabMariaDbSettings.php';
/**
 * This script is for a NEW, DISPOSABLE, empty MariaDB database ONLY.
 * Required: SOKNA_HA2B_DISPOSABLE_CONFIRM=YES_DISPOSABLE_NO_PRODUCTION,
 * SOKNA_HA2B_DB_NAME=sokna_ha2_lab_<random 8..16 lowercase hex>, DB_USER/PASS.
 * Builds and drops ONLY two lab tables; never touches SOKNA business schemas.
 * Does NOT create a database, grant privileges, commit an installation, or deploy code.
 */
$results=[];$created=false;$db=null;$lab='';$id='';$serverConfirmed=false;$labSchemaHash=null;
function check(string $name,bool $yes):void {global $results;$results[]=['case'=>$name,'pass'=>$yes];if(!$yes)fwrite(STDERR,"FAILED: $name\n");}
function block(array $r):bool{return !($r['ok']??false) && !($r['execution_authorized']??true) && !($r['for_install']??true);}
function dbconnect():PDO { return LabMariaDbSettings::connect(); }

try {
 if(PHP_SAPI!=='cli')throw new RuntimeException('CLI_ONLY');
 if(getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION')throw new RuntimeException('EXPLICIT_DISPOSABLE_CONFIRM_REQUIRED');
 if(!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
 $lab=getenv('SOKNA_HA2B_DB_NAME')?:'';$db=dbconnect();
 if(!hash_equals($lab,(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('WRONG_TARGET_DATABASE');
 check('MariaDB server signature',stripos((string)$db->query('SELECT VERSION()')->fetchColumn(),'mariadb')!==false);
 if (!end($results)['pass'])throw new RuntimeException('NOT_MARIADB');$serverConfirmed=true;
 $existing=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
 if($existing!==[])throw new RuntimeException('LAB_DB_MUST_BE_COMPLETELY_EMPTY');
 $sql=file_get_contents(__DIR__.'/schema_lab_v2.sql');
 $created=true;
 foreach(explode(';',$sql)as$statement){$statement=trim(preg_replace('/^--[^\n]*\n/m','',$statement));if($statement!=='')$db->exec($statement);}
 $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sokna_ha2_lab_guard','sokna_ha2_lab_events') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
 check('both real DB tables are InnoDB',count($engines)===2 && count(array_filter($engines,fn($r)=>strtoupper((string)$r['ENGINE'])==='INNODB'))===2);
 $columns=$db->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sokna_ha2_lab_guard','sokna_ha2_lab_events') ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
 $expectedGuard=['installation_id','sequence_no','fence_no','phase','tx_id','plan_sha256','stage_sha256'];
 $expectedEvents=['installation_id','sequence_no','fence_no','tx_id','plan_sha256','event_name','stage_sha256','reason_code'];
 $actualGuard=array_column(array_values(array_filter($columns,fn($r)=>$r['TABLE_NAME']==='sokna_ha2_lab_guard')),'COLUMN_NAME');
 $actualEvents=array_column(array_values(array_filter($columns,fn($r)=>$r['TABLE_NAME']==='sokna_ha2_lab_events')),'COLUMN_NAME');
 check('physical test schema matches expected column catalog',$actualGuard===$expectedGuard && $actualEvents===$expectedEvents);
 $labSchemaHash=hash('sha256',json_encode($columns,JSON_THROW_ON_ERROR));
 $id=bin2hex(random_bytes(16));$tx=bin2hex(random_bytes(16));$plan=bin2hex(random_bytes(32));$stage=bin2hex(random_bytes(32));
 $db->prepare('INSERT INTO sokna_ha2_lab_guard (installation_id) VALUES (?)')->execute([$id]);
 $j=new PdoMariaDbLabReleaseJournalV2($db,$id,$lab,true);
 $first=$j->inspect();check('fresh lab not installed',($first['phase']??'')==='LAB_IDLE_UNQUALIFIED'&&!$first['installed_release_proven']);
 check('reject invalid id',block($j->begin('invalid',$plan)));
 check('reject invalid plan',block($j->begin($tx,'invalid')));
 // Multi-process proof: session A holds FOR UPDATE lock, session B must time out.
 $lockDb=dbconnect();$lockDb->beginTransaction();$s=$lockDb->prepare('SELECT sequence_no FROM sokna_ha2_lab_guard WHERE installation_id=? FOR UPDATE');$s->execute([$id]);
 $env=array_merge($_ENV,[
  'SOKNA_HA2B_DISPOSABLE_CONFIRM'=>'YES_DISPOSABLE_NO_PRODUCTION','SOKNA_HA2B_DB_NAME'=>$lab,
  'SOKNA_HA2B_DB_HOST'=>getenv('SOKNA_HA2B_DB_HOST')?:'127.0.0.1','SOKNA_HA2B_DB_PORT'=>getenv('SOKNA_HA2B_DB_PORT')?:'3306',
  'SOKNA_HA2B_DB_USER'=>getenv('SOKNA_HA2B_DB_USER')?:'','SOKNA_HA2B_DB_PASS'=>getenv('SOKNA_HA2B_DB_PASS')?:'',
  'SOKNA_HA2B_INSTALLATION_ID'=>$id,'SOKNA_HA2B_TX_ID'=>$tx,'SOKNA_HA2B_PLAN_SHA'=>$plan]);
 $proc=proc_open([PHP_BINARY,__DIR__.'/real_mariadb_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$env);
 if(!is_resource($proc))throw new RuntimeException('WORKER_COULD_NOT_START');
 fclose($pipes[0]);$childText=stream_get_contents($pipes[1]);fclose($pipes[1]);$childErr=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($proc);
 $child=json_decode($childText,true);
 check('real other process denied by InnoDB row lock',is_array($child) && ($child['code']??'')==='DB_ERROR_OR_UNKNOWN_FAIL_CLOSED' && $exit===0);
 $lockDb->rollBack();
 check('lock timeout left original row untouched',($j->inspect()['sequence']??-1)===0);
 $beg=$j->begin($tx,$plan);check('one durable fenced begin',($beg['ok']??false)&&($beg['db_fence_lab_only']??-1)===1);
 check('duplicate begin is unprivileged',block($j->begin($tx,$plan)));
 check('conflicting request denied',block($j->begin(bin2hex(random_bytes(16)),$plan)));
 check('wrong plan staged denied',block($j->staged($tx,bin2hex(random_bytes(32)),$stage)));
 $st=$j->staged($tx,$plan,$stage);check('stage persisted atomically',($st['ok']??false)&&($st['db_sequence_lab_only']??-1)===2);
 check('identical stage replay not committed',block($j->staged($tx,$plan,$stage)));
 check('changed stage digest denied',block($j->staged($tx,$plan,bin2hex(random_bytes(32)))));
 check('restart sees stage',((new PdoMariaDbLabReleaseJournalV2(dbconnect(),$id,$lab,true))->inspect()['phase']??'')==='STAGED_OBSERVED');
 check('wrong tx unknown denied',block($j->uncertain(bin2hex(random_bytes(16)),'OUTCOME_UNKNOWN')));
 check('unknown committed as unresolved',($j->uncertain($tx,'OUTCOME_UNKNOWN')['db_sequence_lab_only']??-1)===3);
 check('unknown retry never clears',block($j->uncertain($tx,'OUTCOME_UNKNOWN')));
 check('new install blocked after unknown',block($j->begin(bin2hex(random_bytes(16)),$plan)));
 $s=$db->prepare('SELECT COUNT(*) FROM sokna_ha2_lab_events WHERE installation_id=?');$s->execute([$id]);check('exact three atomic audit events',(int)$s->fetchColumn()===3);
 $current=$j->inspect();check('no installed proof from MariaDB',!$current['installed_release_proven']&&!$current['commit_proven']&&!$current['execution_authorized']);
 $db->prepare('DELETE FROM sokna_ha2_lab_events WHERE installation_id=? AND sequence_no=2')->execute([$id]);
 check('deleted middle audit event fails closed',!($j->inspect()['ok']??true));
 // No attempt to repair a damaged witness in place. Clean-up only in disposable DB.
} catch(Throwable $e){check('LAB_TEST_SETUP_OR_SQL_ERROR',false);fwrite(STDERR,"GATE BLOCKED: ".get_class($e)." — ".$e->getMessage()."\n");}
finally {
 if($created&&$db instanceof PDO){
  try {$db->exec('DROP TABLE IF EXISTS sokna_ha2_lab_events');$db->exec('DROP TABLE IF EXISTS sokna_ha2_lab_guard');}
  catch(Throwable $e){fwrite(STDERR,"DISPOSABLE_LAB_CLEANUP_REQUIRED: ".$e->getMessage()."\n");check('disposable cleanup',false);}
 }
}
$passed=count(array_filter($results,fn($r)=>$r['pass']));$total=count($results);
$report=['stage'=>'F1-HA2B1','status'=>$total>0&&$passed===$total?'PASS_REAL_MARIADB_LAB_ONLY':'BLOCKED_OR_FAIL',
 'actual_mariadb_exercised'=>$serverConfirmed,'lab_physical_schema_observation_sha256'=>$labSchemaHash,'tests'=>$results,'passed'=>$passed,'total'=>$total,
 'installed_release_proven'=>false,'execution_authorized'=>false,'production_mutation'=>false];
file_put_contents(__DIR__.'/../results/real_mariadb_result.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$report['status'],'passed'=>$passed,'total'=>$total,'production_authorized'=>false],JSON_PRETTY_PRINT)."\n";
exit($report['status']==='PASS_REAL_MARIADB_LAB_ONLY'?0:1);
