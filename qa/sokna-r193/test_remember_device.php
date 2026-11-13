<?php
declare(strict_types=1);
require __DIR__ . '/../local/src/Core/RememberDeviceStore.php';
require __DIR__ . '/../local/src/Core/Session.php';
use Sokna\Local\Core\RememberDeviceStore as Store;
function check(bool $condition,string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
$base=sys_get_temp_dir().'/sokna-r191-'.bin2hex(random_bytes(6));
$store=new Store($base);
$local=['HTTP_HOST'=>'127.0.0.1','SERVER_PORT'=>80,'REMOTE_ADDR'=>'127.0.0.1'];
$lan=['HTTP_HOST'=>'192.168.1.10','SERVER_PORT'=>80];
$https=['HTTP_HOST'=>'example.local','HTTPS'=>'on','SERVER_PORT'=>443];
check(Store::allowedOnRequest($local),'HTTP loopback allowed');
check(Store::allowedOnRequest($https),'HTTPS allowed');
check(!Store::allowedOnRequest($lan),'HTTP LAN denied');
check(!Store::allowedOnRequest(['HTTP_HOST'=>'localhost','REMOTE_ADDR'=>'192.168.1.37','SERVER_PORT'=>80]),'Spoofed Host header denied');
try{$store->issue(7,'password-hash-test',$lan,1000);throw new RuntimeException('LAN did not deny');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'not available'),'LAN issuance blocked');}
$token=$store->issue(7,'password-hash-test',$local,1000);
check(strlen($token)===64 && ctype_xdigit($token),'random bearer length');
$record=$store->resolve($token,1001);
check(is_array($record)&&$record['user_id']===7,'resolve valid token');
check(hash_equals($record['password_fingerprint'],hash('sha256','password-hash-test')),'password fingerprint');
check($store->resolve(str_repeat('a',64),1001)===null,'unknown token denied');
check($store->resolve($token,1000+Store::TTL_SECONDS)===null,'expired token denied');
$token2=$store->issue(8,'new-hash',$https,time());
$files=glob($base.'/auth/remembered-devices/*.json')?:[];
check(count($files)===1,'only one active file after expired removal');
check(!str_contains(file_get_contents($files[0]),$token2),'bearer not persisted in file');
$store->revoke($token2);
check($store->resolve($token2)===null,'logout revocation');
check($store->resolve('../invalid')===null,'path traversal denied');
foreach (glob($base.'/auth/remembered-devices/*')?:[] as $f) unlink($f);
@rmdir($base.'/auth/remembered-devices');@rmdir($base.'/auth');@rmdir($base);
echo "PASS R191 remembered-device test suite\n";
