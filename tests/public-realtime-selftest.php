<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/src/Http/RealtimeHttpAdapter.php';

use Sokna\PublicEdge\Http\RealtimeHttpAdapter;

function public_realtime_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$name = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m3');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$installationId = 'realtime-transport-ci';
$projectionId = 'projection-realtime-ci';
$secret = 'realtime-transport-secret';

$core = sokna_public_bootstrap([
    'db' => ['host'=>$host,'port'=>$port,'name'=>$name,'charset'=>'utf8mb4','user'=>$user,'pass'=>$pass],
    'relay' => ['installation_secrets'=>[$installationId=>$secret],'clock_skew_seconds'=>300],
]);
$core->migrations()->migrate();
$pdo = $core->database();
$pdo->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute([$installationId, 'Realtime Transport CI', 1, 1, 1]);
$pdo->prepare('INSERT INTO auth_projections(installation_id,projection_id,username,display_name,role,password_hash,capabilities_json,preparation_areas_json,projection_version,active) VALUES(?,?,?,?,?,?,?,?,?,?)')
    ->execute([$installationId,$projectionId,'realtime-ci','Realtime CI','manager',password_hash('not-used',PASSWORD_DEFAULT),json_encode(['orders.mutate']),json_encode([]),1,1]);
$issued = $core->publicSessions()->issue($installationId, $projectionId);
$token = (string)($issued['token'] ?? '');
if ($token === '') public_realtime_fail('Public session token was not issued.');
$adapter = new RealtimeHttpAdapter($core);

$now = time();
$envelope = [
    'request_id' => 'rt-ci-001',
    'kind' => 'order.edit',
    'created_at' => gmdate(DATE_ATOM, $now),
    'expires_at' => gmdate(DATE_ATOM, $now + 300),
    'payload' => ['order_id'=>42,'note'=>'ci'],
    'expected_version' => 7,
    'expected_state' => 'open',
    'correlation_id' => 'corr-rt-ci-001',
];
$body = json_encode($envelope, JSON_UNESCAPED_SLASHES);
if (!is_string($body)) public_realtime_fail('Realtime envelope encoding failed.');
$queued = $adapter->enqueue($token, $body);
if ((int)($queued['status'] ?? 0) !== 202 || ($queued['body']['state'] ?? '') !== 'queued' || ($queued['body']['deduplicated'] ?? true) !== false) {
    public_realtime_fail('Realtime enqueue did not create queued request.');
}
$dedupe = $adapter->enqueue($token, $body);
if ((int)($dedupe['status'] ?? 0) !== 200 || ($dedupe['body']['deduplicated'] ?? false) !== true) {
    public_realtime_fail('Realtime idempotent replay did not deduplicate.');
}
$conflictEnvelope = $envelope;
$conflictEnvelope['payload'] = ['order_id'=>42,'note'=>'different'];
$conflictBody = json_encode($conflictEnvelope, JSON_UNESCAPED_SLASHES);
$conflict = $adapter->enqueue($token, is_string($conflictBody) ? $conflictBody : '{}');
if ((int)($conflict['status'] ?? 0) !== 409 || ($conflict['body']['error'] ?? '') !== 'request_id_conflict') {
    public_realtime_fail('Realtime request_id conflict taxonomy drifted.');
}

$claimPath = '/api/v1/local/claim.php';
$claimBody = json_encode(['lease_seconds'=>20], JSON_UNESCAPED_SLASHES);
if (!is_string($claimBody)) public_realtime_fail('Claim body encoding failed.');
$claimTs = (string)time();
$claimNonce = 'rt-claim-' . bin2hex(random_bytes(5));
$claimSig = $core->signedLocalRequests()->sign($secret, 'POST', $claimPath, $claimTs, $claimNonce, $claimBody);
$claim = $adapter->claim($installationId, 'POST', $claimPath, $claimTs, $claimNonce, $claimSig, $claimBody);
if ((int)($claim['status'] ?? 0) !== 200 || ($claim['body']['request']['request_id'] ?? '') !== 'rt-ci-001') {
    public_realtime_fail('Signed realtime claim did not return queued request.');
}
$lease = (string)($claim['body']['lease_token'] ?? '');
if (strlen($lease) !== 48) public_realtime_fail('Realtime lease token shape drifted.');
$storedLease = $pdo->prepare('SELECT lease_token_hash FROM realtime_requests WHERE installation_id=? AND request_id=?');
$storedLease->execute([$installationId, 'rt-ci-001']);
$storedHash = (string)$storedLease->fetchColumn();
if ($storedHash !== hash('sha256', $lease) || $storedHash === $lease) public_realtime_fail('Realtime lease must be stored only as SHA256 hash.');

$wrongAck = json_encode(['request_id'=>'rt-ci-001','lease_token'=>str_repeat('0',48),'state'=>'committed','result'=>['ok'=>true]], JSON_UNESCAPED_SLASHES);
if (!is_string($wrongAck)) public_realtime_fail('Wrong-lease ACK encoding failed.');
$wrongTs = (string)time();
$wrongNonce = 'rt-ack-wrong-' . bin2hex(random_bytes(5));
$wrongSig = $core->signedLocalRequests()->sign($secret, 'POST', '/api/v1/local/ack.php', $wrongTs, $wrongNonce, $wrongAck);
$wrong = $adapter->ack($installationId, 'POST', '/api/v1/local/ack.php', $wrongTs, $wrongNonce, $wrongSig, $wrongAck);
if ((int)($wrong['status'] ?? 0) !== 409 || ($wrong['body']['error'] ?? '') !== 'lease_conflict') public_realtime_fail('Realtime wrong lease was not rejected.');

$ackBody = json_encode(['request_id'=>'rt-ci-001','lease_token'=>$lease,'state'=>'committed','result'=>['order_id'=>42,'version'=>8]], JSON_UNESCAPED_SLASHES);
if (!is_string($ackBody)) public_realtime_fail('ACK body encoding failed.');
$ackTs = (string)time();
$ackNonce = 'rt-ack-' . bin2hex(random_bytes(5));
$ackSig = $core->signedLocalRequests()->sign($secret, 'POST', '/api/v1/local/ack.php', $ackTs, $ackNonce, $ackBody);
$acked = $adapter->ack($installationId, 'POST', '/api/v1/local/ack.php', $ackTs, $ackNonce, $ackSig, $ackBody);
if ((int)($acked['status'] ?? 0) !== 200 || ($acked['body']['state'] ?? '') !== 'committed' || ($acked['body']['deduplicated'] ?? true) !== false) {
    public_realtime_fail('Realtime ACK did not commit terminal transport state.');
}
$result = $adapter->result($token, 'rt-ci-001');
if ((int)($result['status'] ?? 0) !== 200 || ($result['body']['terminal'] ?? false) !== true || ($result['body']['result']['version'] ?? null) !== 8) {
    public_realtime_fail('Realtime result lookup did not round-trip terminal result.');
}

$repeatTs = (string)time();
$repeatNonce = 'rt-ack-repeat-' . bin2hex(random_bytes(5));
$repeatSig = $core->signedLocalRequests()->sign($secret, 'POST', '/api/v1/local/ack.php', $repeatTs, $repeatNonce, $ackBody);
$repeat = $adapter->ack($installationId, 'POST', '/api/v1/local/ack.php', $repeatTs, $repeatNonce, $repeatSig, $ackBody);
if ((int)($repeat['status'] ?? 0) !== 200 || ($repeat['body']['deduplicated'] ?? false) !== true) public_realtime_fail('Realtime terminal ACK retry was not deduplicated.');

fwrite(STDOUT, "Public M3 realtime transport self-test: OK\n");
