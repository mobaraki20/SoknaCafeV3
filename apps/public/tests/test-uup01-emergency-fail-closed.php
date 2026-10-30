<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Emergency/PublicUpdateException.php';
require_once __DIR__.'/../src/Emergency/PublicUpdateService.php';

use Sokna\PublicEdge\Emergency\PublicUpdateException;
use Sokna\PublicEdge\Emergency\PublicUpdateService;

if (!class_exists(ZipArchive::class)) { fwrite(STDERR, "ZipArchive missing\n"); exit(2); }
$root=sys_get_temp_dir().'/sokna-r26-'.bin2hex(random_bytes(5));
@mkdir($root,0700,true);
$pass=0;
function expect(bool $ok,string $msg): void {global $pass;if(!$ok)throw new RuntimeException($msg);$pass++;echo "PASS ".$msg."\n";}
function rejected(callable $work,string $expected): void {
    try { $work(); } catch(PublicUpdateException $e) {
        if($e->errorCode===$expected){expect(true,$expected);return;}
        throw new RuntimeException('Expected '.$expected.', got '.$e->errorCode);
    }
    throw new RuntimeException('Unexpectedly accepted '.$expected);
}
function put(string $file,array $value): void {
    if(!is_dir(dirname($file)))mkdir(dirname($file),0700,true);
    file_put_contents($file,json_encode($value,JSON_THROW_ON_ERROR));
}
function fixture(string $base,string $version,bool $fence=false): PublicUpdateService {
    $web=$base.'/web';$storage=$base.'/storage';
    @mkdir($web,0700,true);@mkdir($storage,0700,true);
    file_put_contents($web.'/VERSION.txt', $version."\n");
    put($storage.'/emergency/update/state.json',[
        'format'=>'sokna-public-update-state-v1',
        'active_version'=>'3.0.0-g3.6-workspace.35es-stale-state',
        'staged'=>null
    ]);
    put($storage.'/routine-update-v2/state.json',[
        'format'=>'sokna-public-routine-update-state-v2',
        'schema_version'=>2,'active_version'=>$version,
        'db_forward_fence'=>$fence?['transaction_id'=>'historic-forward-only']:null,
        'pending_verification'=>null,'activation_journal'=>null
    ]);
    return new PublicUpdateService($web,$storage,$base.'/trust.json',null);
}
function zipped(string $file,string $version,bool $corrupt=false): string {
    $target=$version."\n";
    $record=['path'=>'VERSION.txt','size'=>strlen($target),'sha256'=>hash('sha256',$target)];
    $manifest=['format'=>'sokna-component-package-v1','schema_version'=>1,
        'component'=>'public-edge','version'=>$version,
        'contracts'=>['local_public_contract'=>1],'files'=>[$record]];
    $zip=new ZipArchive();
    if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('zip create');
    $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));
    $zip->addFromString('payload/VERSION.txt',$corrupt?'tampered':$target);
    $zip->close();return hash_file('sha256',$file);
}
function rm(string $dir): void {
    if(!is_dir($dir))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}
    rmdir($dir);
}
try {
    $target='3.0.0-g3.6-workspace.35fj-sourceqa';
    $package=$root.'/one-immutable-target.zip';
    $digest=zipped($package,$target);
    $a=fixture($root.'/install-a','3.0.0-g3.6-workspace.35fh-real-like-source');
    $b=fixture($root.'/install-b','3.0.0-g3.6-workspace.35fi-real-like-source');
    expect($a->currentVersion()==='3.0.0-g3.6-workspace.35fh-real-like-source','disk VERSION wins over stale 35ES state');
    expect($a->snapshot()['active_version']===$a->currentVersion(),'Emergency status reflects actual disk version');
    $stageA=$a->stageUploadedZip($package,'qa:a');
    $stageB=$b->stageUploadedZip($package,'qa:b');
    expect($stageA['package_sha256']===$digest&&$stageB['package_sha256']===$digest,'same immutable ZIP stages from two distinct on-disk versions');
    $same=zipped($root.'/same.zip','3.0.0-g3.6-workspace.35fh-real-like-source');
    $repairStage=$a->stageUploadedZip($root.'/same.zip','qa:a');
    expect($repairStage['version']===$a->currentVersion(),'exact-version package may stage for explicit Repair');
    rejected(fn()=>$a->activateStaged('qa:a'),'same_version_requires_repair');
    zipped($root.'/old.zip','3.0.0-g3.6-workspace.35fg-old');
    rejected(fn()=>$a->stageUploadedZip($root.'/old.zip','qa:a'),'downgrade_or_reinstall_forbidden');

    // Routine and Emergency MUST serialize on the SAME canonical storage lock.
    $lockPath=$root.'/install-a/storage/public-update-lifecycle.lock';
    $held=fopen($lockPath,'c+');
    if(!is_resource($held)||!flock($held,LOCK_EX|LOCK_NB))throw new RuntimeException('QA_SHARED_LOCK_UNAVAILABLE');
    try{
        rejected(fn()=>$a->stageUploadedZip($package,'qa:busy'),'lifecycle_busy');
        rejected(fn()=>$a->activateStaged('qa:busy'),'lifecycle_busy');
        rejected(fn()=>$a->repairStaged('qa:busy'),'lifecycle_busy');
        rejected(fn()=>$a->rollback('qa:busy'),'lifecycle_busy');
    }finally{flock($held,LOCK_UN);fclose($held);}
    expect(file_get_contents($root.'/install-a/web/VERSION.txt')===
        "3.0.0-g3.6-workspace.35fh-real-like-source\n",
        'shared lock rejection never altered live Web');
    // A Routine active identity that disagrees with physical code requires
    // explicit reconciliation before any Emergency work can proceed.
    $drift=fixture($root.'/unreconciled','3.0.0-g3.6-workspace.35fh-disk');
    put($root.'/unreconciled/storage/routine-update-v2/state.json',[
        'format'=>'sokna-public-routine-update-state-v2',
        'schema_version'=>2,
        'active_version'=>'3.0.0-g3.6-workspace.35fg-routine-stale',
        'db_forward_fence'=>null,
        'pending_verification'=>null,
        'activation_journal'=>null
    ]);
    rejected(fn()=>$drift->stageUploadedZip($package,'qa:drift'),'routine_owner_not_reconciled');

    zipped($root.'/uncomparable.zip','different-format');
    rejected(fn()=>$a->stageUploadedZip($root.'/uncomparable.zip','qa:a'),'unverifiable_version_order');
    $bad=$root.'/bad.zip';zipped($bad,$target,true);
    rejected(fn()=>$a->stageUploadedZip($bad,'qa:a'),'hash_mismatch');
    $f=fixture($root.'/forward-fence','3.0.0-g3.6-workspace.35fh-readback',true);
    rejected(fn()=>$f->stageUploadedZip($package,'qa:forward'),'db_forward_recovery_required');
    rejected(fn()=>$f->rollback('qa:forward'),'db_forward_recovery_required');
    rejected(fn()=>$f->repairStaged('qa:forward'),'db_forward_recovery_required');
    $staged=fixture($root.'/older-staged','3.0.0-g3.6-workspace.35fh-actual');
    put($root.'/older-staged/storage/emergency/update/state.json',[
        'format'=>'sokna-public-update-state-v1','staged'=>['version'=>'3.0.0-g3.6-workspace.35fg-older']
    ]);
    rejected(fn()=>$staged->activateStaged('qa:older'),'downgrade_or_reinstall_forbidden');
    @unlink($root.'/install-a/storage/routine-update-v2/state.json');
    rejected(fn()=>$a->rollback('qa:unknown'),'foundation_not_ready');
    rejected(fn()=>$a->stageUploadedZip($package,'qa:unknown'),'foundation_not_ready');
    expect(is_file($root.'/install-a/web/VERSION.txt'),'all negative cases left current Web unchanged');
    echo "R26_PUBLIC_EMERGENCY_FAIL_CLOSED_PASS ($pass checks); STAGE ONLY, NO PRODUCTION ACTIVATION\n";
}finally{rm($root);}
