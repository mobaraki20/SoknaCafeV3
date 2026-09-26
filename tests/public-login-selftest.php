<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function public_login_fail(string $message): never
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
    'auth' => [
        'failure_limit' => 3,
        'failure_window_seconds' => 300,
        'block_seconds' => 120,
        'session_ttl_seconds' => 3600,
    ],
]);
$core->migrations()->migrate();
$pdo = $core->database();

$installationId = 'login-installation';
$pdo->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute([$installationId, 'Login CI', 1, 1, 1]);
$passwordHash = password_hash('correct-password', PASSWORD_DEFAULT);
$core->authProjections()->sync($installationId, [[
    'projection_id' => 'user:10',
    'username' => 'alice',
    'display_name' => 'Alice Remote',
    'role' => 'staff',
    'password_hash' => $passwordHash,
    'capabilities' => ['orders.mutate', 'orders.table_draft'],
    'preparation_areas' => ['kitchen', 'bar'],
    'projection_version' => 1,
    'active' => true,
]]);

for ($i = 0; $i < 3; $i++) {
    $bad = $core->loginService()->login($installationId, 'alice', 'wrong-password', '198.51.100.10', 'corr-bad-' . $i);
    if (($bad['ok'] ?? true) !== false || ($bad['error'] ?? '') !== 'invalid_credentials' || (int)($bad['status'] ?? 0) !== 401) {
        public_login_fail('Wrong password did not preserve invalid_credentials behavior before throttle activation.');
    }
}
$blocked = $core->loginService()->login($installationId, 'alice', 'wrong-password', '198.51.100.10', 'corr-blocked');
if (($blocked['error'] ?? '') !== 'too_many_attempts' || (int)($blocked['status'] ?? 0) !== 429 || (int)($blocked['retry_after'] ?? 0) < 1) {
    public_login_fail('Failed-login throttle did not activate predictably.');
}

$success = $core->loginService()->login($installationId, 'alice', 'correct-password', '198.51.100.11', 'corr-success');
if (($success['ok'] ?? false) !== true || (int)($success['status'] ?? 0) !== 200) public_login_fail('Correct projected password did not login successfully.');
if (($success['projection_id'] ?? '') !== 'user:10' || ($success['display_name'] ?? '') !== 'Alice Remote') public_login_fail('Successful login response shape drifted.');
if (($success['capabilities'] ?? null) !== ['orders.mutate', 'orders.table_draft']) public_login_fail('Projected capabilities were not returned by login.');
if (($success['preparation_areas'] ?? null) !== ['kitchen', 'bar']) public_login_fail('Projected preparation scopes were not returned by login.');
$token = (string)($success['token'] ?? '');
if ($token === '') public_login_fail('Successful login did not issue an opaque token.');

$stored = (string)$pdo->query("SELECT token_hash FROM public_sessions WHERE installation_id='login-installation' ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($stored === $token || $stored !== hash('sha256', $token)) public_login_fail('Public session persisted raw token material.');
$session = $core->publicSessions()->resolve($token);
if (!is_array($session) || ($session['projection_id'] ?? '') !== 'user:10') public_login_fail('Issued Public session could not be resolved against active projection.');

$core->authProjections()->sync($installationId, []);
if ($core->publicSessions()->resolve($token) !== null) public_login_fail('Inactive projection did not revoke effective Public session access.');
$disabledLogin = $core->loginService()->login($installationId, 'alice', 'correct-password', '198.51.100.12', 'corr-disabled');
if (($disabledLogin['ok'] ?? true) !== false || ($disabledLogin['error'] ?? '') !== 'invalid_credentials') public_login_fail('Inactive projection remained login-capable.');

$auditText = implode('\n', array_map('strval', $pdo->query("SELECT CONCAT_WS('|',event_key,COALESCE(account_key,''),COALESCE(origin_key,''),COALESCE(correlation_id,''),COALESCE(metadata_json,'')) FROM auth_security_audit WHERE installation_id='login-installation'")->fetchAll(PDO::FETCH_COLUMN)));
if ($auditText === '') public_login_fail('Auth security audit did not record login events.');
if (str_contains($auditText, 'wrong-password') || str_contains($auditText, 'correct-password') || str_contains($auditText, $passwordHash) || str_contains($auditText, $token)) {
    public_login_fail('Auth security audit leaked password/verifier/token material.');
}

$throttleRow = $pdo->query("SELECT account_key,origin_key FROM auth_login_throttle WHERE installation_id='login-installation' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($throttleRow) || strlen((string)$throttleRow['account_key']) !== 64 || strlen((string)$throttleRow['origin_key']) !== 64) {
    public_login_fail('Throttle keys were not stored as opaque SHA-256 values.');
}
if (($throttleRow['account_key'] ?? '') === 'alice' || ($throttleRow['origin_key'] ?? '') === '198.51.100.10') {
    public_login_fail('Throttle storage retained raw account/network origin identifiers.');
}

fwrite(STDOUT, "Public M3 login/session/throttle self-test: OK\n");
