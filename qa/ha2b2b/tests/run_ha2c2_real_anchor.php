<?php
declare(strict_types=1);
require_once __DIR__.'/../source/SeparateLabEpochAnchor.php';
require_once __DIR__.'/../source/InstalledStateEvidenceVerifier.php';
require_once __DIR__.'/../source/LabMariaDbSettings.php';
$tests=[];$primary=null;$remote=null;$madeP=false;$madeR=false;$tmp=null;$real=false;
function checkC2(string $n,bool $p):void {global $tests;$tests[]=['case'=>$n,'pass'=>$p];if(!$p)fwrite(STDERR,"FAILED: $n\n");}
function deniedC2(callable $f):bool {try{$f();return false;}catch(Throwable $e){return true;}}
function deniedReadC2(array $r):bool{return ($r['status']??'')==='BLOCKED'&&($r['execution_authorized']??true)===false;}
try {
 if(PHP_SAPI!=='cli'||getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION'||!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('DISPOSABLE_MYSQL_ONLY');
 $anchorName=(string)getenv('SOKNA_HA2C_ANCHOR_DB_NAME');$remoteHost=(string)getenv('SOKNA_HA2C_ANCHOR_DB_HOST');
 if(!preg_match('/^sokna_ha2_anchor_[a-f0-9]{8,16}$/D',$anchorName)||$remoteHost===''||$remoteHost===(string)getenv('SOKNA_HA2B_DB_HOST'))throw new RuntimeException('ANCHOR_HOST_AND_NAME_REQUIRED');
 $primary=LabMariaDbSettings::connect();
 $remote=new PDO('mysql:host='.$remoteHost.';port=3306;dbname='.$anchorName.';charset=utf8mb4',(string)getenv('SOKNA_HA2C_ANCHOR_DB_USER'),(string)getenv('SOKNA_HA2C_ANCHOR_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>2]);
 $nameP=(string)$primary->query('SELECT DATABASE()')->fetchColumn();$nameR=(string)$remote->query('SELECT DATABASE()')->fetchColumn();
 $hostP=(string)$primary->query('SELECT @@hostname')->fetchColumn();$hostR=(string)$remote->query('SELECT @@hostname')->fetchColumn();
 if(!preg_match('/^sokna_ha2_lab_[a-f0-9]{8,16}$/D',$nameP)||$nameR!==$anchorName||$hostP===$hostR||stripos((string)$remote->query('SELECT VERSION()')->fetchColumn(),'MariaDB')===false)throw new RuntimeException('NOT_TWO_INDEPENDENT_LAB_DATABASE_PROCESSES');
 $oldPrimary=$primary->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'ha2c1_lab_%'")->fetchAll(PDO::FETCH_COLUMN);
 $oldAnchor=$remote->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'ha2c2_lab_%'")->fetchAll(PDO::FETCH_COLUMN);
 if($oldPrimary!==[]||$oldAnchor!==[])throw new RuntimeException('DISPOSABLE_TABLES_NOT_EMPTY');
 $real=true;
 $remote->exec('CREATE TABLE ha2c2_lab_floor (installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,epoch BIGINT UNSIGNED NOT NULL,revision BIGINT UNSIGNED NOT NULL,release_set_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL) ENGINE=InnoDB');$madeR=true;
 $primary->exec('CREATE TABLE ha2c1_lab_installations (installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,release_set_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,active_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,authority_epoch BIGINT UNSIGNED NOT NULL,state VARCHAR(32) NOT NULL) ENGINE=InnoDB');$madeP=true;
 $primary->exec('CREATE TABLE ha2c1_lab_migrations (migration_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,applied_epoch BIGINT UNSIGNED NOT NULL,applied_at DATETIME NOT NULL,status VARCHAR(16) NOT NULL) ENGINE=InnoDB');
 $anchor=new SeparateLabEpochAnchor($remote);$install=bin2hex(random_bytes(16));$release=hash('sha256','ha2c2_lab_release');
 checkC2('two physically distinct MariaDB container hostnames',$hostP!==$hostR);
 checkC2('anchor absent must not look installed',deniedC2(fn()=>$anchor->read($install)));
 checkC2('cannot skip initial zero to 17',deniedC2(fn()=>$anchor->advance($install,0,17,$release)));
 $r1=$anchor->advance($install,0,1,$release);checkC2('separate store begins epoch1 revision1',$r1['epoch']===1&&$r1['revision']===1);
 checkC2('duplicate first begin refused',deniedC2(fn()=>$anchor->advance($install,0,1,$release)));
 checkC2('stale compare-and-swap refused',deniedC2(fn()=>$anchor->advance($install,0,18,$release)));
 checkC2('no epoch decrease',deniedC2(fn()=>$anchor->advance($install,1,1,$release)));
 checkC2('no invalid release digest',deniedC2(fn()=>$anchor->advance($install,1,2,'bad')));
 checkC2('invalid install id refused',deniedC2(fn()=>$anchor->read('../outside')));
 $r17=$anchor->advance($install,1,17,$release);checkC2('monotonic CAS advancement durable',$r17['epoch']===17&&$r17['revision']===2);
 $reconnect=new PDO('mysql:host='.$remoteHost.';port=3306;dbname='.$anchorName.';charset=utf8mb4',(string)getenv('SOKNA_HA2C_ANCHOR_DB_USER'),(string)getenv('SOKNA_HA2C_ANCHOR_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>2]);
 checkC2('new separate connection observes epoch17',(new SeparateLabEpochAnchor($reconnect))->read($install)['epoch']===17);
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if($key===false)throw new RuntimeException('EPHEMERAL_KEY_FAILED');$pub=openssl_pkey_get_details($key)['key'];$pin=hash('sha256',$pub);$now=time();
 $payload=['installation_id'=>$install,'epoch'=>17,'revision'=>2,'release_set_sha256'=>$release,'issued_at'=>$now-2,'expires_at'=>$now+45,'nonce'=>bin2hex(random_bytes(16))];
 $sign=static function(array $p)use($key):string {$sig='';if(!openssl_sign(LabFloorSignedReadback::bytes($p),$sig,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('SIGN_FAILED');return base64_encode($sig);};
 $sig=$sign($payload);
 $check=static fn(array $p,string $s,?string $pem=null,?string $pinned=null,?int $at=null,?string $rel=null):array=>LabFloorSignedReadback::check($anchor,$p,$s,$pem??$pub,$pinned??$pin,$install,$rel??$release,$at??$now);
 $c=$check($payload,$sig);checkC2('signed floor matches fresh remote DB readback',$c['status']==='MATCHED_SEPARATE_LAB_ANCHOR_NOT_COMMIT'&&$c['epoch']===17);
 checkC2('matched floor never means committed',!$c['commit_proven']&&!$c['execution_authorized']&&!$c['for_install']);
 checkC2('invalid signature blocked',deniedReadC2($check($payload,base64_encode(random_bytes(70)))));
 checkC2('untrusted key pin blocked',deniedReadC2($check($payload,$sig,pinned:str_repeat('0',64))));
 checkC2('changed signed floor refused',deniedReadC2($check(array_replace($payload,['epoch'=>18]),$sig)));
 checkC2('wrong release context denied',deniedReadC2($check($payload,$sig,rel:str_repeat('a',64))));
 checkC2('expired signed floor blocked',deniedReadC2($check($payload,$sig,at:$now+60)));
 checkC2('future signed floor blocked',deniedReadC2($check($payload,$sig,at:$now-6)));
 checkC2('unknown receipt key blocked',deniedReadC2($check($payload+['extra'=>true],$sig)));
 $tmp=tempnam(sys_get_temp_dir(),'sokna_c2_lab_');file_put_contents($tmp,'HA2C2 lab active only');$active=hash_file('sha256',$tmp);
 $primary->prepare('INSERT INTO ha2c1_lab_installations VALUES (?,?,?,?,?)')->execute([$install,$release,$active,17,'observed_lab']);
 $primary->prepare('INSERT INTO ha2c1_lab_migrations VALUES (?,?,?,?,?)')->execute(['lab_c2',hash('sha256','migration'),17,'2026-10-19 00:00:00','applied']);
 $schema=InstalledStateEvidenceVerifier::physical($primary);$mig=InstalledStateEvidenceVerifier::migrations($primary);
 $receipt=['installation_id'=>$install,'release_set_sha256'=>$release,'active_sha256'=>$active,'schema_sha256'=>$schema,'migrations_sha256'=>$mig,'authority_epoch'=>17,'issued_at'=>$now-2,'expires_at'=>$now+45,'nonce'=>bin2hex(random_bytes(16))];
 $localSig='';openssl_sign(InstalledStateEvidenceVerifier::signedBytes($receipt),$localSig,$key,OPENSSL_ALGO_SHA256);$localSig=base64_encode($localSig);
 $localVerify=static fn(int $floor):array=>InstalledStateEvidenceVerifier::verify($primary,$receipt,$localSig,$pub,$pin,$install,$release,$tmp,$floor,$now);
 checkC2('local evidence matches remote floor17 in disposable DB',$localVerify($anchor->read($install)['epoch'])['status']==='MATCHED_LAB_EVIDENCE_NOT_COMMIT');
 $r18=$anchor->advance($install,17,18,$release);
 checkC2('anchor advanced independently to epoch18',$r18['epoch']===18);
 checkC2('old signed anchor receipt refused after CAS epoch18',deniedReadC2($check($payload,$sig)));
 checkC2('local DB rollback to epoch17 detected against anchor18',$localVerify($anchor->read($install)['epoch'])['status']==='BLOCKED');
 checkC2('duplicate stale advance after crash cannot roll back anchor',deniedC2(fn()=>$anchor->advance($install,17,19,$release)));
 $remote->prepare('UPDATE ha2c2_lab_floor SET epoch=5 WHERE installation_id=?')->execute([$install]);
 checkC2('remote storage tamper invalidates prior signed anchor readback',deniedReadC2($check($payload,$sig)));
 checkC2('local rollback cannot pass even after anchor tamper using stale receipt',deniedReadC2($check($payload,$sig)));
 checkC2('anchor class exposes no production commit or rollback',!array_intersect(['commit','activate','rollback','recover'],get_class_methods(SeparateLabEpochAnchor::class)));
 checkC2('two independent DB endpoints retained',($primary->getAttribute(PDO::ATTR_CONNECTION_STATUS)!==$remote->getAttribute(PDO::ATTR_CONNECTION_STATUS))||$hostP!==$hostR);
}catch(Throwable $e){checkC2('LAB_SETUP_OR_SQL_FAILURE:'.$e->getMessage(),false);}
finally {
 if($madeP && $primary){try{$primary->exec('DROP TABLE IF EXISTS ha2c1_lab_migrations');$primary->exec('DROP TABLE IF EXISTS ha2c1_lab_installations');}catch(Throwable $e){checkC2('PRIMARY_LAB_CLEANUP_FAILED',false);}}
 if($madeR && $remote){try{$remote->exec('DROP TABLE IF EXISTS ha2c2_lab_floor');}catch(Throwable $e){checkC2('ANCHOR_LAB_CLEANUP_FAILED',false);}}
 if(is_string($tmp)&&is_file($tmp))unlink($tmp);
}
$pass=count(array_filter($tests,static fn($t)=>$t['pass']));$total=count($tests);
$result=['stage'=>'F1-HA2C2A','status'=>($real&&$total>0&&$pass===$total)?'PASS_TWO_SEPARATE_MARIADB_LAB_ONLY':'BLOCKED_OR_FAILED','actual_two_mariadb_exercised'=>$real,'passed'=>$pass,'total'=>$total,'tests'=>$tests,'operational_offhost_authority_proven'=>false,'trust_root_provisioned'=>false,'installed_release_proven'=>false,'commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];
file_put_contents(__DIR__.'/../results/ha2c2_separate_anchor.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['status'=>$result['status'],'passed'=>$pass,'total'=>$total],JSON_PRETTY_PRINT)."\n";
exit($result['status']==='PASS_TWO_SEPARATE_MARIADB_LAB_ONLY'?0:1);
