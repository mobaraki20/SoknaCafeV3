<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

function mysql_migration_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m2');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sokna-v3-mysql-' . bin2hex(random_bytes(6));

$core = sokna_local_bootstrap([
    'app' => [
        'timezone' => 'Asia/Tehran',
        'data_dir' => $root,
    ],
    'db' => [
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'charset' => 'utf8mb4',
        'user' => $user,
        'pass' => $pass,
    ],
]);

$first = $core->migrations()->migrate();
if ($first !== ['0001_m2_platform_core']) {
    mysql_migration_fail('First M2 migration pass did not apply exactly the expected migration.');
}
$second = $core->migrations()->migrate();
if ($second !== []) mysql_migration_fail('Second M2 migration pass was not idempotent.');

$pdo = $core->database();
$tables = array_values(array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
foreach (['schema_migrations', 'settings', 'users', 'user_capabilities', 'audit_log'] as $table) {
    if (!in_array($table, $tables, true)) mysql_migration_fail("Expected M2 table {$table} is missing after migrate().");
}
if (in_array('user_preparation_areas', $tables, true)) {
    mysql_migration_fail('M2 created user_preparation_areas even though that schema belongs to Orders/Preparation.');
}

$marker = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
$marker->execute(['0001_m2_platform_core']);
if ((int)$marker->fetchColumn() !== 1) mysql_migration_fail('Migration ledger marker is missing or duplicated.');

$passwordHash = password_hash('ci-password', PASSWORD_DEFAULT);
$insertUser = $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');
$insertUser->execute(['ci-operator', $passwordHash, 'CI Operator', 'operator']);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_capabilities(user_id,capability,enabled) VALUES(?,?,1)')
    ->execute([$userId, 'orders_floor']);

$identity = $core->identityRepository()->findActiveById($userId);
if (($identity['username'] ?? '') !== 'ci-operator') mysql_migration_fail('PDO identity authority could not read the migrated users table.');
if ($core->capabilities()->forUser($identity) !== ['orders_floor']) {
    mysql_migration_fail('PDO capability authority could not read the migrated user_capabilities table.');
}

$columns = $pdo->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('actor_display_name_snapshot', array_map('strval', $columns), true)) {
    mysql_migration_fail('Audit compatibility snapshot column is missing from the real migrated schema.');
}

fwrite(STDOUT, "Local MariaDB M2 migration self-test: OK\n");
