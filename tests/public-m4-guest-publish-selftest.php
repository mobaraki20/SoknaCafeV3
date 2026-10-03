<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/src/Http/GuestSyncHttpAdapter.php';

use Sokna\PublicEdge\Core\CanonicalJson;
use Sokna\PublicEdge\Http\GuestSyncHttpAdapter;

function public_m4_guest_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function signed_call(GuestSyncHttpAdapter $adapter, object $verifier, string $secret, string $installationId, string $methodName, string $path, array $payload): array
{
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = (string)time();
    $nonce = 'm4-' . bin2hex(random_bytes(10));
    $signature = $verifier->sign($secret, 'POST', $path, $timestamp, $nonce, $raw);
    return $adapter->{$methodName}($installationId, 'POST', $path, $timestamp, $nonce, $signature, $raw);
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) remove_tree($child); else @unlink($child);
    }
    @rmdir($path);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m3');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$installationId = 'm4-guest-installation';
$secret = 'm4-guest-ci-secret';
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sokna-m4-' . bin2hex(random_bytes(6));

$core = sokna_public_bootstrap([
    'db' => [
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'charset' => 'utf8mb4',
        'user' => $user,
        'pass' => $pass,
    ],
    'relay' => [
        'installation_secrets' => [$installationId => $secret],
        'clock_skew_seconds' => 300,
    ],
    'app' => ['storage_dir' => $storage],
]);
$core->migrations()->migrate();
$pdo = $core->database();
$pdo->prepare(
    'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=1,order_intake_enabled=1'
)->execute([$installationId, 'M4 Guest CI', 1, 1, 1]);

$adapter = new GuestSyncHttpAdapter($core);
$verifier = $core->signedLocalRequests();

try {
    $bytes = 'not-a-real-png-but-content-addressed-for-contract-test';
    $sha = hash('sha256', $bytes);
    $mediaPayload = [
        'sha256' => $sha,
        'mime' => 'image/png',
        'extension' => 'png',
        'size' => strlen($bytes),
        'content_base64' => base64_encode($bytes),
    ];
    $media = signed_call($adapter, $verifier, $secret, $installationId, 'media', '/api/v1/local/guest/media.php', $mediaPayload);
    if (($media['status'] ?? 0) !== 201 || (($media['body']['deduplicated'] ?? null) !== false)) {
        public_m4_guest_fail('M4 signed Guest media upload failed.');
    }
    $mediaReplay = signed_call($adapter, $verifier, $secret, $installationId, 'media', '/api/v1/local/guest/media.php', $mediaPayload);
    if (($mediaReplay['status'] ?? 0) !== 200 || (($mediaReplay['body']['deduplicated'] ?? null) !== true)) {
        public_m4_guest_fail('M4 Guest media content-addressed deduplication drifted.');
    }

    $snapshot = [
        'format' => 'sokna-guest-snapshot-v1',
        'items' => [['id' => 'coffee', 'name' => 'Coffee']],
    ];
    $manifest = ['hero' => ['sha256' => $sha, 'extension' => 'png']];
    $contentHash = CanonicalJson::sha256([
        'format' => 'sokna-guest-snapshot-v1',
        'snapshot' => $snapshot,
        'media_manifest' => $manifest,
    ]);
    $revisionId = 'guest-' . substr($contentHash, 0, 32);
    $publishPayload = [
        'revision_id' => $revisionId,
        'content_hash' => $contentHash,
        'generated_at' => gmdate('c'),
        'snapshot' => $snapshot,
        'media_manifest' => $manifest,
    ];
    $publish = signed_call($adapter, $verifier, $secret, $installationId, 'publish', '/api/v1/local/guest/publish.php', $publishPayload);
    if (($publish['status'] ?? 0) !== 200 || (($publish['body']['active'] ?? null) !== true) || (($publish['body']['deduplicated'] ?? null) !== false)) {
        public_m4_guest_fail('M4 immutable Guest publish/activation failed.');
    }
    $publishReplay = signed_call($adapter, $verifier, $secret, $installationId, 'publish', '/api/v1/local/guest/publish.php', $publishPayload);
    if (($publishReplay['status'] ?? 0) !== 200 || (($publishReplay['body']['deduplicated'] ?? null) !== true)) {
        public_m4_guest_fail('M4 Guest publish idempotency drifted.');
    }

    $active = $pdo->prepare('SELECT revision_id FROM guest_active_revisions WHERE installation_id=?');
    $active->execute([$installationId]);
    if ((string)$active->fetchColumn() !== $revisionId) {
        public_m4_guest_fail('M4 active Guest revision pointer did not switch atomically.');
    }

    $availabilityCore = [
        'generated_at' => gmdate('c'),
        'items' => ['coffee' => ['available' => true]],
        'order_acceptance' => ['enabled' => true],
    ];
    $availabilityPayload = ['version' => CanonicalJson::sha256($availabilityCore)] + $availabilityCore;
    $availability = signed_call($adapter, $verifier, $secret, $installationId, 'availability', '/api/v1/local/guest/availability.php', $availabilityPayload);
    if (($availability['status'] ?? 0) !== 200 || ($availability['body']['version'] ?? '') !== $availabilityPayload['version']) {
        public_m4_guest_fail('M4 availability projection sync failed.');
    }

    $stored = $pdo->prepare('SELECT version FROM guest_availability_state WHERE installation_id=?');
    $stored->execute([$installationId]);
    if ((string)$stored->fetchColumn() !== $availabilityPayload['version']) {
        public_m4_guest_fail('M4 availability projection was not persisted independently.');
    }

    $badPublish = $publishPayload;
    $badPublish['content_hash'] = str_repeat('0', 64);
    $bad = signed_call($adapter, $verifier, $secret, $installationId, 'publish', '/api/v1/local/guest/publish.php', $badPublish);
    if (($bad['status'] ?? 0) !== 422 || ($bad['body']['error'] ?? '') !== 'guest_revision_integrity_failed') {
        public_m4_guest_fail('M4 Guest publish integrity failure taxonomy drifted.');
    }
} finally {
    remove_tree($storage);
}

fwrite(STDOUT, "Public M4 Guest publish/media/availability self-test: OK\n");
