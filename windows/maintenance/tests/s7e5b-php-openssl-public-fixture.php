<?php
// F1-S7E5B — CI-only PHP/OpenSSL (DER) peer to independent .NET verifier.
// This IS NOT the canonical Public Maintenance Kernel or a production API.
// Private signing key is generated IN MEMORY, never written, uploaded or logged.
declare(strict_types=1);
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_OS') !== 'Linux' || $argc !== 2) {
    throw new RuntimeException('S7E5B_DISPOSABLE_LINUX_RUNNER_ONLY');
}
$root = realpath((string) getenv('RUNNER_TEMP'));
$folder = realpath($argv[1]);
if (!$root || !$folder || dirname($folder) !== $root
    || !str_starts_with(basename($folder), 'sokna-public-php-der-')) {
    throw new RuntimeException('S7E5B_FOREIGN_ROOT');
}
$private = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1'
]);
if (!$private) throw new RuntimeException('S7E5B_OPENSSL_P256_UNAVAILABLE');
$details = openssl_pkey_get_details($private);
if (!is_array($details) || ($details['bits'] ?? 0) !== 256 ||
    !isset($details['key'])) throw new RuntimeException('S7E5B_BAD_SIGNER');
$now = 621355968000000000 + (time() * 10000000);
$entry = [
    'format' => 'sokna-public-kernel-observation-v1',
    'key_id' => 'ci-php-open-ssl-p256',
    'nonce' => bin2hex(random_bytes(16)),
    'request_id' => bin2hex(random_bytes(16)),
    'issued_utc_ticks' => $now,
    'expires_utc_ticks' => $now + 300000000,
    'installation_id' => 'ci-public-installation-001',
    'authority_epoch' => 8,
    'install_generation' => 4,
    'active_release_set_id' => 'public-ci-release-004',
    'release_sequence' => 29,
    'active_tree_sha256' => str_repeat('a',64),
    'schema_fingerprint' => str_repeat('c',64),
    'protocol_version' => '2.0',
    'kernel_health' => 'healthy',
    'app_health' => 'unavailable',
    'lifecycle_state' => 'recovery',
    'stage_signed_verified' => false,
    'exact_base_verified' => false,
    'mutation_fenced' => true,
    'execution_authorized' => false,
    'signature' => ''
];
// Versioned protocol is EXACT ordered JSON encoding with domain separation.
// All protocol string values in this portable fixture are ASCII.
$json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$bytes = "SOKNA-PUBLIC-KERNEL-READONLY-CHALLENGE-V1\n" . $json;
if (!openssl_sign($bytes, $signature, $private, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('S7E5B_OPENSSL_SIGN_FAILED');
}
if (strlen($signature) < 68 || strlen($signature) > 72 ||
    openssl_verify($bytes, $signature, $details['key'], OPENSSL_ALGO_SHA256) !== 1) {
    throw new RuntimeException('S7E5B_OPENSSL_DER_SELF_VERIFY_FAILED');
}
$entry['signature'] = base64_encode($signature);
file_put_contents($folder . '/receipt.json',
    json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
file_put_contents($folder . '/public.pem', $details['key']);
file_put_contents($folder . '/signed-bytes.bin', $bytes);
if (count(glob($folder . '/*private*')) > 0) {
    throw new RuntimeException('S7E5B_PRIVATE_MATERIAL_WRITTEN');
}
echo json_encode([
    'format' => 'sokna-f1-s7e5b-php-openssl-der-producer-v1',
    'result' => 'PASS',
    'private_key_exported' => false,
    'der_signature' => true,
    'fixture_not_real_public' => true
], JSON_THROW_ON_ERROR), PHP_EOL;
