<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function public_m3_fail(string $message): never
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

$first = $core->migrations()->migrate();
if ($first !== ['0001_m3_public_edge_core']) {
    public_m3_fail('First Public M3 migration pass did not apply exactly the expected migration.');
}
$second = $core->migrations()->migrate();
if ($second !== []) public_m3_fail('Second Public M3 migration pass was not idempotent.');

$pdo = $core->database();
$tables = array_values(array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
$expected = [
    'schema_migrations', 'installations', 'auth_projections', 'public_sessions', 'realtime_requests',
    'request_nonces', 'installation_heartbeats', 'deferred_work', 'auth_login_throttle', 'auth_security_audit',
];
foreach ($expected as $table) {
    if (!in_array($table, $tables, true)) public_m3_fail("Expected Public M3 table {$table} is missing.");
}
foreach (['guest_publish_revisions', 'guest_active_revisions', 'guest_availability_state', 'remote_read_models'] as $table) {
    if (in_array($table, $tables, true)) public_m3_fail("M3 incorrectly created M4-owned table {$table}.");
}

$pdo->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute(['ci-installation', 'CI Cafe', 1, 1, 1]);
$passwordHash = password_hash('ci-password', PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO auth_projections(installation_id,projection_id,username,display_name,role,password_hash,capabilities_json,preparation_areas_json,projection_version,active) VALUES(?,?,?,?,?,?,?,?,?,?)')
    ->execute(['ci-installation', 'user:1', 'alice', 'Alice', 'staff', $passwordHash, json_encode(['orders.mutate']), json_encode(['kitchen','bar']), 1, 1]);

$token = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO public_sessions(installation_id,projection_id,token_hash,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))')
    ->execute(['ci-installation', 'user:1', hash('sha256', $token)]);
$storedToken = (string)$pdo->query('SELECT token_hash FROM public_sessions LIMIT 1')->fetchColumn();
if ($storedToken === $token || $storedToken !== hash('sha256', $token)) {
    public_m3_fail('Public session token was not persisted only as SHA-256 hash.');
}

$requestId = 'same-request-id';
$realtimeEnvelope = json_encode(['request_id'=>$requestId,'kind'=>'order.edit','actor_projection_id'=>'user:1','payload'=>[]], JSON_UNESCAPED_SLASHES);
$deferredEnvelope = json_encode(['request_id'=>$requestId,'kind'=>'supply.need.create','actor_projection_id'=>'user:1','payload'=>[]], JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO realtime_requests(installation_id,request_id,request_hash,kind,actor_projection_id,envelope_json,state,expires_at) VALUES(?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))')
    ->execute(['ci-installation', $requestId, hash('sha256', (string)$realtimeEnvelope), 'order.edit', 'user:1', $realtimeEnvelope, 'queued']);
$pdo->prepare('INSERT INTO deferred_work(installation_id,request_id,request_hash,kind,actor_projection_id,envelope_json,state,occurred_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())')
    ->execute(['ci-installation', $requestId, hash('sha256', (string)$deferredEnvelope), 'supply.need.create', 'user:1', $deferredEnvelope, 'pending_sync']);
if ((int)$pdo->query('SELECT COUNT(*) FROM realtime_requests')->fetchColumn() !== 1
    || (int)$pdo->query('SELECT COUNT(*) FROM deferred_work')->fetchColumn() !== 1) {
    public_m3_fail('Realtime and Deferred stores are not independently durable.');
}

$pdo->prepare('INSERT INTO request_nonces(installation_id,nonce,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))')
    ->execute(['ci-installation', 'nonce-1']);
$duplicateRejected = false;
try {
    $pdo->prepare('INSERT INTO request_nonces(installation_id,nonce,expires_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))')
        ->execute(['ci-installation', 'nonce-1']);
} catch (PDOException) {
    $duplicateRejected = true;
}
if (!$duplicateRejected) public_m3_fail('Durable nonce replay uniqueness did not reject a duplicate.');

foreach (['auth_login_throttle', 'auth_security_audit'] as $table) {
    $columns = array_map('strtolower', array_map('strval', $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($columns as $column) {
        if (str_contains($column, 'password') || str_contains($column, 'verifier') || $column === 'token' || $column === 'token_hash') {
            public_m3_fail("Secret-bearing column {$column} exists in {$table}.");
        }
    }
}

$marker = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
$marker->execute(['0001_m3_public_edge_core']);
if ((int)$marker->fetchColumn() !== 1) public_m3_fail('Public migration ledger marker is missing or duplicated.');

fwrite(STDOUT, "Public M3 MariaDB migration self-test: OK\n");
