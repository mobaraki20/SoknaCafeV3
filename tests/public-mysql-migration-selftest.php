<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function public_migration_fail(string $message): never
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
$expectedMigrations = ['0001_m3_public_edge_core', '0002_m4_guest_public_projection', '0003_g3_emergency_update_takeover'];
if ($first !== $expectedMigrations) {
    public_migration_fail('First Public migration pass did not apply the expected M3+M4+G3 migrations: ' . json_encode($first));
}
$second = $core->migrations()->migrate();
if ($second !== []) public_migration_fail('Second Public migration pass was not idempotent.');

$pdo = $core->database();
$tables = array_values(array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)));
$expected = [
    'schema_migrations', 'installations', 'auth_projections', 'public_sessions', 'realtime_requests',
    'request_nonces', 'installation_heartbeats', 'deferred_work', 'auth_login_throttle', 'auth_security_audit',
    'guest_publish_revisions', 'guest_active_revisions', 'guest_availability_state', 'remote_read_models',
    'installation_pairing_secrets', 'installation_takeovers', 'public_emergency_audit',
];
foreach ($expected as $table) {
    if (!in_array($table, $tables, true)) public_migration_fail("Expected Public table {$table} is missing.");
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
    public_migration_fail('Public session token was not persisted only as SHA-256 hash.');
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
    public_migration_fail('Realtime and Deferred stores are not independently durable.');
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
if (!$duplicateRejected) public_migration_fail('Durable nonce replay uniqueness did not reject a duplicate.');

foreach (['auth_login_throttle', 'auth_security_audit'] as $table) {
    $columns = array_map('strtolower', array_map('strval', $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($columns as $column) {
        if (str_contains($column, 'password') || str_contains($column, 'verifier') || $column === 'token' || $column === 'token_hash') {
            public_migration_fail("Secret-bearing column {$column} exists in {$table}.");
        }
    }
}

// M4 schema ownership and integrity checks.
$revisionId = 'guest-' . str_repeat('a', 32);
$contentHash = hash('sha256', 'ci-guest-snapshot');
$pdo->prepare('INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')
    ->execute(['ci-installation', $revisionId, $contentHash, json_encode(['format'=>'sokna-guest-snapshot-v1']), json_encode([])]);

$unknownPointerRejected = false;
try {
    $pdo->prepare('INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())')
        ->execute(['ci-installation', 'guest-' . str_repeat('b', 32)]);
} catch (PDOException) {
    $unknownPointerRejected = true;
}
if (!$unknownPointerRejected) public_migration_fail('M4 active revision pointer accepted a missing immutable revision.');

$pdo->prepare('INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())')
    ->execute(['ci-installation', $revisionId]);

$duplicateContentRejected = false;
try {
    $pdo->prepare('INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')
        ->execute(['ci-installation', 'guest-' . str_repeat('c', 32), $contentHash, json_encode(['format'=>'sokna-guest-snapshot-v1']), json_encode([])]);
} catch (PDOException) {
    $duplicateContentRejected = true;
}
if (!$duplicateContentRejected) public_migration_fail('M4 immutable revision content hash uniqueness was not enforced.');

$availabilityPayload = ['items'=>[], 'order_acceptance'=>['enabled'=>true]];
$pdo->prepare('INSERT INTO guest_availability_state(installation_id,version,payload_json,generated_at,last_sync_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
    ->execute(['ci-installation', hash('sha256', json_encode($availabilityPayload)), json_encode($availabilityPayload)]);
$pdo->prepare('INSERT INTO remote_read_models(installation_id,model_key,source_version,payload_json,generated_at,last_sync_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
    ->execute(['ci-installation', 'operations', hash('sha256', '{}'), json_encode([])]);

foreach ($expectedMigrations as $version) {
    $marker = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $marker->execute([$version]);
    if ((int)$marker->fetchColumn() !== 1) public_migration_fail("Public migration ledger marker {$version} is missing or duplicated.");
}

fwrite(STDOUT, "Public M3+M4+G3 MariaDB migration self-test: OK\n");
