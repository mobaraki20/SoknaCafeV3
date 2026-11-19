<?php
declare(strict_types=1);
// Disposable GitHub Actions Windows test only. No production or live Cafe root.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_OS') !== 'Windows' || getenv('SOKNA_R186_CI_ONLY') !== 'YES') {
    throw new RuntimeException('R300_R299_BASELINE_DISPOSABLE_WINDOWS_ONLY');
}
[$script, $old, $root, $port] = array_pad($argv, 4, '');
if (!is_dir($old) || !is_dir($root) || !$port || !is_file($root.'/.u01-ci-only')) {
    throw new RuntimeException('R300_R299_BASELINE_REFUSE_UNTRUSTED_ROOT');
}
require_once $old.'/bootstrap.php';
require_once $old.'/src/Setup/BrowserSetupService.php';
$svc = new \Sokna\Local\Setup\BrowserSetupService($root,$old);
$preflight=$svc->preflight($root.'/Data');
if (($preflight['ok']??false)!==true) {
    throw new RuntimeException('R300_R299_BASELINE_BASE_SETUP_PREFLIGHT '.json_encode($preflight,JSON_UNESCAPED_UNICODE));
}
$input = ['db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>'sokna_r186_exact','user'=>'root','pass'=>''],
    'create_database'=>true, 'data_dir'=>$root.'/Data', 'timezone'=>'Asia/Tehran',
    'admin_user'=>'ci.admin', 'admin_password'=>'R300_R299_BASELINE-Disposable-Only-2026',
    'cafe_name'=>'QA only', 'table_count'=>3];
$svc->installNew($input);
$s=$svc->status();
if (($s['installed']??false)!==true || ($s['binding_current']??false)!==true)
    throw new RuntimeException('R300_R299_BASELINE_231FL_BROWSER_INSTALL_NOT_BOUND');
$db=new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=sokna_r186_exact;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$n=(int)$db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
if ($n!==83) throw new RuntimeException('R300_R299_BASELINE_EXPECTED_83_MIGRATIONS_ACTUAL_'.$n);
echo "R300_R299_BASELINE_NATIVE_WINDOWS_EXACT_R275_INSTALL_83_MIGRATIONS_PASS\n";
