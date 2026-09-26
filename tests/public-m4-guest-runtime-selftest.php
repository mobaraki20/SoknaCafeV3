<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function public_m4_runtime_fail(string $message): never
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
$installationId = 'm4-runtime-installation';
$revisionId = 'guest-' . str_repeat('d', 32);
$contentHash = hash('sha256', 'm4-runtime-content');
$snapshot = ['format' => 'sokna-guest-snapshot-v1', 'items' => [['id' => 'coffee']]];
$availability = ['version' => hash('sha256', 'm4-runtime-availability'), 'items' => ['coffee' => ['available' => true]], 'order_acceptance' => ['enabled' => true]];

$pdo->prepare(
    'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=1,order_intake_enabled=1'
)->execute([$installationId, 'M4 Runtime CI', 1, 1, 1]);
$pdo->prepare('DELETE FROM guest_active_revisions WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('DELETE FROM guest_publish_revisions WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('DELETE FROM guest_availability_state WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('DELETE FROM installation_heartbeats WHERE installation_id=?')->execute([$installationId]);

$pdo->prepare('INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')
    ->execute([$installationId, $revisionId, $contentHash, json_encode($snapshot), json_encode([])]);
$pdo->prepare('INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())')
    ->execute([$installationId, $revisionId]);
$pdo->prepare('INSERT INTO guest_availability_state(installation_id,version,payload_json,generated_at,last_sync_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
    ->execute([$installationId, $availability['version'], json_encode($availability)]);
$pdo->prepare('INSERT INTO installation_heartbeats(installation_id,local_version,runtime_status,telemetry_json,last_seen_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')
    ->execute([$installationId, 'ci', 'healthy', json_encode([])]);

$runtime = $core->guestRuntime();
$bundle = $runtime->bundle($installationId);
if (($bundle['revision_id'] ?? '') !== $revisionId || ($bundle['snapshot']['format'] ?? '') !== 'sokna-guest-snapshot-v1') {
    public_m4_runtime_fail('M4 Guest runtime did not load the immutable active snapshot.');
}
$fresh = $runtime->actionState($bundle, 15);
if (($fresh['enabled'] ?? false) !== true || ($fresh['local_fresh'] ?? false) !== true || ($fresh['availability_fresh'] ?? false) !== true) {
    public_m4_runtime_fail('M4 Guest action state rejected a fresh Local+availability projection.');
}

$pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE installation_id=?')
    ->execute([$installationId]);
$staleLocalBundle = $runtime->bundle($installationId);
$staleLocal = $runtime->actionState($staleLocalBundle, 15);
if (($staleLocalBundle['snapshot']['format'] ?? '') !== 'sokna-guest-snapshot-v1') {
    public_m4_runtime_fail('M4 degraded mode lost read-only Guest content when Local became stale.');
}
if (($staleLocal['enabled'] ?? true) !== false || ($staleLocal['local_fresh'] ?? true) !== false) {
    public_m4_runtime_fail('M4 Guest writes remained enabled while Local heartbeat was stale.');
}

$pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=UTC_TIMESTAMP() WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('UPDATE guest_availability_state SET last_sync_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE installation_id=?')->execute([$installationId]);
$staleAvailabilityBundle = $runtime->bundle($installationId);
$staleAvailability = $runtime->actionState($staleAvailabilityBundle, 15);
if (($staleAvailability['enabled'] ?? true) !== false || ($staleAvailability['availability_fresh'] ?? true) !== false) {
    public_m4_runtime_fail('M4 Guest writes remained enabled while availability projection was stale.');
}
if (($staleAvailabilityBundle['snapshot']['items'][0]['id'] ?? '') !== 'coffee') {
    public_m4_runtime_fail('M4 stale availability incorrectly removed the published read-only snapshot.');
}

$pdo->prepare('UPDATE guest_availability_state SET last_sync_at=UTC_TIMESTAMP() WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('UPDATE installations SET remote_enabled=0 WHERE installation_id=?')->execute([$installationId]);
$remoteDisabled = $runtime->actionState($runtime->bundle($installationId), 15);
if (($remoteDisabled['enabled'] ?? true) !== false) {
    public_m4_runtime_fail('M4 Guest actions ignored installation remote_enabled=false.');
}

$pdo->prepare('UPDATE installations SET active=0 WHERE installation_id=?')->execute([$installationId]);
if ($runtime->bundle($installationId) !== []) {
    public_m4_runtime_fail('Inactive installation still exposed a Guest runtime bundle.');
}

fwrite(STDOUT, "Public M4 Guest runtime degraded-state self-test: OK\n");
