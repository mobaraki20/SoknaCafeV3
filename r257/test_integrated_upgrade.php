<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('R256_QA_PUBLIC_ROOT')===false||
   getenv('R257_QA_DB_PASSWORD')===false)throw new RuntimeException('R257_QA_ONLY');
$root=(string)getenv('R256_QA_PUBLIC_ROOT');
if(!is_file($root.'/config.php')||!is_file($root.'/install.lock'))
    throw new RuntimeException('R257_MUST_UPGRADE_EXISTING_NOT_NEW_INSTALL');
$_SERVER['HTTPS']='on';$_SERVER['HTTP_HOST']='legacy.tenant-peach.example';
$_SERVER['DOCUMENT_ROOT']=$root;
$_SERVER['REQUEST_URI']='/legacy/owner/upgrade';
$_SERVER['SCRIPT_NAME']='/legacy/index.php';
require $root.'/bootstrap.php';
$config=require $root.'/config.php';
if(isset($config['security']['move_owner_private_dir'])||isset($config['security']['move_owner_csrf_key_base64']))
    throw new RuntimeException('R257_NOT_LEGACY_CONFIG');
$originalId=(string)file_get_contents((string)getenv('RUNNER_TEMP').'/r257-old-instance-id.txt');
$lock=json_decode((string)file_get_contents($root.'/install.lock'),true);
if(($lock['public_instance_id']??null)!==$originalId)
    throw new RuntimeException('R257_PUBLIC_ID_CHANGED_BY_UPGRADE');
$pdo=\Sokna\PublicEdge\Core\Database::connect(\Sokna\PublicEdge\Core\Config::fromArray($config));
$migrations=(new \Sokna\PublicEdge\Core\Migrations($pdo,$root.'/database/migrations'))->migrate();
if(count($migrations)!==2 || (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==10)
    throw new RuntimeException('R257_TWO_FORWARD_MIGRATIONS_EXPECTED');
$checks=0;
function checkU(bool $ok,string $desc):void{
 global $checks;
 if(!$ok)throw new RuntimeException('R257_UPGRADE_FAILED '.$desc);
 $checks++;echo "PASS ".$desc."\n";
}
checkU(($pdo->query("SELECT COUNT(*) FROM installations")->fetchColumn())==1,
    'existing_local_installation_row_preserved');
checkU($config['app']['default_installation_id']==='local-existing-peach-001',
    'preexisting_pairing_binding_retained');
$before=$pdo->query("SELECT authority_epoch,order_intake_enabled FROM installations LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$core=sokna_public_bootstrap($config);
$kernel=new \Sokna\PublicEdge\Http\PublicHttpKernel($core,$root);
$staffToken=trim((string)file_get_contents((string)getenv('RUNNER_TEMP').'/r257-admin-token.txt'));
$h=['Origin'=>'https://legacy.tenant-peach.example','Sec-Fetch-Site'=>'same-origin','Cookie'=>'sokna_staff='.$staffToken];
$base='/legacy/api/v1/owner/';
$badH=$h;$badH['Origin']='https://attacker.example';
$bad=$kernel->handle('POST',$base.'upgrade_preflight',[],'{}',$badH);
checkU((int)$bad['status']===403,'cross_site_upgrade_preflight_rejected');
$p=$kernel->handle('POST',$base.'upgrade_preflight',[],'{}',$h);
checkU((int)$p['status']===200,'existing_admin_upgrade_preflight_succeeded');
$result=json_decode((string)$p['body'],true,32,JSON_THROW_ON_ERROR);
$csrf=(string)($result['result']['csrf']??'');
checkU(strlen($csrf)>35,'trusted_staff_receives_bound_csrf');
$invalid=$kernel->handle('POST',$base.'upgrade_activate',[],json_encode([
    'csrf'=>'bad','new_owner_password'=>'separate-site-owner-8828',
]),$h);
checkU((int)$invalid['status']===403,'bad_upgrade_csrf_no_owner_created');
$good=$kernel->handle('POST',$base.'upgrade_activate',[],json_encode([
    'csrf'=>$csrf,'new_owner_password'=>'separate-site-owner-8828',
]),$h);
checkU((int)$good['status']===200,'existing_Public_owner_activated_without_install');
$owner=new \Sokna\PublicEdge\Security\PublicMoveOwnerBootstrapService($pdo);
checkU($owner->initialized($originalId),'site_owner_binds_original_public_instance_id');
checkU((require $root.'/config.php')===$config,'old_config_unchanged');
$again=$kernel->handle('POST',$base.'upgrade_activate',[],json_encode([
    'csrf'=>$csrf,'new_owner_password'=>'new-secret-must-not-overwrite',
]),$h);
checkU((int)$again['status']===403,'second_owner_cannot_overwrite');
$login=$kernel->handle('POST',$base.'login',[],json_encode(['password'=>'separate-site-owner-8828']),$h);
checkU((int)$login['status']===200,'owner_login_supported_with_legacy_config');
$cookie=(string)($login['headers']['Set-Cookie']??'');
checkU(str_contains($cookie,'HttpOnly')&&str_contains($cookie,'Secure')&&str_contains($cookie,'Path=/legacy/'),'secure_legacy_path_owner_cookie');
$h['Cookie']=explode(';',$cookie)[0];
$now=time();
$challenge=[
 'contract'=>'sokna-public-move-attestation-v1',
 'operation_id'=>'move-existing-peach-001',
 'local_installation_id'=>'local-existing-peach-001',
 'source_public_instance_id'=>'',
 'source_base_url'=>'',
 'target_base_url'=>'https://legacy.tenant-peach.example/legacy',
 'nonce'=>bin2hex(random_bytes(24)),
 'issued_at'=>$now,'expires_at'=>$now+180,
];
$prepared=$kernel->handle('POST',$base.'consent_prepare',[],json_encode(['challenge'=>$challenge]),$h);
checkU((int)$prepared['status']===200,'owner_authenticated_consent_prepare');
$csrf2=json_decode((string)$prepared['body'],true,32,JSON_THROW_ON_ERROR)['csrf']??'';
$signed=$kernel->handle('POST',$base.'consent_sign',[],json_encode(['challenge'=>$challenge,'csrf'=>$csrf2]),$h);
checkU((int)$signed['status']===200,'existing_public_signed_receipt_returned');
$body=json_decode((string)$signed['body'],true,32,JSON_THROW_ON_ERROR);
$receipt=$body['receipt_envelope']??[];
checkU(is_array($receipt)&&isset($receipt['receipt']['approval_receipt_id']),'durable_owner_receipt_in_response');
checkU((int)$pdo->query("SELECT COUNT(*) FROM public_move_owner_receipts")->fetchColumn()===1,
    'consent_receipt_written_to_database_once');
checkU(($body['safe_to_cutover']??null)===false,'owner_receipt_does_not_cutover');
$after=$pdo->query("SELECT authority_epoch,order_intake_enabled FROM installations LIMIT 1")->fetch(PDO::FETCH_ASSOC);
checkU($before===$after,'order_intake_and_epoch_not_changed');
checkU((int)$pdo->query("SELECT COUNT(*) FROM installation_pairing_secrets")->fetchColumn()===1,
    'legacy_pairing_secret_preserved');
echo "R257_EXISTING_R249_PUBLIC_REAL_UPGRADE_PASS count=$checks\n";
