<?php
declare(strict_types=1);
require_once __DIR__.'/IssuerProtocol.php';
require_once __DIR__.'/SeparateLabEpochAnchor.php';
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function out(array $p,int $status=200):never {http_response_code($status);echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
 if(PHP_SAPI!=='cli-server'||getenv('SOKNA_HA2B_DISPOSABLE_CONFIRM')!=='YES_DISPOSABLE_NO_PRODUCTION')throw new RuntimeException('LAB_ONLY');
 $keydir=(string)getenv('SOKNA_ISSUER_KEYDIR');if(!str_starts_with($keydir,'/tmp/sokna_lab_issuer_'))throw new RuntimeException('NO_KEYDIR');
 $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
 if($path==='/public' && $_SERVER['REQUEST_METHOD']==='GET')out(['status'=>'LAB_ONLY_UNTRUSTED_BOOTSTRAP','public_key'=>file_get_contents($keydir.'/pub.pem'),'key_id'=>hash('sha256',file_get_contents($keydir.'/pub.pem'))]);
 if($_SERVER['REQUEST_METHOD']!=='POST' || !in_array($path,['/floor','/advance'],true))throw new RuntimeException('UNKNOWN_OPERATION');
 $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??'');$token=(string)getenv('SOKNA_ISSUER_TEST_TOKEN');
 if(strlen($token)<32 || !hash_equals('Bearer '.$token,$authorization))throw new RuntimeException('UNAUTHORIZED');
 $raw=file_get_contents('php://input');if(strlen($raw)>1024)throw new RuntimeException('OVERSIZE');
 $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
 if(!is_array($body)||!isset($body['nonce'],$body['installation_id'])||!is_string($body['nonce'])||!preg_match('/^[a-f0-9]{32}$/D',$body['nonce']))throw new RuntimeException('INVALID_REQUEST');
 $dbName=(string)getenv('SOKNA_HA2C_ANCHOR_DB_NAME');if(!preg_match('/^sokna_ha2_anchor_[a-f0-9]{8,16}$/D',$dbName))throw new RuntimeException('INVALID_LAB_DB');
 $db=new PDO('mysql:host='.(string)getenv('SOKNA_HA2C_ANCHOR_DB_HOST').';port=3306;dbname='.$dbName.';charset=utf8mb4',(string)getenv('SOKNA_HA2C_ANCHOR_DB_USER'),(string)getenv('SOKNA_HA2C_ANCHOR_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_TIMEOUT=>2]);
 $anchor=new SeparateLabEpochAnchor($db);
 if($path==='/advance'){
  if(array_keys($body)!==['installation_id','expected','next','release_set_sha256','nonce'] || !is_int($body['expected']) || !is_int($body['next']) || !is_string($body['release_set_sha256']))throw new RuntimeException('BAD_ADVANCE');
  $row=$anchor->advance($body['installation_id'],$body['expected'],$body['next'],$body['release_set_sha256']);
 }else{if(array_keys($body)!==['installation_id','nonce'])throw new RuntimeException('BAD_READ');$row=$anchor->read($body['installation_id']);}
 $now=time();$payload=['installation_id'=>$row['installation_id'],'epoch'=>$row['epoch'],'revision'=>$row['revision'],'release_set_sha256'=>$row['release_set_sha256'],'issued_at'=>$now,'expires_at'=>$now+25,'nonce'=>$body['nonce']];
 $key=openssl_pkey_get_private('file://'.$keydir.'/secret.pem');if(!$key)throw new RuntimeException('SIGNING_KEY_MISSING');$signature='';if(!openssl_sign(IssuerProtocol::bytes($payload),$signature,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('SIGN_FAILED');
 out(['status'=>'SIGNED_LAB_FLOOR_NOT_COMMIT','payload'=>$payload,'signature'=>base64_encode($signature),'key_id'=>hash('sha256',file_get_contents($keydir.'/pub.pem')),'execution_authorized'=>false,'for_install'=>false]);
}catch(Throwable $e){out(IssuerProtocol::blocked($e->getMessage()),503);}
