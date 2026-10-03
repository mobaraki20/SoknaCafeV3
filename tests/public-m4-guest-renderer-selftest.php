<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

function public_m4_guest_renderer_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function public_m4_guest_renderer_assert(bool $condition, string $message): void
{
    if (!$condition) public_m4_guest_renderer_fail($message);
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

$installationId = 'm4-guest-renderer-installation';
$tableToken = 'm4-renderer-table-token';
$revisionId = 'guest-' . str_repeat('f', 32);
$contentHash = hash('sha256', 'm4-guest-renderer-content');
$maliciousName = '<script>alert("x")</script> قهوه';
$snapshot = [
    'format' => 'sokna-guest-snapshot-v1',
    'cafe_name' => 'کافه تست سکنا',
    'features' => ['table_sessions_enabled' => true],
    'tables' => [[
        'id' => 7,
        'name' => 'میز ۷',
        'code' => 'T7',
        'token' => $tableToken,
    ]],
    'menus' => [
        ['menu_key' => 'main', 'name' => 'منوی اصلی', 'sort_order' => 1],
        ['menu_key' => 'evening', 'name' => 'منوی عصر', 'sort_order' => 2],
    ],
    'catalogs' => [
        'main' => [
            'menu' => ['menu_key' => 'main', 'name' => 'منوی اصلی', 'sort_order' => 1],
            'categories' => [[
                'id' => 10,
                'name' => 'نوشیدنی گرم',
                'sort_order' => 1,
            ]],
            'items' => [[
                'id' => 100,
                'category_id' => 10,
                'category_name' => 'نوشیدنی گرم',
                'name' => $maliciousName,
                'description' => 'ترکیب روز',
                'price' => 180000,
                'available' => true,
                'image_path' => '',
            ]],
        ],
        'evening' => [
            'menu' => ['menu_key' => 'evening', 'name' => 'منوی عصر', 'sort_order' => 2],
            'categories' => [],
            'items' => [],
        ],
    ],
];
$availability = [
    'version' => hash('sha256', 'm4-renderer-availability'),
    'items' => ['100' => ['available' => true]],
    'order_acceptance' => ['cafe' => true, 'kitchen' => true, 'bar' => true],
    'waiter_enabled_table' => true,
    'tables' => [
        '7' => [
            'session' => [
                'token' => 'session-renderer',
                'started_at' => gmdate('c'),
                'status' => 'active',
            ],
        ],
    ],
];

$pdo->prepare(
    'INSERT INTO installations(installation_id,display_name,active,remote_enabled,order_intake_enabled) VALUES(?,?,?,?,?) '
    . 'ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),active=1,remote_enabled=1,order_intake_enabled=1'
)->execute([$installationId, 'Renderer CI', 1, 1, 1]);
foreach (['guest_active_revisions','guest_publish_revisions','guest_availability_state','installation_heartbeats'] as $tableName) {
    $pdo->prepare("DELETE FROM {$tableName} WHERE installation_id=?")->execute([$installationId]);
}
$pdo->prepare(
    'INSERT INTO guest_publish_revisions(installation_id,revision_id,content_hash,snapshot_json,media_manifest_json,generated_at) '
    . 'VALUES(?,?,?,?,?,UTC_TIMESTAMP())'
)->execute([
    $installationId,
    $revisionId,
    $contentHash,
    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    json_encode([]),
]);
$pdo->prepare('INSERT INTO guest_active_revisions(installation_id,revision_id,activated_at) VALUES(?,?,UTC_TIMESTAMP())')
    ->execute([$installationId, $revisionId]);
$pdo->prepare(
    'INSERT INTO guest_availability_state(installation_id,version,payload_json,generated_at,last_sync_at) '
    . 'VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
)->execute([
    $installationId,
    $availability['version'],
    json_encode($availability, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);
$pdo->prepare(
    'INSERT INTO installation_heartbeats(installation_id,local_version,runtime_status,telemetry_json,last_seen_at) '
    . 'VALUES(?,?,?,?,UTC_TIMESTAMP())'
)->execute([$installationId, 'ci', 'healthy', json_encode([])]);

$renderer = $core->guestRenderer();
$fresh = $renderer->render(
    $installationId,
    ['table' => $tableToken, 'menu' => 'main'],
    [
        'css' => '/assets/scds/guest.css',
        'js' => '/assets/scds/guest.js',
        'create_order' => '/api/v1/guest/compat/create-order',
        'waiter_call' => '/api/v1/guest/compat/waiter-call',
    ],
);
$html = (string)($fresh['body'] ?? '');
public_m4_guest_renderer_assert(($fresh['status'] ?? 0) === 200, 'M4 Guest renderer did not return HTTP 200 for a fresh published table surface.');
public_m4_guest_renderer_assert(str_contains($html, '<html lang="fa" dir="rtl">'), 'M4 Guest renderer is not Persian/RTL at the HTML owner.');
public_m4_guest_renderer_assert(str_contains($html, 'class="sg-app"'), 'M4 Guest renderer did not consume the registered sg-* Guest shell.');
public_m4_guest_renderer_assert(str_contains($html, 'class="sg-action-state is-ready"'), 'M4 Guest renderer did not expose the fresh action-state semantic.');
public_m4_guest_renderer_assert(str_contains($html, 'data-sg-order-submit'), 'Fresh eligible table did not expose the Guest order flow.');
public_m4_guest_renderer_assert(str_contains($html, 'data-sg-waiter'), 'Fresh waiter-enabled table did not expose the waiter action.');
public_m4_guest_renderer_assert(str_contains($html, '/assets/scds/guest.css') && str_contains($html, '/assets/scds/guest.js'), 'M4 Guest renderer is not using the canonical Guest SCDS assets.');
public_m4_guest_renderer_assert(!str_contains($html, '<style') && !str_contains($html, ' style='), 'M4 Guest renderer introduced an inline-style island.');
public_m4_guest_renderer_assert(!str_contains($html, $maliciousName), 'M4 Guest renderer emitted unescaped snapshot HTML.');
public_m4_guest_renderer_assert(str_contains($html, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'), 'M4 Guest renderer did not safely escape snapshot text.');
public_m4_guest_renderer_assert(str_contains($html, '۱۸۰,۰۰۰ تومان'), 'M4 Guest renderer did not present money with Persian digits.');

$pdo->prepare('UPDATE installation_heartbeats SET last_seen_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE installation_id=?')
    ->execute([$installationId]);
$stale = $renderer->render($installationId, ['table' => $tableToken], [
    'css' => '/assets/scds/guest.css',
    'js' => '/assets/scds/guest.js',
    'create_order' => '/api/v1/guest/compat/create-order',
    'waiter_call' => '/api/v1/guest/compat/waiter-call',
]);
$staleHtml = (string)($stale['body'] ?? '');
public_m4_guest_renderer_assert(($stale['status'] ?? 0) === 200, 'Stale Local heartbeat removed the read-only Guest menu.');
public_m4_guest_renderer_assert(str_contains($staleHtml, 'class="sg-action-state is-degraded"'), 'Stale Local heartbeat did not render the shared degraded action state.');
public_m4_guest_renderer_assert(str_contains($staleHtml, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'), 'Degraded Guest mode lost the immutable published menu.');
public_m4_guest_renderer_assert(!str_contains($staleHtml, 'data-sg-order-submit'), 'Guest mutation UI remained available while Local was stale.');
public_m4_guest_renderer_assert(!str_contains($staleHtml, 'data-sg-waiter'), 'Waiter mutation UI remained available while Local was stale.');

$invalid = $renderer->render($installationId, ['table' => 'invalid-table-token'], ['css' => '/assets/scds/guest.css']);
$invalidHtml = (string)($invalid['body'] ?? '');
public_m4_guest_renderer_assert(($invalid['status'] ?? 0) === 404, 'Invalid Guest QR did not render a 404 system state.');
public_m4_guest_renderer_assert(str_contains($invalidHtml, 'noindex,nofollow,noarchive'), 'Invalid Guest QR system state is indexable.');
public_m4_guest_renderer_assert(!str_contains($invalidHtml, '<style') && str_contains($invalidHtml, 'sg-system-state'), 'Guest system-state page bypassed SCDS ownership.');

$pdo->prepare('DELETE FROM guest_active_revisions WHERE installation_id=?')->execute([$installationId]);
$unpublished = $renderer->render($installationId, [], ['css' => '/assets/scds/guest.css']);
public_m4_guest_renderer_assert(($unpublished['status'] ?? 0) === 503, 'Missing active Guest revision did not render a safe unpublished state.');
public_m4_guest_renderer_assert(str_contains((string)$unpublished['body'], 'منوی عمومی هنوز منتشر نشده'), 'Unpublished Guest state lost canonical Persian product language.');

$cssPath = dirname(__DIR__) . '/apps/public/assets/scds/guest.css';
$jsPath = dirname(__DIR__) . '/apps/public/assets/scds/guest.js';
$registryPath = dirname(__DIR__) . '/docs/ui-design-system/COMPONENT_REGISTRY.json';
$css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
$js = is_file($jsPath) ? (string)file_get_contents($jsPath) : '';
$registry = is_file($registryPath) ? (string)file_get_contents($registryPath) : '';
public_m4_guest_renderer_assert($css !== '' && $js !== '', 'Canonical Guest SCDS runtime assets are missing.');
public_m4_guest_renderer_assert(str_contains($css, ':focus-visible'), 'Guest SCDS asset has no visible focus owner.');
public_m4_guest_renderer_assert(str_contains($css, 'min-block-size: 44px'), 'Guest SCDS asset does not enforce the 44px touch target contract.');
public_m4_guest_renderer_assert(str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'Guest SCDS asset does not honor reduced motion.');
public_m4_guest_renderer_assert(!str_contains($css, '!important'), 'Guest SCDS asset introduced !important debt.');
public_m4_guest_renderer_assert(!preg_match('/(^|[}\s])\.btn(?:[\s,{.:#]|$)/m', $css), 'Guest SCDS asset created a parallel generic button owner.');
public_m4_guest_renderer_assert(!str_contains($js, 'innerHTML'), 'Guest runtime JS introduced an unsafe HTML injection path.');
public_m4_guest_renderer_assert(str_contains($registry, '"guest_menu_shell"') && str_contains($registry, '"guest_order_flow"'), 'Guest SCDS canonical owners are not registered.');

fwrite(STDOUT, "Public M4 SCDS Guest renderer self-test: OK\n");
