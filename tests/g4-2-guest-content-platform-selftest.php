<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';
require_once dirname(__DIR__) . '/apps/public/bootstrap.php';

use Sokna\Local\Core\Config as LocalConfig;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\GuestContent\GuestContentException;
use Sokna\Local\Domain\PublicEdge\PublicEdgePublisherService;
use Sokna\Local\Domain\PublicEdge\PublicEdgeSyncClient;
use Sokna\PublicEdge\Http\PublicHttpKernel;

function g42(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$host = (string)(getenv('SOKNA_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string)(getenv('SOKNA_TEST_DB_PORT') ?: '3306');
$localName = (string)(getenv('SOKNA_TEST_DB_NAME') ?: 'sokna_m2');
$user = (string)(getenv('SOKNA_TEST_DB_USER') ?: 'sokna');
$pass = (string)(getenv('SOKNA_TEST_DB_PASS') ?: 'sokna');
$rootUser = (string)(getenv('SOKNA_TEST_DB_ROOT_USER') ?: 'root');
$rootPass = (string)(getenv('SOKNA_TEST_DB_ROOT_PASS') ?: 'root');
$publicName = preg_replace('/[^A-Za-z0-9_]/', '_', $localName) . '_public_g42';

$root = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $rootUser,
    $rootPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$root->exec("DROP DATABASE IF EXISTS `{$publicName}`");
$root->exec("CREATE DATABASE `{$publicName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$qu = $root->quote($user);
$qp = $root->quote($pass);
$root->exec("CREATE USER IF NOT EXISTS {$qu}@'%' IDENTIFIED BY {$qp}");
$root->exec("GRANT ALL PRIVILEGES ON `{$publicName}`.* TO {$qu}@'%'");
$root->exec('FLUSH PRIVILEGES');

$data = sys_get_temp_dir() . '/sokna-g42-' . bin2hex(random_bytes(5));
@mkdir($data, 0700, true);
$installation = 'g42-installation';
$secret = 'g42-secret-' . bin2hex(random_bytes(12));

$localConfig = [
    'app' => ['timezone' => 'UTC', 'data_dir' => $data],
    'db' => ['host' => $host, 'port' => $port, 'name' => $localName, 'charset' => 'utf8mb4', 'user' => $user, 'pass' => $pass],
    'installation' => ['id' => $installation],
    'runtime' => ['local_token' => 'g42'],
    'public' => ['base_url' => 'http://127.0.0.1', 'shared_secret' => $secret],
    'integrations' => ['accommodation' => ['base_url' => '', 'secret' => '']],
];
$local = sokna_local_bootstrap($localConfig);
$applied = $local->migrations()->migrate();
g42(in_array('0023_g4_guest_content_platform', $applied, true), 'G4.2 Local migration was not applied');
$lp = $local->database();
foreach (['guest_content_config','guest_media_assets','guest_media_derivatives','guest_media_references'] as $table) {
    $exists = (int)$lp->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=" . $lp->quote($table))->fetchColumn();
    g42($exists === 1, "G4.2 table {$table} missing");
}

$password = password_hash('G42AdminPass!', PASSWORD_DEFAULT);
$q = $lp->prepare('INSERT INTO users(username,password_hash,display_name,role,active) VALUES(?,?,?,?,1)');
$q->execute(['g42-admin', $password, 'G42 Admin', 'admin']);
$adminId = (int)$lp->lastInsertId();
$admin = ['id' => $adminId, 'username' => 'g42-admin', 'display_name' => 'G42 Admin', 'role' => 'admin', 'active' => 1];

$settings = $lp->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
foreach ([
    ['cafe.name','G42 Cafe'],
    ['installation.id',$installation],
    ['orders_accepting.cafe','1'],
    ['orders_accepting.kitchen','1'],
    ['orders_accepting.bar','1'],
    ['waiter_call_enabled','1'],
] as [$key,$value]) $settings->execute([$key,$value]);

$lp->exec("INSERT INTO menus(menu_key,name,status,sort_order) VALUES('g42-main','منوی G42','active',1)");
$menuId = (int)$lp->lastInsertId();
$lp->exec("INSERT INTO categories(category_key,name,audience,sort_order,active) VALUES('g42-coffee','قهوه','guest_staff',1,1)");
$categoryId = (int)$lp->lastInsertId();
$lp->prepare('INSERT INTO menu_categories(menu_id,category_id,sort_order) VALUES(?,?,1)')->execute([$menuId,$categoryId]);
$lp->prepare("INSERT INTO items(item_code,category_id,name,description,price,available,active,featured,staff_only,sellable_kind,takeaway_allowed,preparation_station,sort_order) VALUES('g42-item',?,'لاته G42','تست رسانه و محتوای مهمان',220000,1,1,0,0,'menu_item',1,'bar',1)")->execute([$categoryId]);
$itemId = (int)$lp->lastInsertId();
$lp->prepare('INSERT INTO menu_items(menu_id,item_id) VALUES(?,?)')->execute([$menuId,$itemId]);

$content = $local->guestContent();
$theme = $content->saveThemeDraft([
    'theme_key' => 'sokna-house',
    'settings' => ['primary' => '#123456', 'radius_md' => '20px'],
], $admin);
g42(($theme['saved'] ?? false) === true, 'Theme draft did not save');
$copy = $content->saveCopyDraft(['copy' => [
    'menu_subtitle' => 'منوی تست G42',
    'ready_title' => 'G42 ارتباط برقرار است',
    'search_placeholder' => 'G42 جست‌وجو',
]], $admin);
g42(($copy['saved'] ?? false) === true, 'Guest copy draft did not save');
$published = $content->publishDraft($admin);
g42(($published['published'] ?? false) === true && (int)$published['revision'] === 1, 'Guest content draft did not publish');

$markupRejected = false;
try {
    $content->saveCopyDraft(['copy' => ['menu_subtitle' => '<script>alert(1)</script>']], $admin);
} catch (GuestContentException $e) {
    $markupRejected = $e->errorCode === 'copy_markup';
}
g42($markupRejected, 'Guest copy accepted markup');

if (!extension_loaded('gd') || !function_exists('imagewebp')) throw new RuntimeException('G4.2 requires GD WebP support');
$fixture = imagecreatetruecolor(900, 600);
$bg = imagecolorallocate($fixture, 245, 240, 230);
$accent = imagecolorallocate($fixture, 120, 70, 40);
imagefilledrectangle($fixture, 0, 0, 899, 599, $bg);
imagefilledellipse($fixture, 450, 300, 280, 280, $accent);
$uploadPath = $data . '/g42.png';
imagepng($fixture, $uploadPath, 6);
imagedestroy($fixture);
$imported = $content->importUpload($uploadPath, 'g42.png', 'تصویر لاته G42', $admin);
$mediaKey = (string)($imported['media_key'] ?? '');
g42($mediaKey !== '', 'Media import did not return a media key');
$dedupe = $content->importUpload($uploadPath, 'same.png', 'نسخه تکراری', $admin);
g42(($dedupe['deduplicated'] ?? false) === true && ($dedupe['media_key'] ?? '') === $mediaKey, 'Media content-addressed deduplication failed');
$derivative = $lp->query("SELECT mime,extension,width_px,height_px,processor,byte_size FROM guest_media_derivatives WHERE variant_key='guest-card' AND media_id=(SELECT id FROM guest_media_assets WHERE media_key=".$lp->quote($mediaKey).")")->fetch(PDO::FETCH_ASSOC);
g42(is_array($derivative), 'Standard guest-card derivative missing');
g42(($derivative['mime'] ?? '') === 'image/webp' && ($derivative['extension'] ?? '') === 'webp', 'Guest-card derivative is not WebP');
g42((int)($derivative['width_px'] ?? 0) === 640 && (int)($derivative['height_px'] ?? 0) === 640, 'Guest-card derivative is not 640x640');
g42(($derivative['processor'] ?? '') === 'gd-center-crop-640-webp84', 'Guest-card processor contract mismatch');

$content->assignMediaToItem($itemId, $mediaKey, $admin);
$imagePath = (string)$lp->query("SELECT image_path FROM items WHERE id={$itemId}")->fetchColumn();
g42($imagePath === 'media:' . $mediaKey, 'Media reference was not attached to item');

$blocked = false;
try {
    $content->archiveMedia($mediaKey, $admin);
} catch (GuestContentException $e) {
    $blocked = $e->errorCode === 'media_in_use';
}
g42($blocked, 'Referenced media could be archived');

$publicConfig = [
    'db' => ['host' => $host, 'port' => $port, 'name' => $publicName, 'charset' => 'utf8mb4', 'user' => $user, 'pass' => $pass],
    'app' => ['default_installation_id' => $installation, 'storage_dir' => $data . '/public-storage', 'cookie_secure' => '0'],
    'relay' => ['installation_secrets' => [$installation => $secret], 'clock_skew_seconds' => 300],
    'auth' => ['session_ttl_seconds' => 3600, 'failure_limit' => 5, 'failure_window_seconds' => 900, 'block_seconds' => 900],
];
$public = sokna_public_bootstrap($publicConfig);
$public->migrations()->migrate();
$kernel = new PublicHttpKernel($public, dirname(__DIR__) . '/apps/public');
$transport = static function(string $url, string $method, array $headers, string $body) use ($kernel): array {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
    $response = $kernel->handle($method, $path, [], $body, $headers);
    return ['status' => (int)$response['status'], 'body' => (string)($response['body'] ?? '')];
};
$client = new PublicEdgeSyncClient(LocalConfig::fromArray($localConfig), $transport);
$publisher = new PublicEdgePublisherService($lp, $client, $local->publicProjectionBuilder(), new Observability($data), 'g42-ci');
$sync = $publisher->syncAll();
g42(($sync['success'] ?? false) === true, 'G4.2 Public Edge publish failed');
g42(($sync['channels']['guest_media']['ok'] ?? false) === true, 'Guest media channel was not published');

$pp = $public->database();
g42((int)$pp->query("SELECT COUNT(*) FROM guest_publish_revisions WHERE installation_id='g42-installation'")->fetchColumn() === 1, 'Guest revision missing on Public');
g42((int)$pp->query("SELECT COUNT(*) FROM guest_active_revisions WHERE installation_id='g42-installation'")->fetchColumn() === 1, 'Guest active revision missing on Public');

$bundle = $public->guestRuntime()->bundle($installation);
$presentation = (array)(($bundle['snapshot']['presentation'] ?? []));
g42((string)($presentation['theme']['theme_key'] ?? '') === 'sokna-house', 'Published theme missing from Public snapshot');
g42((string)($presentation['theme']['tokens']['primary'] ?? '') === '#123456', 'Published theme token missing from Public snapshot');
g42((string)($presentation['copy']['menu_subtitle'] ?? '') === 'منوی تست G42', 'Central guest copy missing from Public snapshot');
$manifest = (array)($bundle['media_manifest'] ?? []);
g42(count($manifest) === 1, 'Published media manifest is not singular/expected');
$meta = array_values($manifest)[0];
g42((string)($meta['alt_text'] ?? '') === 'تصویر لاته G42', 'Published media alt text missing');
$sha = (string)($meta['sha256'] ?? '');
$ext = (string)($meta['extension'] ?? '');
g42($sha !== '' && $ext === 'webp', 'Published media metadata is not standardized WebP');

$mediaResponse = $kernel->handle('GET', "/media/{$installation}/{$sha}.{$ext}");
g42((int)$mediaResponse['status'] === 200 && is_file((string)($mediaResponse['file_path'] ?? '')), 'Public media replica is not readable');
$themeCss = $kernel->handle('GET', "/theme/{$installation}.css");
g42((int)$themeCss['status'] === 200 && str_contains((string)$themeCss['body'], '--sg-color-primary:#123456'), 'Published theme CSS did not render safe token');
$menu = $kernel->handle('GET', '/menu', ['installation' => $installation]);
$menuHtml = (string)($menu['body'] ?? '');
g42((int)$menu['status'] === 200, 'Public menu did not render');
g42(str_contains($menuHtml, 'منوی تست G42'), 'Central guest copy not rendered in Public menu');
g42(str_contains($menuHtml, 'G42 ارتباط برقرار است'), 'Ready copy not rendered in Public menu');
g42(str_contains($menuHtml, 'G42 جست‌وجو'), 'Search copy not rendered in Public menu');
g42(str_contains($menuHtml, 'تصویر لاته G42'), 'Media alt text not rendered in Public menu');
g42(str_contains($menuHtml, "/media/{$installation}/{$sha}.{$ext}"), 'Published media URL not rendered in Public menu');
g42(str_contains($menuHtml, "/theme/{$installation}.css"), 'Theme CSS endpoint not linked by Public menu');

$content->assignMediaToItem($itemId, '', $admin);
$archived = $content->archiveMedia($mediaKey, $admin);
g42(($archived['archived'] ?? false) === true, 'Unreferenced media did not archive');
$dry = $content->garbageCollect($admin, true);
g42(in_array($mediaKey, (array)($dry['candidates'] ?? []), true), 'Archived unreferenced media not offered to GC');
$gc = $content->garbageCollect($admin, false);
g42(in_array($mediaKey, (array)($gc['removed'] ?? []), true), 'Archived media was not removed by GC');
g42((int)$lp->query("SELECT COUNT(*) FROM guest_media_assets WHERE media_key=" . $lp->quote($mediaKey))->fetchColumn() === 0, 'GC left media database record');

$svgRejected = $public->guestMedia()->put($installation, [
    'sha256' => str_repeat('a',64),
    'mime' => 'image/svg+xml',
    'extension' => 'svg',
    'size' => 4,
    'content_base64' => base64_encode('<svg'),
]);
g42((int)$svgRejected['status'] === 400, 'Public media store accepted SVG replica');

$root->exec("DROP DATABASE IF EXISTS `{$publicName}`");
fwrite(STDOUT, "G4.2 Guest Content Platform MariaDB E2E: OK\n");
