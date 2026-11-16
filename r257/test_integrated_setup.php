<?php
declare(strict_types=1);

$root = (string)getenv('R256_QA_PUBLIC_ROOT');
$dsnPass=(string)getenv('R256_QA_DB_PASSWORD');
if ($root === '' || !is_dir($root) || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('R256_DISPOSABLE_CI_ONLY');
}
if (is_file($root.'/install.lock') || is_file($root.'/config.php')) {
    throw new RuntimeException('R256_MUST_BE_CLEAN_FIXTURE');
}
$_SERVER['HTTPS']='on';
$host=(string)(getenv('R256_QA_HOST')?:'public-tenant-blue.example');
$subdir=(string)(getenv('R256_QA_SUBDIR')?:'/public');
$dbName=(string)(getenv('R256_QA_DB_NAME')?:'sokna_r256_qa');
if ($subdir!=='' && (!str_starts_with($subdir,'/') || str_ends_with($subdir,'/'))) throw new RuntimeException('invalid_qa_subdir');
$_SERVER['HTTP_HOST']=$host;
$_SERVER['DOCUMENT_ROOT']=$root;
$_SERVER['REQUEST_URI']=$subdir.'/setup/';
$_SERVER['SCRIPT_NAME']=$subdir.'/setup/index.php';
require $root.'/bootstrap.php';
$service=new \Sokna\PublicEdge\Setup\PublicSetupService($root);
$checks=0;
function ok256(bool $ok,string $name):void {global $checks;if(!$ok)throw new RuntimeException('R256_FAIL_'.$name);$checks++;echo "PASS_$name\n";}
$before=$service->status();ok256($before['installed']===false,'clean_state');
$pre=$service->preflight($root.'/storage');
ok256($pre['ok']===true,'real_host_setup_preflight');
ok256($pre['base_url']==='https://'.$host.$subdir,'arbitrary_host_and_base_path');
$installed=$service->install([
 'storage_dir'=>$root.'/storage',
 'routing_probe_ok'=>true,
 'db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>$dbName,'user'=>'root','pass'=>$dsnPass],
 'create_database'=>false,
 'emergency_password'=>'isolated-emergency-only-2574',
 'owner_password'=>'isolated-owner-strong-2574',
]);
ok256(($installed['installed']??null)===true,'real_r249_browser_setup_completed');
ok256(($installed['owner_initialized']??null)===true,'owner_created_in_same_setup');
ok256(!array_key_exists('setup_token',$installed)&&!array_key_exists('owner_password',$installed),'setup_secret_not_returned');
$after=$service->status();ok256($after['installed']===true&&$after['paired']===false,'fresh_public_not_locally_paired');
$lock=json_decode((string)file_get_contents($root.'/install.lock'),true,16,JSON_THROW_ON_ERROR);
$id=(string)($lock['public_instance_id']??'');
ok256(str_starts_with($id,'public-')&&strlen($id)>15,'instance_identity_persisted');
$config=require $root.'/config.php';
$private=(string)($config['security']['move_owner_private_dir']??'');
$web=realpath($root);
$pr=realpath($private);
ok256($pr!==false&&$web!==false&&!str_starts_with($pr.'/',rtrim($web,'/').'/'),'owner_key_outside_real_webroot');
$pdo=(new \Sokna\PublicEdge\Core\Database)::connect(\Sokna\PublicEdge\Core\Config::fromArray($config));
$owner=new \Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService($pdo);
ok256($owner->initialized($id)===true,'real_mariadb_owner_record');
ok256((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===10,'eight_migrations_plus_two_forward_only');
$key=(new \Sokna\PublicEdge\Security\PublicMoveInstanceKeyStore($private,$root))->load($id);
ok256(strlen($key)===SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,'protected_signing_key_valid');
$core=sokna_public_bootstrap($config);
$kernel=new \Sokna\PublicEdge\Http\PublicHttpKernel($core,$root);
$headers=['Origin'=>'https://'.$host,'Sec-Fetch-Site'=>'same-origin'];
$base=$subdir.'/api/v1/owner/';
$bad=$kernel->handle('POST',$base.'login',[],json_encode(['password'=>'wrong-password']),$headers);
ok256((int)$bad['status']===401,'wrong_owner_password_denied_through_real_kernel');
$login=$kernel->handle('POST',$base.'login',[],json_encode(['password'=>'isolated-owner-strong-2574']),$headers);
ok256((int)$login['status']===200,'owner_login_routed_via_real_public_kernel');
$cookie=(string)($login['headers']['Set-Cookie']??'');
ok256(str_contains($cookie,'Secure')&&str_contains($cookie,'HttpOnly')&&str_contains($cookie,'SameSite=Strict')&&str_contains($cookie,'Path='.($subdir===''?'/':$subdir.'/')),'scoped_secure_owner_cookie');
ok256(!str_contains((string)$login['body'],'token')&&!str_contains((string)$login['body'],'password'),'owner_token_not_in_response_body');
$headers['Cookie']=explode(';',$cookie)[0];
$now=time();
$challenge=[
 'contract'=>'sokna-public-move-attestation-v1',
 'operation_id'=>'move-disposable-qa-001',
 'local_installation_id'=>'local-disposable-A01',
 'source_public_instance_id'=>'',
 'source_base_url'=>'',
 'target_base_url'=>'https://'.$host.$subdir,
 'nonce'=>bin2hex(random_bytes(24)),
 'issued_at'=>$now,
 'expires_at'=>$now+180,
];
$prepare=$kernel->handle('POST',$base.'consent_prepare',[],json_encode(['challenge'=>$challenge]),$headers);
ok256((int)$prepare['status']===200,'consent_challenge_csrf_issued');
$prj=json_decode((string)$prepare['body'],true,16,JSON_THROW_ON_ERROR);
$csrf=(string)($prj['csrf']??'');
ok256(strlen($csrf)>35,'challenge_specific_csrf');
$badH=$headers;$badH['Origin']='https://attacker.example';
$wrong=$kernel->handle('POST',$base.'consent_sign',[],json_encode(['challenge'=>$challenge,'csrf'=>$csrf]),$badH);
ok256((int)$wrong['status']===403,'cross_origin_owner_consent_denied');
$signed=$kernel->handle('POST',$base.'consent_sign',[],json_encode(['challenge'=>$challenge,'csrf'=>$csrf]),$headers);
ok256((int)$signed['status']===200,'signed_target_owner_attestation_via_real_kernel');
$signedBody=json_decode((string)$signed['body'],true,16,JSON_THROW_ON_ERROR);
$proof=$signedBody['proof']??[];
ok256(($proof['safe_to_cutover']??null)===false&&($proof['owner_grant_issued']??null)===false,'no_cutover_or_local_grant_created');
$e=$proof['attestation']??[];
$valid=is_array($e)&&sodium_crypto_sign_verify_detached(
 base64_decode((string)($e['signature_b64']??''),true),
 json_encode($e['payload'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
 base64_decode((string)($e['payload']['public_key_b64']??''),true)
);
ok256($valid,'target_ed25519_signature_verifies');
$logout=$kernel->handle('POST',$base.'logout',[],'{}',$headers);
ok256((int)$logout['status']===200,'owner_logout_routed');
$denied=$kernel->handle('POST',$base.'consent_prepare',[],json_encode(['challenge'=>$challenge]),$headers);
ok256((int)$denied['status']===403,'logged_out_owner_cannot_approve');
$extra=$kernel->handle('POST',$base.'setup',[],'{}',$headers);
ok256((int)$extra['status']===404,'anonymous_setup_route_not_exposed');
echo "R257_REAL_R249_INTEGRATED_OWNER_SETUP_PASS count=$checks\n";