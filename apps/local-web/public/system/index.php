<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
use Sokna\Local\UI\LocalUrl;
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
    <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button><a class="sc-button sc-button--secondary" href="<?= htmlspecialchars(LocalUrl::path('/integrations/'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">مدیریت اتصال‌ها</a><a class="sc-button sc-button--secondary" href="<?= htmlspecialchars(LocalUrl::path('/printing/'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">مدیریت چاپ</a><button class="sc-button sc-button--secondary" type="button" data-support data-page-action aria-label="دریافت بسته پشتیبانی">دریافت بسته پشتیبانی</button></div>
  </div>
  <div class="sc-alert" role="status" aria-live="polite" data-status hidden></div>
  <div class="sc-alert sc-alert--info" role="status" aria-live="polite" data-support-download hidden></div>

  <div class="sc-system-health-stack" data-panel="health">
    <section class="sc-data-panel sc-system-health-panel"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>وضعیت کلی</h2><p data-health-summary-copy>بخش‌های مستقل سامانه در حال بررسی‌اند؛ نتیجه هر بخش به‌محض آماده‌شدن نمایش داده می‌شود.</p></div><span class="sc-badge" data-overall>در حال بررسی</span></div>
      <small class="sc-muted" data-health-probe-state>۰ از ۴ بررسی آماده است.</small>
      <div class="sc-readiness-strip" data-health-dimensions aria-label="لایه‌های آمادگی سامانه">
        <article class="sc-readiness-item"><span>دردسترس‌بودن سامانه</span><strong>در حال بررسی</strong><small>هسته محلی و دیتابیس</small></article>
        <article class="sc-readiness-item"><span>آمادگی وابستگی‌ها</span><strong>در حال بررسی</strong><small>سرویس‌های دستگاه، زمان و بازیابی</small></article>
        <article class="sc-readiness-item"><span>قابل‌اجرا بودن گردش‌کارها</span><strong>در حال بررسی</strong><small>وب عمومی و چاپ</small></article>
      </div>
      <div class="sc-system-action-list" data-health-issues><div class="sc-empty">هنوز مورد نیازمند اقدام پیدا نشده است.</div></div>
    </div></section>
    <details class="sc-data-panel sc-system-technical" data-health-technical>
      <summary class="sc-disclosure__summary">جزئیات فنی و بررسی‌های سالم</summary>
      <div class="sc-card__body sc-stack"><div class="sc-maintenance-grid" data-components></div><div class="sc-card-grid" data-checks></div></div>
    </details>
  </div>

  <div class="sc-stack" data-panel="updates" hidden>
    <div class="sc-alert sc-alert--info" role="status" aria-live="polite" data-unified-updater-readiness>در حال بررسی وضعیت نصب امن…</div>
    <div class="sc-system-split-grid">
    <section class="sc-card sc-system-operation-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>به‌روزرسانی سامانه محلی</h2><p>فایل به‌روزرسانی را انتخاب کن؛ سامانه آن را قطعه‌قطعه بارگذاری و قبل از نصب کامل بررسی می‌کند.</p></div><span class="sc-badge sc-version-badge" data-local-current-version aria-label="نسخه نصب‌شده">—</span></div>
      <form data-update-upload class="sc-stack" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><div class="sc-field"><label class="sc-field__label" for="sc-local-update-package">فایل به‌روزرسانی</label><div class="sc-file-picker" data-file-picker><input class="sc-file-picker__input" id="sc-local-update-package" type="file" name="package" accept=".zip,application/zip" required><button class="sc-button sc-button--secondary" type="button" data-file-trigger aria-controls="sc-local-update-package">انتخاب فایل</button><span class="sc-file-picker__name" data-file-name aria-live="polite">فایلی انتخاب نشده است</span></div></div><div class="sc-actions"><button class="sc-button" type="submit">بررسی فایل</button></div></form>
      <form data-maintenance-upload class="sc-stack" enctype="multipart/form-data" hidden>
        <div class="sc-field"><label class="sc-field__label" for="sc-maintenance-release-set">بسته ZIP به‌روزرسانی سکنا برای بررسی و آماده‌سازی</label>
          <div class="sc-file-picker" data-file-picker>
            <input class="sc-file-picker__input" type="file" id="sc-maintenance-release-set" name="release_set" accept=".zip,application/zip" disabled required>
            <button class="sc-button sc-button--secondary" type="button" data-file-trigger aria-controls="sc-maintenance-release-set" disabled>انتخاب فایل</button>
            <span class="sc-file-picker__name" data-file-name aria-live="polite">فایلی انتخاب نشده است</span>
          </div>
        </div>
        <div class="sc-actions"><button class="sc-button sc-button--secondary" type="submit" disabled>انتقال امن و پیش‌بررسی</button>
          <button class="sc-button" type="button" data-maintenance-activate disabled>نصب نسخه تأییدشده</button></div>
      </form>
      <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-maintenance-connection-check>آزمایش اتصال Maintenance</button></div>
      <div class="sc-alert sc-alert--info" role="status" aria-live="polite" data-maintenance-connection-result hidden></div>
      <div class="sc-alert sc-alert--info" role="status" aria-live="polite" data-maintenance-upload-message hidden></div>
      <div class="sc-alert sc-event-feedback sc-alert--info" role="status" aria-live="polite" data-local-update-feedback hidden></div>
      <div class="sc-stack" data-maintenance-progress hidden aria-live="polite">
        <strong>وضعیت آخرین عملیات Maintenance</strong>
        <div class="sc-alert sc-alert--info" data-maintenance-progress-message>در حال بررسی وضعیت ثبت‌شده…</div>
        <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-maintenance-progress-refresh>بررسی مجدد وضعیت</button></div>
      </div>
      <div class="sc-alert sc-alert--danger sc-stack" role="alert" data-local-update-last-failure hidden></div>
      <div class="sc-list-item" data-staged>فایلی برای نصب آماده نشده است.</div>
      <div class="sc-update-recovery-state" data-local-rollback-state><strong>امکان بازگشت</strong><span>در حال بررسی آخرین وضعیت سالم…</span></div>
      <div class="sc-actions" data-legacy-local-actions><button class="sc-button" type="button" data-activate>نصب به‌روزرسانی</button><button class="sc-button sc-button--secondary" type="button" data-repair>ترمیم نصب فعلی</button><button class="sc-button sc-button--secondary" type="button" data-discard-staged>لغو بسته آماده‌شده</button><button class="sc-button sc-button--danger" type="button" data-rollback>بازگشت به آخرین وضعیت سالم</button></div>
    </div></section>

    <section class="sc-card sc-system-operation-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>به‌روزرسانی وب عمومی</h2><p>فایل وب عمومی را انتخاب کن و عملیات لازم را از همین‌جا انجام بده.</p></div><span class="sc-badge sc-version-badge" data-public-current-version aria-label="نسخه نصب‌شده وب عمومی">—</span></div>
      <form data-public-update-upload class="sc-stack" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><div class="sc-field"><label class="sc-field__label" for="sc-public-update-package">فایل به‌روزرسانی وب عمومی</label><div class="sc-file-picker" data-file-picker><input class="sc-file-picker__input" id="sc-public-update-package" type="file" name="package" accept=".zip,application/zip" required><button class="sc-button sc-button--secondary" type="button" data-file-trigger aria-controls="sc-public-update-package">انتخاب فایل</button><span class="sc-file-picker__name" data-file-name aria-live="polite">فایلی انتخاب نشده است</span></div></div><div class="sc-actions"><button class="sc-button" type="submit">بررسی فایل</button></div></form>
      <div class="sc-alert sc-event-feedback sc-alert--info" role="status" aria-live="polite" data-public-update-feedback hidden></div>
      <div class="sc-list-item" data-public-staged>فایلی برای نصب آماده نشده است.</div>
      <div class="sc-update-recovery-state" data-public-rollback-state><strong>امکان بازگشت وب عمومی</strong><span>در حال بررسی آخرین وضعیت سالم…</span></div>
      <div class="sc-actions"><button class="sc-button" type="button" data-public-activate>نصب به‌روزرسانی</button><button class="sc-button sc-button--secondary" type="button" data-public-repair>ترمیم وب عمومی</button><button class="sc-button sc-button--danger" type="button" data-public-rollback>بازگشت به آخرین وضعیت سالم</button></div>
      <details class="sc-disclosure sc-system-emergency-disclosure"><summary><span>دسترسی اضطراری وب عمومی</span></summary><div class="sc-stack sc-details-body"><p class="sc-muted">این بخش فقط زمانی استفاده می‌شود که دسترسی عادی به وب عمومی ممکن نباشد.</p><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-public-emergency-code>ساخت کد دسترسی اضطراری</button><a class="sc-button sc-button--secondary" data-public-emergency-link aria-disabled="true" tabindex="-1" target="_blank" rel="noopener">باز کردن صفحه اضطراری</a></div><div class="sc-alert sc-alert--warning sc-hidden sc-secret" data-public-emergency-secret></div></div></details>
    </div></section>
  </div>

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
      <form data-recovery-upload class="sc-stack" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><div class="sc-field"><label class="sc-field__label" for="sc-recovery-backup-file">فایل پشتیبان</label><div class="sc-file-picker" data-file-picker><input class="sc-file-picker__input" id="sc-recovery-backup-file" type="file" name="backup" required><button class="sc-button sc-button--secondary" type="button" data-file-trigger aria-controls="sc-recovery-backup-file">انتخاب فایل</button><span class="sc-file-picker__name" data-file-name aria-live="polite">فایلی انتخاب نشده است</span></div></div><div class="sc-actions"><button class="sc-button sc-button--secondary" type="submit">بارگذاری فایل پشتیبان</button></div></form>
      <input type="hidden" data-restore-id>
      <label class="sc-field"><span class="sc-field__label">رمز فایل پشتیبان</span><input class="sc-control" type="password" data-restore-pass autocomplete="off"></label>
      <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-backup-inspect>بررسی فایل</button><button class="sc-button sc-button--danger" type="button" data-backup-restore>بازیابی اطلاعات</button></div>
      <div class="sc-alert sc-alert--info sc-hidden" data-backup-manifest></div>
    </div></section>
  </div>
</section>
<script src="<?=ProductShell::asset('assets/system-diagnostics.js')?>" defer></script>
<?php ProductShell::end(); ?>
