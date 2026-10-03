<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/src/Http/DeferredHttpAdapter.php';

use Sokna\PublicEdge\Http\DeferredHttpAdapter;

function public_deferred_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m3');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$installationId = 'deferred-transport-ci';
$projectionId = 'projection-deferred-ci';
$secret = 'deferred-transport-secret';

$core = sokna_public_bootstrap([
    'db' => ['host'=>$host,'port'=>$port,'name'=>$name,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],
    'relay' => ['installation_secrets'=>[$installationId=>$secret],'clock_skew_seconds'=>300],
]);
$core->migrations()->migrate();
$pdo = $core->database();
$pdo->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute([$installationId, 'Deferred Transport CI', 1, 1, 1]);
$pdo->prepare('INSERT INTO auth_projections(installation_id,projection_id,username,display_name,role,password_hash,capabilities_json,preparation_areas_json,projection_version,active) VALUES(?,?,?,?,?,?,?,?,?,?)')
    ->execute([$installationId,$projectionId,'deferred-ci','Deferred CI','manager',password_hash('not-used',PASSWORD_DEFAULT),json_encode(['inventory.waste.defer']),json_encode([]),1,1]);
$issued = $core->publicSessions()->issue($installationId, $projectionId);
$token = (string)($issued['token'] ?? '');
if ($token === '') public_deferred_fail('Public session token was not issued.');
$adapter = new DeferredHttpAdapter($core);

$now = time();
$envelope = [
    'request_id' => 'df-ci-001',
    'kind' => 'inventory.waste',
    'created_at' => gmdate(DATE_ATOM, $now),
    'occurred_at' => gmdate(DATE_ATOM, $now - 30),
    'payload' => ['item_id'=>9,'qty'=>2],
    'expected_version' => 3,
    'correlation_id' => 'corr-df-ci-001',
];
$body = json_encode($envelope, JSON_UNESCAPED_SLASHES);
if (!is_string($body)) public_deferred_fail('Deferred envelope encoding failed.');
$queued = $adapter->enqueue($token, $body);
if ((int)($queued['status'] ?? 0) !== 202 || ($queued['body']['state'] ?? '') !== 'pending_sync' || ($queued['body']['deduplicated'] ?? true) !== false) {
    public_deferred_fail('Deferred enqueue did not create pending_sync work.');
}
$dedupe = $adapter->enqueue($token, $body);
if ((int)($dedupe['status'] ?? 0) !== 200 || ($dedupe['body']['deduplicated'] ?? false) !== true) public_deferred_fail('Deferred idempotent replay did not deduplicate.');
$conflictEnvelope = $envelope;
$conflictEnvelope['payload'] = ['item_id'=>9,'qty'=>4];
$conflictJson = json_encode($conflictEnvelope, JSON_UNESCAPED_SLASHES);
$conflict = $adapter->enqueue($token, is_string($conflictJson) ? $conflictJson : '{}');
if ((int)($conflict['status'] ?? 0) !== 409 || ($conflict['body']['error'] ?? '') !== 'request_id_conflict') public_deferred_fail('Deferred request_id conflict taxonomy drifted.');
$badExpiry = $envelope;
$badExpiry['request_id'] = 'df-ci-expiry';
$badExpiry['expires_at'] = gmdate(DATE_ATOM, $now + 300);
$badExpiryJson = json_encode($badExpiry, JSON_UNESCAPED_SLASHES);
$badExpiryResult = $adapter->enqueue($token, is_string($badExpiryJson) ? $badExpiryJson : '{}');
if ((int)($badExpiryResult['status'] ?? 0) !== 400 || ($badExpiryResult['body']['error'] ?? '') !== 'invalid_envelope') public_deferred_fail('Deferred incorrectly accepted expires_at semantics.');

$list = $adapter->list($token, 100);
if ((int)($list['status'] ?? 0) !== 200 || count($list['body']['items'] ?? []) !== 1) public_deferred_fail('Deferred actor-scoped list did not return queued work.');

$claimPath = '/api/v1/local/deferred/claim.php';
$claimBody = json_encode(['lease_seconds'=>30], JSON_UNESCAPED_SLASHES);
if (!is_string($claimBody)) public_deferred_fail('Deferred claim body encoding failed.');
$claimTs = (string)time();
$claimNonce = 'df-claim-' . bin2hex(random_bytes(5));
$claimSig = $core->signedLocalRequests()->sign($secret, 'POST', $claimPath, $claimTs, $claimNonce, $claimBody);
$claim = $adapter->claim($installationId, 'POST', $claimPath, $claimTs, $claimNonce, $claimSig, $claimBody);
if ((int)($claim['status'] ?? 0) !== 200 || ($claim['body']['request']['request_id'] ?? '') !== 'df-ci-001') public_deferred_fail('Signed Deferred claim did not return pending work.');
$lease = (string)($claim['body']['lease_token'] ?? '');
if (strlen($lease) !== 64) public_deferred_fail('Deferred lease token shape drifted.');
$attempt = $pdo->prepare('SELECT lease_token,attempt_count FROM deferred_work WHERE installation_id=? AND request_id=?');
$attempt->execute([$installationId, 'df-ci-001']);
$attemptRow = $attempt->fetch(PDO::FETCH_ASSOC);
if (!is_array($attemptRow) || (string)$attemptRow['lease_token'] !== $lease || (int)$attemptRow['attempt_count'] !== 1) public_deferred_fail('Deferred lease/attempt persistence drifted.');

