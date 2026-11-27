<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SOKNA_QA_DISPOSABLE')!=='YES'||getenv('QA_DB_PASSWORD')!=='synthetic-cp116-ci-only')exit(2);
$root=realpath($argv[1]);if(!$root||basename($root)!=='local')exit(2);require $root.'/bootstrap.php';
$cfg=require dirname($root).'/config.php';if(($cfg['db']['host']??'')!=='127.0.0.1'||($cfg['db']['name']??'')!=='sokna_cp150'||($cfg['db']['pass']??'')!==getenv('QA_DB_PASSWORD'))exit(2);
$c=sokna_local_bootstrap($cfg);$p=$c->database();
function ck($x,$n){if(!$x)throw new RuntimeException($n);echo "PASS $n\n";}
$mode=$argv[2]??'prepare';
if($mode==='prepare'){
 $actor=$p->query("SELECT id,role FROM users WHERE username='qa-target'")->fetch(PDO::FETCH_ASSOC);
 $r=$c->printTemplates()->installStandard('customer',$actor);ck((int)($r['package_id']??$r['id']??0)>0,'standard customer template installed in disposable fixture');exit;
}
if($mode==='sync'){
 $publisher=$c->publicEdgePublisher();$seq=$publisher->seedProjectionInvalidations(['operations','reports'],'qa.a14.v2');ck($seq>0,'seed operations and reports invalidation');
 $r=$publisher->syncProjectionModelsV2(250,4,10,null,['operations','reports']);foreach(['operations','reports'] as $key){ck(($r['models'][$key]['ok']??false)===true,'actual signed '.$key.' v2 ACK');}
 $h=$publisher->syncHeartbeat(5);ck(($h['success']??false)===true,'actual signed heartbeat');exit;
}
if($mode==='heartbeat'){$h=$c->publicEdgePublisher()->syncHeartbeat(5);ck(($h['success']??false)===true,'actual signed heartbeat restored');exit;}
if($mode==='age'){
 $publicCfg=require dirname($root).'/public/config.php';if(($publicCfg['db']['host']??'')!=='127.0.0.1'||($publicCfg['db']['name']??'')!=='sokna_cp150_public'||($publicCfg['db']['pass']??'')!==getenv('QA_DB_PASSWORD'))exit(2);
 $f=json_decode(file_get_contents(dirname($root).'/cp144-fixture.json'),true,64,JSON_THROW_ON_ERROR);
 $db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_cp150_public;charset=utf8mb4','root',getenv('QA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $s=$db->prepare('UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 120 SECOND) WHERE installation_id=?');$s->execute([$f['installation']]);ck($s->rowCount()===1,'disposable heartbeat aged, snapshots untouched');exit;
}
exit(2);
