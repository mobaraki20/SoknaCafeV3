<?php
declare(strict_types=1);
// QA only: create an independent scratch Local install using the real application setup.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R213_DISPOSABLE_ONLY') !== 'YES') {
    throw new RuntimeException('REFUSE_NON_CI_ENVIRONMENT');
}
$web = realpath((string)getenv('SOKNA_R213_WEB'));
$tmp = realpath((string)getenv('RUNNER_TEMP'));
if ($web === false || $tmp === false || !str_starts_with($web, $tmp . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('REFUSE_NON_TEMP_WEB');
}
$root = dirname($web);
if (is_file($root.'/config.php') || is_file($root.'/install.lock')) {
    throw new RuntimeException('REFUSE_EXISTING_INSTALL');
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:18080';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '18080';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/setup/index.php';
$_SERVER['SCRIPT_FILENAME'] = $web.'/public/setup/index.php';
$_SERVER['DOCUMENT_ROOT'] = $web.'/public';
require $web.'/bootstrap.php';
$setup = new \Sokna\Local\Setup\BrowserSetupService($root, $web);
$result = $setup->installNew([
    'data_dir' => $root.'/Data',
    'db' => ['host'=>'127.0.0.1','port'=>'3306','name'=>'sokna_r213_isolated','user'=>'root','pass'=>(string)getenv('SOKNA_R213_DB_PASSWORD')],
    'create_database' => true,
    'admin_user' => 'qa_r213_admin',
    'admin_password' => (string)getenv('SOKNA_R213_ADMIN_PASSWORD'),
    'cafe_name' => 'SOKNA isolated R213 QA',
    'table_count' => 1,
]);
if (($result['state']??'')!=='installed'||$setup->status()['state']!=='installed') {
    throw new RuntimeException('SETUP_NOT_INSTALLED');
}
$cfg=require $root.'/config.php';
$pdo=\Sokna\Local\Core\Database::connect(\Sokna\Local\Core\Config::fromArray($cfg));
if ((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()!==81) throw new RuntimeException('WRONG_MIGRATIONS');
echo "PASS_R213_REAL_BROWSER_SETUP_81_MIGRATIONS\n";
