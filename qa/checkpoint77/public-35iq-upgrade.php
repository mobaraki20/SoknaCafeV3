<?php
declare(strict_types=1);
use Sokna\PublicEdge\Update\RoutinePublicUpdateService as Routine;
use Sokna\PublicEdge\Emergency\PublicUpdateException;
use Sokna\PublicEdge\Core\Migrations;
use Sokna\PublicEdge\Health\PublicHealthService;
if(getenv('GITHUB_ACTIONS')!=='true'||$argc!==5)throw new RuntimeException('CI_ONLY');
[$script,$phase,$web,$target,$pkg]=$argv;
function ck($ok,$m){if(!$ok)throw new RuntimeException($m);}
$storage=$web.'/storage';$state=$storage.'/routine-update-v2/state.json';
$receipt=json_decode(file_get_contents(dirname($pkg).'/factory-evidence-decision.json'),true,512,JSON_THROW_ON_ERROR);
ck(hash_file('sha256',$pkg)===$receipt['files'][basename($pkg)]['sha256'],'PACKAGE_PIN');
require $web.'/src/Emergency/PublicUpdateException.php';
require $web.'/src/Update/RoutinePublicUpdateService.php';
require $web.'/src/Core/Migrations.php';
require $web.'/src/Core/SafeErrors.php';
require $web.'/src/Health/PublicHealthService.php';
$pwd=getenv('SOKNA_CI_DB_PASS');$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_ci_upgrade77;charset=utf8mb4','root',$pwd,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$m=new Migrations($db,$web.'/database/migrations');
$health=new PublicHealthService($db,$web,$storage);
$r=new Routine($web,$storage,$web.'/resources/update-trust-v1.json',fn()=>$health->status()['status']===200,fn()=>$m->migrate(),null,fn()=>$m->pendingVersions());
$want=$receipt['public'];$base=$want['qa_only_foundation_origin'];
if($phase==='stage'){
    ck(count($m->migrate())===11,'BASE_DB');$db->exec("INSERT INTO installations(installation_id) VALUES('cp77-fixture')");
    $before=$r->managedTreeDigest();ck($before!==$base['managed_tree_sha256'],'UNTRANSITIONED');
    try{$r->stageUploadedDeltaZip($pkg,'ci-old',true);throw new RuntimeException('UNTRANSITIONED_ACCEPTED');}catch(PublicUpdateException $e){ck($e->errorCode==='base_not_qualified','UNEXPECTED_REJECT_'.$e->errorCode);}
    ck($r->managedTreeDigest()===$before&&$r->snapshot()['staged']===null&&!is_file($r->maintenanceLockPath()),'REJECT_MUTATED');
    echo "PASS actual old-tree rejection before stage\n";
    // Emulate ONLY the already tested cp75 offline owner transition, on disposable copy.
    foreach(['recovery.php','src/Recovery/StableRecoveryService.php','src/Emergency/PublicUpdateService.php'] as $n)ck(copy($target.'/'.$n,$web.'/'.$n),'OWNER_COPY');
    ck($r->managedTreeDigest()===$base['managed_tree_sha256'],'FOUNDATION_TREE');
    $s=['format'=>'sokna-public-routine-update-state-v2','schema_version'=>2,'active_version'=>$base['version'],'active_managed_tree_sha256'=>$base['managed_tree_sha256'],'previous_version'=>'','lkg_recovery_id'=>'','staged'=>null,'pending_verification'=>null,'activation_journal'=>null,'db_forward_fence'=>null,'history'=>[]];
    ck(file_put_contents($state,json_encode($s,JSON_THROW_ON_ERROR))!==false,'FIXTURE_STATE');
    $staged=$r->stageUploadedDeltaZip($pkg,'ci-exact',true);
    ck($staged['package_sha256']===hash_file('sha256',$pkg),'STAGE_PACKAGE');
    $pending=$r->activateStaged('ci-exact');
    ck($pending['current_version']===$want['version']&&$r->managedTreeDigest()===$want['managed_tree_sha256']&&is_array($pending['pending_verification']),'ACTIVATE');
    echo "PASS actual 35iq owner-transition fixture -> exact 35iu payload stage and activate\n";
}elseif($phase==='commit'){
    ck($r->currentVersion()===$want['version']&&$m->pendingVersions()===['0012_auth_snapshot_watermark'],'TARGET_PENDING_0012');
    $snap=$r->commitActivation('ci-exact',fn($v)=>$health->status()['body']);
    ck($snap['active_version']===$want['version']&&$snap['managed_tree_matches_expected']===true&&$snap['pending_verification']===null&&$snap['db_forward_fence']===null&&!is_file($r->maintenanceLockPath()),'COMMIT_STATE');
    ck(count($m->verifiedAppliedVersions())===12&&(int)$db->query("SELECT COUNT(*) FROM installations WHERE installation_id='cp76-fixture'")->fetchColumn()===1,'DB_MARKER');
    foreach($want['protected_pins'] as $n=>$sha)ck(hash_file('sha256',$web.'/'.$n)===$sha,'STABLE_PIN');
    echo "PASS actual 0012 migration in fresh process, exact readback, DB marker and all stable pins preserved\n";
}else throw new RuntimeException('PHASE');
