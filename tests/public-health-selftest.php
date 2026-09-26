<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/src/Http/HealthHttpAdapter.php';

use Sokna\PublicEdge\Core\SafeErrors;
use Sokna\PublicEdge\Http\HealthHttpAdapter;

function public_health_fail(string $message): never
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
    'db' => ['host'=>$host,'port'=>$port,'name'=>$name,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],
]);
$core->migrations()->migrate();
$adapter = new HealthHttpAdapter($core);
$correlationId = 'health-ci-12345678';
$status = $adapter->status($correlationId);
$body = $status['body'] ?? [];
if ((int)($status['status'] ?? 0) !== 200 || ($body['ok'] ?? false) !== true) public_health_fail('Public health did not report ready state.');
if (($body['component'] ?? '') !== 'public-edge' || ($body['database'] ?? '') !== 'ready') public_health_fail('Public health ownership/database shape drifted.');
if ((int)($body['applied_migrations'] ?? 0) < 1) public_health_fail('Public health did not observe applied schema migration.');
if (($body['correlation_id'] ?? '') !== $correlationId) public_health_fail('Public health did not preserve valid correlation id.');

$secretMessage = 'password=do-not-leak db-host=internal';
$safe = SafeErrors::fromThrowable(new RuntimeException($secretMessage), $correlationId);
$encoded = json_encode($safe, JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) public_health_fail('Safe error encoding failed.');
if (str_contains($encoded, 'password=do-not-leak') || str_contains($encoded, 'db-host=internal') || str_contains($encoded, 'RuntimeException')) {
    public_health_fail('Safe error leaked exception detail.');
}
if ((int)($safe['status'] ?? 0) !== 500 || ($safe['body']['error'] ?? '') !== 'public_internal_error') public_health_fail('Safe error taxonomy drifted.');
if (($safe['body']['correlation_id'] ?? '') !== $correlationId) public_health_fail('Safe error lost correlation id.');

$generated = SafeErrors::correlationId('bad');
if (preg_match('/^[a-f0-9]{32}$/D', $generated) !== 1) public_health_fail('Invalid correlation input did not produce opaque correlation id.');

fwrite(STDOUT, "Public M3 health/safe-error self-test: OK\n");
