<?php
declare(strict_types=1);
require_once __DIR__.'/../source/RemoteIssuerLabClient.php';
$tests=[];function t(string $n,bool $v):void {global $tests;$tests[]=['case'=>$n,'pass'=>$v];if(!$v)fwrite(STDERR,"FAILED $n\n");}
function blocked(array $r):bool {return ($r['status']??'')==='BLOCKED'&&($r['execution_authorized']??true)===false&&($r['for_install']??true)===false;}
try{
 t('consumer cannot read anchor DB password',getenv('SOKNA_HA2C_ANCHOR_DB_PASS')===false);
 t('consumer has no issuer private-key path',getenv('SOKNA_ISSUER_KEYDIR')===false);
 t('consumer has no anchor DB host binding',getenv('SOKNA_HA2C_ANCHOR_DB_HOST')===false);
 t('consumer cannot hold issuer signing key',!is_file('/tmp/sokna_lab_issuer_secret.pem'));
 if(getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION')throw new RuntimeException('LAB_CONSENT');
 $token=(string)getenv('SOKNA_ISSUER_TEST_TOKEN');if(strlen($token)<32)throw new RuntimeException('BAD_TOKEN');
 $raw=@file_get_contents('http://issuer:8080/public');if(!is_string($raw))throw new RuntimeException('NO_PUBLIC_KEY');
 $pub=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(($pub['status']??'')!=='LAB_ONLY_UNTRUSTED_BOOTSTRAP')throw new RuntimeException('NO_LAB_PUB');
 $pem=$pub['public_key'];$pin=hash('sha256',$pem);t('issuer public key key_id matches',hash_equals($pin,$pub['key_id']));
 $client=new RemoteIssuerLabClient('http://issuer:8080',$token,$pem,$pin);
 t('issuer anchor hostname not visible from consumer zone',gethostbyname('anchor')==='anchor');
 $dbName=(string)getenv('SOKNA_HA2B_DB_NAME');if(!preg_match('/^sokna_ha2_lab_[a-f0-9]{8,16}$/D',$dbName))throw new RuntimeException('BAD_LOCAL_DB');
 $local=new PDO('mysql:host='.(string)getenv('SOKNA_HA2B_DB_HOST').';port=3306;dbname='.$dbName.';charset=utf8mb4',(string)getenv('SOKNA_HA2B_DB_USER'),(string)getenv('SOKNA_HA2B_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);
 if(stripos((string)$local->query('SELECT VERSION()')->fetchColumn(),'mariadb')===false)throw new RuntimeException('REAL_LOCAL_MARIADB_REQUIRED');
 $local->exec('CREATE TABLE ha2c2b_lab_local (installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,epoch BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
 t('consumer has real distinct local MariaDB and table',$local->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ha2c2b_lab_local'")->fetchColumn()==='InnoDB');
 $install=bin2hex(random_bytes(16));$release=hash('sha256','HA2C2B laboratory release '.bin2hex(random_bytes(12)));
 t('unsigned nonexistent floor denied',blocked($client->read($install,1,$release)));
 $r=$client->advance($install,0,1,$release);t('authorized advance gives signed 1',($r['status']??'')==='MATCHED_ISSUER_LAB_NOT_COMMIT'&&$r['epoch']===1);
 t('signed receipt cannot authorize install',$r['execution_authorized']===false&&$r['for_install']===false&&$r['commit_proven']===false);
 t('read fresh signed floor epoch1',$client->read($install,1,$release)['status']==='MATCHED_ISSUER_LAB_NOT_COMMIT');
 t('reject repeating initial generation',blocked($client->advance($install,0,1,$release)));
 t('reject changed release set',blocked($client->read($install,1,str_repeat('a',64))));
 t('reject request beyond current remote floor',blocked($client->read($install,2,$release)));
 $bad=new RemoteIssuerLabClient('http://issuer:8080',str_repeat('0',64),$pem,$pin);
 t('invalid auth fails closed',blocked($bad->read($install,1,$release)));
 t('wrong pinned public key cannot instantiate',(function()use($token,$pem){try{new RemoteIssuerLabClient('http://issuer:8080',$token,$pem,str_repeat('0',64));return false;}catch(Throwable $e){return true;}})());
 $alt=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$altpub=openssl_pkey_get_details($alt)['key'];
 $badPinClient=new RemoteIssuerLabClient('http://issuer:8080',$token,$altpub,hash('sha256',$altpub));
 t('foreign trusted key rejects true server signature',blocked($badPinClient->read($install,1,$release)));
 $r18=$client->advance($install,1,18,$release);
 t('anchor can increase to 18 via CAS signed readback',($r18['epoch']??0)===18);
 t('reconnect reads authoritative epoch18',$client->read($install,18,$release)['status']==='MATCHED_ISSUER_LAB_NOT_COMMIT');
 t('local rollback epoch17 rejected when remote18',blocked($client->read($install,17,$release)));
 t('duplicate old CAS after unknown outcome denied',blocked($client->advance($install,1,19,$release)));
 $local->prepare('INSERT INTO ha2c2b_lab_local (installation_id,epoch) VALUES (?,?)')->execute([$install,17]);
 $st=$local->prepare('SELECT epoch FROM ha2c2b_lab_local WHERE installation_id=?');$st->execute([$install]);$rolled=(int)$st->fetchColumn();
 t('rollback is represented by actual independently stored local MariaDB epoch17',$rolled===17);
 t('issuer rejects observed local stale database epoch',blocked($client->read($install,$rolled,$release)));
 t('remote floor cannot be decremented',blocked($client->advance($install,18,17,$release)));
 t('malformed installation id blocked',blocked($client->read('not-an-id',18,$release)));
 t('future expected epoch blocked',blocked($client->read($install,19,$release)));
 t('conflicting release cannot silently advance',blocked($client->advance($install,18,19,str_repeat('z',64))));
 t('no production commit API in client',!array_intersect(['commit','activate','rollback','recover'],get_class_methods(RemoteIssuerLabClient::class)));
 // Fail closed on real network errors: port 8081 has no server in this internal test topology.
 $offline=new RemoteIssuerLabClient('http://issuer:8081',$token,$pem,$pin);
 t('issuer outage refuses apparent installed floor',blocked($offline->read($install,18,$release)));
 t('issuer outage refuses advance',blocked($offline->advance($install,18,19,$release)));
 // Nonce exchange: two independently signed readbacks should not be identical.
 $ra=$client->read($install,18,$release);$rb=$client->read($install,18,$release);
 t('two independently fresh reads both validate',$ra['status']==='MATCHED_ISSUER_LAB_NOT_COMMIT'&&$rb['status']==='MATCHED_ISSUER_LAB_NOT_COMMIT');
 t('canonical issuer schema rejects unknown receipt fields',(function(){try{IssuerProtocol::bytes(['extra'=>true]);return false;}catch(Throwable $e){return true;}})());
 t('canonical issuer schema rejects nonce drift',(function(){try{IssuerProtocol::bytes(['installation_id'=>str_repeat('a',32),'epoch'=>1,'revision'=>1,'release_set_sha256'=>str_repeat('b',64),'issued_at'=>100,'expires_at'=>110,'nonce'=>'weak']);return false;}catch(Throwable $e){return true;}})());
 t('temporary issuer never proves operational custody',!isset($ra['installed_release_proven'])&&($ra['commit_proven']??true)===false);
}catch(Throwable $e){t('LAB_EXCEPTION:'.$e->getMessage(),false);}
if(isset($local) && $local instanceof PDO){try{$local->exec('DROP TABLE IF EXISTS ha2c2b_lab_local');}catch(Throwable $e){t('LOCAL_LAB_CLEANUP_FAILED',false);}}
$passed=count(array_filter($tests,fn($x)=>$x['pass']));$total=count($tests);
$result=['stage'=>'F1-HA2C2B1','status'=>($passed===$total&&$total>=20)?'PASS_SPLIT_SIGNER_MARIADB_LAB_ONLY':'BLOCKED_OR_FAILED','passed'=>$passed,'total'=>$total,'tests'=>$tests,'private_signing_key_in_consumer'=>false,'anchor_db_credentials_in_consumer'=>false,'offhost_operational_custody_proven'=>false,'anchor_same_ci_runner'=>true,'install_commit_proven'=>false,'execution_authorized'=>false,'for_install'=>false];
file_put_contents(__DIR__.'/../results/ha2c2b_split_issuer.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo json_encode(['status'=>$result['status'],'passed'=>$passed,'total'=>$total],JSON_PRETTY_PRINT)."\n";exit($result['status']==='PASS_SPLIT_SIGNER_MARIADB_LAB_ONLY'?0:1);
