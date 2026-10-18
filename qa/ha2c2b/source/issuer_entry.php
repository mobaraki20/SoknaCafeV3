<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION')exit(3);
$folder='/tmp/sokna_lab_issuer_'.bin2hex(random_bytes(4));if(!mkdir($folder,0700,true))exit(4);
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
if(!$key||!openssl_pkey_export($key,$pem))exit(5);
$pub=openssl_pkey_get_details($key)['key'];
file_put_contents($folder.'/secret.pem',$pem,LOCK_EX);chmod($folder.'/secret.pem',0600);
file_put_contents($folder.'/pub.pem',$pub,LOCK_EX);chmod($folder.'/pub.pem',0644);
putenv('SOKNA_ISSUER_KEYDIR='.$folder);
try {
 $name=(string)getenv('SOKNA_HA2C_ANCHOR_DB_NAME');
 if(!preg_match('/^sokna_ha2_anchor_[a-f0-9]{8,16}$/D',$name))throw new RuntimeException('INVALID_NAME');
 $db=new PDO('mysql:host=anchor;port=3306;dbname='.$name.';charset=utf8mb4',(string)getenv('SOKNA_HA2C_ANCHOR_DB_USER'),(string)getenv('SOKNA_HA2C_ANCHOR_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);
 if(!str_contains(strtolower((string)$db->query('SELECT VERSION()')->fetchColumn()),'mariadb'))throw new RuntimeException('NOT_MARIADB');
 $db->exec('CREATE TABLE IF NOT EXISTS ha2c2_lab_floor (installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, epoch BIGINT UNSIGNED NOT NULL, revision BIGINT UNSIGNED NOT NULL, release_set_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL) ENGINE=InnoDB');
}catch(Throwable $e){fwrite(STDERR,'LAB_ISSUER_BOOT_BLOCKED:'.$e->getMessage()."\n");exit(6);}
$cmd=PHP_BINARY;passthru(escapeshellarg($cmd).' -S 0.0.0.0:8080 '.escapeshellarg(__DIR__.'/issuer_router.php'),$code);
exit($code);
