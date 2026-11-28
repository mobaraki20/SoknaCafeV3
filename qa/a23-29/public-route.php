<?php
declare(strict_types=1);
// Disposable QA harness only. No public entrypoint or real host deployment capability.
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('QA_DB_PASSWORD')!=='synthetic-a2329-ci-only'||$argc!==4)exit(2);
[$script,$phase,$web,$lab]=$argv;
$lab=realpath($lab);$web=realpath($web);
if(!$lab||!$web||dirname($lab)!==realpath(getenv('RUNNER_TEMP'))||basename($lab)!=='sokna-a23-29'||dirname($web)!==$lab||basename($web)!=='old-web')exit(2);
require $web.'/bootstrap.php';
require_once $web.'/src/Update/IndependentEmergencyRoutineBridge.php';
use Sokna\PublicEdge\Emergency\PublicUpdateException;
use Sokna\PublicEdge\Update\IndependentEmergencyRoutineBridge;
use Sokna\PublicEdge\Setup\PublicSetupService;
function ck(bool $v,string $m){if(!$v)throw new RuntimeException($m);echo "PASS $m\n";}
function js(string $p):array{return json_decode(file_get_contents($p),true,128,JSON_THROW_ON_ERROR);}
function writej(string $p,array $v):void{file_put_contents($p,json_encode($v,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",LOCK_EX);}
function tableFingerprint(PDO $db):array{
 $out=[];$names=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
 foreach($names as $t){if($t==='schema_migrations')continue;$rows=$db->query('SELECT * FROM '.chr(96).str_replace(chr(96),chr(96).chr(96),$t).chr(96))->fetchAll(PDO::FETCH_ASSOC);$l=array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($l,SORT_STRING);$out[$t]=['count'=>count($l),'sha256'=>hash('sha256',implode("\n",$l))];}
 return $out;
}
function pins(string $root,array $p):bool{foreach($p as $n=>$h)if(!is_file($root.'/'.$n)||is_link($root.'/'.$n)||hash_file('sha256',$root.'/'.$n)!==$h)return false;return true;}
function rejects(callable $f,string $code,string $label):void{try{$f();}catch(PublicUpdateException $e){ck($e->errorCode===$code,$label.' '.$e->errorCode);return;}throw new RuntimeException('UNEXPECTED_SUCCESS '.$label);}
$candidate=$lab.'/candidate';$q=js($candidate.'/A23-29-FACTORY-INTAKE-RECEIPT.json');$c=$q['public'];$owner=$q['owner_contract'];$pkg=$candidate.'/PUBLIC-35JD-UPDATE-QA-NOT-INSTALL.zip';$oldpkg=$lab.'/prior-a21/PUBLIC-A21-UPDATE-CANDIDATE-QA-ONLY.zip';
$oldId='qa-a2329-source';$newId='qa-a2329-target';$route=getenv('QA_ROUTE');if(!in_array($route,['routine','emergency'],true))exit(2);
$oldV='3.0.0-g3.6-workspace.35jc-a21-postrelease-20261128';$oldTree='73af2761f69f50a42d3fb715f06e7b93dd2446ee519154aa7450b21fbce0d593';
if($phase==='setup'){
 $_SERVER['HTTP_HOST']='127.0.0.1:18766';$_SERVER['REQUEST_URI']='/setup/api.php';$_SERVER['DOCUMENT_ROOT']=$web;
 $setup=new PublicSetupService($web);$init=$setup->install(['routing_probe_ok'=>true,'create_database'=>true,'storage_dir'=>$lab.'/private','db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_a2329_public','user'=>'root','pass'=>getenv('QA_DB_PASSWORD')],'owner_password'=>'synthetic-a2329-owner-only','emergency_password'=>'synthetic-a2329-emergency-only']);
 ck($init['success']===true&&$init['owner_initialized']===true,'genuine exact 35jb setup');
 $pair=$setup->pair(['pairing_code'=>$init['pairing_code'],'installation_id'=>$oldId,'shared_secret'=>bin2hex(random_bytes(32)),'display_name'=>'QA owner transition']);ck($pair['success']===true,'actual paired installation fixture');
 exit;
}
$cfg=require $web.'/config.php';ck($cfg['db']['name']==='sokna_a2329_public','disposable DB only');
$core=sokna_public_bootstrap($cfg);$db=$core->database();$m=$core->migrations();$r=$core->routinePublicUpdates();$storage=$cfg['app']['storage_dir'];$b=new IndependentEmergencyRoutineBridge($r,$storage,fn()=>$m->pendingVersions(),fn()=>$core->health()->status()['body']);
$readback=fn($version)=>$core->health()->status()['body'];
if($phase==='prior-stage'){
 ck(count($m->verifiedAppliedVersions())===12,'historical 35jb actual ledger');
 ck(hash_file('sha256',$oldpkg)==='7d7bd5b6943a5c860ca7fb7b750d1268540630f9fce27ccf5a559359674b61a2','exact A21 package fixture');
 $r->stageUploadedDeltaZip($oldpkg,'qa-prerequisite',true);$r->activateStaged('qa-prerequisite');exit;
}
if($phase==='prior-commit'){
 $s=$r->commitActivation('qa-prerequisite',$readback);
 ck($s['active_version']===$oldV&&$r->managedTreeDigest()===$oldTree&&$s['managed_tree_matches_expected']===true,'genuine A21 activation establishes authoritative ledger');
 ck(pins($web,$c['protected_before'])&&count($m->verifiedAppliedVersions())===12,'all six original protected pins and old schema');
 writej($lab.'/prior-fingerprint.json',['rows'=>tableFingerprint($db),'config'=>hash_file('sha256',$web.'/config.php'),'install'=>hash_file('sha256',$web.'/install.lock'),'tree'=>$r->managedTreeDigest()]);
 exit;
}
$before=js($lab.'/prior-fingerprint.json');
if($phase==='verify-backup'){
 $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_a2329_public_backup;charset=utf8mb4','root',getenv('QA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 ck(tableFingerprint($pdo)===$before['rows'],'actual independent database backup restored and every table fingerprint matched');
 ck(tableFingerprint($db)===$before['rows'],'original database unchanged during backup');
 ck($pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()==12,'restored backup migration ledger');
 writej($lab.'/backup-receipt.json',['verified'=>true,'table_count'=>count($before['rows']),'backup_sha256'=>hash_file('sha256',$lab.'/before.sql'),'schema_count'=>12,'actual_mariadb'=>$db->query('SELECT VERSION()')->fetchColumn()]);
 exit;
}
if($phase==='stage'){
 ck(js($lab.'/backup-receipt.json')['verified']===true,'independent backup gate');
 ck($r->managedTreeDigest()===$oldTree&&$r->currentVersion()===$oldV&&pins($web,$c['protected_before']),'exact installed origin');
 ck(hash_file('sha256',$pkg)===$q['files'][basename($pkg)]['sha256'],'same frozen update ZIP');
 $negative=$lab.'/negative-protected.zip';
 rejects(fn()=>$r->stageUploadedDeltaZip($negative,'qa-negative',true),'stable_path_forbidden','real Routine guard rejects protected member');
 rejects(fn()=>$b->stageUploadedZip($negative,'qa-negative'),'stable_path_forbidden','independent Emergency guard rejects protected member');
 ck($r->snapshot()['staged']===null&&$r->managedTreeDigest()===$oldTree&&pins($web,$c['protected_before']),'negative requests changed neither stage nor installed code');
 $st=$route==='routine'?$r->stageUploadedDeltaZip($pkg,'qa-a2329',true):$b->stageUploadedZip($pkg,'qa-a2329');
 ck($st['package_sha256']===hash_file('sha256',$pkg)&&$st['version']===$c['version'],'actual selected route stages frozen bytes');
 ck($r->managedTreeDigest()===$oldTree&&pins($web,$c['protected_before']),'stage leaves all installed bytes unchanged');
 $a=$route==='routine'?$r->activateStaged('qa-a2329'):$b->activateStaged('qa-a2329');
 ck($a['current_version']===$c['version']&&is_array($a['pending_verification'])&&is_file($r->maintenanceLockPath()),'actual activation held in maintenance pending verification');
 ck($r->managedTreeDigest()===$c['tree']&&pins($web,$c['protected_before']),'managed target exact, updater still preserves all original protected pins');
 ck(tableFingerprint($db)===$before['rows']&&count($m->verifiedAppliedVersions())===12,'pre-commit commercial database unchanged');
 exit;
}
if($phase==='owner-readback'){
 // This phase only verifies the separately performed cold, single-file QA transition.
 ck(is_file($r->maintenanceLockPath())&&is_array($r->snapshot()['pending_verification'])&&$r->snapshot()['db_forward_fence']===null,'owner transition remains pre-DDL under existing maintenance fence');
 ck($r->managedTreeDigest()===$c['tree']&&pins($web,$c['protected_after']),'all six authorized target protected pins plus managed tree exact');
 ck(tableFingerprint($db)===$before['rows']&&hash_file('sha256',$web.'/config.php')===$before['config']&&hash_file('sha256',$web.'/install.lock')===$before['install'],'cold owner transition preserves data and installation identity');
 ck($m->pendingVersions()===['0013_verified_takeover_binding'],'only approved append-only migration pending');
 exit;
}
if($phase==='commit'){
 ck(is_file($r->maintenanceLockPath())&&pins($web,$c['protected_after'])&&$r->managedTreeDigest()===$c['tree'],'fresh process owner pins and target before commit');
 $s=$route==='routine'?$r->commitActivation('qa-a2329',$readback):$b->commitPending('qa-a2329');
 ck($s['active_version']===$c['version']&&$s['managed_tree_matches_expected']===true&&$s['pending_verification']===null&&$s['db_forward_fence']===null&&!is_file($r->maintenanceLockPath()),'actual selected route completed fenced commit');
 ck(count($m->verifiedAppliedVersions())===13&&$m->pendingVersions()===[],'thirteenth real migration verified');
 ck(tableFingerprint($db)===$before['rows'],'every preexisting table row fingerprint preserved after migration');
 ck(hash_file('sha256',$web.'/config.php')===$before['config']&&hash_file('sha256',$web.'/install.lock')===$before['install'],'installation config and lock preserved');
 $z=new ZipArchive();$clean=$candidate.'/PUBLIC-35JD-CLEAN-QA-NOT-INSTALL.zip';ck(hash_file('sha256',$clean)===$q['files'][basename($clean)]['sha256']&&$z->open($clean)===true,'exact frozen clean archive');
 for($i=0;$i<$z->numFiles;$i++){$n=$z->getNameIndex($i);if(hash_file('sha256',$web.'/'.$n)!==hash('sha256',$z->getFromIndex($i)))throw new RuntimeException('CLEAN_UPGRADE_MISMATCH '.$n);}
 $count=$z->numFiles;$z->close();ck($count===259,'all 259 installed product files equal exact Clean archive');
 ck($m->migrate()===[],'migration replay idempotent');
 // Narrow smoke on the newly installed exact protected class; full previous transfer/guest QA is not repeated.
 rejects(fn()=>$core->takeover()->createOffer($oldId,$oldId),'takeover_same_identity','installed owner refuses same identity');
 $offer=$core->takeover()->createOffer($oldId,$newId,1800,'qa-owner');
 $pre=$core->takeover()->previewBound($newId,$offer['enrollment_code'],$oldId);ck($pre['authority_epoch']===2,'installed owner previews exact source and next generation');
 rejects(fn()=>$core->takeover()->complete($newId,$offer['enrollment_code'],bin2hex(random_bytes(32)),'', $oldId,3),'takeover_offer_mismatch','wrong generation refused');
 $done=$core->takeover()->complete($newId,$offer['enrollment_code'],bin2hex(random_bytes(32)),'QA replacement',$oldId,2);ck($done['old_revoked']===true&&$done['authority_state']==='resync_required'&&$done['remote_enabled']===false&&$done['order_intake_enabled']===false,'installed exact owner revokes source and fences new writer');
 $rows=$db->query("SELECT installation_id,active,remote_enabled,order_intake_enabled,authority_epoch,authority_state FROM installations ORDER BY installation_id")->fetchAll(PDO::FETCH_ASSOC);$new=array_values(array_filter($rows,fn($x)=>$x['installation_id']===$newId))[0];$old=array_values(array_filter($rows,fn($x)=>$x['installation_id']===$oldId))[0];
 ck((int)$old['active']===0&&(int)$new['active']===1&&(int)$new['remote_enabled']===0&&(int)$new['order_intake_enabled']===0&&(int)$new['authority_epoch']===2,'database readback single authority with intake paused');
 ck((int)$db->query("SELECT binding_epoch FROM installation_takeovers WHERE status='completed'")->fetchColumn()===2,'actual new migration binding epoch persisted');
 writej($lab.'/route-receipt.json',['status'=>'PASS','route'=>$route,'source'=>$oldV,'target'=>$c['version'],'package_sha256'=>hash_file('sha256',$pkg),'clean_sha256'=>hash_file('sha256',$clean),'managed_tree'=>$c['tree'],'protected_after'=>$c['protected_after'],'migrations'=>13,'all_product_files'=>$count,'backup_verified'=>true,'data_preserved_before_takeover'=>true,'installed_owner_smoke'=>true,'owner_step'=>'cold disposable QA file replacement only; no production installer approved','production_authorized'=>false]);
 exit;
}
exit(2);
