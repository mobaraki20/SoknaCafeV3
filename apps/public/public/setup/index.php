<?php
declare(strict_types=1);

use Sokna\PublicEdge\Setup\PublicSetupService;

if(function_exists('header_remove'))header_remove('X-Powered-By');
$root=dirname(__DIR__,2);
require_once $root.'/bootstrap.php';
$configPath=trim((string)(getenv('SOKNA_PUBLIC_CONFIG')?:($root.'/config.php')));

$trustProxy=(string)(getenv('SOKNA_PUBLIC_TRUST_PROXY_HEADERS')?:'')==='1';
$forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));
$serverSecure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')
    || strtolower((string)($_SERVER['REQUEST_SCHEME']??''))==='https'
    || (int)($_SERVER['SERVER_PORT']??0)===443;
$secure=$serverSecure||($trustProxy&&$forwarded==='https');
session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict','path'=>'/setup/']);
session_start();
if(!isset($_SESSION['sokna_public_setup_csrf']))$_SESSION['sokna_public_setup_csrf']=bin2hex(random_bytes(24));

$setup=new PublicSetupService($root,$configPath);
$status=$setup->status();
if(($status['installed']??false)===true&&($status['paired']??false)===true){
    unset($_SESSION['sokna_public_setup_pairing_code']);
}

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>راه‌اندازی Public Edge سکنا</title>
<link rel="stylesheet" href="/setup/setup.css">
</head>
<body>
<main class="setup" data-public-setup>
  <section class="card hero">
    <span class="badge">SOKNA Public Edge</span>
    <h1>راه‌اندازی وب عمومی</h1>
    <p>فایل‌ها را روی هاست Extract کن، Document Root را روی پوشه <code>public</code> بگذار و همین صفحه را باز کن. بقیه مراحل از اینجا انجام می‌شود.</p>
  </section>

  <div class="alert" data-message hidden></div>

  <section class="card">
    <h2>۱. بررسی هاست</h2>
    <div class="checks" data-preflight></div>
    <label class="field">
      <span>Storage</span>
      <input data-storage dir="ltr" autocomplete="off" placeholder="مسیر پوشه storage">
      <small>پیش‌فرض داخل ریشه Public Edge و خارج از Document Root است.</small>
    </label>
    <button type="button" data-action="preflight" class="button secondary">بررسی دوباره</button>
  </section>

  <section class="card">
    <h2>۲. دیتابیس</h2>
    <div class="grid">
      <label class="field"><span>Host</span><input data-db="host" dir="ltr" value="127.0.0.1"></label>
      <label class="field"><span>Port</span><input data-db="port" dir="ltr" inputmode="numeric" value="3306"></label>
      <label class="field"><span>Database</span><input data-db="name" dir="ltr" value="sokna_public"></label>
      <label class="field"><span>User</span><input data-db="user" dir="ltr" autocomplete="username"></label>
      <label class="field span"><span>Password</span><input data-db="pass" dir="ltr" type="password" autocomplete="new-password"></label>
    </div>
    <label class="check"><input type="checkbox" data-create-db> اگر هاست اجازه می‌دهد و دیتابیس وجود ندارد، آن را بساز</label>
    <button type="button" data-action="test-db" class="button secondary">تست اتصال دیتابیس</button>
    <p class="result" data-db-result></p>
  </section>

  <section class="card">
    <h2>۳. نصب</h2>
    <p>در این مرحله config امن ساخته می‌شود، migrationهای Public به‌ترتیب اجرا می‌شوند، Storage بررسی می‌شود و Setup قفل می‌شود.</p>
    <div class="actions">
      <button type="button" data-action="install" class="button">نصب Public Edge</button>
      <button type="button" data-action="resume" class="button secondary" hidden>ادامه نصب نیمه‌تمام</button>
    </div>
  </section>

  <section class="card pairing" data-pairing hidden>
    <h2>۴. اتصال به Local Web</h2>
    <p>در Local Web برو به <strong>وضعیت سیستم ← اتصال وب عمومی</strong>. آدرس و کد زیر را وارد کن.</p>
    <label class="field"><span>Public URL</span><input data-public-url dir="ltr" readonly></label>
    <div class="secret">
      <span>Pairing Code</span>
      <code data-pairing-code></code>
      <button type="button" class="button secondary" data-copy-code>کپی کد</button>
    </div>
    <p class="warning">این کد فقط برای اتصال اولیه است و پس از Pair شدن دیگر پذیرفته نمی‌شود.</p>
  </section>

  <section class="card" data-done hidden>
    <h2>آماده است</h2>
    <p>Public Edge نصب و به Local متصل شده است.</p>
    <a class="button link" href="/menu">باز کردن منو</a>
  </section>
</main>
<script src="/setup/setup.js" defer></script>
</body>
</html>
