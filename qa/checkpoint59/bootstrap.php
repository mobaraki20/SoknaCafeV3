<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('RUNNER_OS')!=='Windows'||getenv('SOKNA_R186_CI_ONLY')!=='YES')throw new RuntimeException('DISPOSABLE_WINDOWS_ONLY');
[$_, $old,$root,$port]=array_pad($argv,4,'');
if(!is_file($root.'/.u01-ci-only')||!is_dir($old)||!ctype_digit($port))throw new RuntimeException('FIXTURE_ROOT_INVALID');
require_once $old.'/bootstrap.php';
require_once $old.'/src/Setup/BrowserSetupService.php';
$setup=new \Sokna\Local\Setup\BrowserSetupService($root,$old);
$pre=$setup->preflight($root.'/Data');
if(empty($pre['ok']))throw new RuntimeException('BASELINE_PREFLIGHT_FAILED '.json_encode($pre['checks']??[],JSON_UNESCAPED_UNICODE));
$setup->installNew(['db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>'sokna_r186_exact','user'=>'root','pass'=>''],'create_database'=>true,'data_dir'=>$root.'/Data','timezone'=>'Asia/Tehran','admin_user'=>'ci.admin','admin_password'=>'Disposable-QA-only-20261126','cafe_name'=>'Sokna CI Only','table_count'=>3]);
$st=$setup->status();if(empty($st['installed'])||empty($st['binding_current']))throw new RuntimeException('BASELINE_BINDING_FAILED');
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=sokna_r186_exact;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==(int)getenv('SOKNA_BASE_LEDGER'))throw new RuntimeException('BASELINE_LEDGER_FAILED');
echo "PASS EXACT BASELINE INSTALL WITHOUT SOURCE INSTRUMENTATION\n";
