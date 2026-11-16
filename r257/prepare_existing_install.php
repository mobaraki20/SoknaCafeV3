<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true' || getenv('R257_QA_OLD_ROOT')===false ||
   getenv('R257_QA_DB_PASSWORD')===false) throw new RuntimeException('R257_DISPOSABLE_CI_ONLY');
$root=(string)getenv('R257_QA_OLD_ROOT');$dbName='sokna_r257_upgrade_qa';$pass=(string)getenv('R257_QA_DB_PASSWORD');
if(!is_dir($root)||is_file($root.'/config.php')||is_file($root.'/install.lock'))
    throw new RuntimeException('R257_OLD_SOURCE_IS_NOT_CLEAN');
$_SERVER['HTTPS']='on';$_SERVER['HTTP_HOST']='legacy.tenant-peach.example';
$_SERVER['DOCUMENT_ROOT']=$root;$_SERVER['REQUEST_URI']='/legacy/setup/';
$_SERVER['SCRIPT_NAME']='/legacy/setup/index.php';
require $root.'/bootstrap.php';
$setup=new \Sokna\PublicEdge\Setup\PublicSetupService($root);
$installed=$setup->install([
 'storage_dir'=>$root.'/storage','routing_probe_ok'=>true,
 'db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>$dbName,'user'=>'root','pass'=>$pass],
 'create_database'=>false,'emergency_password'=>'upgrade-disposable-emergency-389',
]);
if(($installed['installed']??null)!==true)throw new RuntimeException('OLD_INSTALL_FAILED');
$local='local-existing-peach-001';
$paired=$setup->pair([
 'pairing_code'=>$installed['pairing_code'],
 'installation_id'=>$local,'shared_secret'=>bin2hex(random_bytes(32)),
 'display_name'=>'Independent cafe peach QA only',
]);
if(($paired['paired']??false)!==true)throw new RuntimeException('OLD_PAIRING_FAILED');
$config=require $root.'/config.php';
$pdo=\Sokna\PublicEdge\Core\Database::connect(\Sokna\PublicEdge\Core\Config::fromArray($config));
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==8)
    throw new RuntimeException('R249_OLD_MIGRATION_COUNT_NOT_EIGHT');
$pdo->prepare(
 "INSERT INTO auth_projections(installation_id,projection_id,username,role,password_hash,capabilities_json,active) VALUES (?,?,?,?,?,'[]',1)"
)->execute([$local,'admin-existing-peach','qa-admin-peach','admin',password_hash('qa-password-only',PASSWORD_DEFAULT)]);
$token=(new \Sokna\PublicEdge\Auth\PublicSessionStore($pdo))->issue($local,'admin-existing-peach')['token'];
file_put_contents((string)getenv('RUNNER_TEMP').'/r257-admin-token.txt',$token,LOCK_EX);
$lock=json_decode((string)file_get_contents($root.'/install.lock'),true);
if(!is_array($lock)||!isset($lock['public_instance_id']))throw new RuntimeException('OLD_PUBLIC_INSTANCE_ID_MISSING');
file_put_contents((string)getenv('RUNNER_TEMP').'/r257-old-instance-id.txt',(string)$lock['public_instance_id'],LOCK_EX);
echo "R257_OLD_R249_EIGHT_MIGRATION_PAIRED_PUBLIC_CREATED\n";
