<?php
declare(strict_types=1);

if (function_exists('header_remove')) header_remove('X-Powered-By');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Referrer-Policy: no-referrer');

$root = dirname(__DIR__);
$configPath = $root . '/config.php';
require_once $root . '/bootstrap.php';

$service = new \Sokna\PublicEdge\Setup\PublicSetupService($root, $configPath);
$preflight = $service->preflight();
$result = null;
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $result = $service->install([
            'db' => [
                'host' => (string)($_POST['db_host'] ?? '127.0.0.1'),
                'port' => (string)($_POST['db_port'] ?? '3306'),
                'name' => (string)($_POST['db_name'] ?? 'sokna_public'),
                'user' => (string)($_POST['db_user'] ?? ''),
                'pass' => (string)($_POST['db_pass'] ?? ''),
            ],
            'create_database' => isset($_POST['create_database']),
            'storage_dir' => (string)($_POST['storage_dir'] ?? ''),
            'cookie_secure' => !isset($_POST['cookie_secure']) || (string)$_POST['cookie_secure'] === '1',
        ]);
    } catch (Throwable $e) {
        $map = [
            'setup_locked' => 'راه‌اندازی Public Edge قبلاً انجام شده است.',
            'environment_incomplete' => 'پیش‌نیازهای PHP کامل نیستند.',
            'storage_path_invalid' => 'مسیر storage معتبر نیست.',
            'storage_unavailable' => 'مسیر storage قابل نوشتن نیست.',
            'database_not_mariadb' => 'سرور دیتابیس MariaDB نیست.',
            'database_input_invalid' => 'اطلاعات دیتابیس کامل یا معتبر نیست.',
            'config_write_failed' => 'نوشتن config.php انجام نشد.',
            'final_health_failed' => 'بررسی نهایی سلامت Public Edge کامل نشد.',
        ];
        $error = $map[$e->getMessage()] ?? 'راه‌اندازی کامل نشد. اطلاعات دیتابیس و دسترسی فایل‌ها را بررسی کنید.';
    }
}
$installed = $service->installed();
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>راه‌اندازی Public Edge سکنا</title>
<style>
body{font-family:system-ui,sans-serif;background:#f6f4ef;color:#25231f;margin:0}.wrap{max-width:820px;margin:32px auto;padding:0 18px}.card{background:#fff;border:1px solid #dedbd2;border-radius:16px;padding:20px;margin:14px 0;box-shadow:0 2px 10px #0000000c}h1,h2{margin-top:0}label{display:block;margin:12px 0 5px;font-weight:600}input{box-sizing:border-box;width:100%;padding:11px;border:1px solid #bbb;border-radius:9px;font:inherit;direction:ltr}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.check{display:flex;gap:8px;align-items:center;font-weight:400}.check input{width:auto}.btn{border:0;border-radius:9px;padding:11px 18px;font:inherit;cursor:pointer;background:#25231f;color:#fff}.ok{background:#e9f7ee;border-color:#acd7bb}.err{background:#fff0f0;border-color:#e1b0b0}.code{display:block;direction:ltr;text-align:left;word-break:break-all;font:700 16px ui-monospace,monospace;background:#f2f2f2;padding:14px;border-radius:10px}.muted{color:#666}@media(max-width:650px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body><main class="wrap">
<section class="card"><h1>راه‌اندازی Public Edge سکنا</h1><p>این مرحله دیتابیس Public را آماده می‌کند و در پایان یک کد یک‌بارمصرف برای اتصال به Local Web می‌دهد.</p></section>

<?php if ($error !== ''): ?><section class="card err"><strong><?=h($error)?></strong></section><?php endif; ?>

<?php if (is_array($result)): ?>
<section class="card ok">
<h2>راه‌اندازی کامل شد</h2>
<p>این کد را همین حالا در Local Web، بخش «وضعیت سیستم ← اتصال وب عمومی» وارد کنید. کد فقط یک‌بار مصرف می‌شود و حدود ۳۰ دقیقه اعتبار دارد.</p>
<code class="code"><?=h((string)$result['pairing_code'])?></code>
<p class="muted">بعد از اتصال موفق، این صفحه دیگر کد را نمایش نمی‌دهد.</p>
</section>
<?php elseif ($installed): ?>
<section class="card ok"><h2>Public Edge راه‌اندازی شده است</h2><p>برای ادامه از Local Web استفاده کنید. اگر کد اتصال اولیه را از دست داده‌اید، از این صفحه کد تازه‌ای صادر نمی‌شود تا سطح setup عمومی قابل سوءاستفاده نباشد.</p></section>
<?php else: ?>
<section class="card"><h2>پیش‌نیازها</h2>
<?php foreach ($preflight as $key=>$ok): ?><div><?= $ok ? '✓' : '✕' ?> <?=h((string)$key)?></div><?php endforeach; ?>
</section>
<section class="card"><form method="post" autocomplete="off">
<h2>دیتابیس و Storage</h2>
<div class="grid">
<div><label>DB Host</label><input name="db_host" value="<?=h((string)($_POST['db_host']??'127.0.0.1'))?>" required></div>
<div><label>DB Port</label><input name="db_port" value="<?=h((string)($_POST['db_port']??'3306'))?>" required></div>
<div><label>Database</label><input name="db_name" value="<?=h((string)($_POST['db_name']??'sokna_public'))?>" required></div>
<div><label>DB User</label><input name="db_user" value="<?=h((string)($_POST['db_user']??''))?>" required></div>
</div>
<label>DB Password</label><input type="password" name="db_pass" required>
<label>Storage absolute path</label><input name="storage_dir" value="<?=h((string)($_POST['storage_dir']??($root.'/storage')))?>" required>
<label class="check"><input type="checkbox" name="create_database" value="1" <?=isset($_POST['create_database'])?'checked':''?>>اگر دیتابیس وجود ندارد، ایجاد شود</label>
<input type="hidden" name="cookie_secure" value="1">
<p class="muted">روی hosting واقعی Public باید HTTPS فعال باشد.</p>
<button class="btn" type="submit">راه‌اندازی Public Edge</button>
</form></section>
<?php endif; ?>
</main></body></html>
