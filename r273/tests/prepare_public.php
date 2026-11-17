<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true')throw new RuntimeException('CI_ONLY');
$root=(string)getenv('R273_PUBLIC_ROOT');
$pass=(string)getenv('R273_DB_PASSWORD');
$name=(string)getenv('R273_PUBLIC_DB');
$installId=(string)getenv('R273_INSTALLATION_ID');
if(!is_dir($root)||is_file($root.'/install.lock'))throw new RuntimeException('EXACT_FRESH_FIXTURE_REQUIRED');
$_SERVER['HTTPS']='on';$_SERVER['HTTP_HOST']='r273-public.test:18443';$_SERVER['DOCUMENT_ROOT']=$root;
$basePath=getenv('R273_PUBLIC_BASE_PATH');$basePath=$basePath===false?'/new-public':$basePath;if(!in_array($basePath,['','/new-public'],true))throw new RuntimeException('INVALID_QA_PUBLIC_BASE_PATH');$_SERVER['REQUEST_URI']=$basePath.'/setup/';$_SERVER['SCRIPT_NAME']=$basePath.'/setup/index.php';
require $root.'/bootstrap.php';
$setup=new \Sokna\PublicEdge\Setup\PublicSetupService($root);
$installed=$setup->install([
 'storage_dir'=>$root.'/storage','routing_probe_ok'=>true,
 'db'=>['host'=>'127.0.0.1','port'=>'3306','name'=>$name,'user'=>'root','pass'=>$pass],
 'create_database'=>false,
 'emergency_password'=>'test-emergency-only-strong-273',
 'owner_password'=>'test-owner-only-strong-273',
]);
if(($installed['owner_initialized']??null)!==true)throw new RuntimeException('OWNER_NOT_INITIALIZED');
$paired=$setup->pair(['pairing_code'=>$installed['pairing_code'],
 'installation_id'=>$installId,'shared_secret'=>str_repeat('f',64),
 'replacement_mode'=>false,'display_name'=>'Existing café Public']);
if(($paired['paired']??false)!==true ||$setup->status()['paired']!==true)throw new RuntimeException('EXISTING_PAIRED_NOT_CREATED');
$config=require $root.'/config.php';
$pdo=new PDO('mysql:host=127.0.0.1;dbname='.$name.';charset=utf8mb4','root',$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$migrations=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if($migrations!==11)throw new RuntimeException('PUBLIC_NOT_11_MIGRATIONS_'.$migrations);
$q=$pdo->prepare('SELECT authority_epoch,authority_state,remote_enabled,order_intake_enabled FROM installations WHERE installation_id=?');
$q->execute([$installId]);$row=$q->fetch(PDO::FETCH_ASSOC);
if(!is_array($row)||$row['authority_state']!=='active'||(int)$row['remote_enabled']!==1)throw new RuntimeException('BASELINE_OLD_PAIR_NOT_ACTIVE');
echo json_encode(['ok'=>true,'public'=>'35ih','migrations'=>$migrations,'initial_pair_existing'=>true,'installation'=>$installId])."\n";
