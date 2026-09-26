<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/src/Http/ConnectivityHttpAdapter.php';

use Sokna\PublicEdge\Http\ConnectivityHttpAdapter;

function public_connectivity_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m3');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$installationId = 'heartbeat-installation';
$secret = 'heartbeat-ci-secret';

$core = sokna_public_bootstrap([
    'db' => ['host'=>$host,'port'=>$port,'name'=>$name,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],
    'relay' => ['installation_secrets'=>[$installationId=>$secret],'clock_skew_seconds'=>300],
]);
$core->migrations()->migrate();
$core->database()->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute([$installationId, 'Heartbeat CI', 1, 1, 1]);
$adapter = new ConnectivityHttpAdapter($core);

$before = $adapter->status($installationId, 45);
if ((int)($before['status'] ?? 0) !== 200 || ($before['body']['known'] ?? false) !== true || ($before['body']['local_fresh'] ?? true) !== false) {
    public_connectivity_fail('Known installation without heartbeat did not report stale connectivity.');
}

$path = '/api/v1/local/heartbeat.php';
$timestamp = (string)time();
$nonce = 'heartbeat-' . bin2hex(random_bytes(5));
$body = json_encode([
    'local_version' => '3.0.0-m3',
    'runtime_status' => 'healthy',
    'telemetry' => ['queue_depth'=>2,'source'=>'ci'],
], JSON_UNESCAPED_SLASHES);
if (!is_string($body)) public_connectivity_fail('Heartbeat body encoding failed.');
$signature = $core->signedLocalRequests()->sign($secret, 'POST', $path, $timestamp, $nonce, $body);
$result = $adapter->heartbeat($installationId, 'POST', $path, $timestamp, $nonce, $signature, $body);
if ((int)($result['status'] ?? 0) !== 200 || ($result['body']['ok'] ?? false) !== true) {
    public_connectivity_fail('Signed heartbeat was not accepted.');
}

$status = $adapter->status($installationId, 45);
$payload = $status['body'] ?? [];
if ((int)($status['status'] ?? 0) !== 200 || ($payload['local_fresh'] ?? false) !== true) public_connectivity_fail('Fresh heartbeat did not mark Local connectivity fresh.');
if (($payload['local_version'] ?? '') !== '3.0.0-m3' || ($payload['runtime_status'] ?? '') !== 'healthy') public_connectivity_fail('Heartbeat metadata drifted.');
if (($payload['telemetry']['queue_depth'] ?? null) !== 2) public_connectivity_fail('Heartbeat telemetry round-trip failed.');

$replay = $adapter->heartbeat($installationId, 'POST', $path, $timestamp, $nonce, $signature, $body);
if ((int)($replay['status'] ?? 0) !== 409 || ($replay['body']['error'] ?? '') !== 'replay_detected') {
    public_connectivity_fail('Heartbeat replay was not rejected by shared signed-request guard.');
}

$unknown = $adapter->status('missing-installation', 45);
if ((int)($unknown['status'] ?? 0) !== 404 || ($unknown['body']['known'] ?? true) !== false) {
    public_connectivity_fail('Unknown installation connectivity status taxonomy drifted.');
}

$core->database()->prepare("UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 120 SECOND) WHERE installation_id=?")
    ->execute([$installationId]);
$stale = $adapter->status($installationId, 45);
if (($stale['body']['local_fresh'] ?? true) !== false) public_connectivity_fail('Stale heartbeat remained fresh beyond freshness window.');

fwrite(STDOUT, "Public M3 connectivity/heartbeat self-test: OK\n");
