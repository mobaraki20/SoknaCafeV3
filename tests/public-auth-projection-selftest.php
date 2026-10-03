<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function public_projection_fail(string $message): never
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

$installationId = 'projection-installation';
$pdo->prepare('INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?)')
    ->execute([$installationId, 'Projection CI', 1, 1, 1]);

$oldHash = password_hash('old-password', PASSWORD_DEFAULT);
$firstHash = password_hash('first-password', PASSWORD_DEFAULT);
$secondHash = password_hash('second-password', PASSWORD_DEFAULT);

$count = $core->authProjections()->sync($installationId, [
    [
        'projection_id' => 'user:1',
        'username' => 'alice',
        'display_name' => 'Alice',
        'role' => 'staff',
        'password_hash' => $firstHash,
        'capabilities' => ['orders.mutate', 'orders.table_draft'],
        'preparation_areas' => ['kitchen', 'bar'],
        'projection_version' => 10,
        'active' => true,
    ],
    [
        'projection_id' => 'user:2',
        'username' => 'bob',
        'display_name' => 'Bob',
        'role' => 'staff',
        'password_hash' => $oldHash,
        'capabilities' => ['preparation.read'],
        'preparation_areas' => ['bar'],
        'projection_version' => 4,
        'active' => true,
    ],
    ['projection_id' => '', 'username' => 'invalid', 'password_hash' => ''],
]);
if ($count !== 2) public_projection_fail('Projection sync did not count exactly the valid authoritative rows.');

$rows = $pdo->query("SELECT projection_id,username,password_hash,capabilities_json,preparation_areas_json,projection_version,active FROM auth_projections WHERE installation_id='projection-installation' ORDER BY projection_id")->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 2) public_projection_fail('Projection sync stored an invalid or missing row.');
if ((int)$rows[0]['active'] !== 1 || (int)$rows[1]['active'] !== 1) public_projection_fail('Initial projection set was not active.');
if (!password_verify('first-password', (string)$rows[0]['password_hash'])) public_projection_fail('Projected verifier for alice was not preserved.');
if (json_decode((string)$rows[0]['preparation_areas_json'], true) !== ['kitchen', 'bar']) public_projection_fail('Preparation scope order changed during projection sync.');

$count = $core->authProjections()->sync($installationId, [
    [
        'projection_id' => 'user:1',
        'username' => 'alice',
        'display_name' => 'Alice Updated',
        'role' => 'staff',
        'password_hash' => $secondHash,
        'capabilities' => ['orders.mutate'],
        'preparation_areas' => ['kitchen'],
        'projection_version' => 11,
        'active' => true,
    ],
]);
if ($count !== 1) public_projection_fail('Second authoritative projection sync count drifted.');

$alice = $pdo->query("SELECT display_name,password_hash,capabilities_json,preparation_areas_json,projection_version,active FROM auth_projections WHERE installation_id='projection-installation' AND projection_id='user:1'")->fetch(PDO::FETCH_ASSOC);
$bob = $pdo->query("SELECT active FROM auth_projections WHERE installation_id='projection-installation' AND projection_id='user:2'")->fetch(PDO::FETCH_ASSOC);
if (!is_array($alice) || !is_array($bob)) public_projection_fail('Projection rows disappeared unexpectedly.');
if (($alice['display_name'] ?? '') !== 'Alice Updated' || (int)$alice['projection_version'] !== 11 || (int)$alice['active'] !== 1) public_projection_fail('Authoritative projection update was not applied.');
if (!password_verify('second-password', (string)$alice['password_hash'])) public_projection_fail('Verifier update did not replace the prior projection value.');
if (json_decode((string)$alice['capabilities_json'], true) !== ['orders.mutate']) public_projection_fail('Capability projection update drifted.');
if (json_decode((string)$alice['preparation_areas_json'], true) !== ['kitchen']) public_projection_fail('Preparation-area projection update drifted.');
if ((int)$bob['active'] !== 0) public_projection_fail('Projection omitted from authoritative sync was not deactivated.');

fwrite(STDOUT, "Public M3 auth projection self-test: OK\n");
