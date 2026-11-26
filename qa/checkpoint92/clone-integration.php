<?php
declare(strict_types=1);
use Sokna\PublicEdge\Update\RoutinePublicUpdateService as Routine;
use Sokna\PublicEdge\Core\Migrations;
use Sokna\PublicEdge\Health\PublicHealthService;
if(getenv('GITHUB_ACTIONS')!=='true'||$argc!==3)throw new RuntimeException('CI_ONLY');
function ck($ok,$m){if(!$ok)throw new RuntimeException($m);}
[$script,$phase,$web]=$argv;
$root=realpath(getenv('RUNNER_TEMP'));$web=realpath($web);ck($root&&$web&&str_starts_with($web,$root.'/'),'PRIVATE_ROOT');
require $web.'/src/Emergency/PublicUpdateException.php';
require $web.'/src/Update/RoutinePublicUpdateService.php';
require $web.'/src/Core/Migrations.php';
require $web.'/src/Core/SafeErrors.php';
require $web.'/src/Health/PublicHealthService.php';
require getenv('GITHUB_WORKSPACE').'/qa/checkpoint56/orphan_fence_readonly.php';
function db($name,$user,$pass){return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
$src=db('sokna_cp92_source','cp92_read',getenv('CP92_READ_PASS'));
$clone=db('sokna_cp92_clone','cp92_clone',getenv('CP92_CLONE_PASS'));
$pkg=$root.'/fixture/PUBLIC-ORIGIN-TO-35IU-FIXTURE-QA-ONLY.zip';
ck(hash_file('sha256',$pkg)==='cd91fb137074e523ecb05d68518cf3a882ebefb751aba1c7004f8b55f256d9a7','PACKAGE_PIN');
$marker=fn(PDO $p)=>(int)$p->query("SELECT COUNT(*) FROM installations WHERE installation_id='cp92-fixture'")->fetchColumn();
$sourceProof=schemaProof($src);
ck(count($sourceProof['ledger'])===8&&$marker($src)===1,'SOURCE_LEDGER_MARKER');
$baselineHash=hash('sha256',json_encode($sourceProof,JSON_THROW_ON_ERROR));
if($phase==='proof'){
 ck($web===$root.'/old-web'&&schemaProof($clone)===$sourceProof&&$marker($clone)===1,'RESTORE_MISMATCH');
 // Negative capability test: the clone account must not even be able to read the source.
 try{$clone->query('SELECT version FROM sokna_cp92_source.schema_migrations');throw new RuntimeException('CROSS_DB_PRIVILEGE');}catch(PDOException $e){ck((int)($e->errorInfo[1]??0)===1142,'UNEXPECTED_SOURCE_DENIAL');}
 file_put_contents($root.'/schema-proof.json',json_encode(['source_schema_sha256'=>$baselineHash,'ledger'=>$sourceProof['ledger'],'source_database'=>'sokna_cp92_source','clone_database'=>'sokna_cp92_clone','marker'=>1,'actual_restore_match'=>true,'cross_database_privilege_denied'=>true],JSON_THROW_ON_ERROR));
 echo "PASS verified backup restore and restricted clone account\n";exit;
}
ck($web===$root.'/private/candidate-DO-NOT-SERVE','PREPARED_COPY_REQUIRED');
$j=json_decode(file_get_contents($root.'/private/journal.json'),true,512,JSON_THROW_ON_ERROR);
$state=$web.'/storage/routine-update-v2/state.json';
$s=json_decode(file_get_contents($state),true,512,JSON_THROW_ON_ERROR);
$guard="<?php\ndeclare(strict_types=1);\nthrow new RuntimeException('SOKNA offline candidate: verified independent DB binding required; do not serve.');\n";
ck(file_get_contents($web.'/config.php')===$guard&&$j['deployment_authorized']===false,'CONFIG_GUARD');
ck(($s['foundation_handoff']['authorization_sha256']??null)===$j['permit_sha256']&&($s['foundation_handoff']['previous_fence']??null)===$j['plan']['fence'],'HANDOFF_LINK');
$proof=json_decode(file_get_contents($root.'/schema-proof.json'),true,512,JSON_THROW_ON_ERROR);
ck($baselineHash===$proof['source_schema_sha256'],'SOURCE_SCHEMA_CHANGED');
ck($clone->query('SELECT DATABASE()')->fetchColumn()==='sokna_cp92_clone'&&$src->query('SELECT DATABASE()')->fetchColumn()==='sokna_cp92_source','DB_BINDING');
$m=new Migrations($clone,$web.'/database/migrations');$health=new PublicHealthService($clone,$web,$web.'/storage');
$r=new Routine($web,$web.'/storage',$web.'/resources/update-trust-v1.json',fn()=>$health->status()['status']===200,fn()=>$m->migrate(),null,fn()=>$m->pendingVersions());
if($phase==='stage'){
 ck(schemaProof($clone)===$sourceProof&&$marker($clone)===1&&$r->managedTreeDigest()===$j['plan']['transition_tree'],'PREPARED_RESTORE');
 ck($s['db_forward_fence']===null&&$s['foundation_handoff']['no_database_mutation']===true,'DISPOSITION');
 $a=$r->stageUploadedDeltaZip($pkg,'cp92-clone',true);ck($a['package_sha256']===hash_file('sha256',$pkg),'STAGE');
 $b=$r->activateStaged('cp92-clone');ck(is_array($b['pending_verification']),'ACTIVATION');
 ck($marker($src)===1&&schemaProof($src)===$sourceProof,'SOURCE_MUTATED');
 echo "PASS actual prepared copy stage and activate with independent clone only\n";
}elseif($phase==='commit'){
 ck($m->pendingVersions()===['0009_public_move_owner_setup','0010_public_move_owner_receipts','0011_public_move_intake_pause','0012_auth_snapshot_watermark'],'PENDING');
 $snap=$r->commitActivation('cp92-clone',fn($v)=>$health->status()['body']);
 ck($snap['active_version']==='3.0.0-g3.6-workspace.35iu-checkpoint76-qa'&&$snap['managed_tree_matches_expected']&&$snap['db_forward_fence']===null&&$snap['pending_verification']===null,'COMMIT');
 ck(count($m->verifiedAppliedVersions())===12&&$marker($clone)===1&&$m->pendingVersions()===[],'CLONE_LEDGER');
 ck(schemaProof($src)===$sourceProof&&$marker($src)===1,'SOURCE_MUTATED');
 $final=json_decode(file_get_contents($state),true,512,JSON_THROW_ON_ERROR);
 ck($final['foundation_handoff']===$s['foundation_handoff'],'HANDOFF_HISTORY_CHANGED');
 ck(file_get_contents($web.'/config.php')===$guard,'CONFIG_GUARD_CHANGED');
 echo "PASS clone commit, source eight ledger preserved, historical disposition retained and config still blocked\n";
}else throw new RuntimeException('PHASE');
