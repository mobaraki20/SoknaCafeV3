<?php
declare(strict_types=1);
use Sokna\PublicEdge\Update\RoutinePublicUpdateService as Routine;
use Sokna\PublicEdge\Emergency\PublicUpdateException;
use Sokna\PublicEdge\Core\Migrations;
use Sokna\PublicEdge\Health\PublicHealthService;
if(getenv('GITHUB_ACTIONS')!=='true'||$argc!==5)throw new RuntimeException('DISPOSABLE_CI_ONLY');
[$script,$phase,$web,$target,$pkg]=$argv;
function ck($ok,$m){if(!$ok)throw new RuntimeException($m);}
$root=realpath(getenv('RUNNER_TEMP'));ck($root!==false&&str_starts_with(realpath($web),$root.'/')&&!is_file($web.'/config.php')&&!is_file($web.'/install.lock'),'NOT_DISPOSABLE');
$storage=$web.'/storage';$state=$storage.'/routine-update-v2/state.json';
$receipt=json_decode(file_get_contents(dirname($pkg).'/factory-evidence-decision.json'),true,512,JSON_THROW_ON_ERROR);
ck(hash_file('sha256',$pkg)===$receipt['files'][basename($pkg)]['sha256'],'PACKAGE_PIN');
require $web.'/src/Emergency/PublicUpdateException.php';
require $web.'/src/Update/RoutinePublicUpdateService.php';
require $web.'/src/Core/Migrations.php';
require $web.'/src/Core/SafeErrors.php';
require $web.'/src/Health/PublicHealthService.php';
require getenv('GITHUB_WORKSPACE').'/qa/checkpoint56/orphan_fence_readonly.php';
$pwd=getenv('SOKNA_CI_DB_PASS');function db($name){global $pwd;return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4','root',$pwd,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
$db=db('sokna_ci_upgrade77');$m=new Migrations($db,$web.'/database/migrations');$health=new PublicHealthService($db,$web,$storage);
$r=new Routine($web,$storage,$web.'/resources/update-trust-v1.json',fn()=>$health->status()['status']===200,fn()=>$m->migrate(),null,fn()=>$m->pendingVersions());
$want=$receipt['public'];$base=$want['qa_only_foundation_origin'];
$historical=['transaction_id'=>'20260101-000000-disposable','schema_owner_version'=>'fixture-historical-owner','pending_at_boundary'=>['0007_authority_epoch_fencing','0008_public_user_ui_preferences'],'policy'=>'forward_recovery_only'];
$history=[['action'=>'fixture_historical_commit','result'=>'success','transaction_id'=>$historical['transaction_id']]];
$marker=fn(PDO $p)=>(int)$p->query("SELECT COUNT(*) FROM installations WHERE installation_id='cp89-fixture'")->fetchColumn();
if($phase==='prepare'){
 ck($r->managedTreeDigest()==='90b26f6d15899b3d53dc85d653928b9851e3580ea73d38e5d3e83cf258126619','ORIGINAL_TREE');
 ck(count($m->migrate())===8&&$m->pendingVersions()===[],'ORIGINAL_DB');
 $db->exec("INSERT INTO installations(installation_id) VALUES('cp89-fixture')");
 ck($marker($db)===1,'MARKER');
 echo "PASS archived 35hv actual original eight migrations and business marker\n";
}elseif($phase==='stage'){
 ck(hash_file('sha256',$root.'/backup.sql')!==false,'BACKUP_EXISTS');
 $restored=db('sokna_ci_restore89');$actual=schemaProof($db);$reference=schemaProof($restored);
 ck($actual===$reference&&count($actual['ledger'])===8&&$marker($restored)===1&&$marker($db)===1,'INDEPENDENT_RESTORE');
 echo "PASS independent actual database backup and restore, 8-ledger metadata and marker\n";
 $before=$r->managedTreeDigest();ck($before!==$base['managed_tree_sha256'],'UNTRANSITIONED');
 try{$r->stageUploadedDeltaZip($pkg,'ci-old',true);throw new RuntimeException('UNTRANSITIONED_ACCEPTED');}catch(PublicUpdateException $e){ck($e->errorCode==='base_not_qualified','UNEXPECTED_REJECT_'.$e->errorCode);}
 ck($r->managedTreeDigest()===$before&&$r->snapshot()['staged']===null&&!is_file($r->maintenanceLockPath()),'REJECT_MUTATED');
 echo "PASS untransitioned historical tree rejected without mutation\n";
 $s=['format'=>'sokna-public-routine-update-state-v2','schema_version'=>2,'active_version'=>$base['version'],'active_managed_tree_sha256'=>$before,'previous_version'=>'','lkg_recovery_id'=>'','staged'=>null,'pending_verification'=>null,'activation_journal'=>null,'db_forward_fence'=>$historical,'history'=>$history];
 ck(is_dir(dirname($state))||mkdir(dirname($state),0700,true),'STATE_DIR');
 ck(file_put_contents($state,json_encode($s,JSON_THROW_ON_ERROR))!==false,'FIXTURE_STATE');
 $frozen=file_get_contents($state);
 try{$r->rollback('ci-fenced');throw new RuntimeException('UNSAFE_ROLLBACK');}catch(PublicUpdateException $e){ck($e->errorCode==='db_forward_rollback_forbidden','WRONG_FENCE_REJECT_'.$e->errorCode);}
 ck(file_get_contents($state)===$frozen&&$r->managedTreeDigest()===$before&&schemaProof($db)===$actual,'FENCE_REJECT_MUTATED');
 echo "PASS historical synthetic fence blocks actual old Routine rollback\n";
 // Disposable QA only: exercise the previously tested owner-only transition and
 // emulate the separately authorized historical disposition. Never a live repair tool.
 foreach(['recovery.php','src/Recovery/StableRecoveryService.php','src/Emergency/PublicUpdateService.php'] as $n)ck(copy($target.'/'.$n,$web.'/'.$n),'OWNER_COPY');
 ck($r->managedTreeDigest()===$base['managed_tree_sha256'],'FOUNDATION_TREE');
 $s['active_managed_tree_sha256']=$base['managed_tree_sha256'];$s['historical_fence_disposition']=['classification'=>'DISPOSABLE_QA_ONLY','fence'=>$historical,'backup_sha256'=>hash_file('sha256',$root.'/backup.sql'),'no_migration_executed'=>true,'no_rollback_authorized'=>true];$s['db_forward_fence']=null;
 ck(file_put_contents($state.'.qa-tmp',json_encode($s,JSON_THROW_ON_ERROR))!==false&&rename($state.'.qa-tmp',$state),'FIXTURE_DISPOSITION');
 ck(schemaProof($db)===$actual&&$r->snapshot()['managed_tree_matches_expected']===true,'DISPOSITION_MUTATION');
 echo "PASS disposable disposition preserved historical fence record and unchanged actual DB\n";
 $staged=$r->stageUploadedDeltaZip($pkg,'ci-exact',true);
 ck($staged['package_sha256']===hash_file('sha256',$pkg),'STAGE_PACKAGE');
 $pending=$r->activateStaged('ci-exact');
 ck($pending['current_version']===$want['version']&&$r->managedTreeDigest()===$want['managed_tree_sha256']&&is_array($pending['pending_verification']),'ACTIVATE');
 echo "PASS exact eight-origin ZIP stage and activate from historical 35hv fixture\n";
}elseif($phase==='commit'){
 $expected=['0009_public_move_owner_setup','0010_public_move_owner_receipts','0011_public_move_intake_pause','0012_auth_snapshot_watermark'];
 ck($r->currentVersion()===$want['version']&&$m->pendingVersions()===$expected,'TARGET_PENDING_4');
 $snap=$r->commitActivation('ci-exact',fn($v)=>$health->status()['body']);
 ck($snap['active_version']===$want['version']&&$snap['managed_tree_matches_expected']===true&&$snap['pending_verification']===null&&$snap['db_forward_fence']===null&&!is_file($r->maintenanceLockPath()),'COMMIT_STATE');
 ck(count($m->verifiedAppliedVersions())===12&&$m->pendingVersions()===[]&&$marker($db)===1,'DB_LEDGER_MARKER');
 foreach($want['protected_pins'] as $n=>$sha)ck(hash_file('sha256',$web.'/'.$n)===$sha,'STABLE_PIN');
 $final=json_decode(file_get_contents($state),true,64,JSON_THROW_ON_ERROR);
 ck(($final['historical_fence_disposition']['fence']??null)===$historical&&($final['historical_fence_disposition']['classification']??null)==='DISPOSABLE_QA_ONLY','HISTORY_LOST');
 ck($marker(db('sokna_ci_restore89'))===1&&count(schemaProof(db('sokna_ci_restore89'))['ledger'])===8,'BACKUP_RESTORE_CHANGED');
 echo "PASS actual 0009-0012 migration in fresh process, exact readback, marker, prior fence record and stable pins preserved\n";
 ck($m->migrate()===[]&&$marker($db)===1,'MIGRATION_REPLAY');
 echo "PASS no migration replay on repeated target migrate\n";
}else throw new RuntimeException('PHASE');
