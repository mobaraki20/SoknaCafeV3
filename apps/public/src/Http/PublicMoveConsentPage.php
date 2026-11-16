<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use InvalidArgumentException;

/**
 * A very small Public owner screen for R259. Never renders keys, passwords
 * or installer claims; all actions are authenticated same-origin HTTP.
 */
final class PublicMoveConsentPage
{
    public static function render(string $baseUrl): array
    {
        $parts=parse_url($baseUrl);
        if (!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])) {
            throw new InvalidArgumentException('invalid_public_move_base_url');
        }
        $base=rtrim($baseUrl,'/');
        $safe=htmlspecialchars($base,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html=<<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>تأیید انتقال وب عمومی</title>
<link rel="stylesheet" href="{$safe}/assets/sokna-public-move.css">
</head>
<body data-move-owner-page data-public-base="{$safe}">
<main class="shell">
  <header><h1>تأیید انتقال وب عمومی</h1>
  <p>این درخواست از سامانهٔ مدیریت کافه آغاز شده است. ابتدا با رمز مدیر همین وب عمومی وارد شوید.</p></header>
  <div class="status" data-status role="status" aria-live="polite">در حال بررسی درخواست…</div>
  <section data-login-section hidden>
    <label for="owner-password">رمز مدیر وب عمومی</label>
    <input id="owner-password" data-password type="password" autocomplete="current-password">
    <button type="button" data-owner-login>ورود مدیر</button>
    <p class="note">این رمز فقط برای تأیید مالکیت مقصد است؛ رمز Local یا کد اتصال نیست.</p>
  </section>
  <section data-approve-section hidden>
    <p>آیا انتقال وب عمومی به این نصب را تأیید می‌کنید؟</p>
    <button type="button" data-owner-approve>تأیید مالکیت مقصد</button>
  </section>
  <details data-owner-upgrade>
    <summary>اگر قبلاً رمز مدیریت وب عمومی نساخته‌اید</summary>
    <p>برای نصب موجود، ابتدا از <a href="{$safe}/staff/login" target="_blank" rel="noopener">ورود کارکنان</a> با حساب مدیر وارد شوید؛ سپس اینجا روی «آماده‌سازی رمز» بزنید.</p>
    <button type="button" class="secondary" data-upgrade-check>آماده‌سازی رمز مدیر وب عمومی</button>
    <div data-upgrade-form hidden>
      <label for="new-owner-password">رمز جدید مدیر وب عمومی (حداقل ۱۲ نویسه)</label>
      <input id="new-owner-password" data-upgrade-password type="password" autocomplete="new-password" minlength="12">
      <button type="button" data-upgrade-create>ثبت رمز مدیریت</button>
    </div>
  </details>
</main>
<script src="{$safe}/assets/sokna-public-move.js" defer></script>
</body>
</html>
HTML;
        return [
            'status'=>200,
            'headers'=>[
                'Content-Type'=>'text/html; charset=utf-8',
                'Cache-Control'=>'no-store',
                'Pragma'=>'no-cache',
                'Referrer-Policy'=>'no-referrer',
                'X-Content-Type-Options'=>'nosniff',
                'X-Frame-Options'=>'DENY',
                'Content-Security-Policy'=>"default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'"
            ],
            'body'=>$html
        ];
    }
}
