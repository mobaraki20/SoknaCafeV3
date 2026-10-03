<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Core\Auth;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\Config;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Observability;
use Sokna\Local\Core\Session;

function auth_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

final class FakeIdentityRepository implements IdentityRepository
{
    public bool $throwOnIdLookup = false;

    public function __construct(
        private array $users,
        private array $capabilities,
        private array $areas,
    ) {
    }

    public function findActiveById(int $userId): ?array
    {
        if ($this->throwOnIdLookup) throw new RuntimeException('simulated transient DB failure');
        $user = $this->users[$userId] ?? null;
        return is_array($user) && (int)($user['active'] ?? 0) === 1 ? $user : null;
    }

    public function findActiveByUsername(string $username): ?array
    {
        foreach ($this->users as $user) {
            if ((string)($user['username'] ?? '') === $username && (int)($user['active'] ?? 0) === 1) return $user;
        }
        return null;
    }

    public function capabilitiesForUser(int $userId): array
    {
        return array_values(array_map('strval', $this->capabilities[$userId] ?? []));
    }

    public function preparationAreasForUser(int $userId): array
    {
        return array_values(array_map('strval', $this->areas[$userId] ?? []));
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sokna-v3-auth-' . bin2hex(random_bytes(6));
$config = Config::fromArray([
    'app' => [
        'data_dir' => $root,
        'session_name' => 'SOKNA_M2_TEST_SID',
        'session_lifetime' => 43200,
    ],
]);
$observability = Observability::fromConfig($config);
Session::start($config, $observability, '/sokna', false);
if (session_name() !== 'SOKNA_M2_TEST_SID') auth_fail('Session policy did not apply the configured session name.');
if (session_save_path() !== $root . DIRECTORY_SEPARATOR . 'sessions') auth_fail('Session storage is not under the Local data root.');
session_write_close();

$passwordHash = password_hash('correct-password', PASSWORD_DEFAULT);
$repository = new FakeIdentityRepository([
    1 => ['id'=>1,'username'=>'alice','display_name'=>'Alice Fresh','role'=>'staff','active'=>1,'password_hash'=>$passwordHash],
    2 => ['id'=>2,'username'=>'disabled','display_name'=>'Disabled','role'=>'staff','active'=>0,'password_hash'=>$passwordHash],
    3 => ['id'=>3,'username'=>'admin','display_name'=>'Admin','role'=>'admin','active'=>1,'password_hash'=>$passwordHash],
], [
    1 => ['preparation', 'shift_supervision', 'unknown_capability'],
], [
    1 => ['bar', 'invalid-area', 'kitchen'],
]);
$capabilities = new Capabilities($repository);

$_SESSION = [
    'user' => ['id'=>1,'username'=>'alice','display_name'=>'Stale Name','role'=>'staff'],
    'user_last_activity' => time(),
];
$auth = new Auth($repository, $capabilities, 43200);
$user = $auth->currentUser();
if (($user['display_name'] ?? '') !== 'Alice Fresh') auth_fail('Active session identity was not refreshed from canonical users authority.');
if ($auth->userCapabilities($user) !== ['preparation', 'shift_supervision']) auth_fail('Capability filtering changed or unknown capability was accepted.');
if ($auth->preparationAreas($user) !== ['bar', 'kitchen']) auth_fail('Preparation-area authority did not filter to canonical kitchen/bar scopes.');
if (!$auth->hasCapability('preparation', $user)) auth_fail('Granted preparation capability was lost.');
if ($auth->hasCapability('unknown_capability', $user)) auth_fail('Unknown capability was treated as valid.');

$admin = ['id'=>3,'username'=>'admin','display_name'=>'Admin','role'=>'admin'];
if ($capabilities->forUser($admin) !== Capabilities::DEFINITIONS) auth_fail('Admin capability compatibility semantics changed.');

$_SESSION = [
    'user' => ['id'=>2,'username'=>'disabled','display_name'=>'Disabled','role'=>'staff'],
    'user_last_activity' => time(),
];
$disabledAuth = new Auth($repository, $capabilities, 43200);
if ($disabledAuth->currentUser() !== null) auth_fail('Disabled account retained an authenticated session.');

$_SESSION = [
    'user' => ['id'=>1,'username'=>'alice','display_name'=>'Alice','role'=>'staff'],
    'user_last_activity' => time() - 43201,
];
$expiredAuth = new Auth($repository, $capabilities, 43200);
if ($expiredAuth->currentUser() !== null) auth_fail('Expired 12h shift session was not rejected.');

$_SESSION = [
    'user' => ['id'=>1,'username'=>'alice','display_name'=>'Cached Alice','role'=>'staff'],
    'user_last_activity' => time(),
];
$repository->throwOnIdLookup = true;
$transientAuth = new Auth($repository, $capabilities, 43200);
$transient = $transientAuth->currentUser();
if (($transient['display_name'] ?? '') !== 'Cached Alice') auth_fail('Transient DB read failure changed proven authenticated-shift compatibility behavior.');
$repository->throwOnIdLookup = false;

$_SESSION = [];
$loginAuth = new Auth($repository, $capabilities, 43200);
if (!$loginAuth->login('alice', 'correct-password')) auth_fail('Valid password login failed.');
if (isset($_SESSION['user']['password_hash'])) auth_fail('Password hash leaked into session identity.');

$_SESSION = [];
$badLoginAuth = new Auth($repository, $capabilities, 43200);
if ($badLoginAuth->login('alice', 'wrong-password')) auth_fail('Invalid password login succeeded.');

fwrite(STDOUT, "Local Auth M2 self-test: OK\n");
