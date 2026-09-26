<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Domain\Sellables\SellableKind;

function m5_sellable_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function m5_sellable_assert(bool $condition, string $message): void
{
    if (!$condition) m5_sellable_fail($message);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m2');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sokna-v3-m5-sellables-' . bin2hex(random_bytes(4));

$core = sokna_local_bootstrap([
    'app' => ['timezone' => 'Asia/Tehran', 'data_dir' => $root],
    'db' => [
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'charset' => 'utf8mb4',
        'user' => $user,
        'pass' => $pass,
    ],
]);
$core->migrations()->migrate();
$pdo = $core->database();

$tables = array_values(array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
foreach (['menus','categories','menu_categories','items','menu_items'] as $table) {
    m5_sellable_assert(in_array($table, $tables, true), "M5.1 sellable/catalog table {$table} is missing.");
}
foreach (['print_jobs'] as $laterDomain) {
    m5_sellable_assert(!in_array($laterDomain, $tables, true), "Current Local stack leaked post-M5.2 domain table {$laterDomain}.");
}

m5_sellable_assert(SellableKind::normalizeRead(null) === SellableKind::MENU_ITEM, 'Legacy-safe missing kind did not normalize to menu_item.');
m5_sellable_assert(SellableKind::normalizeRead('not-a-kind') === SellableKind::MENU_ITEM, 'Legacy-safe invalid read did not normalize to menu_item.');
m5_sellable_assert(SellableKind::requireWrite('service_item') === SellableKind::SERVICE_ITEM, 'service_item strict write validation failed.');
$invalidRejected = false;
try {
    SellableKind::requireWrite('food-service-by-name');
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
m5_sellable_assert($invalidRejected, 'Invalid write-time sellable kind was guessed instead of rejected.');

$pdo->exec("INSERT INTO categories(category_key,name,sort_order,active) VALUES('drinks','نوشیدنی',10,1)");
$categoryId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('main','منوی اصلی','active',10)");
$menuId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,10)')->execute([$menuId, $categoryId]);

$repo = $core->sellables();
$normalId = $repo->create([
    'category_id' => $categoryId,
    'name' => 'قهوه بیرون‌بر',
    'price' => 150000,
    'staff_only' => true,
    'takeaway_allowed' => true,
    'preparation_station' => 'service',
    'sellable_kind' => 'menu_item',
]);
$serviceId = $repo->create([
    'item_code' => 'SERVICE-TAKEAWAY',
    'category_id' => $categoryId,
    'name' => 'سرویس بیرون‌بر',
    'price' => 0,
    'staff_only' => true,
    'takeaway_allowed' => true,
    'preparation_station' => 'none',
    'sellable_kind' => 'service_item',
]);
$pdo->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?),(?,?)')
    ->execute([$menuId, $normalId, $menuId, $serviceId]);

$normal = $repo->find($normalId);
$service = $repo->find($serviceId);
m5_sellable_assert(is_array($normal) && $normal['sellable_kind'] === 'menu_item', 'Category/station/name/staff-only properties incorrectly inferred service_item.');
m5_sellable_assert(is_array($service) && $service['sellable_kind'] === 'service_item', 'Explicit service item classification was not preserved.');
m5_sellable_assert((int)$service['staff_only'] === 1 && (string)$service['preparation_station'] === 'none', 'Independent service properties were rewritten by classification.');

$repo->changeKind($normalId, 'service_item');
$changed = $repo->find($normalId);
m5_sellable_assert(($changed['sellable_kind'] ?? '') === 'service_item', 'Explicit sellable kind update did not persist.');
m5_sellable_assert((string)($changed['preparation_station'] ?? '') === 'service', 'Changing sellable kind mutated preparation station.');
m5_sellable_assert((int)($changed['staff_only'] ?? 0) === 1, 'Changing sellable kind mutated staff_only.');

$dbRejected = false;
try {
    $pdo->prepare('UPDATE items SET sellable_kind=? WHERE id=?')->execute(['inferred_service', $serviceId]);
} catch (PDOException) {
    $dbRejected = true;
}
m5_sellable_assert($dbRejected, 'Database constraint accepted a sellable kind outside menu_item/service_item.');

$catalog = $repo->catalogRows();
m5_sellable_assert(count($catalog) === 2, 'Canonical sellable catalog did not return the expected active rows.');
foreach ($catalog as $row) {
    m5_sellable_assert(in_array($row['sellable_kind'] ?? '', ['menu_item','service_item'], true), 'Catalog exposed a non-canonical sellable kind.');
    m5_sellable_assert(isset($row['sellable_kind_label']), 'Catalog omitted explicit sellable kind label.');
}

$migrationSql = (string)file_get_contents(dirname(__DIR__) . '/apps/local-web/database/migrations/0002_m5_sellables.sql');
m5_sellable_assert(!preg_match('/CREATE TABLE IF NOT EXISTS\s+(orders|order_items|table_drafts|inventory|financial)/i', $migrationSql), 'M5.1 migration contains a later-domain table owner.');
m5_sellable_assert(!preg_match('/category[^\n;]*(service_item)|preparation_station[^\n;]*(service_item)/i', $migrationSql), 'M5.1 migration infers service classification from category/station.');
m5_sellable_assert(!str_contains($migrationSql, 'UPDATE order_items'), 'M5.1 migration rewrites historical order lines.');

fwrite(STDOUT, "Local M5.1 explicit Sellables self-test: OK\n");
