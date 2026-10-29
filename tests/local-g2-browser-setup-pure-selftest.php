<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/local-web/src/Setup/SetupException.php';
require_once __DIR__.'/../apps/local-web/src/Setup/BrowserSetupService.php';
use Sokna\Local\Setup\BrowserSetupService;
function g21_assert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sokna-g21-pure-'.bin2hex(random_bytes(5));mkdir($tmp,0700,true);
$web=$tmp.DIRECTORY_SEPARATOR.'Web';mkdir($web,0700,true);
$webVersion=trim((string)file_get_contents(__DIR__.'/../apps/local-web/VERSION.txt'));
file_put_contents($web.DIRECTORY_SEPARATOR.'VERSION.txt',$webVersion."\n");
$service=new BrowserSetupService($tmp,$web);
try{
  $s=$service->status();g21_assert($s['state']==='new'&&!$s['installed'],'new state mismatch');
  $pf=$service->preflight($tmp.DIRECTORY_SEPARATOR.'data');g21_assert(isset($pf['checks'])&&count($pf['checks'])>=9,'preflight checks missing');
  $ids=array_column($pf['checks'],'id');foreach(['php_version','ext_pdo','ext_pdo_mysql','ext_mbstring','ext_sodium','migrations_readable','package_root_writable','data_dir_writable'] as $id)g21_assert(in_array($id,$ids,true),'preflight id missing '.$id);
  file_put_contents($tmp.'/config.php',"<?php return ['installation'=>['id'=>'local-test']];\n");
  $s=$service->status();g21_assert($s['state']==='partial'&&$s['resume_available'],'partial state mismatch');
  file_put_contents($tmp.'/install.lock',json_encode(['format'=>'wrong','installation_id'=>'local-test']));
  $s=$service->status();g21_assert($s['state']==='partial'&&!$s['installed'],'invalid lock must not activate app');
  file_put_contents($tmp.'/install.lock',json_encode(['format'=>'sokna-install-lock-v3','installation_id'=>'local-test']));
  $s=$service->status();
  g21_assert($s['state']==='existing'&&$s['locked']&&!$s['installed'],'valid lock without Web binding was activated');
  $binding=['format'=>'sokna-local-installation-binding-v1','installation_id'=>'another-machine','web_version'=>$webVersion];
  file_put_contents($web.'/.sokna-installation.json',json_encode($binding));
  $s=$service->status();
  g21_assert($s['state']==='existing'&&!$s['installed'],'foreign Web binding was accepted');
  $binding['installation_id']='local-test';
  file_put_contents($web.'/.sokna-installation.json',json_encode($binding));
  $s=$service->status();g21_assert($s['state']==='installed'&&$s['locked']&&$s['binding_current'],'valid lock and Web binding mismatch');
  echo "G2.1 Browser Setup pure self-test: OK\n";
} finally {
  @unlink($web.'/.sokna-installation.json');@unlink($web.'/VERSION.txt');@rmdir($web);
  foreach(glob($tmp.'/*')?:[] as $f)@unlink($f);@rmdir($tmp);
}
