<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

use Sokna\PublicEdge\Core\CanonicalJson;

function public_m4_remote_fail(string $message): never
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
$installationId = 'm4-remote-read-installation';

$pdo->prepare(
    'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=1,order_intake_enabled=1'
)->execute([$installationId, 'M4 Remote Read CI', 1, 1, 1]);
$pdo->prepare('DELETE FROM remote_read_models WHERE installation_id=?')->execute([$installationId]);
$pdo->prepare('DELETE FROM installation_heartbeats WHERE installation_id=?')->execute([$installationId]);
$core->connectivity()->heartbeat($installationId, 'ci', 'healthy', []);

$preparationPayload = [
    'tasks' => [
        ['id' => 't-kitchen', 'area' => 'kitchen'],
        ['id' => 't-bar', 'area' => 'bar'],
    ],
    'adjustments' => [
        ['id' => 'a-kitchen', 'area_key' => 'kitchen'],
        ['id' => 'a-bar', 'area_key' => 'bar'],
    ],
];
$deferredPayload = [
    'supply_groups' => [['id' => 'sg-1']],
    'inventory_items' => [['id' => 'i-1']],
    'count_drafts' => [['id' => 'c-1']],
    'subscribers' => [['id' => 's-1']],
    'expense_categories' => [['id' => 'e-1']],
];
$reportsPayload = ['summary' => ['orders' => 12]];

$generatedAt = gmdate(DATE_ATOM);
$models = [];
foreach ([
    'preparation' => $preparationPayload,
    'deferred_context' => $deferredPayload,
    'reports' => $reportsPayload,
] as $modelKey => $payload) {
    $models[] = [
        'format' => 'sokna-remote-read-v1',
        'model_key' => $modelKey,
        'source_version' => CanonicalJson::sha256($payload),
        'generated_at' => $generatedAt,
        'payload' => $payload,
    ];
}

$service = $core->remoteReadModels();
$first = $service->sync($installationId, ['models' => $models]);
if (($first['status'] ?? 0) !== 200 || ($first['body']['synced'] ?? -1) !== 3 || ($first['body']['unchanged'] ?? -1) !== 0) {
    public_m4_remote_fail('M4 Remote Read Model initial sync did not persist all valid models.');
}
$replay = $service->sync($installationId, ['models' => $models]);
if (($replay['body']['synced'] ?? -1) !== 0 || ($replay['body']['unchanged'] ?? -1) !== 3) {
    public_m4_remote_fail('M4 Remote Read Model replay did not preserve idempotent source-version semantics.');
}

$baseSession = [
    'installation_id' => $installationId,
    'projection_id' => 'projection-kitchen',
    'capabilities' => ['preparation.read'],
    'preparation_areas' => ['kitchen'],
];
$prepared = $service->read($baseSession, 'preparation');
$tasks = $prepared['body']['payload']['tasks'] ?? [];
$adjustments = $prepared['body']['payload']['adjustments'] ?? [];
if (($prepared['status'] ?? 0) !== 200
    || count($tasks) !== 1
    || ($tasks[0]['id'] ?? '') !== 't-kitchen'
    || count($adjustments) !== 1
    || ($adjustments[0]['id'] ?? '') !== 'a-kitchen') {
    public_m4_remote_fail('M4 preparation read did not enforce preparation-area scope.');
}

$monitorSession = $baseSession;
$monitorSession['capabilities'] = ['preparation.monitor'];
$monitor = $service->read($monitorSession, 'preparation');
if (count($monitor['body']['payload']['tasks'] ?? []) !== 2) {
    public_m4_remote_fail('M4 preparation.monitor did not retain full preparation read scope.');
}

$forbidden = $service->read([
    'installation_id' => $installationId,
    'capabilities' => ['operations.read'],
    'preparation_areas' => [],
], 'reports');
if (($forbidden['status'] ?? 0) !== 403 || ($forbidden['body']['error'] ?? '') !== 'forbidden') {
    public_m4_remote_fail('M4 Remote Read Model capability guard allowed an unauthorized model.');
}

$deferred = $service->read([
    'installation_id' => $installationId,
    'capabilities' => ['deferred.context', 'subscriber.payment.defer'],
    'preparation_areas' => [],
], 'deferred_context');
$deferredBody = $deferred['body']['payload'] ?? [];
if (($deferred['status'] ?? 0) !== 200
    || ($deferredBody['supply_groups'] ?? null) !== []
    || ($deferredBody['inventory_items'] ?? null) !== []
    || ($deferredBody['count_drafts'] ?? null) !== []
    || count($deferredBody['subscribers'] ?? []) !== 1
    || ($deferredBody['expense_categories'] ?? null) !== []) {
    public_m4_remote_fail('M4 deferred_context pruning did not preserve field-level capability scope.');
}

$unknown = $service->read([
    'installation_id' => $installationId,
    'capabilities' => ['*'],
    'preparation_areas' => [],
], 'unknown-model');
if (($unknown['status'] ?? 0) !== 404 || ($unknown['body']['error'] ?? '') !== 'unknown_model') {
    public_m4_remote_fail('M4 Remote Read Model allowlist did not reject an unknown model key.');
}

$pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE installation_id=?')
    ->execute([$installationId]);
$stale = $service->read([
    'installation_id' => $installationId,
    'capabilities' => ['reports.read'],
    'preparation_areas' => [],
], 'reports');
if (($stale['status'] ?? 0) !== 200 || ($stale['body']['stale'] ?? false) !== true) {
    public_m4_remote_fail('M4 Remote Read Model did not expose stale state when Local connectivity was stale.');
}

fwrite(STDOUT, "Public M4 Remote Read Model sync/read/filter self-test: OK\n");
