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

$expected = ['0001_m2_platform_core', '0002_m5_sellables', '0003_m5_orders', '0004_m5_table_drafts', '0005_m5_preparation', '0006_m5_inventory', '0007_m5_supply', '0008_m5_deferred_receipts', '0009_m5_tax', '0010_m5_expenses'];
$first = $core->migrations()->migrate();
if ($first !== $expected) {
    mysql_migration_fail('First Local migration pass did not apply the expected ordered migration stack: ' . json_encode($first));
}
$second = $core->migrations()->migrate();
if ($second !== []) mysql_migration_fail('Second Local migration pass was not idempotent.');

$pdo = $core->database();
$tables = array_values(array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
foreach (['schema_migrations', 'settings', 'users', 'user_capabilities', 'audit_log', 'menus', 'categories', 'items', 'menu_items', 'cafe_tables', 'table_sessions', 'orders', 'order_items', 'order_business_sequences', 'order_status_history', 'table_drafts', 'table_draft_items', 'user_preparation_areas', 'order_preparation_claims', 'inventory_categories', 'inventory_items', 'inventory_purchase_units', 'inventory_balances', 'inventory_movements', 'inventory_recipe_versions', 'inventory_recipe_components', 'inventory_count_sessions', 'inventory_count_lines', 'inventory_order_events', 'inventory_supply_needs', 'inventory_supply_receipts', 'inventory_supply_receipt_allocations', 'deferred_work_receipts', 'deferred_review_items', 'tax_rate_versions', 'tax_item_policy_versions', 'financial_periods', 'expense_categories', 'expenses'] as $table) {
    if (!in_array($table, $tables, true)) mysql_migration_fail("Expected Local table {$table} is missing after migrate().");
}
foreach (['settlement_records', 'print_jobs'] as $laterDomain) {
    if (in_array($laterDomain, $tables, true)) mysql_migration_fail("M5.2 Local stack leaked later-domain table {$laterDomain}.");
}

$marker = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
foreach ($expected as $version) {
    $marker->execute([$version]);
    if ((int)$marker->fetchColumn() !== 1) mysql_migration_fail("Migration ledger marker {$version} is missing or duplicated.");
}

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

fwrite(STDOUT, "Local MariaDB migration stack self-test: OK\n");
