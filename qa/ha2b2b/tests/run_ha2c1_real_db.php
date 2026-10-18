<?php
declare(strict_types=1);
require_once __DIR__.'/../source/InstalledStateEvidenceVerifier.php';
require_once __DIR__.'/../source/LabMariaDbSettings.php';
$cases=[];$created=false;$db=null;$tmp=null;$real=false;$lab=(string)(getenv('SOKNA_HA2B_DB_NAME')?:'');
function okcase(string $label,bool $v):void{global $cases;$cases[]=['case'=>$label,'pass'=>$v];if(!$v)fwrite(STDERR,"FAIL: $label\n");}
function blocked(array $r):bool{return ($r['status']??'')==='BLOCKED'&&($r['execution_authorized']??true)===false&&($r['installed_release_proven']??true)===false;}
try {
 if(PHP_SAPI!=='cli'||getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION'||!preg_match('/^sokna_ha2_lab_[a-f0-9]{8,16}$/D',$lab)||!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('DISPOSABLE_PDO_MYSQL_REQUIRED');
 $db=LabMariaDbSettings::connect();
 if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$lab||stripos((string)$db->query('SELECT VERSION()')->fetchColumn(),'mariadb')===false)throw new RuntimeException('WRONG_DATABASE_OR_SERVER');$real=true;
 $existing=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'ha2c1_lab_%'")->fetchAll(PDO::FETCH_COLUMN);
 if($existing!==[])throw new RuntimeException('HA2C_LAB_NOT_EMPTY');
 $db->exec("CREATE TABLE ha2c1_lab_installations (installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,release_set_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, active_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, authority_epoch BIGINT UNSIGNED NOT NULL,state VARCHAR(32) NOT NULL) ENGINE=InnoDB");
 $created=true;
 $db->exec("CREATE TABLE ha2c1_lab_migrations (migration_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, applied_epoch BIGINT UNSIGNED NOT NULL, applied_at DATETIME NOT NULL, status VARCHAR(16) NOT NULL) ENGINE=InnoDB");
 $installation=bin2hex(random_bytes(16));$release=bin2hex(random_bytes(32));
 $tmp=tempnam(sys_get_temp_dir(),'ha2c1_active_');if($tmp===false)throw new RuntimeException('TEMP_FILE_FAILED');file_put_contents($tmp,'active lab bytes only');$active=hash_file('sha256',$tmp);
 $db->prepare('INSERT INTO ha2c1_lab_installations VALUES (?,?,?,?,?)')->execute([$installation,$release,$active,17,'observed_lab']);
 $db->prepare('INSERT INTO ha2c1_lab_migrations VALUES (?,?,?,?,?)')->execute(['lab_m001',hash('sha256','migration lab content'),17,'2026-10-19 00:00:00','applied']);
 $schema=InstalledStateEvidenceVerifier::physical($db);$migrations=InstalledStateEvidenceVerifier::migrations($db);
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if($key===false)throw new RuntimeException('EPHEMERAL_TEST_KEY_FAILED');$det=openssl_pkey_get_details($key);$pem=$det['key'];$pin=hash('sha256',$pem);
 $now=time();$p=['installation_id'=>$installation,'release_set_sha256'=>$release,'active_sha256'=>$active,'schema_sha256'=>$schema,'migrations_sha256'=>$migrations,'authority_epoch'=>17,'issued_at'=>$now-5,'expires_at'=>$now+60,'nonce'=>bin2hex(random_bytes(16))];
 $sign=function(array $data) use($key):string {$sig='';if(openssl_sign(InstalledStateEvidenceVerifier::signedBytes($data),$sig,$key,OPENSSL_ALGO_SHA256)!==true)throw new RuntimeException('LAB_SIGN_FAILED');return base64_encode($sig);};
 $sig=$sign($p);$verify=function(array $data,string $signature,?string $pub=null,?string $keypin=null,?string $ins=null,?string $rel=null,?string $file=null,int $floor=17,?int $when=null)use($db,$pem,$pin,$installation,$release,$tmp,$now):array{return InstalledStateEvidenceVerifier::verify($db,$data,$signature,$pub??$pem,$keypin??$pin,$ins??$installation,$rel??$release,$file??$tmp,$floor,$when??$now);};
 $v=$verify($p,$sig);
 okcase('valid signed receipt crosschecks real MariaDB and file',$v['status']==='MATCHED_LAB_EVIDENCE_NOT_COMMIT'&&$v['matched_lab_evidence']);
 okcase('valid evidence still never asserts installed or commit',!$v['installed_release_proven']&&!$v['commit_proven']&&!$v['execution_authorized']&&!$v['for_install']);
 okcase('wrong installation blocked',blocked($verify($p,$sig,ins:bin2hex(random_bytes(16)))));
 okcase('wrong release set blocked',blocked($verify($p,$sig,rel:bin2hex(random_bytes(32)))));
 okcase('rollback floor ahead blocked',blocked($verify($p,$sig,floor:18)));
 okcase('floor absent blocked',blocked($verify($p,$sig,floor:0)));
 okcase('expired receipt blocked',blocked($verify($p,$sig,when:$now+70)));
 okcase('receipt from future blocked',blocked($verify($p,$sig,when:$now-10)));
 okcase('mutated signed digest blocked',blocked($verify(array_replace($p,['active_sha256'=>str_repeat('0',64)]),$sig)));
 okcase('mutated unsigned epoch blocked',blocked($verify(array_replace($p,['authority_epoch'=>18]),$sig)));
 okcase('changed signature blocked',blocked($verify($p,base64_encode(random_bytes(70)))));
 okcase('malformed signature base64 blocked',blocked($verify($p,'%%BAD%%')));
 okcase('replacement key pin denied',blocked($verify($p,$sig,keypin:str_repeat('f',64))));
 $wrong=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$wrongPem=openssl_pkey_get_details($wrong)['key'];
 okcase('different key with different pin denies forged original signature',blocked($verify($p,$sig,pub:$wrongPem,keypin:hash('sha256',$wrongPem))));
 okcase('unknown signed receipt property blocked',blocked($verify($p+['unexpected'=>'value'],$sig)));
 $reverse=$p;$one=$reverse['installation_id'];unset($reverse['installation_id']);$reverse['installation_id']=$one;
 okcase('receipt key order mismatch blocked',blocked($verify($reverse,$sig)));
 okcase('invalid signed epoch type blocked',blocked($verify(array_replace($p,['authority_epoch'=>'17']),$sig)));
 okcase('invalid nonce blocked',blocked($verify(array_replace($p,['nonce'=>'bad']),$sig)));
 file_put_contents($tmp,'ACTIVE FILE TAMPERED');okcase('active file content drift blocked',blocked($verify($p,$sig)));
 file_put_contents($tmp,'active lab bytes only');
 $db->exec("UPDATE ha2c1_lab_installations SET state='not-installed' WHERE installation_id='$installation'");okcase('DB self-asserted state drift blocked',blocked($verify($p,$sig)));
 $db->exec("UPDATE ha2c1_lab_installations SET state='observed_lab',authority_epoch=18 WHERE installation_id='$installation'");okcase('DB authority epoch mismatch blocked',blocked($verify($p,$sig)));
 $db->exec("UPDATE ha2c1_lab_installations SET authority_epoch=17,release_set_sha256='".str_repeat('f',64)."' WHERE installation_id='$installation'");okcase('DB release set drift blocked',blocked($verify($p,$sig)));
 $db->prepare("UPDATE ha2c1_lab_installations SET release_set_sha256=? WHERE installation_id=?")->execute([$release,$installation]);
 $db->exec("UPDATE ha2c1_lab_migrations SET status='pending' WHERE migration_id='lab_m001'");okcase('migration not applied blocked',blocked($verify($p,$sig)));
 $db->exec("UPDATE ha2c1_lab_migrations SET status='applied',checksum='".str_repeat('f',64)."' WHERE migration_id='lab_m001'");okcase('migration checksum drift blocked',blocked($verify($p,$sig)));
 $db->prepare('UPDATE ha2c1_lab_migrations SET checksum=? WHERE migration_id=?')->execute([hash('sha256','migration lab content'),'lab_m001']);
 $db->exec('ALTER TABLE ha2c1_lab_migrations ADD COLUMN tampered_col INT NULL');okcase('physical schema drift blocked',blocked($verify($p,$sig)));
 $db->exec('ALTER TABLE ha2c1_lab_migrations DROP COLUMN tampered_col');
 $db->exec("DELETE FROM ha2c1_lab_migrations WHERE migration_id='lab_m001'");okcase('missing migrations blocked',blocked($verify($p,$sig)));
 okcase('verifier has no commit/activate/recover/rollback methods',!array_intersect(['commit','activate','recover','rollback'],get_class_methods(InstalledStateEvidenceVerifier::class)));
 okcase('lab evidence never independently proves offhost trust',blocked($verify($p,$sig,floor:0)));
} catch(Throwable $e){okcase('LAB_SETUP_OR_SQL_ERROR:'.$e->getMessage(),false);}
finally {
 if($db instanceof PDO && $created){try{$db->exec('DROP TABLE IF EXISTS ha2c1_lab_migrations');$db->exec('DROP TABLE IF EXISTS ha2c1_lab_installations');}catch(Throwable $e){okcase('DISPOSABLE_CLEANUP_FAILED',false);}}
 if(is_string($tmp)&&is_file($tmp))unlink($tmp);
}
$passed=count(array_filter($cases,fn($x)=>$x['pass']));$total=count($cases);
$result=['stage'=>'F1-HA2C1','status'=>$real&&$total>0&&$passed===$total?'PASS_REAL_MARIADB_READONLY_EVIDENCE_LAB_ONLY':'BLOCKED_OR_FAIL','actual_mariadb_exercised'=>$real,'passed'=>$passed,'total'=>$total,'tests'=>$cases,'installed_release_proven'=>false,'commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];
file_put_contents(__DIR__.'/../results/ha2c1_installed_evidence.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$result['status'],'passed'=>$passed,'total'=>$total,'authorization'=>false],JSON_PRETTY_PRINT)."\n";
exit($result['status']==='PASS_REAL_MARIADB_READONLY_EVIDENCE_LAB_ONLY'?0:1);
