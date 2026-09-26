<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/src/Security/SignedLocalRequestVerifier.php';

use Sokna\PublicEdge\Security\SignedLocalRequestVerifier;

function public_signed_fail(string $message): never
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
$installationId = 'signed-installation';
$secret = 'signed-ci-secret';
$pdo->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute([$installationId, 'Signed CI', 1, 1, 1]);

$verifier = new SignedLocalRequestVerifier($pdo, [$installationId => $secret], 300);
$vectorBase = $verifier->signatureBase('POST', '/api/v1/local/claim.php', '1760000000', 'nonce-123', '{"limit":10}');
$expectedBase = "sokna-relay-v1\nPOST\n/api/v1/local/claim.php\n1760000000\nnonce-123\nca502dec04523cdc33afece69a9b600d5b9bd022d453791cc693b6b372f808ad";
if ($vectorBase !== $expectedBase) public_signed_fail('Signed Local request base drifted from M1 vector.');
$vectorSignature = $verifier->sign('test-secret', 'POST', '/api/v1/local/claim.php', '1760000000', 'nonce-123', '{"limit":10}');
if ($vectorSignature !== '6233349d136481c04836c5a860576fdfcec768696245006c62882265d6093bf5') {
    public_signed_fail('Signed Local HMAC vector drifted from M1 contract.');
}

$unknown = $verifier->verify('missing-installation', 'POST', '/api/v1/local/projection_sync.php', (string)time(), 'nonce-u', '{}', 'bad');
if (($unknown['status'] ?? 0) !== 401 || ($unknown['error'] ?? '') !== 'unknown_installation') {
    public_signed_fail('Unknown installation taxonomy drifted.');
}

$timestamp = (string)time();
$path = '/api/v1/local/projection_sync.php';
$body = '{"projections":[]}';
$bad = $verifier->verify($installationId, 'POST', $path, $timestamp, 'nonce-bad', $body, 'deadbeef');
if (($bad['status'] ?? 0) !== 401 || ($bad['error'] ?? '') !== 'bad_signature') {
    public_signed_fail('Bad signature taxonomy drifted.');
}

$nonce = 'nonce-valid-' . bin2hex(random_bytes(6));
$signature = $verifier->sign($secret, 'POST', $path, $timestamp, $nonce, $body);
$valid = $verifier->verify($installationId, 'POST', $path, $timestamp, $nonce, $body, $signature);
if (($valid['ok'] ?? false) !== true || ($valid['installation_id'] ?? '') !== $installationId) {
    public_signed_fail('Valid signed Local request was rejected.');
}
$replay = $verifier->verify($installationId, 'POST', $path, $timestamp, $nonce, $body, $signature);
if (($replay['status'] ?? 0) !== 409 || ($replay['error'] ?? '') !== 'replay_detected') {
    public_signed_fail('Duplicate nonce was not classified as replay_detected.');
}

$oldTimestamp = (string)(time() - 1000);
$oldNonce = 'nonce-old-' . bin2hex(random_bytes(4));
$oldSignature = $verifier->sign($secret, 'POST', $path, $oldTimestamp, $oldNonce, $body);
$stale = $verifier->verify($installationId, 'POST', $path, $oldTimestamp, $oldNonce, $body, $oldSignature);
if (($stale['status'] ?? 0) !== 401 || ($stale['error'] ?? '') !== 'bad_signature') {
    public_signed_fail('Stale timestamp taxonomy drifted.');
}

fwrite(STDOUT, "Public M3 signed Local request self-test: OK\n");
