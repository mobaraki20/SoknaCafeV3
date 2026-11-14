<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SOKNA_R228_DISPOSABLE_ONLY')!=='YES')throw new RuntimeException('QA_CI_ONLY');
$root=realpath((string)getenv('SOKNA_R228_PUBLIC'));$tmp=realpath((string)getenv('RUNNER_TEMP'));
if(!$root||!$tmp||!str_starts_with($root,$tmp.DIRECTORY_SEPARATOR))throw new RuntimeException('NON_TEMP_PUBLIC');
$_SERVER['HTTP_HOST']='127.0.0.1:18082';$_SERVER['SERVER_NAME']='127.0.0.1';$_SERVER['SERVER_PORT']='18082';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/';
require $root.'/bootstrap.php';
$setup=new \Sokna\PublicEdge\Setup\PublicSetupService($root);
$emergency='r228-qa-only-emergency-pass';
$r=$setup->install(['storage_dir'=>$root.'/storage','routing_probe_ok'=>true,'db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_r228_public_disposable','user'=>'root','pass'=>(string)getenv('SOKNA_R213_DB_PASSWORD')],'create_database'=>true,'emergency_password'=>$emergency]);
if(($r['installed']??false)!==true)throw new RuntimeException('PUBLIC_INSTALL_FAILED');
$installation='r228-ci-paired-installation';$secret=bin2hex(random_bytes(32));
$p=$setup->pair(['pairing_code'=>$r['pairing_code'],'installation_id'=>$installation,'shared_secret'=>$secret,'display_name'=>'R228 QA only']);
if(($p['paired']??false)!==true)throw new RuntimeException('PUBLIC_PAIR_FAILED');
$config=require $root.'/config.php';$core=sokna_public_bootstrap($config);
if((int)$core->database()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==8)throw new RuntimeException('PUBLIC_MIGRATIONS_NOT_EIGHT');
file_put_contents($tmp.'/r228-pairing.json',json_encode(['installation'=>$installation,'secret'=>$secret],JSON_THROW_ON_ERROR),LOCK_EX);
@chmod($tmp.'/r228-pairing.json',0600);
echo "PASS_R228_REAL_PUBLIC_SETUP_AND_PAIR_8_MIGRATIONS\n";