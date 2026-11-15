<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing verifier path');
use Sokna\PublicEdge\Security\SignedLocalRequestVerifier;
$mode=$argv[2]??'fixed';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',(string)getenv('MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS sokna_r242_probe_qa');
$pdo->exec('CREATE DATABASE sokna_r242_probe_qa');
$pdo->exec('USE sokna_r242_probe_qa');
$pdo->exec('CREATE TABLE installations (installation_id VARCHAR(96) PRIMARY KEY, revoked_at DATETIME NULL,authority_epoch INT NOT NULL, authority_state VARCHAR(40) NOT NULL, authority_epoch_required TINYINT NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE installation_pairing_secrets (installation_id VARCHAR(96) PRIMARY KEY,secret_ciphertext TEXT NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE request_nonces (installation_id VARCHAR(96) NOT NULL,nonce VARCHAR(128) NOT NULL,expires_at DATETIME NOT NULL, PRIMARY KEY(installation_id,nonce)) ENGINE=InnoDB');
$id='local-r242-qa';$secret=bin2hex(random_bytes(32));$key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$encrypted=base64_encode($nonce.sodium_crypto_secretbox($secret,$nonce,$key));
$pdo->prepare("INSERT INTO installations VALUES (?,NULL,3,'resync_required',1)")->execute([$id]);
$pdo->prepare('INSERT INTO installation_pairing_secrets VALUES (?,?)')->execute([$id,$encrypted]);
$v=new SignedLocalRequestVerifier($pdo,[],300,base64_encode($key));
$passed=0;
function ck(string $name,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function check(string $path,int $epoch,?string $override=null,?string $nonce=null):array{global $v,$id,$secret;
 $body=json_encode(['authority_epoch'=>$epoch],JSON_THROW_ON_ERROR);$t=(string)time();$n=$nonce??bin2hex(random_bytes(16));
 $sig=$v->sign($override??$secret,'POST',$path,$t,$n,$body);
 return $v->verify($id,'POST',$path,$t,$n,$body,$sig);
}
$path='/api/v1/local/authority/status';$r=check($path,3);
if($mode==='legacy')ck('before-repro-rejects-safe-status',!($r['ok']??false)&&$r['error']==='authority_resync_required');
else ck('after-accepts-safe-signed-status',$r['ok']&&$r['authority_epoch']===3);
ck('new-secret-wrong-signature-rejected',check($path,3,'wrong-secret')['error']==='bad_signature');
ck('old-epoch-fenced',check($path,2)['error']==='authority_epoch_mismatch');
ck('unsafe-route-stays-fenced',check('/api/v1/local/authority/not-a-safe-route',3)['error']==='authority_resync_required');
$pdo->exec("UPDATE installations SET authority_state='reenroll_pending'");
ck('pending-not-authorized-for-status',check($path,3)['error']==='authority_reenrollment_required');
$pdo->exec("UPDATE installations SET authority_state='resync_required'");
if($mode!=='legacy'){
 $n=bin2hex(random_bytes(16));$first=check($path,3,null,$n);$second=check($path,3,null,$n);
 ck('first-nonce-accepted',$first['ok']===true);
 ck('replay-rejected',$second['error']==='replay_detected');
}
echo json_encode(['test'=>$mode,'passed'=>$passed,'real_mariadb'=>true,'status'=>'pass'],JSON_THROW_ON_ERROR)."\n";