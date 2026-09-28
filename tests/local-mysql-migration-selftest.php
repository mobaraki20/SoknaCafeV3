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

$expected = ['0001_m2_platform_core', '0002_m5_sellables', '0003_m5_orders', '0004_m5_table_drafts', '0005_m5_preparation', '0006_m5_inventory', '0007_m5_supply', '0008_m5_deferred_receipts', '0009_m5_tax', '0010_m5_expenses', '0011_m5_financial_periods', '0012_m5_settlement', '0013_m5_integrations', '0014_m7_runtime', '0015_m8_printing', '0016_g1_guest_waiter', '0017_g1_admin_controls', '0018_g1_global_search', '0019_f1_staff_consumption_foundation', '0020_f1_staff_account', '0021_f1_staff_consumption_reporting', '0022_g3_public_edge_sync', '0023_g4_guest_content_platform', '0024_g4_business_extensions', '0025_g4_print_template_packages'];
$first = $core->migrations()->migrate();
if ($first !== $expected) {
    mysql_migration_fail('First Local migration pass did not apply the expected ordered migration stack: ' . json_encode($first));
}
$second = $core->migrations()->migrate();
if ($second !== []) mysql_migration_fail('Second Local migration pass was not idempotent.');

$pdo = $core->database();
$tables = array_values(array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
foreach (['schema_migrations', 'settings', 'users', 'user_capabilities', 'audit_log', 'menus', 'categories', 'menu_categories', 'items', 'menu_items', 'cafe_tables', 'table_sessions', 'table_session_clients', 'waiter_calls', 'orders', 'order_items', 'order_business_sequences', 'order_status_history', 'table_drafts', 'table_draft_items', 'user_preparation_areas', 'order_preparation_claims', 'inventory_categories', 'inventory_items', 'inventory_purchase_units', 'inventory_balances', 'inventory_movements', 'inventory_recipe_versions', 'inventory_recipe_components', 'inventory_count_sessions', 'inventory_count_lines', 'inventory_order_events', 'inventory_supply_needs', 'inventory_supply_receipts', 'inventory_supply_receipt_allocations', 'deferred_work_receipts', 'deferred_review_items', 'tax_rate_versions', 'tax_item_policy_versions', 'financial_periods', 'expense_categories', 'expenses', 'financial_period_close_overrides', 'settlement_records', 'settlement_record_lines', 'invoice_discount_audit', 'subscribers', 'subscriber_ledger', 'accommodation_transfers', 'personnel', 'staff_benefit_policies', 'staff_benefit_policy_rules', 'staff_benefit_profiles', 'staff_benefit_overrides', 'staff_consumptions', 'staff_consumption_lines', 'staff_account_ledger', 'runtime_trigger_receipts', 'print_agents', 'print_destinations', 'print_jobs', 'print_attempts', 'print_claim_requests', 'print_claim_reconciliations', 'public_sync_state', 'guest_content_config', 'guest_media_assets', 'guest_media_derivatives', 'guest_media_references', 'marketing_campaigns', 'marketing_events', 'notification_preferences', 'notification_push_subscriptions', 'notification_outbox', 'notification_inbox', 'print_template_packages', 'print_template_activations'] as $table) {
    if (!in_array($table, $tables, true)) mysql_migration_fail("Expected Local table {$table} is missing after migrate().");
}
foreach (['center_user_projection_cache', 'center_sync_receipts'] as $retiredTable) {
    if (in_array($retiredTable, $tables, true)) mysql_migration_fail("Retired SOKNA Center table {$retiredTable} exists in the clean Local schema.");
}

$orderColumns = array_map('strval', $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN));
if (!in_array('order_context', $orderColumns, true)) mysql_migration_fail('F1.1 orders.order_context is missing.');
$orderTableNullable = $pdo->query("SHOW COLUMNS FROM orders LIKE 'table_id'")->fetch(PDO::FETCH_ASSOC);
if (!is_array($orderTableNullable) || (string)($orderTableNullable['Null'] ?? '') !== 'YES') mysql_migration_fail('F1.1 orders.table_id is not nullable for non-table context.');

$tableColumns = array_map('strval', $pdo->query('SHOW COLUMNS FROM cafe_tables')->fetchAll(PDO::FETCH_COLUMN));
foreach (['zone_label', 'previous_access_token', 'qr_rotated_at', 'qr_rotated_by_user_id'] as $column) {
    if (!in_array($column, $tableColumns, true)) mysql_migration_fail("G1 admin-controls cafe_tables column {$column} is missing.");
}

$requiredIndexes = [
    'items' => ['idx_g16c_items_active_name'],
    'categories' => ['idx_g16c_categories_active_name'],
    'menus' => ['idx_g16c_menus_name'],
    'users' => ['idx_g16c_users_active_display'],
    'cafe_tables' => ['idx_g12b_tables_zone_active', 'idx_g16c_tables_active_name'],
    'personnel' => ['idx_g12b_personnel_active_name'],
    'orders' => ['idx_f11_orders_context_created'],
    'staff_consumptions' => ['idx_f11_staff_consumption_personnel_date','idx_f11_staff_consumption_recorder_date'],
    'staff_account_ledger' => ['idx_f11_staff_account_personnel','idx_f11_staff_account_period'],
];
foreach ($requiredIndexes as $table => $expectedIndexes) {
    $actual = array_map('strval', $pdo->query('SHOW INDEX FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN, 2));
    foreach ($expectedIndexes as $index) {
        if (!in_array($index, $actual, true)) mysql_migration_fail("Required G1 index {$index} is missing from {$table}.");
    }
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
