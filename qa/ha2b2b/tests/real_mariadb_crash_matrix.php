<?php
declare(strict_types=1);
require_once __DIR__.'/../source/LabMariaDbSettings.php';
require_once __DIR__.'/../source/PdoMariaDbLabReleaseJournalV2.php';
/**
 * Real, disposable MariaDB crash/unknown-outcome qualification.
 * Not for production, not an installed release commit, never touches a real SOKNA database.
 */
$results=[]; $created=false; $db=null; $lab=''; $actual=false; $physical=[]; $cleanup=true;
function ck(string $name, bool $pass): void {
    global $results; $results[]=['case'=>$name,'pass'=>$pass];
    if (!$pass) fwrite(STDERR,"FAILED $name\n");
}
function denied(array $r): bool {return !($r['ok']??false) && !($r['execution_authorized']??true) && !($r['for_install']??true);}
function safeLab(): PDO {
    if(PHP_SAPI!=='cli' || getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION') throw new RuntimeException('EXPLICIT_DISPOSABLE_CONFIRM_REQUIRED');
    if(!in_array('mysql',PDO::getAvailableDrivers(),true)) throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
    $cfg=LabMariaDbSettings::fromEnvironment(); $db=LabMariaDbSettings::connect();
    if(!hash_equals($cfg['database'],(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('WRONG_LAB_DATABASE');
    if(stripos((string)$db->query('SELECT VERSION()')->fetchColumn(),'mariadb')===false)throw new RuntimeException('NOT_REAL_MARIADB');
    return $db;
}
function workerEnv(string $id, string $tx, string $plan):array {
    $env=[];
    foreach(['SOKNA_HA2B_DISPOSABLE_CONFIRM','SOKNA_HA2B_DB_HOST','SOKNA_HA2B_DB_PORT','SOKNA_HA2B_DB_NAME','SOKNA_HA2B_DB_USER','SOKNA_HA2B_DB_PASS'] as $k) {$v=getenv($k); if ($v !== false) $env[$k]=$v;}
    return $env+['SOKNA_HA2B_INSTALLATION_ID'=>$id,'SOKNA_HA2B_TX_ID'=>$tx,'SOKNA_HA2B_PLAN_SHA'=>$plan];
}
function killAtMarker(string $mode, string $marker, array $env):array {
    $pipes=[];
    $p=proc_open([PHP_BINARY, __DIR__.'/fault_worker.php', $mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$env);
    if(!is_resource($p)) return ['ready'=>false,'closed'=>false];
    fclose($pipes[0]); stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $buffer=''; $ready=false; $deadline=microtime(true)+8;
    while(microtime(true)<$deadline){
        $buffer.=stream_get_contents($pipes[1]);
        if(str_contains($buffer,$marker)){$ready=true;break;}
        $st=proc_get_status($p);if(!$st['running'])break;
        usleep(50000);
    }
    // Kill the exact child; no host processes or other database sessions touched.
    // On timeout also kill the child, otherwise proc_close could wait on a 30-second sleeper.
    proc_terminate($p,9);
    $deadline=microtime(true)+5; $dead=false;
    while(microtime(true)<$deadline){
        $st=proc_get_status($p);if(!$st['running']){$dead=true;break;}usleep(50000);
    }
    fclose($pipes[1]);fclose($pipes[2]);proc_close($p);
    return ['ready'=>$ready,'closed'=>$dead];
}
try {
    $db=safeLab();$actual=true; $lab=LabMariaDbSettings::fromEnvironment()['database'];
    if($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM)!==[])throw new RuntimeException('LAB_DB_MUST_BE_COMPLETELY_EMPTY');
    // Existing verified, source-owned schema; never derive SQL table names from operator input.
    $sql=(string)file_get_contents(__DIR__.'/schema_lab_v2.sql');$created=true;
    foreach(explode(';',$sql) as $stmt) {$stmt=trim(preg_replace('/^--[^\n]*\n/m','',$stmt));if($stmt!=='')$db->exec($stmt);}
    $rows=$db->query("SELECT TABLE_NAME,ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sokna_ha2_lab_guard','sokna_ha2_lab_events')")->fetchAll(PDO::FETCH_ASSOC);
    ck('tables truly InnoDB',count($rows)===2 && count(array_filter($rows,fn($r)=>strtoupper((string)$r['ENGINE'])==='INNODB'))===2);
    $pk=$db->query("SELECT TABLE_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='PRIMARY' AND TABLE_NAME IN ('sokna_ha2_lab_guard','sokna_ha2_lab_events') ORDER BY TABLE_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $sig=array_map(fn($r)=>$r['TABLE_NAME'].':'.$r['SEQ_IN_INDEX'].':'.$r['COLUMN_NAME'],$pk);
    ck('physical primary keys correct', $sig===['sokna_ha2_lab_events:1:installation_id','sokna_ha2_lab_events:2:sequence_no','sokna_ha2_lab_guard:1:installation_id']);
    $fk=$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='sokna_ha2_lab_events_guard_fk'")->fetchColumn();
    ck('physical foreign key present',(int)$fk===1);
    $id=bin2hex(random_bytes(16));$tx=bin2hex(random_bytes(16));$plan=bin2hex(random_bytes(32));$stage=bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sokna_ha2_lab_guard (installation_id) VALUES(?)')->execute([$id]);
    $env=workerEnv($id,$tx,$plan);
    $test=killAtMarker('hold_uncommitted','UNCOMMITTED_AND_LOCKED',$env);
    ck('uncommitted worker reaches DB lock', $test['ready']);
    ck('force killed worker OS process exits', $test['closed']);
    $again=safeLab();$again->exec('SET SESSION innodb_lock_wait_timeout=2');
    $journal=new PdoMariaDbLabReleaseJournalV2($again,$id,$lab,true);
    $snapshot=$journal->inspect();
    ck('InnoDB undoes uncommitted write after SIGKILL',($snapshot['ok']??false) && ($snapshot['sequence']??-1)===0 && ($snapshot['fence']??-1)===0);
    $test=killAtMarker('write_then_lose_ack','PREPARED_RECORD_DURABLE_NOT_INSTALL_COMMIT',$env);
    ck('DB write reaches committed-before-ACK boundary',$test['ready']);
    ck('post-commit no-ACK worker exits',$test['closed']);
    $journal=new PdoMariaDbLabReleaseJournalV2(safeLab(),$id,$lab,true);
    $snapshot=$journal->inspect();
    ck('restarted process observes one durable PREPARED',($snapshot['ok']??false)&&($snapshot['phase']??'')==='PREPARED'&&($snapshot['sequence']??-1)===1&&($snapshot['fence']??-1)===1);
    ck('duplicate begin never creates second event',denied($journal->begin($tx,$plan)));
    ck('conflicting begin cannot bypass recovered transaction',denied($journal->begin(bin2hex(random_bytes(16)),$plan)));
    $staged=$journal->staged($tx,$plan,$stage);
    ck('staged metadata is not an install commit',($staged['ok']??false)&&!($staged['execution_authorized']??true)&&!($staged['commit_proven']??true));
    $unknown=$journal->uncertain($tx,'CLIENT_ACK_LOST');
    ck('unknown state remains unresolved',($unknown['ok']??false)&&!($unknown['execution_authorized']??true));
    $fresh=new PdoMariaDbLabReleaseJournalV2(safeLab(),$id,$lab,true);
    $snapshot=$fresh->inspect();
    ck('restart still denies release authorization',($snapshot['phase']??'')==='UNKNOWN'&&!($snapshot['installed_release_proven']??true)&&!($snapshot['for_install']??true));
    ck('any new begin blocked during unknown',denied($fresh->begin(bin2hex(random_bytes(16)),$plan)));
    $count=$db->prepare('SELECT COUNT(*) FROM sokna_ha2_lab_events WHERE installation_id=?');$count->execute([$id]);
    ck('only three real DB audit events persisted',(int)$count->fetchColumn()===3);
    $db->prepare('DELETE FROM sokna_ha2_lab_events WHERE installation_id=? AND sequence_no=?')->execute([$id,2]);
    ck('damaged DB ledger fails closed',!($fresh->inspect()['ok']??true));
} catch(Throwable $e) {
    ck('REAL_DB_SETUP_OR_TRANSACTION_FAILURE',false);
    fwrite(STDERR, 'LAB_GATE_FAILED: '.get_class($e).' — '.$e->getMessage()."\n");
} finally {
    if($created && $db instanceof PDO){
        try{$db->exec('DROP TABLE IF EXISTS sokna_ha2_lab_events');$db->exec('DROP TABLE IF EXISTS sokna_ha2_lab_guard');}
        catch(Throwable $e){$cleanup=false;ck('disposable table cleanup',false);}
    }
}
$ok=count(array_filter($results,fn($x)=>$x['pass']));$n=count($results);
$status=$actual&&$cleanup&&$n>0&&$ok===$n?'PASS_REAL_MARIADB_CRASH_MATRIX_LAB_ONLY':'BLOCKED_OR_FAIL';
$report=['stage'=>'F1-HA2B2B-LAB','status'=>$status,'actual_mariadb_exercised'=>$actual,'passed'=>$ok,'total'=>$n,'tests'=>$results,
'install_commit_proven'=>false,'production_schema_touched'=>false,'execution_authorized'=>false,'panel_update_ready'=>false,'for_install'=>false];
file_put_contents(__DIR__.'/../results/real_mariadb_crash_matrix.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$status,'passed'=>$ok,'total'=>$n,'installed_release_proven'=>false],JSON_PRETTY_PRINT)."\n";
exit($status==='PASS_REAL_MARIADB_CRASH_MATRIX_LAB_ONLY'?0:1);
