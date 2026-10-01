<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAdmin($core);
$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'پشتیبانی و نگهداری','system','سلامت سامانه، به‌روزرسانی و پشتیبان‌گیری در یک فضای ساده و قابل‌فهم.',true);
?>
<section class="sc-workspace" data-system-workspace data-api="system/api.php" data-update-api="system/update-chunk.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-toolbar sc-local-nav">
    <div class="sc-tabs" role="tablist" aria-label="بخش‌های نگهداری">
      <button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="health">سلامت سامانه</button>
      <button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="updates">به‌روزرسانی</button>
      <button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="recovery">پشتیبان‌گیری</button>
    </div>
    <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button><button class="sc-button sc-button--secondary" type="button" data-public-sync>همگام‌سازی وب عمومی</button><button class="sc-button" type="button" data-support>دریافت بسته پشتیبانی</button></div>
  </div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال بررسی وضعیت سامانه…</div>

  <div class="sc-system-health-stack" data-panel="health">
    <section class="sc-data-panel sc-system-health-panel"><div class="sc-card__body">
      <div class="sc-section-head"><div><h2>وضعیت کلی</h2><p>اگر بخشی نیاز به توجه داشته باشد، همین‌جا با توضیح قابل‌فهم نمایش داده می‌شود.</p></div><span class="sc-badge" data-overall>—</span></div>
      <div class="sc-maintenance-grid" data-components></div>
    </div></section>
    <section class="sc-card sc-system-pair-card" data-public-pair-card><form class="sc-card__body sc-stack" data-public-pair-form novalidate>
      <div class="sc-section-head"><div><h2>اتصال وب عمومی</h2><p>پس از نصب Public Edge، آدرس و کد اتصال یک‌بارمصرف را اینجا وارد کن.</p></div></div>
      <div class="sc-form-grid sc-form-grid--2">
        <label class="sc-field"><span class="sc-field__label">آدرس وب عمومی</span><input class="sc-control" type="url" data-public-pair-url placeholder="https://example.com" dir="ltr" required></label>
        <label class="sc-field"><span class="sc-field__label">کد اتصال</span><input class="sc-control" data-public-pair-code autocomplete="off" dir="ltr" required></label>
      </div>
      <label class="sc-field"><span class="sc-field__label">نام این نصب</span><input class="sc-control" data-public-pair-name value="کافه سکنا"></label>
      <div class="sc-actions"><button class="sc-button" type="submit" data-public-pair>اتصال وب عمومی</button></div>
      <small class="sc-help" data-public-pair-state>پس از اتصال، همگام‌سازی اولیه به‌صورت جداگانه بررسی می‌شود.</small>
    </form></section>
    <section class="sc-card sc-system-pair-card" data-windows-pair-card><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>اتصال سرویس‌های ویندوز</h2><p>برای Runtime و Print Agent یک کد کوتاه‌عمر بساز و همان کد را در برنامه نصب Windows Services وارد کن. نیازی به ساخت یا دانلود فایل JSON نیست.</p></div><span class="sc-badge" data-windows-pair-status>—</span></div>
      <div class="sc-summary-grid">
        <div class="sc-metric"><span>آدرس Local Web</span><strong data-windows-pair-url dir="ltr">—</strong></div>
        <div class="sc-metric"><span>اعتبار کد</span><strong data-windows-pair-expiry>—</strong></div>
      </div>
      <label class="sc-field"><span class="sc-field__label">نام این دستگاه</span><input class="sc-control" data-windows-pair-name value="دستگاه اصلی سکنا" maxlength="120"><small class="sc-help">این نام برای شناسایی Print Agent در پنل استفاده می‌شود.</small></label>
      <div class="sc-actions"><button class="sc-button" type="button" data-windows-pair-create>ساخت کد اتصال</button><button class="sc-button sc-button--secondary" type="button" data-windows-pair-cancel>لغو کد فعال</button></div>
      <div class="sc-alert sc-alert--warning sc-secret" data-windows-pair-secret hidden>
        <strong>کد اتصال — فقط همین حالا در برنامه نصب وارد کن:</strong>
        <code data-windows-pair-code dir="ltr"></code>
        <button class="sc-button sc-button--secondary" type="button" data-windows-pair-copy>کپی کد</button>
      </div>
      <small class="sc-help" data-windows-pair-help>کد حدود ۱۰ دقیقه اعتبار دارد و پس از اتصال موفق مصرف می‌شود. Secretهای Runtime و Print Agent در صفحه یا فایل دانلودی نمایش داده نمی‌شوند.</small>
    </div></section>
    <section class="sc-data-panel sc-system-checks-panel"><div class="sc-card__body"><div class="sc-section-head"><div><h2>بررسی‌های ضروری</h2><p>مواردی که برای کار عادی سامانه لازم‌اند.</p></div></div><div class="sc-card-grid" data-checks></div></div></section>
  </div>

  <div class="sc-system-split-grid" data-panel="updates" hidden>
    <section class="sc-card sc-system-operation-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>به‌روزرسانی سامانه محلی</h2><p>فایل به‌روزرسانی را انتخاب کن؛ سامانه آن را قطعه‌قطعه بارگذاری و قبل از نصب کامل بررسی می‌کند.</p></div><span class="sc-badge sc-version-badge" data-local-current-version aria-label="نسخه نصب‌شده">—</span></div>
      <form data-update-upload class="sc-stack" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><label class="sc-field"><span class="sc-field__label">فایل به‌روزرسانی</span><input class="sc-control" type="file" name="package" accept=".zip,application/zip" required></label><div class="sc-actions"><button class="sc-button" type="submit">بررسی فایل</button></div></form>
      <div class="sc-alert sc-event-feedback sc-alert--info" role="status" aria-live="polite" data-local-update-feedback hidden></div>
      <div class="sc-list-item" data-staged>فایلی برای نصب آماده نشده است.</div>
      <div class="sc-actions"><button class="sc-button" type="button" data-activate>نصب به‌روزرسانی</button><button class="sc-button sc-button--secondary" type="button" data-repair>ترمیم نصب فعلی</button><button class="sc-button sc-button--danger" type="button" data-rollback>بازگشت به آخرین وضعیت سالم</button></div>
    </div></section>

    <section class="sc-card sc-system-operation-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>به‌روزرسانی وب عمومی</h2><p>فایل وب عمومی را انتخاب کن و عملیات لازم را از همین‌جا انجام بده.</p></div><span class="sc-badge sc-version-badge" data-public-current-version aria-label="نسخه نصب‌شده وب عمومی">—</span></div>
      <form data-public-update-upload class="sc-stack" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><label class="sc-field"><span class="sc-field__label">فایل به‌روزرسانی وب عمومی</span><input class="sc-control" type="file" name="package" accept=".zip,application/zip" required></label><div class="sc-actions"><button class="sc-button" type="submit">بررسی فایل</button></div></form>
      <div class="sc-alert sc-event-feedback sc-alert--info" role="status" aria-live="polite" data-public-update-feedback hidden></div>
      <div class="sc-list-item" data-public-staged>فایلی برای نصب آماده نشده است.</div>
      <div class="sc-actions"><button class="sc-button" type="button" data-public-activate>نصب به‌روزرسانی</button><button class="sc-button sc-button--secondary" type="button" data-public-repair>ترمیم وب عمومی</button><button class="sc-button sc-button--danger" type="button" data-public-rollback>بازگشت به آخرین وضعیت سالم</button></div>
      <details class="sc-disclosure"><summary>دسترسی اضطراری وب عمومی</summary><div class="sc-stack sc-details-body"><p class="sc-muted">این بخش فقط زمانی استفاده می‌شود که دسترسی عادی به وب عمومی ممکن نباشد.</p><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-public-emergency-code>ساخت کد دسترسی اضطراری</button><a class="sc-button sc-button--secondary" data-public-emergency-link aria-disabled="true" tabindex="-1" target="_blank" rel="noopener">باز کردن صفحه اضطراری</a></div><div class="sc-alert sc-alert--warning sc-hidden sc-secret" data-public-emergency-secret></div></div></details>
    </div></section>
  </div>

  <div class="sc-system-recovery-grid" data-panel="recovery" hidden>
    <section class="sc-card sc-system-operation-card sc-system-backup-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>ساخت نسخه پشتیبان</h2><p>اطلاعات کسب‌وکار در یک فایل رمزگذاری‌شده ذخیره می‌شود.</p></div></div>
      <label class="sc-field"><span class="sc-field__label">رمز فایل پشتیبان</span><input class="sc-control" type="password" minlength="12" data-backup-pass autocomplete="new-password"><small class="sc-help">حداقل ۱۲ نویسه؛ این رمز برای بازیابی لازم است.</small></label>
      <div class="sc-actions"><button class="sc-button" type="button" data-backup-create>ساخت و دانلود نسخه پشتیبان</button></div>
      <div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>نسخه پشتیبان</th><th>حجم</th><th>عمل</th></tr></thead><tbody data-backups></tbody></table></div>
    </div></section>

    <section class="sc-card sc-system-operation-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>بازیابی از فایل پشتیبان</h2><p>برای جلوگیری از بازنویسی اطلاعات، بازیابی فقط روی نصب خالی انجام می‌شود.</p></div></div>
      <form data-recovery-upload class="sc-stack" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><label class="sc-field"><span class="sc-field__label">فایل پشتیبان</span><input class="sc-control" type="file" name="backup" required></label><div class="sc-actions"><button class="sc-button sc-button--secondary" type="submit">انتخاب فایل برای بازیابی</button></div></form>
      <input type="hidden" data-restore-id>
      <label class="sc-field"><span class="sc-field__label">رمز فایل پشتیبان</span><input class="sc-control" type="password" data-restore-pass autocomplete="off"></label>
      <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-backup-inspect>بررسی فایل</button><button class="sc-button sc-button--danger" type="button" data-backup-restore>بازیابی اطلاعات</button></div>
      <div class="sc-alert sc-alert--info sc-hidden" data-backup-manifest></div>
    </div></section>

    <section class="sc-card sc-system-operation-card sc-system-transfer-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>انتقال به دستگاه جدید</h2><p>اگر سامانه را روی دستگاه دیگری بازیابی کرده‌ای، اتصال وب عمومی را از این بخش دوباره برقرار کن.</p></div></div>
      <label class="sc-field"><span class="sc-field__label">آدرس وب عمومی</span><input class="sc-control" type="url" data-reenroll-url placeholder="https://example.com"></label>
      <label class="sc-field"><span class="sc-field__label">کد انتقال</span><input class="sc-control" data-reenroll-code autocomplete="off" dir="ltr"></label>
      <label class="sc-field"><span class="sc-field__label">نام این دستگاه</span><input class="sc-control" data-reenroll-name placeholder="مثلاً صندوق اصلی"></label>
      <div class="sc-actions"><button class="sc-button sc-button--danger" type="button" data-public-reenroll>تکمیل انتقال</button></div>
    </div></section>
  </div>
</section>
<script src="<?=ProductShell::asset('assets/system-diagnostics.js')?>" defer></script>
<?php ProductShell::end(); ?>
