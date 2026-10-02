<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/apps/public/bootstrap.php';

use Sokna\PublicEdge\Http\PublicHttpKernel;
use Sokna\PublicEdge\Setup\PublicSetupService;
use Sokna\PublicEdge\Emergency\PublicUpdateException;

function gsetup(bool $ok,string $message): void{if(!$ok){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}}

$host=(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306');
$base=(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2');
$user=(string)(getenv('SOKNA_TEST_DB_USER')?:'sokna');
$pass=(string)(getenv('SOKNA_TEST_DB_PASS')?:'sokna');
$rootUser=(string)(getenv('SOKNA_TEST_DB_ROOT_USER')?:'root');
$rootPass=(string)(getenv('SOKNA_TEST_DB_ROOT_PASS')?:'root');
$db=preg_replace('/[^A-Za-z0-9_]/','_',$base).'_public_setup';
$tick=chr(96);

$admin=new PDO("mysql:host=".$host.";port=".$port.";charset=utf8mb4",$rootUser,$rootPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec("DROP DATABASE IF EXISTS ".$tick.$db.$tick);
$qu=$admin->quote($user);$qp=$admin->quote($pass);
$admin->exec("CREATE USER IF NOT EXISTS ".$qu."@'%' IDENTIFIED BY ".$qp);
$admin->exec("ALTER USER ".$qu."@'%' IDENTIFIED BY ".$qp);
$admin->exec("GRANT ALL PRIVILEGES ON ".$tick.$db.$tick.".* TO ".$qu."@'%'");
$admin->exec("GRANT CREATE ON *.* TO ".$qu."@'%'");
$admin->exec('FLUSH PRIVILEGES');

$temp=sys_get_temp_dir().'/sokna-public-setup-'.bin2hex(random_bytes(5));
mkdir($temp,0700,true);
$configPath=$temp.'/config.php';
$storage=$temp.'/storage';
$component=dirname(__DIR__).'/apps/public';
$setup=new PublicSetupService($component,$configPath);

$status=$setup->status();
gsetup(($status['state']??'')==='fresh'&&($status['installed']??true)===false,'fresh setup state missing');
$pre=$setup->preflight($storage,true,'public.example.test');
gsetup(($pre['ok']??false)===true,'preflight did not pass on supported test environment');

$input=['host'=>$host,'port'=>$port,'name'=>$db,'user'=>$user,'pass'=>$pass];
$installed=$setup->installNew(['storage_dir'=>$storage,'db'=>$input,'create_database'=>true],true,'public.example.test');
gsetup(($installed['success']??false)===true,'browser setup install failed');
gsetup(is_file($configPath),'config.php was not written');
gsetup(is_file($temp.'/install.lock'),'install.lock was not written beside config');
$pairing=(string)($installed['pairing_code']??'');
gsetup(str_starts_with($pairing,'PUB-')&&strlen($pairing)>=40,'one-time pairing code was not generated');

$config=require $configPath;
gsetup(is_array($config),'generated config is not an array');
gsetup(($config['app']['default_installation_id']??null)==='','browser setup should not require manual default installation id');
gsetup(($config['app']['storage_dir']??'')===$storage,'storage path was not persisted');
$key=base64_decode((string)($config['relay']['secret_encryption_key_base64']??''),true);
gsetup(is_string($key)&&strlen($key)===32,'secret encryption key is not 32 random bytes');
gsetup(hash_equals($pairing,(string)($config['relay']['initial_pairing_code']??'')),'pairing code was not persisted for initial handshake');
$resumeClosed=false;
try{$setup->resume(true,'public.example.test');}
catch(\Sokna\PublicEdge\Setup\SetupException $e){$resumeClosed=$e->errorCode==='already_installed';}
gsetup($resumeClosed,'resume could re-expose pairing code after install lock');

$core=sokna_public_bootstrap($config);
$pdo=$core->database();
$applied=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
$catalog=count($core->migrations()->catalog());
gsetup($applied===$catalog&&$catalog>=3,'browser setup did not apply the full migration catalog');
gsetup($core->defaultInstallationId()==='','default installation should be empty before pairing');

$secret='setup-secret-'.bin2hex(random_bytes(32));
$paired=$core->initialPairing()->complete('setup-local-a',$pairing,$secret,'Setup Cafe');
gsetup(($paired['paired']??false)===true,'initial Local/Public pairing failed');
gsetup($core->defaultInstallationId()==='setup-local-a','active paired installation was not resolved dynamically');

$after=$setup->status();
gsetup(($after['installed']??false)===true&&($after['paired']??false)===true,'setup status did not report installed+paired');

$kernel=new PublicHttpKernel($core,$component);
$menu=$kernel->handle('GET','/menu');
gsetup((int)$menu['status']===503,'menu without explicit installation did not resolve the paired installation');
gsetup(str_contains((string)($menu['body']??''),'منوی عمومی هنوز منتشر نشده'),'resolved paired installation did not reach guest renderer');

$closed=false;
try{$core->initialPairing()->complete('setup-local-b',$pairing,'other-'.bin2hex(random_bytes(32)),'Other');}
catch(PublicUpdateException $e){$closed=$e->errorCode==='initial_pairing_closed';}
gsetup($closed,'initial pairing code remained reusable');

$admin->exec("DROP DATABASE IF EXISTS ".$tick.$db.$tick);
fwrite(STDOUT,"Public Browser Setup MariaDB E2E: OK\n");
