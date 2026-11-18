<?php
declare(strict_types=1);
// CI-only: verify real Public Staff form issues the owner preflight cookie.
if (getenv('GITHUB_ACTIONS') !== 'true') throw new RuntimeException('CI_ONLY');
$basePath = getenv('R273_PUBLIC_BASE_PATH') ?: '';
$base='https://r273-public.test:18443'.$basePath;
$id=(string)getenv('R273_INSTALLATION_ID');
$db=(string)getenv('R273_PUBLIC_DB');$pass=(string)getenv('R273_DB_PASSWORD');
$pdo=new PDO('mysql:host=127.0.0.1;dbname='.$db.';charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$projection='r276-admin-projection';
$pdo->prepare("INSERT INTO auth_projections(installation_id,projection_id,username,display_name,role,password_hash,capabilities_json,active) VALUES(?,?,'qa-r276-manager','QA authorized admin','admin',?,'[\"*\"]',1)")
    ->execute([$id,$projection,password_hash('qa-only-r276-manager-pass',PASSWORD_DEFAULT)]);
function request(string $url,string $method='GET',string $data='',string $cookie='',string $ctype=''):array {
 $head="Origin: https://r273-public.test:18443\r\nSec-Fetch-Site: same-origin\r\n";
 if($ctype!=='') $head.='Content-Type: '.$ctype."\r\n";
 if($cookie!=='') $head.='Cookie: '.$cookie."\r\n";
 $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>$head,'content'=>$data,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>12]]);
 $raw=@file_get_contents($url,false,$ctx);$hs=$http_response_header??[];$status=0;$set='';
 foreach($hs as $h){if(preg_match('~^HTTP/\S+\s+(\d+)~',$h,$m))$status=(int)$m[1];
   if(preg_match('~^Set-Cookie:\s*(sokna_staff=[a-f0-9]{64})(?:;|$)~i',$h,$m))$set=$m[1];}
 return ['status'=>$status,'body'=>is_string($raw)?$raw:'','cookie'=>$set];
}
$get=request($base.'/staff/login');
if($get['status']!==200||!str_contains($get['body'],'name="password"'))throw new RuntimeException('REAL_PUBLIC_STAFF_GET_NOT_READY status='.$get['status']);
$body=http_build_query(['installation_id'=>$id,'username'=>'qa-r276-manager','password'=>'qa-only-r276-manager-pass'],'','&',PHP_QUERY_RFC3986);
$login=request($base.'/staff/login','POST',$body,'','application/x-www-form-urlencoded');
if($login['status']!==303||$login['cookie']==='')throw new RuntimeException('REAL_STAFF_POST_LOGIN_FAILED status='.$login['status']);
$token=substr($login['cookie'],strlen('sokna_staff='));
$check=$pdo->prepare('SELECT COUNT(*) FROM public_sessions WHERE installation_id=? AND projection_id=? AND token_hash=?');
$check->execute([$id,$projection,hash('sha256',$token)]);
if((int)$check->fetchColumn()!==1)throw new RuntimeException('REAL_STAFF_SESSION_NOT_PERSISTED');
$anonymous=request($base.'/api/v1/owner/upgrade_preflight','POST','{}','','application/json');
if($anonymous['status']!==403)throw new RuntimeException('ANONYMOUS_OWNER_PREFLIGHT_NOT_DENIED');
$authorized=request($base.'/api/v1/owner/upgrade_preflight','POST','{}',$login['cookie'],'application/json');
$result=json_decode($authorized['body'],true);
if($authorized['status']!==200||($result['result']['owner_initialized']??null)!==true)
 throw new RuntimeException('STAFF_LOGIN_DID_NOT_AUTHORIZE_OWNER_PREFLIGHT status='.$authorized['status'].' code='.($result['error']??'none'));
echo "R276_REAL_PUBLIC_STAFF_LOGIN_AND_OWNER_PREFLIGHT_PASS\n";
