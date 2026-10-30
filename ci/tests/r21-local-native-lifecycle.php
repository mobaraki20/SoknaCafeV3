<?php
declare(strict_types=1);
/**
 * R21 CI-only: real native ZIP -> stage -> activate -> PHP readback -> code-only rollback,
 * using MariaDB 11.4 already migrated to all 76 SQL versions by R19 setup test.
 * No physical host, no Windows service, no install authorization.
 */
$root=(string)getenv('R21_TEST_ROOT');
$pkg=(string)getenv('R21_PACKAGE');
$mode=$argv[1]??'patched';
function checkR21(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
checkR21($root!==''&&is_dir($root.'/Web')&&is_file($pkg),'Missing isolated fixture');
$config=[
 'app'=>['data_dir'=>$root.'/data','name'=>'Sokna R21 isolated test'],
 'db'=>['host'=>(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1'),'port'=>(getenv('SOKNA_TEST_DB_PORT')?:'3306'),
       'name'=>(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2'),'user'=>(getenv('SOKNA_TEST_DB_USER')?:'sokna'),
       'pass'=>(getenv('SOKNA_TEST_DB_PASS')?:'sokna'),'charset'=>'utf8mb4'],
 'installation'=>['id'=>'r21-disposable-installation']
];
file_put_contents($root.'/config.php','<?php return '.var_export($config,true).';');
require_once $root.'/Web/bootstrap.php';
$core=sokna_local_bootstrap($config);
$svc=$core->localUpdates();
$before=$svc->currentVersion();
$staged=$svc->stageUploadedZip($pkg,19);
checkR21(($staged['status']??'')==='ready','Stage failed');
$stagedVersion=(string)$staged['version'];
checkR21($before!==$stagedVersion,'Fixture version must be distinct');
checkR21(($staged['database_preflight']['pending_count']??-1)===0,'Expected code-only no pending MariaDB migration');
checkR21($svc->currentVersion()===$before,'Stage mutated active version');
if($mode==='original'){
 $caught=null;
 try{$svc->activateStaged(19);}catch(\Sokna\Local\Domain\Update\LocalUpdateException $e){$caught=$e;}
 checkR21($caught!==null,'Old updater unexpectedly succeeded; investigate and do not assume');
 checkR21($caught->errorCode==='activation_failed_recovery_mode','Unexpected old activation error: '.$caught->errorCode);
 $s=$svc->snapshot();
 checkR21(($s['recovery_mode']['required']??false)===true,'Missing recovery-required marker');
 checkR21($svc->currentVersion()===$stagedVersion,'Target version must be retained after health failure');
 checkR21(trim(file_get_contents($root.'/Web/VERSION.txt'))===$stagedVersion,'Expected target files to remain');
 $result=['case'=>'old_R17_active_code_to_R20','outcome'=>'EXPECTED_BLOCK','error'=>$caught->errorCode,'recovery_required'=>true];
}else{
 try{$r=$svc->activateStaged(21);}catch(\Sokna\Local\Domain\Update\LocalUpdateException $e){fwrite(STDERR,"R21_ACTIVATE_CAUSE=".json_encode(['code'=>$e->errorCode,'message'=>$e->getMessage(),'details'=>$e->details],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");throw $e;}
 checkR21(($r['action']??'')==='activate'&&($r['active_version']??'')===$stagedVersion,'Activate result mismatch');
 checkR21($svc->currentVersion()===$stagedVersion,'Activate readback incorrect');
 $snap=$svc->snapshot();checkR21(($snap['rollback']['allowed']??false)===true,'Rollback should be allowed when no new migrations');
 checkR21(($snap['recovery_mode']['required']??false)===false,'False recovery mode after successful code-only activation');
 checkR21(($snap['managed_tree_sha256']??'')!=='','Managed tree missing after activation');
 $rolled=$svc->rollback(21);
 checkR21(($rolled['action']??'')==='rollback'&&($rolled['active_version']??'')===$before,'Rollback returned wrong version');
 checkR21(trim(file_get_contents($root.'/Web/VERSION.txt'))===$before,'Rollback did not restore prior disk version');
 $after=$svc->snapshot();
 checkR21(($after['recovery_mode']['required']??false)===false,'Unexpected recovery marker after rollback');
 $result=['case'=>'R20_active_code_to_R20_qa','outcome'=>'PASS','stage'=>'PASS','activate'=>'PASS','readback'=>'PASS','code_only_rollback'=>'PASS'];
}
$versions=$core->migrations()->appliedVersions();checkR21(count($versions)===76,'Applied database migration count changed');
$result+=['mariadb_real'=>true,'zip_native'=>class_exists(ZipArchive::class),'migration_count'=>count($versions),'active_before'=>$before,'package_target'=>$stagedVersion,'source_install_kind'=>'synthetic_CI_copy','full_windows_or_real_host_tested'=>false,'installable'=>false];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