$ackPath = '/api/v1/local/deferred/ack.php';
$ackBody = json_encode(['request_id'=>'df-ci-001','lease_token'=>$lease,'state'=>'needs_review','result'=>['reason'=>'period_check'],'error_code'=>'period_review'], JSON_UNESCAPED_SLASHES);
if (!is_string($ackBody)) public_deferred_fail('Deferred ACK encoding failed.');
$ackTs = (string)time();
$ackNonce = 'df-ack-' . bin2hex(random_bytes(5));
$ackSig = $core->signedLocalRequests()->sign($secret, 'POST', $ackPath, $ackTs, $ackNonce, $ackBody);
$acked = $adapter->ack($installationId, 'POST', $ackPath, $ackTs, $ackNonce, $ackSig, $ackBody);
if ((int)($acked['status'] ?? 0) !== 200 || ($acked['body']['state'] ?? '') !== 'needs_review' || ($acked['body']['deduplicated'] ?? true) !== false) public_deferred_fail('Deferred ACK did not enter needs_review.');

$repeatTs = (string)time();
$repeatNonce = 'df-ack-repeat-' . bin2hex(random_bytes(5));
$repeatSig = $core->signedLocalRequests()->sign($secret, 'POST', $ackPath, $repeatTs, $repeatNonce, $ackBody);
$repeat = $adapter->ack($installationId, 'POST', $ackPath, $repeatTs, $repeatNonce, $repeatSig, $ackBody);
if ((int)($repeat['status'] ?? 0) !== 200 || ($repeat['body']['deduplicated'] ?? false) !== true) public_deferred_fail('Deferred terminal retry was not deduplicated.');

$conflictingTerminalBody = json_encode(['request_id'=>'df-ci-001','lease_token'=>$lease,'state'=>'rejected'], JSON_UNESCAPED_SLASHES);
if (!is_string($conflictingTerminalBody)) public_deferred_fail('Terminal conflict body encoding failed.');
$terminalTs = (string)time();
$terminalNonce = 'df-terminal-' . bin2hex(random_bytes(5));
$terminalSig = $core->signedLocalRequests()->sign($secret, 'POST', $ackPath, $terminalTs, $terminalNonce, $conflictingTerminalBody);
$terminalConflict = $adapter->ack($installationId, 'POST', $ackPath, $terminalTs, $terminalNonce, $terminalSig, $conflictingTerminalBody);
if ((int)($terminalConflict['status'] ?? 0) !== 409 || ($terminalConflict['body']['error'] ?? '') !== 'terminal_state_conflict') public_deferred_fail('Deferred terminal state conflict was not preserved.');

$reconcilePath = '/api/v1/local/deferred/reconcile.php';
$reconcileBody = json_encode(['request_id'=>'df-ci-001','state'=>'committed','result'=>['inventory_event_id'=>77]], JSON_UNESCAPED_SLASHES);
if (!is_string($reconcileBody)) public_deferred_fail('Reconcile body encoding failed.');
$reconcileTs = (string)time();
$reconcileNonce = 'df-reconcile-' . bin2hex(random_bytes(5));
$reconcileSig = $core->signedLocalRequests()->sign($secret, 'POST', $reconcilePath, $reconcileTs, $reconcileNonce, $reconcileBody);
$reconciled = $adapter->reconcile($installationId, 'POST', $reconcilePath, $reconcileTs, $reconcileNonce, $reconcileSig, $reconcileBody);
if ((int)($reconciled['status'] ?? 0) !== 200 || ($reconciled['body']['state'] ?? '') !== 'committed') public_deferred_fail('Deferred needs_review reconciliation failed.');
$result = $adapter->result($token, 'df-ci-001');
if ((int)($result['status'] ?? 0) !== 200 || ($result['body']['state'] ?? '') !== 'committed' || ($result['body']['result']['inventory_event_id'] ?? null) !== 77) public_deferred_fail('Deferred result did not round-trip reconciled terminal result.');

$periodPath = '/api/v1/local/deferred/period-status.php';
$day = gmdate('Y-m-d', $now);
$periodBody = json_encode(['from_date'=>$day,'to_date'=>$day], JSON_UNESCAPED_SLASHES);
if (!is_string($periodBody)) public_deferred_fail('Period status body encoding failed.');
$periodTs = (string)time();
$periodNonce = 'df-period-' . bin2hex(random_bytes(5));
$periodSig = $core->signedLocalRequests()->sign($secret, 'POST', $periodPath, $periodTs, $periodNonce, $periodBody);
$period = $adapter->periodStatus($installationId, 'POST', $periodPath, $periodTs, $periodNonce, $periodSig, $periodBody);
if ((int)($period['status'] ?? 0) !== 200 || ($period['body']['counts']['committed'] ?? 0) !== 1 || ($period['body']['blocking'] ?? -1) !== 0) public_deferred_fail('Deferred period status counts/blocking drifted.');

fwrite(STDOUT, "Public M3 deferred-safe transport self-test: OK\n");
