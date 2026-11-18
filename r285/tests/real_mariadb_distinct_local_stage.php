<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('R285_CI_ONLY');
require __DIR__.'/../overlay/src/Security/PublicDistinctLocalStage.php';
require __DIR__.'/qa_public_replacement_gate.php';
$root=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)(getenv('R285_DB_PASSWORD')?:'qa-only'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS sokna_r285_qa');
$root->exec('CREATE DATABASE sokna_r285_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r285_qa;charset=utf8mb4','root',(string)(getenv('R285_DB_PASSWORD')?:'qa-only'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try {
 foreach([
 "CREATE TABLE installations(installation_id VARCHAR(96) PRIMARY KEY,display_name VARCHAR(160),active TINYINT NOT NULL DEFAULT 1,remote_enabled TINYINT NOT NULL DEFAULT 1,order_intake_enabled TINYINT NOT NULL DEFAULT 1,authority_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,authority_state VARCHAR(40) NOT NULL DEFAULT 'active',authority_epoch_required TINYINT NOT NULL DEFAULT 0,public_move_intake_paused TINYINT NOT NULL DEFAULT 0,authority_changed_at DATETIME(6) NULL,revoked_at DATETIME NULL) ENGINE=InnoDB",
 "CREATE TABLE public_move_owner_receipts(operation_id VARCHAR(128) PRIMARY KEY,public_instance_id VARCHAR(128),local_installation_id VARCHAR(128),expires_epoch BIGINT) ENGINE=InnoDB",
 "CREATE TABLE installation_takeovers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,old_installation_id VARCHAR(96),new_installation_id VARCHAR(96),code_hash VARCHAR(255),status VARCHAR(20),expires_at DATETIME) ENGINE=InnoDB",
 "CREATE TABLE realtime_requests(installation_id VARCHAR(96),state VARCHAR(24)) ENGINE=InnoDB",
 "CREATE TABLE deferred_work(installation_id VARCHAR(96),state VARCHAR(24)) ENGINE=InnoDB",
 "CREATE TABLE auth_projections(installation_id VARCHAR(96),projection_id VARCHAR(96)) ENGINE=InnoDB"
 ] as $sql)$db->exec($sql);
 $old='r285-original-local';$new='r285-replacement-local';$public='public-r285-instance';$operation='r285-operation-owner-approved';
 $db->prepare("INSERT INTO installations(installation_id,authority_epoch) VALUES(?,3),(?,9)")->execute([$old,'another-cafe-tenant']);
 $db->prepare("INSERT INTO public_move_owner_receipts VALUES(?,?,?,?)")->execute([$operation,$public,$new,time()+180]);
 $db->prepare("INSERT INTO realtime_requests VALUES(?,?)")->execute([$old,'queued']);
 $db->prepare("INSERT INTO auth_projections VALUES(?,?)")->execute([$old,'original-manager']);
 $hmac='R285-private-old-handoff-secret-at-least-32-chars';
 $r=['format'=>'sokna-local-machine-offline-fence-v1','source_installation_id'=>$old,
 'business_backup_sha256'=>str_repeat('a',64),'media_archive_sha256'=>str_repeat('b',64),
 'source_http_fenced'=>true,'source_windows_workers_stopped'=>true,'target_business_activated'=>false];
 $r['mac']=hash_hmac('sha256',json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)."\n",$hmac);
 $stage=new \Sokna\PublicEdge\Security\PublicDistinctLocalStage($db,$public,'R285-server-protected-owner-key-at-least-32-chars');
 $n=0;
 $check=function(bool $ok,string $name)use(&$n){if(!$ok)throw new RuntimeException('R285_TEST_FAIL '.$name);$n++;echo 'PASS '.$name."\n";};
 $mustFail=function(callable $f,string $code)use($check){try{$f();throw new RuntimeException('UNSAFE_SUCCESS');}catch(RuntimeException $e){if($e->getMessage()!==$code)throw $e;$check(true,$code);}};
 $try=fn(array $receipt,string $op=$operation,string $b='')=>
   $stage->reserve($old,$new,$op,$receipt,$hmac,$b?:str_repeat('a',64),str_repeat('b',64));
 $mustFail(fn()=>$try($r,'wrong-owner-operation'),'distinct_move_target_owner_approval_missing');
 $bad=$r;$bad['source_http_fenced']=false;
 $mustFail(fn()=>$try($bad),'distinct_move_source_handoff_bad_signature');
 $mustFail(fn()=>$try($r,$operation,str_repeat('c',64)),'distinct_move_source_handoff_unverified');
 $mustFail(fn()=>$try($r),'destination_pending_guest_work');
 $check((int)$db->query("SELECT remote_enabled FROM installations WHERE installation_id='$old'")->fetchColumn()===1,'preflight failures do not fence source');
 $db->exec("UPDATE realtime_requests SET state='completed'");
 $out=$try($r);
 $check($out['ok']===true&&!$out['safe_to_cutover']&&!$out['public_default_binding_changed'],'stage never claims to activate Public');
 $oldState=$db->query("SELECT remote_enabled,order_intake_enabled,authority_state FROM installations WHERE installation_id='$old'")->fetch(PDO::FETCH_ASSOC);
 $newState=$db->query("SELECT active,remote_enabled,order_intake_enabled,authority_epoch FROM installations WHERE installation_id='$new'")->fetch(PDO::FETCH_ASSOC);
 $check((int)$oldState['remote_enabled']===0&&(int)$oldState['order_intake_enabled']===0&&$oldState['authority_state']==='reenroll_pending','old Local fenced atomically');
 $check((int)$newState['active']===0&&(int)$newState['remote_enabled']===0&&(int)$newState['order_intake_enabled']===0&&(int)$newState['authority_epoch']===4,'new Local reserved but inactive');
 $row=$db->query("SELECT code_hash,status FROM installation_takeovers")->fetch(PDO::FETCH_ASSOC);
 $check($row['status']==='pending'&&password_verify($out['enrollment_code'],$row['code_hash']),'takeover code stored hashed');
 $check((int)$db->query("SELECT COUNT(*) FROM auth_projections WHERE installation_id='$old'")->fetchColumn()===1,'source staff history preserved');
 $check((int)$db->query("SELECT remote_enabled FROM installations WHERE installation_id='another-cafe-tenant'")->fetchColumn()===1,'other cafe untouched');
 $mustFail(fn()=>$try($r),'distinct_move_target_already_exists');
 echo 'R285_PUBLIC_DISTINCT_MACHINE_SOURCE_FENCED_OWNER_CONFIRMED_'.(string)$n.'_CHECKS_PASS'."\n";
}finally{$root->exec('DROP DATABASE IF EXISTS sokna_r285_qa');}
