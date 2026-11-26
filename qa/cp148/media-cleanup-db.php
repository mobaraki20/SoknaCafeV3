<?php
declare(strict_types=1);
// QA ONLY: run after the CP144 synthetic Local fixture, in its disposable loopback DB.
if(PHP_SAPI!=='cli'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]??'');$temp=realpath(getenv('RUNNER_TEMP')?:'');
if(!$root||!$temp||$root!==$temp.'/local')exit(2);
$cfg=require $temp.'/config.php';
if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp144'||($cfg['db']['pass']??'')!==getenv('QA_DB_PASSWORD'))exit(2);
require $root.'/bootstrap.php';$core=sokna_local_bootstrap($cfg);$pdo=$core->database();$actor=$pdo->query("SELECT id,role FROM users WHERE username='qa-target' AND role='admin' AND active=1")->fetch(PDO::FETCH_ASSOC);if(!$actor)exit(2);
$media=rtrim($cfg['app']['data_dir'],"\\/").'/guest-media';$service=$core->guestContent();$tmp=$temp.'/cp146-media';mkdir($tmp,0700);$fail=0;
function ck($b,$s){if(!$b)throw new RuntimeException($s);echo "PASS $s\n";}
function group($n,$fn){global $fail;try{$fn();echo "GROUP PASS $n\n";}catch(Throwable $e){$fail++;echo "GROUP FAIL $n ".$e->getMessage()."\n";}}
function makePng($p,$c){$im=imagecreatetruecolor(256,256);imagefill($im,0,0,imagecolorallocate($im,$c,17,71));imagepng($im,$p);imagedestroy($im);}
function asset($pdo,$key){$q=$pdo->prepare('SELECT * FROM guest_media_assets WHERE media_key=?');$q->execute([$key]);return $q->fetch(PDO::FETCH_ASSOC);}
function paths($pdo,$key){$a=asset($pdo,$key);if(!$a)throw new RuntimeException('fixture asset missing');$q=$pdo->prepare('SELECT path_relpath FROM guest_media_derivatives WHERE media_id=? ORDER BY variant_key');$q->execute([$a['id']]);return array_merge([$a['original_relpath']],$q->fetchAll(PDO::FETCH_COLUMN));}
function intent($pdo,$key,$actor,$paths){$q=$pdo->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,details_json) VALUES(?,'guest.media_gc_pending','guest_media',?,?)");$q->execute([$actor['id'],$key,json_encode(['cleanup_paths'=>$paths],JSON_THROW_ON_ERROR)]);}
function lastAction($pdo,$key){$q=$pdo->prepare("SELECT action,details_json FROM audit_log WHERE entity_type='guest_media' AND entity_id=? AND action IN ('guest.media_gc_pending','guest.media_gc_done') ORDER BY id DESC LIMIT 1");$q->execute([$key]);return $q->fetch(PDO::FETCH_ASSOC);}
function failedKey($r,$k){return in_array($k,array_column($r['cleanup_failed']??[],'media_key'),true);}
function upload($s,$a,$tmp,$n){$p=$tmp.'/'.$n.'.png';makePng($p,$n);$r=$s->importUpload($p,'synthetic-'.$n.'.png','',$a);return [$p,$r['media_key']];}
group('GUEST-L346 actual failed cleanup and retry',function()use($service,$actor,$pdo,$media,$tmp){
 [$p,$k]=upload($service,$actor,$tmp,111);$ps=paths($pdo,$k);$card=$media.'/'.$ps[1];$bytes=file_get_contents($card);$service->archiveMedia($k,$actor);unlink($card);mkdir($card,0700);
 $preview=$service->garbageCollect($actor,true);ck(in_array($k,$preview['candidates'],true)&&is_dir($card),'preview does not mutate');
 $r=$service->garbageCollect($actor,false);ck(failedKey($r,$k)&&!asset($pdo,$k),'failed physical deletion recorded after SQL commit');ck(lastAction($pdo,$k)['action']==='guest.media_gc_pending','durable pending record');
 rmdir($card);file_put_contents($card,$bytes);ck(in_array($k,$service->garbageCollect($actor,true)['candidates'],true),'pending is visible in next preview');
 $r=$service->garbageCollect($actor,false);ck(in_array($k,$r['removed'],true)&&!file_exists($card),'retry removes real file');ck(lastAction($pdo,$k)['action']==='guest.media_gc_done','completion recorded');ck(!in_array($k,$service->garbageCollect($actor,true)['candidates'],true),'completion is not replayed');
});
group('GUEST-L346 old intent cannot delete re-upload',function()use($service,$actor,$pdo,$media,$tmp){
 [$p,$k]=upload($service,$actor,$tmp,112);$ps=paths($pdo,$k);$card=$media.'/'.$ps[1];$service->archiveMedia($k,$actor);unlink($card);mkdir($card,0700);$r=$service->garbageCollect($actor,false);ck(failedKey($r,$k),'second pending created');rmdir($card);
 $r=$service->importUpload($p,'synthetic-112.png','',$actor);ck($r['media_key']===$k,'same deterministic key reused');$now=paths($pdo,$k);$hashes=[];foreach($now as $rel)$hashes[$rel]=hash_file('sha256',$media.'/'.$rel);
 $r=$service->garbageCollect($actor,false);ck(!in_array($k,$r['removed'],true)&&!failedKey($r,$k),'stale intent retired');foreach($hashes as $rel=>$h)ck(is_file($media.'/'.$rel)&&hash_file('sha256',$media.'/'.$rel)===$h,'reuploaded path preserved');ck((int)asset($pdo,$k)['active']===1,'reuploaded asset remains active');ck(!empty(json_decode(lastAction($pdo,$k)['details_json'],true)['superseded']),'superseded marker recorded');
});
group('GUEST-L346 simulated interruption after commit and before unlink',function()use($service,$actor,$pdo,$media,$tmp){
 [$p,$k]=upload($service,$actor,$tmp,113);$ps=paths($pdo,$k);$service->archiveMedia($k,$actor);
 $pdo->beginTransaction();try{intent($pdo,$k,$actor,$ps);$q=$pdo->prepare('DELETE FROM guest_media_assets WHERE media_key=? AND active=0');$q->execute([$k]);ck($q->rowCount()===1,'fixture deletion staged');$pdo->rollBack();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 ck(asset($pdo,$k)&&!lastAction($pdo,$k),'rollback removes intent and preserves row');
 $pdo->beginTransaction();try{intent($pdo,$k,$actor,$ps);$q->execute([$k]);ck($q->rowCount()===1,'fixture deletion staged again');$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 foreach($ps as $rel)ck(is_file($media.'/'.$rel),'committed intent with physical file still present');
 $r=$service->garbageCollect($actor,false);ck(in_array($k,$r['removed'],true),'actual service resumes simulated interruption');foreach($ps as $rel)ck(!file_exists($media.'/'.$rel),'all recorded files removed');
});
exit($fail?1:0);
