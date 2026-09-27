<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/local-web/bootstrap.php';
require_once __DIR__.'/../apps/local-web/src/Setup/SetupException.php';
require_once __DIR__.'/../apps/local-web/src/Setup/BrowserSetupService.php';
use Sokna\Local\Setup\BrowserSetupService;
use Sokna\Local\Setup\SetupException;
function g21db_assert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$host=getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1';$port=getenv('SOKNA_TEST_DB_PORT')?:'3306';$name=getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2';$user=getenv('SOKNA_TEST_DB_USER')?:'sokna';$pass=getenv('SOKNA_TEST_DB_PASS')?:'sokna';
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sokna-g21-db-'.bin2hex(random_bytes(5));$data=$tmp.DIRECTORY_SEPARATOR.'data';mkdir($tmp,0700,true);
$service=new BrowserSetupService($tmp,__DIR__.'/../apps/local-web');
try{
  $db=['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass];$probe=$service->testDatabase($db,false);g21db_assert($probe['compatible']===true,'MariaDB compatibility probe failed');
  $result=$service->installNew(['data_dir'=>$data,'db'=>$db,'create_database'=>false,'admin_user'=>'g21admin','admin_password'=>'G21pass!!','cafe_name'=>'G21 Cafe','table_count'=>3,'timezone'=>'Asia/Tehran']);
  g21db_assert(($result['state']??'')==='installed','install did not finish');g21db_assert(is_file($tmp.'/config.php')&&is_file($tmp.'/install.lock'),'config/lock missing');
  $config=require $tmp.'/config.php';$pdo=sokna_local_bootstrap($config)->database();
  g21db_assert((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='g21admin' AND role='admin'")->fetchColumn()===1,'admin missing');
  g21db_assert((int)$pdo->query('SELECT COUNT(*) FROM cafe_tables')->fetchColumn()===3,'initial tables mismatch');
  $iid=(string)$pdo->query("SELECT setting_value FROM settings WHERE setting_key='installation.id'")->fetchColumn();g21db_assert($iid===$config['installation']['id'],'installation identity mismatch');
  g21db_assert(is_file($data.'/secrets/runtime-local.token')&&is_file($data.'/secrets/runtime-health.token')&&is_file($data.'/runtime/runtime-config.json'),'machine files missing');
  @unlink($tmp.'/install.lock');$partial=$service->status();g21db_assert($partial['state']==='partial','partial state after lock loss missing');$resume=$service->resume();g21db_assert(!empty($resume['resumed'])&&$service->status()['installed'],'resume did not restore valid lock');
  try{$service->installNew(['data_dir'=>$data,'db'=>$db,'admin_user'=>'x','admin_password'=>'12345678']);throw new RuntimeException('locked install was accepted');}catch(SetupException $e){g21db_assert($e->errorCode==='setup_locked','unexpected locked error');}
  echo "G2.1 Browser Setup MariaDB self-test: OK\n";
} finally {
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($tmp);
}
