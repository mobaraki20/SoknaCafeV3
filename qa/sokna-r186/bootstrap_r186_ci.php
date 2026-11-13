<?php
declare(strict_types=1);
// Actual Local BrowserSetupService on byte-pinned R153 Web tree, CI disposable Windows only.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_OS') !== 'Windows' || getenv('SOKNA_R186_CI_ONLY') !== 'YES') {
    throw new RuntimeException('R186_WINDOWS_DISPOSABLE_SETUP_ONLY');
}
[$_, $old, $packageRoot, $dbPort] = array_pad($argv,4,'');
if (!is_dir($old) || !is_dir($packageRoot) || !$dbPort || !is_file($packageRoot.'/.u01-ci-only')) throw new RuntimeException('R186_FIXTURE_ROOT_INVALID');
require_once $old.'/bootstrap.php';
require_once $old.'/src/Setup/BrowserSetupService.php';
$input = ['db'=>['host'=>'127.0.0.1','port'=>$dbPort,'name'=>'sokna_r186_exact','user'=>'root','pass'=>''],
    'create_database'=>true,'data_dir'=>$packageRoot.'/Data','timezone'=>'Asia/Tehran','admin_user'=>'ci.admin',
    'admin_password'=>'R186-Disposable-Only','cafe_name'=>'Sokna CI Only','table_count'=>3];
$setup=new \Sokna\Local\Setup\BrowserSetupService($packageRoot,$old);
$preflight=$setup->preflight($packageRoot.'/Data');
if (($preflight['ok']??false)!==true) {
    $failed=array_map(fn($v)=>['name'=>$v['key']??$v['id']??$v['name']??'unknown','reason'=>$v['actual']??$v['detail']??'failed'],array_values(array_filter((array)($preflight['checks']??[]),fn($v)=>(($v['ok']??null)===false))));
    throw new RuntimeException('R186_SETUP_PREFLIGHT_FAILED_'.json_encode($failed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
$result=$setup->installNew($input);
$status=$setup->status();
if (($status['installed']??false)!==true || ($status['binding_current']??false)!==true) throw new RuntimeException('R186_BROWSER_SETUP_DID_NOT_BIND_INSTALLED_WEB');
$cfg=$packageRoot.'/config.php';$lock=$packageRoot.'/install.lock';
if (!is_file($cfg)||!is_file($lock)) throw new RuntimeException('R186_SETUP_CONFIG_LOCK_ABSENT');
$pdo = new PDO('mysql:host=127.0.0.1;port='.$dbPort.';dbname=sokna_r186_exact;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$count=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if ($count!==80) throw new RuntimeException('R186_EXPECTED_80_MIGRATIONS_ACTUAL_'.$count);
echo "PASS_R186_ACTUAL_R153_BROWSER_SETUP_80_MIGRATIONS_CI\n";
