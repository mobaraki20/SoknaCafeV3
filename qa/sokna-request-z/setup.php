<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('CI only');
$tmp=realpath(getenv('RUNNER_TEMP'));$root=$tmp.'/public';$sub=getenv('SOKNA_Z_MODE')==='subfolder'?'/public/menu':'';
$_SERVER['HTTP_HOST']='127.0.0.1:18082';$_SERVER['SERVER_NAME']='127.0.0.1';$_SERVER['SERVER_PORT']='18082';$_SERVER['SCRIPT_NAME']=$sub.'/setup/index.php';$_SERVER['REQUEST_URI']=$sub.'/setup/';$_SERVER['DOCUMENT_ROOT']=$root;
require $root.'/bootstrap.php';
$setup=new \Sokna\PublicEdge\Setup\PublicSetupService($root);
$r=$setup->install(['storage_dir'=>$tmp.'/z-private-public','routing_probe_ok'=>true,'db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_ci_public_z','user'=>'root','pass'=>getenv('SOKNA_CI_DB_PASS')],'create_database'=>true,'owner_password'=>'QA-only-owner-disposable-2026','emergency_password'=>'QA-only-emergency-disposable-2026']);
if(!($r['installed']??false))throw new RuntimeException('setup failed');
$lc=require $tmp.'/config.php';$id=$lc['installation']['id'];$secret=bin2hex(random_bytes(32));
$r=$setup->pair(['pairing_code'=>$r['pairing_code'],'installation_id'=>$id,'shared_secret'=>$secret,'display_name'=>'QA Z']);
if(!($r['paired']??false))throw new RuntimeException('pair failed');
$c=require $root.'/config.php';if($c['app']['base_path']!==$sub)throw new RuntimeException('wrong base path');
$core=sokna_public_bootstrap($c);if((int)$core->database()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==11)throw new RuntimeException('public migration count');
file_put_contents($tmp.'/z-pair.json',json_encode(['id'=>$id,'secret'=>$secret,'sub'=>$sub,'base'=>'http://127.0.0.1:18082'.$sub],JSON_THROW_ON_ERROR),LOCK_EX);chmod($tmp.'/z-pair.json',0600);
echo "PASS Public real setup and pairing 11 migrations\n";
