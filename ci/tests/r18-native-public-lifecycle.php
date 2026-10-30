<?php
declare(strict_types=1);
/* Disposable CI lab. NEVER run against an actual host. */
if (!extension_loaded('zip') || !class_exists(ZipArchive::class)) {
    throw new RuntimeException('Native PHP ZipArchive is required; no shim allowed');
}
require __DIR__.'/PublicUpdateException.php';
require __DIR__.'/RoutinePublicUpdateService.php';
require __DIR__.'/GuardCandidate.php';
use Sokna\PublicEdge\Update\RoutinePublicUpdateService;
use Sokna\PublicEdge\Emergency\PublicUpdateService;
use Sokna\PublicEdge\Emergency\PublicUpdateException;

function must(bool $ok,string $why): void {if (!$ok) throw new RuntimeException($why);}
function deny(callable $f,string $expected): void {
    try {$f();} catch (PublicUpdateException $e) {
        must($e->errorCode===$expected,"expected {$expected} got {$e->errorCode}"); return;
    }
    throw new RuntimeException("Expected rejection {$expected}");
}
function shaFile(string $file,string $sha): void {
    must(hash_file('sha256',$file)===$sha,'SHA mismatch '.basename($file));
}
function unzipChecked(string $path): array {
    $z=new ZipArchive();must($z->open($path)===true,'failed opening '.basename($path));
    try {
        $m=json_decode((string)$z->getFromName('manifest.json'),true,64,JSON_THROW_ON_ERROR);
        must(is_array($m),'manifest missing');
        must(count((array)$m['files'])===218,'Unexpected file count');
        foreach($m['files'] as $e){
            $name=(string)$e['path'];
            must($name!==''&&!str_contains($name,'..')&&!str_starts_with($name,'/'),'unsafe entry');
            $bytes=$z->getFromName('payload/'.$name);
            must(is_string($bytes)&&strlen($bytes)===(int)$e['size']&&hash('sha256',$bytes)===$e['sha256'],'bad payload sha: '.$name);
        }
        return $m;
    }finally{$z->close();}
}
shaFile(__DIR__.'/35fh-public.zip','e8cec8041454a6caf8b3b630a00a0fcfbc6324500e97ee2f0b1842a2c1943622');
shaFile(__DIR__.'/r17-public.zip','65f294c5d50241a07631d14247c157ee238f95414249db0fc5c1640fb24e33fa');
shaFile(__DIR__.'/GuardCandidate.php','4271742b7eb999666b0ae651802a1061e515cc25c66bb71af12d8168fa904cd2');
$old=unzipChecked(__DIR__.'/35fh-public.zip');$new=unzipChecked(__DIR__.'/r17-public.zip');
must(($new['compatibility']['supported_baselines'][0]['version']??'')===$old['version'],'old version not qualified');
$disposable=sys_get_temp_dir().'/r18-native-zip-'.bin2hex(random_bytes(5));mkdir($disposable,0700,true);
function initSite(string $name): array {
    global $disposable,$old;
    $root=$disposable.'/'.$name;$web=$root.'/web';$storage=$root.'/storage';
    mkdir($web,0700,true);mkdir($storage,0700,true);
    $zip=new ZipArchive();must($zip->open(__DIR__.'/35fh-public.zip')===true,'Cannot open baseline');
    foreach($old['files'] as $e){
        $rel=$e['path'];$dst=$web.'/'.$rel;$parent=dirname($dst);
        if(!is_dir($parent))mkdir($parent,0700,true);
        $bytes=$zip->getFromName('payload/'.$rel);must(is_string($bytes),'Cannot read baseline');
        file_put_contents($dst,$bytes);
    }
    $zip->close();
    // Stable owner and emergency.php are intentionally not managed by the R17 ZIP.
    $em=$web.'/src/Emergency';if(!is_dir($em))mkdir($em,0700,true);
    file_put_contents($em.'/PublicUpdateService.php',file_get_contents(__DIR__.'/GuardCandidate.php'));
    file_put_contents($em.'/PublicUpdateException.php',file_get_contents(__DIR__.'/PublicUpdateException.php'));
    file_put_contents($web.'/emergency.php','<?php /* lab fixture only */');
    file_put_contents($root.'/trust.json','{}');
    return [$web,$storage,$root.'/trust.json'];
}
function routine(string $name,?callable $migrationPending=null): RoutinePublicUpdateService {
    [$web,$storage,$trust]=initSite($name);
    return new RoutinePublicUpdateService($web,$storage,$trust,static fn()=>true,null,null,$migrationPending);
}
function guard(string $name): PublicUpdateService {
    [$web,$storage,$trust]=initSite($name);
    return new PublicUpdateService($web,$storage,$trust,static fn()=>true);
}
$cases=[];
$sourceDigest='efc56b606324b16420d4d28914f8c5301ae5f8a677178332d66083b6dc9db70b';
$r=routine('routine-stage');
must($r->currentVersion()===$old['version'],'Routine starting version mismatch');
must($r->snapshot()['managed_tree_sha256']===$sourceDigest,'Routine source tree mismatch');
$staged=$r->stageUploadedDeltaZip(__DIR__.'/r17-public.zip','ci',true);
must(($staged['strict_multibaseline_v1']??false)===true,'Strict source validation not enabled');
must($r->currentVersion()===$old['version'],'Stage modified version');
$cases[]='routine_stage_exact';
$a=$r->activateStaged('ci');must($a['current_version']===$new['version'],'Routine activation failed');
must($a['managed_tree_sha256']===$new['target_managed_tree_sha256'],'Routine target identity mismatch');
must(is_array($a['pending_verification']??null),'Pending readback absent');
$cases[]='routine_activate_readback';
$c=$r->commitActivation('ci');must(array_key_exists('pending_verification',$c)&&$c['pending_verification']===null,'Commit pending');
$cases[]='routine_commit';
$r2=routine('routine-rollback');$r2->stageUploadedDeltaZip(__DIR__.'/r17-public.zip','ci',true);$r2->activateStaged('ci');
$back=$r2->rollback('ci');must($back['managed_tree_sha256']===$sourceDigest,'Rollback pre DB boundary failed');
$cases[]='routine_pre_db_rollback';
$r3=routine('routine-mutated');$ref=new ReflectionClass($r3);$property=$ref->getProperty('componentRoot');$web=$property->getValue($r3);
file_put_contents($web.'/assets/product-ui.css','\nMUTATED',FILE_APPEND);
deny(fn()=>$r3->stageUploadedDeltaZip(__DIR__.'/r17-public.zip','ci',true),'base_not_qualified');
$cases[]='routine_mutated_baseline_denied';
$r4=routine('routine-preflight');$r4->stageUploadedDeltaZip(__DIR__.'/r17-public.zip','ci',true);$r4->activateStaged('ci');
deny(fn()=>$r4->commitActivation('ci'),'migration_preflight_unavailable');
must(is_array($r4->snapshot()['pending_verification']??null),'Preflight failure discarded pending state');
$cases[]='routine_db_inventory_fail_closed';
$e=guard('guard-exact');$e->stageUploadedZip(__DIR__.'/r17-public.zip','ci');
$ea=$e->activateStaged('ci');must($ea['current_version']===$new['version'],'Emergency guard activation failed');
$cases[]='guard_exact_activate';
$eb=$e->rollback('ci');must($eb['current_version']===$old['version'],'Guard rollback failed');
$cases[]='guard_pre_db_rollback';
$bad=guard('guard-mutated');$ref=new ReflectionClass($bad);$p=$ref->getProperty('componentRoot');
file_put_contents($p->getValue($bad).'/assets/product-ui.css','\nMUTATED',FILE_APPEND);
deny(fn()=>$bad->stageUploadedZip(__DIR__.'/r17-public.zip','ci'),'base_not_qualified');
$cases[]='guard_mutated_source_denied';
$drift=guard('guard-drift');$drift->stageUploadedZip(__DIR__.'/r17-public.zip','ci');
file_put_contents((new ReflectionClass($drift))->getProperty('componentRoot')->getValue($drift).'/assets/product-ui.css','\nDRIFT',FILE_APPEND);
deny(fn()=>$drift->activateStaged('ci'),'base_not_qualified');
$cases[]='guard_post_stage_drift_denied';
$invalid=$disposable.'/invalid-target.zip';copy(__DIR__.'/r17-public.zip',$invalid);
$zip=new ZipArchive();must($zip->open($invalid)===true,'Cannot open mutation copy');
$man=json_decode($zip->getFromName('manifest.json'),true);$man['target_managed_tree_sha256']=str_repeat('0',64);
$zip->addFromString('manifest.json',json_encode($man,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$zip->close();
$badTarget=guard('guard-bad-target');
deny(fn()=>$badTarget->stageUploadedZip($invalid,'ci'),'target_tree_contract_mismatch');
$cases[]='guard_target_identity_denied';
$result=['status'=>'PASS','native_zip'=>extension_loaded('zip'),'cases'=>$cases,'cases_count'=>count($cases),'source_sha256'=>hash_file('sha256',__DIR__.'/35fh-public.zip'),'target_sha256'=>hash_file('sha256',__DIR__.'/r17-public.zip'),'host_qualified'=>false];
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";