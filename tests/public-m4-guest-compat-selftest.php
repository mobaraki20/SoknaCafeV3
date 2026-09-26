<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function public_m4_guest_compat_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
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

$installationId = 'm4-guest-compat-installation';
$tableToken = 'm4-guest-table-token';
$clientToken = 'client-token-1234567890';
$revisionId = 'guest-' . str_repeat('e', 32);
$contentHash = hash('sha256', 'm4-guest-compat-content');
$snapshot = [
    'format' => 'sokna-guest-snapshot-v1',
    'tables' => [[
        'id' => 7,
        'name' => 'Table 7',
        'code' => 'T7',
        'token' => $tableToken,
        'public_ref' => 'public-table-7',
    ]],
    'features' => ['table_sessions_enabled' => true],
];
$availability = [
    'version' => hash('sha256', 'm4-guest-compat-availability'),
    'order_acceptance' => ['cafe' => true, 'kitchen' => true, 'bar' => true],
    'order_acceptance_messages' => [],
    'station_states' => [],
    'station_state_hash' => hash('sha256', 'stations'),
    'waiter_enabled_table' => true,
    'waiter_enabled_public' => true,
    'tables' => [],
];

$pdo->prepare(
    'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=1,order_intake_enabled=1'
)->execute([$installationId, 'M4 Guest Compat CI', 1, 1, 1]);

foreach ([
    'realtime_requests',
    'guest_active_revisions',
    'guest_publish_revisions',
    'guest_availability_state',
    'installation_heartbeats',
] as $table) {
    $pdo->prepare("DELETE FROM {$table} WHERE installation_id=?")->execute([$installationId]);
}

$pdo->prepare(
    'INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) '
    . 'VALUES(?,?,?,?,?,UTC_TIMESTAMP())'
)->execute([
    $installationId,
    $revisionId,
    $contentHash,
    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    json_encode([]),
]);
$pdo->prepare(
    'INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())'
)->execute([$installationId, $revisionId]);
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

$payload = [
    'table_token' => $tableToken,
    'session_token' => '',
    'device_token' => '',
    'client_token' => $clientToken,
    'customer_note' => '',
    'items' => [],
];
$requestId = \Sokna\PublicEdge\Guest\GuestCompatibilityService::requestId(
    'guest_order.submit',
    ['client_token' => $clientToken, 'table_token' => $tableToken],
);
$actorProjectionId = 'guest:' . substr(hash('sha256', $installationId . '|' . $clientToken), 0, 32);
$firstEnvelope = [
    'request_id' => $requestId,
    'kind' => 'guest_order.submit',
    'created_at' => gmdate('c', time() - 10),
    'expires_at' => gmdate('c', time() + 35),
    'actor_projection_id' => $actorProjectionId,
    'payload' => $payload,
];

$first = $core->realtime()->enqueueGuest($installationId, $firstEnvelope);
if (($first['status'] ?? 0) !== 202 || ($first['body']['state'] ?? '') !== 'queued') {
    public_m4_guest_compat_fail('M4 Guest compatibility could not enqueue on the existing Realtime queue.');
}
$claim = $core->realtime()->claim($installationId, 20);
if (($claim['status'] ?? 0) !== 200 || ($claim['body']['request']['request_id'] ?? '') !== $requestId) {
    public_m4_guest_compat_fail('M4 Guest compatibility request was not claimable through the M3 Realtime consumer.');
}
$ack = $core->realtime()->ack($installationId, [
    'request_id' => $requestId,
    'lease_token' => (string)$claim['body']['lease_token'],
    'state' => 'committed',
    'result' => ['success' => true, 'order_code' => 'ORD-M4-COMPAT'],
]);
if (($ack['status'] ?? 0) !== 200) {
    public_m4_guest_compat_fail('M4 Guest compatibility request could not be completed through the M3 Realtime ack path.');
}

$compat = $core->guestCompatibility();
$retry = $compat->createOrder($installationId, [
    'table_token' => $tableToken,
    'client_token' => $clientToken,
    'items' => [],
]);
if (($retry['status'] ?? 0) !== 200 || ($retry['body']['order_code'] ?? '') !== 'ORD-M4-COMPAT') {
    public_m4_guest_compat_fail('Stable Guest retry did not deduplicate by logical kind+actor+payload semantics.');
}

$quote = $core->realtime()->enqueue([
    'installation_id' => $installationId,
    'projection_id' => 'projection-quote',
    'capabilities' => ['guest.order.submit'],
], [
    'request_id' => 'm4-guest-quote-' . bin2hex(random_bytes(4)),
    'kind' => 'guest_order.quote',
    'created_at' => gmdate('c'),
    'expires_at' => gmdate('c', time() + 30),
    'payload' => ['quote_mode' => 'create', 'table_token' => $tableToken],
]);
if (($quote['status'] ?? 0) !== 202) {
    public_m4_guest_compat_fail('guest_order.quote is still missing from the Realtime kind/capability contract.');
}

$forbiddenGuestKind = $core->realtime()->enqueueGuest($installationId, [
    'request_id' => 'm4-guest-forbidden-' . bin2hex(random_bytes(4)),
    'kind' => 'settlement.commit',
    'created_at' => gmdate('c'),
    'expires_at' => gmdate('c', time() + 30),
    'actor_projection_id' => 'guest:' . str_repeat('a', 32),
    'payload' => [],
]);
if (($forbiddenGuestKind['status'] ?? 0) !== 403 || ($forbiddenGuestKind['body']['error'] ?? '') !== 'forbidden') {
    public_m4_guest_compat_fail('Guest compatibility was able to enqueue a non-Guest Realtime mutation kind.');
}

$pdo->prepare(
    'UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE installation_id=?'
)->execute([$installationId]);
$degraded = $compat->tableContext($installationId, [
    'table_token' => $tableToken,
    'device_token' => 'device-ci',
]);
if (($degraded['status'] ?? 0) !== 200
    || ($degraded['body']['degraded'] ?? false) !== true
    || ($degraded['body']['can_order'] ?? true) !== false
    || ($degraded['body']['table']['id'] ?? 0) !== 7) {
    public_m4_guest_compat_fail('Guest table context did not preserve read-only degraded fallback while Local was stale.');
}

$metric = $compat->metric();
if (($metric['status'] ?? 0) !== 200 || ($metric['body']['success'] ?? false) !== true) {
    public_m4_guest_compat_fail('Guest metric compatibility no-op behavior regressed.');
}

$http = new \Sokna\PublicEdge\Http\GuestCompatibilityHttpAdapter($core);
$invalidJson = $http->metric($installationId, '{invalid');
if (($invalidJson['status'] ?? 0) !== 400 || ($invalidJson['body']['code'] ?? '') !== 'invalid_json') {
    public_m4_guest_compat_fail('Guest compatibility HTTP adapter did not reject invalid JSON.');
}

fwrite(STDOUT, "Public M4 Guest compatibility/Realtime reuse self-test: OK\n");
