<?php
/**
 * SOKNA R125/R112 exact out-of-band recovery evidence reconciler.
 * One-case-only. NO business SQL mutations, no service control, no updater activation.
 * Must follow successfully completed R125 emergency restore and fail closed otherwise.
 * Does NOT grant general multi-baseline update support.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
const MROOT = 'C:/ProgramData/SOKNA/Maintenance';
const SROOT = 'D:/SOKNA';
const CURRENT_BUILD = '3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat';
const FAILED_BUILD = '3.0.0-g6.2-workspace.231dt-r125-local-inventory-repair-uat';
const CURRENT_TREE = '04ee10c8315c20b7c046c2fd954589f271934530d4c92d701405a98f938c0770';
const FAILED_TREE = '1085ed719d694b32485713a04d47f6e9fcfbbfda44481633b974bc5eb76b49e5';
const BACKUP_SHA = '074e0affb4c0eea114dc7eed3c48b5687a4c42b54e8f6d9d8597e15cff0c57a6';
const OP_ID = 'b26dc160ce42441ebf8f6e393f41b924';
const BACKUP_BYTES = 725306396;
const CURRENT_RELEASE = 'sokna-r112-guest-safe-append-uat-20261105';
const FAILED_RELEASE = 'sokna-r125-local-inventory-candidate-repair-20261106-uat';
const SOURCE_FP = 'migration-ledger-sha256-v1:deb5af9ad17f91af67360dd7a89de3b00b84be171675f4fb710ca1dd73e52ed9';
const DEST_FP = 'migration-ledger-sha256-v1:e0ad6cdf80b753292d4f239f2d00beaef78e98f8a4cf8cc7cf66363c813a226a';
const BACKUP_NAME = 'local-before-82fe217f202a4e99be1e22b44c0a3103.sql';
const OLD_SLOT = 'D:/SOKNA/Web.release.231dg-04ee10c8315c';
const TARGET_SLOT = 'D:/SOKNA/Web.release.231dt-1085ed719d69';
$logPath = __DIR__ . '/SOKNA-R129-RECONCILE-RESULT.txt';
$logHandle = fopen($logPath, 'ab');
if ($logHandle === false) exit(50);
function logline(string $msg):void {global $logHandle; fwrite($logHandle, gmdate('c').' '. $msg . "\r\n");fflush($logHandle);echo $msg.PHP_EOL;}
function stopcase(string $code):never {logline('BLOCKED_NO_NEW_INSTALL: '.$code);exit(48);}
function must(bool $check, string $code):void {if (!$check) stopcase($code);}
function samepath(string $a, string $b):bool {return strtolower(rtrim(str_replace('\\','/',$a),'/'))===strtolower(rtrim(str_replace('\\','/',$b),'/'));}
function readJSON(string $p):array {
    must(is_file($p) && !is_link($p),'FILE_NOT_NORMAL');
    $raw=file_get_contents($p);must(is_string($raw),'FILE_NOT_READABLE');
    $d=json_decode($raw,true);must(is_array($d) && json_last_error()===JSON_ERROR_NONE,'JSON_NOT_VALID');
    return $d;
}
function requireFilesTree(string $root):string {
    $inv=readJSON($root.'/resources/local-managed-files-v1.json');
    $paths=$inv['files']??null;
    must(($inv['format']??null)==='sokna-local-managed-files-v1' && is_array($paths) && count($paths)===537 && count(array_unique($paths))===537,'CURRENT_INVENTORY_UNEXPECTED');
    sort($paths,SORT_STRING);$h=hash_init('sha256');
    foreach($paths as $rel){
       must(is_string($rel) && preg_match('~^(?!/)(?!.*(?:^|/)\\.{1,2}(?:/|$))[A-Za-z0-9_.\-/]+$~D',$rel)===1,'BAD_MANAGED_PATH');
       $p=$root.'/'.str_replace('\\','/',$rel);
       must(is_file($p),'MANAGED_FILE_MISSING');
       hash_update($h,$rel."\0".hash_file('sha256',$p)."\n");
    }
    return hash_final($h);
}
function scalarEq(array $d,string $key,string $value):bool {return isset($d[$key]) && is_string($d[$key]) && hash_equals($value,$d[$key]);}
function checkOperation(array $o):void {
    must(($o['format']??'')==='sokna-maintenance-operation-v1' && ($o['schema_version']??null)===1,'OP_FORMAT');
    must(scalarEq($o,'operation_id',OP_ID) && scalarEq($o,'operation_type','update.local-code-only-activate'),'OP_IDENTITY');
    must(scalarEq($o,'state','recovery_required') && scalarEq($o,'result_code','CODE_ONLY_EXECUTION_OUTCOME_UNPROVEN'),'OP_STATE_CHANGED');
    must(($o['activation_performed']??null)===true && ($o['database_mutation_performed']??null)===true,'OP_MUTATION_FLAGS_UNEXPECTED');
    must(($o['attempt_count']??0)>=1 && ($o['attempt_count']??0)<10,'OP_ATTEMPTS_UNEXPECTED');
    $canonical=strtolower(trim((string)$o['operation_type']))."\n".trim((string)$o['package_ref'])."\n".trim((string)$o['operator_id']);
    must(scalarEq($o,'canonical_request_sha256',hash('sha256',$canonical)),'OP_REQUEST_IDENTITY_HASH');
    must((bool)preg_match('/^[0-9a-f]{32}$/',(string)($o['package_ref']??'')),'OP_PACKAGE_REF');
}
function noOtherBlocking(string $root):void {
    $p=$root.'/operations';must(is_dir($p),'OPERATIONS_DIRECTORY_MISSING');
    $files=glob($p.'/op-*.json');must(is_array($files) && count($files)>0 && count($files)<=20000,'OPERATION_INDEX_UNEXPECTED');
    $blocked=[];
    foreach($files as $path){
        $o=readJSON($path);
        must(preg_match('/^op-([a-f0-9]{32})\.json$/',basename($path),$m)===1 && ($o['operation_id']??null)===$m[1],'OPERATION_FILE_MISMATCH');
        if(in_array(($o['state']??''),['accepted','running','activating','recovering','recovery_required'],true))$blocked[]=$o['operation_id'];
    }
    must($blocked===[OP_ID],'FOREIGN_OR_MISSING_BLOCKING_OPERATIONS');
}
function atomicWriteJson(string $path,array $data):void {
    $raw=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $tmp=$path.'.reconcile-tmp-'.bin2hex(random_bytes(8));
    $f=fopen($tmp,'xb');must($f!==false,'ATOMIC_TEMP_CREATE_FAILED');
    try {
       must(fwrite($f,$raw."\r\n")===strlen($raw."\r\n"),'ATOMIC_TEMP_WRITE_FAILED');
       must(fflush($f),'ATOMIC_TEMP_FLUSH_FAILED');
    } finally {fclose($f);}
    must(rename($tmp,$path),'ATOMIC_REPLACE_FAILED');
}
logline('TOOL: SOKNA_R129_EXACT_POST_RECOVERY_RECONCILE');
logline('MODE: MAINTENANCE_EVIDENCE_ONLY_NO_SQL_MUTATION_NO_SERVICE_RESTART');
must(PHP_OS_FAMILY==='Windows','NOT_WINDOWS');
$lockName=MROOT.'/local-slots/r125-reconcile-exclusive.lock';
$lf=fopen($lockName,'c+');must(is_resource($lf),'LOCK_OPEN_FAILED');
must(flock($lf,LOCK_EX|LOCK_NB),'RECONCILIATION_ALREADY_ACTIVE');
$statePath=MROOT.'/local-slots/routine-state.json';
$opPath=MROOT.'/operations/op-'.OP_ID.'.json';
$state=readJSON($statePath);$operation=readJSON($opPath);
must(($state['format']??'')==='sokna-local-slot-routine-state-v1' && ($state['schema_version']??0)===1,'ROUTINE_FORMAT');
must(scalarEq($state,'phase','RECOVERY_REQUIRED') && ($state['recovery_required']??false)===true,'ROUTINE_NOT_INTERRUPTED');
must(scalarEq($state,'current_build',CURRENT_BUILD) && scalarEq($state,'target_build',FAILED_BUILD),'ROUTINE_WRONG_BUILDS');
must(scalarEq($state,'current_managed_tree_sha256',CURRENT_TREE) && scalarEq($state,'target_managed_tree_sha256',FAILED_TREE),'ROUTINE_TREE_MISMATCH');
must(($state['current_managed_files']??0)===537 && ($state['target_managed_files']??0)===541,'ROUTINE_FILE_COUNT_MISMATCH');
must(scalarEq($state,'releaseSetId',FAILED_RELEASE),'ROUTINE_RELEASE_MISMATCH');
must(samepath((string)$state['package_root'],SROOT) && samepath((string)$state['stable_web_root'],SROOT.'/Web'),'ROUTINE_ROOT_MISMATCH');
must(samepath((string)$state['previous_slot'],OLD_SLOT) && samepath((string)$state['target_slot'],TARGET_SLOT),'ROUTINE_SLOTS_MISMATCH');
must(($state['activation_performed']??true)===false && ($state['database_mutation_performed']??false)===true && ($state['public_mutation_performed']??true)===false && ($state['windows_reboot_required']??true)===false,'ROUTINE_FLAGS_CHANGED');
$ev=$state['events']??[];must(is_array($ev) && count($ev)>=5 && ($ev[array_key_last($ev)]['state']??null)==='RECOVERY_REQUIRED','ROUTINE_LAST_EVENT_CHANGED');
$plan=$state['migrationPlan']??[];
must(($plan['sourceFingerprint']??'')===SOURCE_FP && ($plan['targetFingerprint']??'')===DEST_FP && ($plan['sourceHead']??'')==='0076_late_served_accounting' && ($plan['targetHead']??'')==='0079_cafe_staff_initial_accounts','ROUTINE_MIGRATION_PLAN_MISMATCH');
checkOperation($operation);
noOtherBlocking(MROOT);
logline('PRECHECK: EXACT_RECOVERY_REQUIRED_AND_SINGLE_BLOCKED_OPERATION');
$id=readJSON(MROOT.'/installed-release-identity.json');$loc=$id['local']??[];
must(($loc['release_id']??null)===CURRENT_RELEASE && ($loc['version']??'')===CURRENT_BUILD && ($loc['managed_tree_sha256']??'')===CURRENT_TREE && ($loc['schema_fingerprint']??'')===SOURCE_FP && ($loc['migration_head']??'')==='0076_late_served_accounting','INSTALLED_IDENTITY_NOT_R112');
$webreal=realpath(SROOT.'/Web');$slotreal=realpath(OLD_SLOT);
must($webreal!==false && $slotreal!==false && samepath($webreal,$slotreal),'WEB_NOT_PREVIOUS_SLOT');
$version=file_get_contents(SROOT.'/Web/VERSION.txt');must(is_string($version)&&trim($version)===CURRENT_BUILD,'ACTIVE_VERSION_FILE_MISMATCH');
$tree=requireFilesTree(OLD_SLOT);must(hash_equals(CURRENT_TREE,$tree),'ACTIVE_TREE_SHA256_MISMATCH');
logline('PRECHECK: ACTIVE_R112_POINTER_AND_537_MANAGED_FILES_VERIFIED');
$backup=$state['databaseBackup']??[];
must(($backup['sha256']??'')===BACKUP_SHA && ($backup['bytes']??0)===BACKUP_BYTES && samepath((string)($backup['path']??''),MROOT.'/local-slots/private-db-backups/'.BACKUP_NAME),'BACKUP_IDENTITY_MISMATCH');
$backupPath=(string)$backup['path'];must(is_file($backupPath) && filesize($backupPath)===BACKUP_BYTES,'BACKUP_NOT_ACCESSIBLE_OR_SIZE');
must(hash_equals(BACKUP_SHA,hash_file('sha256',$backupPath)),'BACKUP_HASH_MISMATCH');
$intent=readJSON(MROOT.'/local-slots/r125-emergency-recovery-intent.json');
must(($intent['format']??null)==='sokna-r125-emergency-exact-revert-v1' && ($intent['from_version']??'')===FAILED_BUILD && ($intent['to_version']??'')===CURRENT_BUILD && ($intent['backup_sha256']??'')===BACKUP_SHA && ($intent['reason']??'')==='0078_FIRST_GUARD_AND_ONLY_0077_EMPTY_TABLE','EMERGENCY_INTENT_INVALID');
logline('PRECHECK: VERIFIED_725MB_BACKUP_AND_RECOVERY_INTENT');
$ctx=stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true]]);
$resp=@file_get_contents('http://127.0.0.1:18080/login.php',false,$ctx);
must(is_string($resp) && strlen($resp)>0,'LOCAL_HTTP_NOT_RESPONDING');
$cfg=require SROOT.'/config.php';must(is_array($cfg)&&is_array($cfg['db']??null),'DATABASE_CONFIG_UNKNOWN');$db=$cfg['db'];
$pdo=new PDO('mysql:host='.($db['host']??'127.0.0.1').';port='.($db['port']??3306).';dbname='.($db['name']??'').';charset=utf8mb4',(string)$db['user'],(string)($db['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>8,PDO::ATTR_PERSISTENT=>false]);
unset($db,$cfg);
$pdo->exec('START TRANSACTION READ ONLY');
try {
 $v=$pdo->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1')->fetchColumn();
 $c=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
 $recent=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version >= '0077'")->fetchColumn();
 $pending=(int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state <> 'applied'")->fetchColumn();
 $later=(int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE version >= '0077'")->fetchColumn();
 $table=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='inventory_opening_activations'")->fetchColumn();
 must($v==='0076_late_served_accounting' && $c===76 && $recent===0 && $pending===0 && $later===0 && $table===0,'DATABASE_NOT_EXACT_RESTORED_0076');
}finally {$pdo->exec('ROLLBACK');}
logline('PRECHECK: LIVE_DATABASE_MATCHES_R112_0076');
// Re-check operation and routine just before any evidence modifications.
checkOperation(readJSON($opPath));noOtherBlocking(MROOT);
must(($state['events'][array_key_last($state['events'])]['state']??null)==='RECOVERY_REQUIRED','STATE_RECHECK_FAILED');
$originalState=file_get_contents($statePath);$originalOp=file_get_contents($opPath);
must(is_string($originalState) && is_string($originalOp),'EVIDENCE_READ_ERROR');
$stamp='20261107-r125-r112';$priorState=MROOT.'/local-slots/r125-reconcile-original-'.$stamp.'.json';
$priorOp=MROOT.'/local-slots/r125-reconcile-original-operation-'.$stamp.'.json';
$receipt=MROOT.'/local-slots/r125-reconcile-intent-'.$stamp.'.json';
must(!file_exists($priorState) && !file_exists($priorOp) && !file_exists($receipt),'RECONCILIATION_ALREADY_ATTEMPTED');
$intentData=['format'=>'sokna-r129-external-recovery-reconciliation-v1','at_utc'=>gmdate('c'),'recovered_build'=>CURRENT_BUILD,'blocked_operation_id'=>OP_ID,'backup_sha256'=>BACKUP_SHA,'routine_state_original_sha256'=>hash('sha256',$originalState),'operation_original_sha256'=>hash('sha256',$originalOp),'db_schema_head'=>'0076_late_served_accounting'];
// Save originals first. Never delete the original backup or replay ledgers.
must(file_put_contents($priorState,$originalState,LOCK_EX)!==false && file_put_contents($priorOp,$originalOp,LOCK_EX)!==false,'ORIGINAL_EVIDENCE_COPY_FAILED');
must(file_put_contents($receipt,json_encode($intentData,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)!==false,'RECONCILE_INTENT_SAVE_FAILED');
logline('RECOVERY_RECONCILIATION: ORIGINAL_EVIDENCE_ARCHIVED_AND_INTENT_WRITTEN');
$state['recovery_required']=false;
$state['phase']='RECOVERED_TO_PREVIOUS_SLOT';
$state['updated_at_utc']=gmdate('c');
$state['events'][]=['state'=>'RECOVERED_TO_PREVIOUS_SLOT','at_utc'=>gmdate('c'),'detail'=>'R125_EXTERNAL_EXACT_ROLLBACK_R112_PROVEN_BY_R129'];
// Historical DB mutation flag remains true for forensic accountability, not current DB state.
atomicWriteJson($statePath,$state);
logline('RECOVERY_RECONCILIATION: ROUTINE_STATE_SETTLED_TO_PREVIOUS_SLOT');
$operation['state']='failed';
$operation['result_code']='CODE_ONLY_ENGINE_ROLLED_BACK_VERIFIED';
$operation['result_message']='R125 0078 failed; exact externally verified rollback to R112/0076; see local-slots/r125-reconcile-intent-20261107-r125-r112.json';
$operation['updated_at_utc']=gmdate('c');
$operation['database_mutation_performed']=false; // Matches official confirmed-rollback operation result; original archived.
atomicWriteJson($opPath,$operation);
logline('RECOVERY_RECONCILIATION: BLOCKED_ACTIVATION_CLASSIFIED_AS_FAILED_AFTER_VERIFIED_ROLLBACK');
$stateNow=readJSON($statePath);$opNow=readJSON($opPath);
must(($stateNow['phase']??null)==='RECOVERED_TO_PREVIOUS_SLOT' && ($stateNow['recovery_required']??true)===false && ($opNow['state']??'')==='failed','READBACK_FAILED_REVIEW_REQUIRED');
noOtherBlockingOrSuccess(MROOT);
logline('SUCCESS: MAINTENANCE_R125_BLOCKER_SETTLED_LOCAL_R112_UNCHANGED');
logline('IMPORTANT: DO_NOT_INSTALL_R125_OR_UNQUALIFIED_R126_R128_ON_CAFE');
function noOtherBlockingOrSuccess(string $root):void {
 $ops=glob($root.'/operations/op-*.json');must(is_array($ops),'OPERATIONS_LOST');
 foreach($ops as $p){$o=readJSON($p);must(!in_array($o['state']??'',['accepted','running','activating','recovering','recovery_required'],true),'BLOCKERS_REMAIN_AFTER_RECONCILIATION');}
}