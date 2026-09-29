<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/local-web/src/Setup/SetupException.php';
require_once __DIR__.'/../apps/local-web/src/Setup/BrowserSetupService.php';
use Sokna\Local\Setup\BrowserSetupService;
function g21_assert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sokna-g21-pure-'.bin2hex(random_bytes(5));mkdir($tmp,0700,true);
$service=new BrowserSetupService($tmp,__DIR__.'/../apps/local-web');
try{
  $s=$service->status();g21_assert($s['state']==='new'&&!$s['installed'],'new state mismatch');
  g21_assert(isset($s['recommended_data_dir'])&&str_ends_with(str_replace('\\','/',(string)$s['recommended_data_dir']),'/Data'),'recommended data dir missing');
  $pf=$service->preflight($tmp.DIRECTORY_SEPARATOR.'data');g21_assert(isset($pf['checks'])&&count($pf['checks'])>=9,'preflight checks missing');
  $ids=array_column($pf['checks'],'id');foreach(['php_version','ext_pdo','ext_pdo_mysql','ext_mbstring','ext_sodium','migrations_readable','package_root_writable','data_dir_writable'] as $id)g21_assert(in_array($id,$ids,true),'preflight id missing '.$id);
  file_put_contents($tmp.'/config.php',"<?php return ['installation'=>['id'=>'local-test']];\n");
  $s=$service->status();g21_assert($s['state']==='partial'&&$s['resume_available'],'partial state mismatch');
  file_put_contents($tmp.'/install.lock',json_encode(['format'=>'wrong','installation_id'=>'local-test']));
  $s=$service->status();g21_assert($s['state']==='partial'&&!$s['installed'],'invalid lock must not activate app');
  file_put_contents($tmp.'/install.lock',json_encode(['format'=>'sokna-install-lock-v3','installation_id'=>'local-test']));
  $s=$service->status();g21_assert($s['state']==='installed'&&$s['locked'],'valid lock mismatch');
  echo "G2.1 Browser Setup pure self-test: OK\n";
} finally { foreach(glob($tmp.'/*')?:[] as $f)@unlink($f);@rmdir($tmp); }
