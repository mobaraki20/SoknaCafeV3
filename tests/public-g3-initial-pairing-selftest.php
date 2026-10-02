<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/apps/public/bootstrap.php';

use Sokna\PublicEdge\Setup\PublicInitialPairingService;
use Sokna\PublicEdge\Setup\PublicSetupService;

function g31p(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL ".$message.PHP_EOL);exit(1);} }
function g31prm(string $path): void {
    if(!file_exists($path))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}
    rmdir($path);
}

$host=(string)(getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1');
$port=(string)(getenv('SOKNA_TEST_DB_PORT')?:'3306');
$base=(string)(getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2');
$rootUser=(string)(getenv('SOKNA_TEST_DB_ROOT_USER')?:'root');
$rootPass=(string)(getenv('SOKNA_TEST_DB_ROOT_PASS')?:'root');
$db=preg_replace('/[^A-Za-z0-9_]/','_',$base).'_public_initial_pair';
$tmp=sys_get_temp_dir().'/sokna-public-initial-pair-'.bin2hex(random_bytes(5));
$storage=$tmp.'/storage';@mkdir($storage,0700,true);$configPath=$tmp.'/config.php';
$root=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$rootUser,$rootPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS '.$db);

$setup=new PublicSetupService(dirname(__DIR__).'/apps/public',$configPath);
$result=$setup->install([
    'db'=>['host'=>$host,'port'=>$port,'name'=>$db,'user'=>$rootUser,'pass'=>$rootPass],
    'create_database'=>true,
    'storage_dir'=>$storage,
    'cookie_secure'=>true,
]);
$code=(string)($result['pairing_code']??'');
g31p(str_starts_with($code,'pub1_')&&strlen($code)>20,'setup did not issue initial pairing code');
g31p(is_file($configPath),'setup did not create config.php');
$config=require $configPath;
g31p((string)($config['app']['default_installation_id']??'')==='','setup prematurely assigned an installation');
g31p(strlen((string)($config['relay']['secret_encryption_key_base64']??''))>20,'setup did not create pairing encryption key');

$core=sokna_public_bootstrap($config);$core->migrations()->migrate();
$pair=new PublicInitialPairingService($core->database(),$core->pairingSecrets(),$storage,$configPath);
$secret=bin2hex(random_bytes(32));
$done=$pair->complete('local-uat-1',$code,$secret,'SOKNA UAT');
g31p(($done['ok']??false)===true,'initial pairing did not complete');
g31p($core->pairingSecrets()->secret('local-uat-1')===$secret,'initial pairing secret did not round-trip');
$saved=require $configPath;
g31p((string)($saved['app']['default_installation_id']??'')==='local-uat-1','Public config did not persist default installation');
$row=$core->database()->query("SELECT active,remote_enabled,order_intake_enabled FROM installations WHERE installation_id='local-uat-1'")->fetch(PDO::FETCH_ASSOC);
g31p(is_array($row)&&(int)$row['active']===1&&(int)$row['remote_enabled']===1&&(int)$row['order_intake_enabled']===1,'initial installation is not active');
try{$pair->complete('other-installation',$code,bin2hex(random_bytes(32)),'Other');g31p(false,'one-time pairing code was reusable');}
catch(Throwable $e){g31p(in_array($e->getMessage(),['already_paired','pairing_consumed'],true),'unexpected reuse rejection: '.$e->getMessage());}

$root->exec('DROP DATABASE IF EXISTS '.$db);g31prm($tmp);
fwrite(STDOUT,"G3.1 Public initial setup/pairing MariaDB self-test: OK\n");
