<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/src/Http/AuthHttpAdapter.php';

use Sokna\PublicEdge\Http\AuthHttpAdapter;

function public_http_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m3');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$installationId = 'http-installation';
$secret = 'http-ci-secret';

$core = sokna_public_bootstrap([
    'db' => ['host'=>$host,'port'=>$port,'name'=>$name,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],
    'relay' => ['installation_secrets' => [$installationId => $secret], 'clock_skew_seconds' => 300],
    'auth' => ['failure_limit'=>3,'failure_window_seconds'=>300,'block_seconds'=>120,'session_ttl_seconds'=>3600],
]);
$core->migrations()->migrate();
$core->database()->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute([$installationId, 'HTTP CI', 1, 1, 1]);
$adapter = new AuthHttpAdapter($core);

$path = '/api/v1/local/projection_sync.php';
$timestamp = (string)time();
$nonce = 'http-sync-' . bin2hex(random_bytes(5));
$passwordHash = password_hash('correct-password', PASSWORD_DEFAULT);
$body = json_encode(['projections' => [[
    'projection_id' => 'user:20',
    'username' => 'alice',
    'display_name' => 'Alice HTTP',
    'role' => 'staff',
    'password_hash' => $passwordHash,
    'capabilities' => ['orders.mutate'],
    'preparation_areas' => ['kitchen'],
    'projection_version' => 1,
    'active' => true,
]]], JSON_UNESCAPED_SLASHES);
if (!is_string($body)) public_http_fail('Projection test body encoding failed.');
$signature = $core->signedLocalRequests()->sign($secret, 'POST', $path, $timestamp, $nonce, $body);

$sync = $adapter->projectionSync($installationId, 'POST', $path, $timestamp, $nonce, $signature, $body);
if ((int)($sync['status'] ?? 0) !== 200 || ($sync['body']['ok'] ?? false) !== true || (int)($sync['body']['synced'] ?? 0) !== 1) {
    public_http_fail('Signed projection sync HTTP adapter failed.');
}
$replay = $adapter->projectionSync($installationId, 'POST', $path, $timestamp, $nonce, $signature, $body);
if ((int)($replay['status'] ?? 0) !== 409 || ($replay['body']['error'] ?? '') !== 'replay_detected') {
    public_http_fail('Projection sync adapter did not surface durable replay taxonomy.');
}

$badJson = $adapter->login('{', '203.0.113.5', 'corr-http-json');
if ((int)($badJson['status'] ?? 0) !== 400 || ($badJson['body']['error'] ?? '') !== 'invalid_json') {
    public_http_fail('Login HTTP adapter invalid_json taxonomy drifted.');
}

$loginBody = json_encode(['installation_id'=>$installationId,'username'=>'alice','password'=>'correct-password'], JSON_UNESCAPED_SLASHES);
$login = $adapter->login((string)$loginBody, '203.0.113.5', 'corr-http-login');
if ((int)($login['status'] ?? 0) !== 200 || ($login['body']['ok'] ?? false) !== true || ($login['body']['projection_id'] ?? '') !== 'user:20') {
    public_http_fail('Login HTTP adapter failed to preserve successful remote-login behavior.');
}
if (!is_string($login['body']['token'] ?? null) || ($login['body']['token'] ?? '') === '') public_http_fail('Login HTTP adapter did not return opaque session token.');

fwrite(STDOUT, "Public M3 auth HTTP adapter self-test: OK\n");

require __DIR__ . '/public-connectivity-selftest.php';
