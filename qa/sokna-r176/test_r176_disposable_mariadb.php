<?php
declare(strict_types=1);
// A disposable CI database only; NEVER point this test at a cafe/home database.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SOKNA_R176_DISPOSABLE') !== 'YES' || count($argv) !== 2) exit(87);
$root = $argv[1];
if (!is_file($root.'/src/Domain/System/GoLiveResetEngine.php')) exit(87);
require_once $root.'/src/Domain/System/GoLiveResetEngine.php';
function need(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('R176_CI_FAIL_'.$label);
    echo 'PASS '.$label."\n";
}
$dbName = 'sokna_r176_disposable';
$admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','ci-root-only',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$admin->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$dbName.';charset=utf8mb4','root','ci-root-only',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$policyPath=$root.'/resources/golive-clearance-policy.json';
$p=json_decode((string)file_get_contents($policyPath),true,512,JSON_THROW_ON_ERROR);
$actions=$p['actions'];ksort($actions);
need(count($actions)===111,'ONE_HUNDRED_ELEVEN_EXACT_TABLE_POLICY');
foreach($actions as $table=>$action) {
    if ($table==='public_authority_state') {
        $db->exec("CREATE TABLE `$table` (installation_id VARCHAR(96) PRIMARY KEY,authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1, phase VARCHAR(32) NOT NULL DEFAULT 'active',reason VARCHAR(96) NULL,reenrolled_at DATETIME(6) NULL,activated_at DATETIME(6) NULL) ENGINE=InnoDB");
        continue;
    }
    $db->exec("CREATE TABLE `$table` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payload VARCHAR(120) NULL) ENGINE=InnoDB");
}
$db->exec("INSERT INTO public_authority_state(installation_id,authority_epoch,phase) VALUES ('ci-installation',1,'active')");
$db->exec("INSERT INTO users(payload) VALUES ('preserved-employee')");
$db->exec("INSERT INTO menu_items(payload) VALUES ('preserved-menu')");
$db->exec("INSERT INTO audit_log(payload) VALUES ('test')");
for($i=0;$i<15;$i++)$db->exec('INSERT INTO audit_log(payload) SELECT payload FROM audit_log');
$db->exec("INSERT INTO orders(payload) VALUES ('test-order')");
$db->exec("INSERT INTO order_items(payload) VALUES ('test-item')");
// Emulate the real migration 0053: deleting a staff consumption emits
// projection invalidation after the outbox itself was cleared at index 44.
$db->exec("CREATE TRIGGER ci_staff_consumption_ad AFTER DELETE ON staff_consumptions
   FOR EACH ROW INSERT INTO public_projection_outbox(payload) VALUES ('staff_consumptions.delete')");
for ($i=0;$i<271;$i++) $db->exec("INSERT INTO staff_consumptions(payload) VALUES ('test-staff-consumption')");
$clearTables=array_keys(array_filter($actions, static fn($a)=>$a==='CLEAR'));
need(array_search('public_projection_outbox',$clearTables,true)===44,'OUTBOX_EARLIER_THAN_TRIGGER_SOURCES');
need(array_search('staff_consumptions',$clearTables,true)===54,'REAL_RESUME_TABLE_INDEX_54');
$before=(int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
need($before===32768,'REAL_MARIADB_32K_BULK_OPERATION_ROWS');
$engine=new Sokna\Local\Domain\System\GoLiveResetEngine($db,$policyPath);
$inspection=$engine->inspect();
need($inspection['safe_schema']&&$inspection['table_count']===111,'READ_ONLY_EXACT_SCHEMA_INSPECTION');
$initial=$engine->beginBatched('ci-installation',2);
need((int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===$before,'INITIAL_CHECKPOINT_NO_DATA_MUTATION');
need((int)$initial['initial_counts']['audit_log']===$before,'PRE_CLEAR_COUNTS_PERSISTED');
$bad=$initial;$bad['policy_sha256']=str_repeat('f',64);
try{$engine->advanceBatched('ci-installation',2,$bad);throw new RuntimeException('BAD_POLICY_ACCEPTED');}
catch(RuntimeException $e){need($e->getMessage()==='GOLIVE_BATCH_STATE_INVALID','TAMPERED_POLICY_FAIL_CLOSED');}
$first=$engine->advanceBatched('ci-installation',2,$initial);
need($first['completed']===false,'FIRST_REQUEST_BOUNDED_NOT_COMPLETE');
$afterFirst=(int)$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
need($afterFirst>0&&$afterFirst<$before,'FIRST_REQUEST_COMMITTED_SMALL_CHUNKS');
need((int)$db->query('SELECT authority_epoch FROM public_authority_state WHERE installation_id=\'ci-installation\'')->fetchColumn()===1,'EPOCH_NOT_ADVANCED_UNTIL_DONE');
// Simulate PHP death immediately after COMMIT but before persisting the
// next cursor. Replaying an earlier checkpoint must be harmless.
$replayed=$engine->advanceBatched('ci-installation',2,$initial);
need($replayed['state']['chunks']>0,'STALE_CHECKPOINT_REPLAY_SURVIVED');
$cursor=$replayed['state'];$completed=false;$tailWitness=false;
for($i=0;$i<120;$i++){
    $prior=$cursor;
    $out=$engine->advanceBatched('ci-installation',2,$cursor);
    $cursor=$out['state'];
    if(!$out['completed'] && $cursor['table_index']===count($clearTables) &&
       isset($cursor['deleted']['public_projection_outbox']) &&
       (int)$cursor['deleted']['public_projection_outbox']>=271) {
        $tailWitness=true;
        need((int)$db->query('SELECT COUNT(*) FROM public_projection_outbox')->fetchColumn()===0,
           'TAIL_DRAIN_ZERO_AFTER_TRIGGER_REINSERT');
        need((int)$db->query('SELECT authority_epoch FROM public_authority_state')->fetchColumn()===1,
           'EPOCH_UNCHANGED_UNTIL_TAIL_VERIFIED');
        // Simulate loss of the response AFTER tail DELETE COMMIT. An earlier
        // checkpoint can safely be replayed: final verification sees zero.
        $cursor=$prior;
    }
    if($out['completed']){$completed=true;break;}
}
need($tailWitness,'OUTBOX_LATE_TRIGGER_TAIL_RECONCILIATION_WITNESSED');
need($completed,'RESUMED_TO_COMPLETION');
foreach($actions as $table=>$action){
    if($action==='CLEAR')need((int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0,'EMPTY_'.$table);
}
need((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===1 &&
     (int)$db->query('SELECT COUNT(*) FROM menu_items')->fetchColumn()===1,'PRESERVED_USERS_AND_MENU');
$epoch=$db->query("SELECT authority_epoch,phase FROM public_authority_state WHERE installation_id='ci-installation'")->fetch(PDO::FETCH_ASSOC);
need((int)$epoch['authority_epoch']===2&&$epoch['phase']==='resync_required','EPOCH_COMMITTED_ONCE_AT_END');
try{$engine->advanceBatched('ci-installation',2,$cursor);throw new RuntimeException('SECOND_PURGE_ALLOWED');}
catch(RuntimeException $e){need($e->getMessage()==='GOLIVE_LOCAL_AUTHORITY_EPOCH_MISMATCH','SECOND_PURGE_FAIL_CLOSED');}
echo "PASS R176_OUTBOX_TAIL_RECOVERY_WITH_TRIGGER_CI\n";
