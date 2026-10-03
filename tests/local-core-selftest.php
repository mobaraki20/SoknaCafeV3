<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

function fail_test(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sokna-v3-local-core-' . bin2hex(random_bytes(6));
$core = sokna_local_bootstrap([
    'app' => [
        'timezone' => 'Asia/Tehran',
        'data_dir' => $root,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'sokna_test',
        'charset' => 'utf8mb4',
        'user' => 'sokna',
        'pass' => 'not-used-by-this-test',
    ],
]);

if ($core->observability()->dataRoot() !== $root) fail_test('Configured Local data root was not retained.');
if ($core->config()->requiredString('app.timezone') !== 'Asia/Tehran') fail_test('Config lookup failed.');

$correlation = $core->observability()->correlationId('M2-Test-Trace-0001');
if ($correlation !== 'M2-Test-Trace-0001') fail_test('Valid correlation ID was not preserved.');
if ($core->observability()->correlationId() !== $correlation) fail_test('Correlation ID changed inside one Local bootstrap instance.');

$redacted = $core->observability()->redact([
    'username' => 'operator',
    'password' => 'super-secret',
    'nested' => ['access_token' => 'token-value'],
]);
if (($redacted['password'] ?? null) !== '[REDACTED]') fail_test('Password redaction failed.');
if (($redacted['nested']['access_token'] ?? null) !== '[REDACTED]') fail_test('Nested token redaction failed.');

$statePath = $core->observability()->runtimeDir() . DIRECTORY_SEPARATOR . 'm2-selftest.json';
$core->observability()->atomicJsonWrite($statePath, ['ok' => true, 'phase' => 'M2']);
$state = $core->observability()->readJsonFile($statePath);
if (($state['ok'] ?? null) !== true || ($state['phase'] ?? null) !== 'M2') fail_test('Atomic JSON state round-trip failed.');

$core->observability()->logEvent('info', 'm2.local_core_selftest', [
    'password' => 'must-not-appear',
    'component' => 'local',
]);
$logPath = $core->observability()->logDir() . DIRECTORY_SEPARATOR . 'sokna-' . gmdate('Y-m-d') . '.jsonl';
$log = is_file($logPath) ? (string)file_get_contents($logPath) : '';
if ($log === '' || str_contains($log, 'must-not-appear')) fail_test('Structured logging/redaction self-test failed.');
if (!str_contains($log, $correlation)) fail_test('Correlation ID was not propagated into structured log output.');

fwrite(STDOUT, "Local Core M2 self-test: OK\n");
