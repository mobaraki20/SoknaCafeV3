<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function m4_isolation_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function m4_isolation_assert(bool $condition, string $message): void
{
    if (!$condition) m4_isolation_fail($message);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m3');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');

$core = sokna_public_bootstrap([
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

$installationId = 'm4-failure-isolation';
$tableToken = 'm4-failure-table';
$revisionId = 'guest-' . str_repeat('a', 32);
$snapshot = [
    'format' => 'sokna-guest-snapshot-v1',
    'cafe_name' => 'Failure Isolation Cafe',
    'features' => ['table_sessions_enabled' => false],
    'tables' => [[
        'id' => 11,
        'name' => 'میز ۱۱',
        'code' => 'T11',
        'token' => $tableToken,
    ]],
    'categories' => [['id' => 1, 'name' => 'منو']],
    'items' => [[
        'id' => 1,
        'category_id' => 1,
        'category_name' => 'منو',
        'name' => 'قهوه',
        'description' => '',
        'price' => 100000,
        'available' => true,
        'image_path' => '',
    ]],
];
$availability = [
    'version' => hash('sha256', 'm4-isolation-availability'),
    'items' => ['1' => ['available' => true]],
    'order_acceptance' => ['cafe' => true, 'kitchen' => true, 'bar' => true],
    'waiter_enabled_table' => true,
    'tables' => [],
];

$pdo->prepare(
    'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=1,order_intake_enabled=1'
)->execute([$installationId, 'M4 Failure Isolation', 1, 1, 1]);
foreach (['realtime_requests','guest_active_revisions','guest_publish_revisions','guest_availability_state','installation_heartbeats'] as $table) {
    $pdo->prepare("DELETE FROM {$table} WHERE installation_id=?")->execute([$installationId]);
}
$pdo->prepare(
    'INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) '
    . 'VALUES(?,?,?,?,?,UTC_TIMESTAMP())'
)->execute([
    $installationId,
    $revisionId,
    hash('sha256', 'm4-isolation-snapshot'),
    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    json_encode([]),
]);
$pdo->prepare('INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())')
    ->execute([$installationId, $revisionId]);
$pdo->prepare(
    'INSERT INTO guest_availability_state(installation_id,version,payload_json,generated_at,last_sync_at) '
    . 'VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
)->execute([
    $installationId,
    $availability['version'],
    json_encode($availability, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$pdo->prepare(
    'INSERT INTO installation_heartbeats(installation_id,local_version,runtime_status,telemetry_json,last_seen_at) '
    . 'VALUES(?,?,?,?,UTC_TIMESTAMP())'
)->execute([$installationId, 'ci', 'healthy', json_encode([])]);

$renderer = $core->guestRenderer();
$compat = $core->guestCompatibility();
$endpoints = [
    'css' => '/assets/scds/guest.css',
    'js' => '/assets/scds/guest.js',
    'create_order' => '/api/v1/guest/compat/create-order',
    'waiter_call' => '/api/v1/guest/compat/waiter-call',
];

// Baseline: published content is available while Local + projection are fresh.
$fresh = $renderer->render($installationId, ['table' => $tableToken], $endpoints);
m4_isolation_assert(($fresh['status'] ?? 0) === 200, 'Fresh Guest surface is unavailable before isolation scenarios.');
m4_isolation_assert(str_contains((string)$fresh['body'], 'data-sg-order-submit'), 'Fresh Guest surface did not expose eligible mutation UI.');

// Local-down: stale heartbeat must retain immutable content but remove mutation capability.
$pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE installation_id=?')
    ->execute([$installationId]);
$localDown = $renderer->render($installationId, ['table' => $tableToken], $endpoints);
$localDownHtml = (string)($localDown['body'] ?? '');
m4_isolation_assert(($localDown['status'] ?? 0) === 200, 'Local-down removed the published Guest read surface.');
m4_isolation_assert(str_contains($localDownHtml, 'قهوه'), 'Local-down lost immutable published Guest content.');
m4_isolation_assert(str_contains($localDownHtml, 'is-degraded'), 'Local-down did not expose degraded state.');
m4_isolation_assert(!str_contains($localDownHtml, 'data-sg-order-submit'), 'Local-down left Guest mutation UI enabled.');
$blockedLocalMutation = $compat->createOrder($installationId, [
    'table_token' => $tableToken,
    'client_token' => 'm4-local-down-client-123456',
    'device_token' => 'm4-local-down-device',
    'items' => [['item_id' => 1, 'quantity' => 1, 'fulfillment_mode' => 'dine_in']],
]);
m4_isolation_assert(
    ($blockedLocalMutation['status'] ?? 0) === 503 && ($blockedLocalMutation['body']['code'] ?? '') === 'local_unavailable',
    'Local-down allowed a Guest mutation to cross the Public boundary.'
);

// Internet/sync-loss representation: Local heartbeat can still be fresh while Public availability
// projection has stopped advancing. Public must remain read-only instead of inventing authority.
$pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=UTC_TIMESTAMP() WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('UPDATE guest_availability_state SET last_sync_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE installation_id=?')
    ->execute([$installationId]);
$syncDown = $renderer->render($installationId, ['table' => $tableToken], $endpoints);
$syncDownHtml = (string)($syncDown['body'] ?? '');
m4_isolation_assert(($syncDown['status'] ?? 0) === 200, 'Internet/sync loss removed the immutable Guest read surface.');
m4_isolation_assert(str_contains($syncDownHtml, 'قهوه'), 'Internet/sync loss lost the last published snapshot.');
m4_isolation_assert(str_contains($syncDownHtml, 'is-degraded'), 'Internet/sync loss did not become explicit degraded state.');
m4_isolation_assert(!str_contains($syncDownHtml, 'data-sg-order-submit'), 'Internet/sync loss left mutation UI enabled from stale projection data.');
$blockedSyncMutation = $compat->createOrder($installationId, [
    'table_token' => $tableToken,
    'client_token' => 'm4-sync-down-client-1234567',
    'device_token' => 'm4-sync-down-device',
    'items' => [['item_id' => 1, 'quantity' => 1, 'fulfillment_mode' => 'dine_in']],
]);
m4_isolation_assert(
    ($blockedSyncMutation['status'] ?? 0) === 503 && ($blockedSyncMutation['body']['code'] ?? '') === 'local_unavailable',
    'Internet/sync loss allowed Public to mutate from stale availability state.'
);

// Remote control disabled: published content is still readable, writes remain disabled.
$pdo->prepare('UPDATE guest_availability_state SET last_sync_at=UTC_TIMESTAMP() WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('UPDATE installations SET remote_enabled=0 WHERE installation_id=?')->execute([$installationId]);
$remoteDisabled = $renderer->render($installationId, ['table' => $tableToken], $endpoints);
m4_isolation_assert(($remoteDisabled['status'] ?? 0) === 200, 'remote_enabled=false removed immutable Guest content.');
m4_isolation_assert(!str_contains((string)$remoteDisabled['body'], 'data-sg-order-submit'), 'remote_enabled=false left Guest mutation UI enabled.');

fwrite(STDOUT, "Public M4 failure-isolation self-test: OK\n");
