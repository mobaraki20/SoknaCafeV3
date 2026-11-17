<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'اتصال‌ها','integrations','وضعیت و راه‌اندازی اتصال‌های بیرونی سامانه را از یک مرکز مدیریت کن.',true);
?>
<section class="sc-workspace" data-connections-workspace data-api="integrations/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-toolbar sc-local-nav"><div class="sc-tabs" role="tablist" aria-label="اتصال‌ها"><button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="accommodation">اقامتگاه</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="public">وب عمومی</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="windows">سرویس‌های ویندوز</button></div><button class="sc-button sc-button--secondary sc-refresh-action" type="button" data-refresh aria-label="بازخوانی اطلاعات" title="بازخوانی اطلاعات"><svg class="sc-ui-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 11a8 8 0 0 0-14.1-5.2L4 8m0-5v5h5M4 13a8 8 0 0 0 14.1 5.2L20 16m0 5v-5h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div>
  <div class="sc-visually-hidden" role="status" aria-live="polite" data-status hidden></div>

  <section class="sc-work-panel" data-panel="accommodation">
    <div class="sc-section-head"><div><h2>اتصال اقامتگاه</h2><p>اینجا فقط راه‌اندازی اتصال انجام می‌شود؛ انتقال حساب و تاریخچه مالی در بخش «مالی» قرار دارد.</p></div><span class="sc-badge" data-accommodation-badge>—</span></div>
    <article class="sc-card"><form class="sc-card__body sc-form" data-accommodation-form novalidate>
      <label class="sc-inline"><input type="checkbox" data-accommodation-enabled> اتصال اقامتگاه فعال باشد</label>
      <label class="sc-field"><span class="sc-field__label">آدرس سرویس اقامتگاه</span><input class="sc-control" type="url" data-accommodation-url placeholder="https://stay.example.com" dir="ltr"><small class="sc-help">برای سرور بیرونی، HTTPS الزامی است.</small></label>
      <label class="sc-field"><span class="sc-field__label">کلید اتصال</span><input class="sc-control" type="password" data-accommodation-key autocomplete="new-password" dir="ltr" placeholder="برای حفظ کلید فعلی خالی بگذار"><small class="sc-help" data-accommodation-key-state>کلیدی ذخیره نشده است.</small><small class="sc-help sc-canonical-id" data-accommodation-fingerprint hidden></small></label>
      <div class="sc-actions"><button class="sc-button" type="submit">ذخیره تنظیمات اتصال</button><button class="sc-button sc-button--secondary" type="button" data-accommodation-test>آزمایش اتصال</button></div>
      <div class="sc-event-feedback sc-alert sc-alert--danger" data-accommodation-feedback hidden></div>
    </form></article>
  </section>

  <section class="sc-work-panel" data-panel="public" hidden>
    <div class="sc-section-head"><div><h2>وب عمومی</h2><p>اتصال مهمان و منوی عمومی به سامانه داخلی از اینجا راه‌اندازی و بررسی می‌شود.</p></div><span class="sc-badge" data-public-badge>—</span></div>
    <div class="sc-summary-grid"><article class="sc-card sc-metric"><span>آدرس وب عمومی</span><strong data-public-url dir="ltr">—</strong></article><article class="sc-card sc-metric"><span>آخرین همگام‌سازی</span><strong data-public-sync-at>—</strong></article><article class="sc-card sc-metric"><span>نسخه وب عمومی</span><strong data-public-version>—</strong></article></div>
    <article class="sc-card" data-public-move-surface><form class="sc-card__body sc-form" data-public-move-form novalidate>
      <div class="sc-section-head"><div><h3>انتقال وب عمومی</h3><p>آدرس مقصد را وارد کنید. سامانه نوع اتصال را بررسی می‌کند؛ اطلاعات و اتصال فعلی تا تأیید نهایی حفظ می‌شوند.</p></div></div>
      <label class="sc-field"><span class="sc-field__label">آدرس جدید وب عمومی</span><input class="sc-control" type="url" data-public-move-target placeholder="https://example.com/public" dir="ltr" autocomplete="url" required><small class="sc-help">آدرس می‌تواند در ریشهٔ دامنه یا داخل یک پوشه باشد.</small></label>
      <div class="sc-actions"><button class="sc-button" type="submit" data-public-move-check>بررسی و انتقال</button><button class="sc-button sc-button--secondary" type="button" data-public-move-resume hidden>ادامهٔ اتصال</button><button class="sc-button sc-button--secondary" type="button" data-public-move-cancel hidden>لغو درخواست</button></div>
      <div class="sc-event-feedback sc-alert" data-public-move-feedback role="status" aria-live="polite" hidden></div>
      <div class="sc-stack sc-alert sc-alert--warning" data-public-existing-panel hidden>
        <strong>وب عمومی مقصد از قبل نصب و متصل شده است</strong>
        <p>نیازی به نصب مجدد یا حذف اطلاعات نیست. مدیر همین Public از کنسول امن یک کد یک‌بارمصرف می‌گیرد و اینجا وارد می‌کند. اتصال قبلی Local تا تأیید رمز جدید حفظ می‌شود.</p>
        <div class="sc-form-grid sc-form-grid--2">
          <label class="sc-field"><span class="sc-field__label">شناسه نصب Local</span><code dir="ltr" data-public-existing-installation>—</code></label>
          <label class="sc-field"><span class="sc-field__label">حداقل نسل جدید اتصال</span><strong data-public-existing-epoch>—</strong></label>
        </div>
        <p><a href="#" data-public-existing-console target="_blank" rel="noopener noreferrer">بازکردن تأیید امن مالک Public</a></p>
        <label class="sc-field"><span class="sc-field__label">کد یک‌بارمصرف مقصد</span>
          <input data-public-existing-code class="sc-control" autocomplete="off" dir="ltr" placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX"></label>
        <label class="sc-field"><input type="checkbox" data-public-existing-confirm>
          اطمینان دارم سفارش معلقی فقط در Public مقصد باقی نمانده و مسئولیت تغییر اتصال را تأیید می‌کنم.</label>
        <div class="sc-actions"><button class="sc-button" type="button" data-public-existing-activate>اتصال و همگام‌سازی امن</button>
        <button class="sc-button sc-button--secondary" type="button" data-public-existing-resume hidden>ادامه بازیابی امن</button>
        <button class="sc-button sc-button--secondary" type="button" data-public-existing-sync hidden>تلاش دوباره همگام‌سازی</button></div>
        <p class="sc-help">در صورت خطای بازیابی، کد تازه نسازید؛ ابتدا «ادامه بازیابی امن» را بررسی کنید. پس از کامل‌شدن Sync، سفارش‌گیری فعال می‌شود.</p>
      </div>
      <small class="sc-help">سامانه مقصد را به دو حالت نصب تازه و نصب موجود تشخیص می‌دهد؛ هیچ‌کدام نباید موجب حذف اطلاعات Public یا Local شوند.</small>
    </form></article>
    <article class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h3>وضعیت همگام‌سازی</h3><p>منو و سفارش، دسترسی کارکنان و تصاویر جداگانه بررسی می‌شوند؛ خرابی تصویر منو را از کار نمی‌اندازد.</p></div></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>بخش</th><th>وضعیت</th><th>آخرین موفقیت</th><th>جزئیات</th></tr></thead><tbody data-public-channels><tr><td colspan="4">در حال دریافت وضعیت…</td></tr></tbody></table></div></div></article>
    <article class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h3>وضعیت ارتباط</h3><p>ارسال به وب، اتصال خودکار، آخرین ارتباط و صف پاسخ هرکدام وضعیت مستقل دارند؛ موفقیت یکی به معنی سلامت همه نیست.</p></div></div><div class="sc-summary-grid"><article class="sc-card sc-metric"><span>ارسال به وب عمومی</span><strong data-public-signal="outbound">—</strong><small class="sc-help" data-public-signal-help="outbound">—</small></article><article class="sc-card sc-metric"><span>اتصال خودکار سرویس</span><strong data-public-signal="relay">—</strong><small class="sc-help" data-public-signal-help="relay">—</small></article><article class="sc-card sc-metric"><span>آخرین ارتباط با وب</span><strong data-public-signal="heartbeat">—</strong><small class="sc-help" data-public-signal-help="heartbeat">—</small></article><article class="sc-card sc-metric"><span>صف درخواست‌های ورودی</span><strong data-public-signal="queue">—</strong><small class="sc-help" data-public-signal-help="queue">—</small></article></div></div></article>
    <article class="sc-card" data-public-initial-pair-card><form class="sc-card__body sc-form" data-public-pair-form novalidate><div class="sc-section-head"><div><h3>اتصال اولیه پابلیک</h3><p>فقط برای لوکالی که تا امروز هیچ اتصال پابلیکی نداشته است. نصب تازه پابلیکِ متصل به لوکال قدیمی را از «بازیابی اتصال» انجام بده.</p></div></div><div class="sc-form-grid sc-form-grid--2"><label class="sc-field"><span class="sc-field__label">آدرس وب عمومی</span><input class="sc-control" type="url" data-public-pair-url placeholder="https://example.com" dir="ltr" required></label><label class="sc-field"><span class="sc-field__label">کد اتصال</span><input class="sc-control" data-public-pair-code autocomplete="off" dir="ltr" required></label></div><label class="sc-field"><span class="sc-field__label">نام این نصب</span><input class="sc-control" data-public-pair-name value="کافه سکنا"></label><div class="sc-actions"><button class="sc-button" type="submit" data-public-pair>ثبت اتصال</button><button class="sc-button sc-button--secondary" type="button" data-public-sync>همگام‌سازی اکنون</button><button class="sc-button sc-button--secondary" type="button" data-public-probe>آزمایش ارتباط</button></div><div class="sc-event-feedback sc-alert sc-alert--danger" data-public-feedback hidden></div><small class="sc-help" data-public-relay-detail hidden></small></form></article>
    <details class="sc-connection-advanced" data-public-recovery-advanced><summary class="sc-disclosure__summary"><strong>ابزارهای بازیابی پیشرفته</strong></summary>
    <article class="sc-card" data-public-replacement-panel><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h3>بازیابی اتصال پابلیک پس از نصب تازه</h3><p>برای زمانی که لوکال از قبل متصل بوده اما پابلیک دوباره نصب شده است. این کار تغییر آدرس یا انتقال دستگاه نیست.</p></div><span class="sc-badge sc-badge--warning">عملیات حساس</span></div>
      <div class="sc-alert sc-alert--warning">پیش از آغاز، از اطلاعات لوکال و پابلیک نسخه پشتیبان معتبر داشته باشید. پس از شروع، سفارش‌گیری پابلیک تا پایان همگام‌سازی ایمن مسدود می‌ماند.</div>
      <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-public-replacement-preflight>بررسی ایمن بازیابی</button></div>
      <div class="sc-event-feedback sc-alert sc-alert--danger" data-public-replacement-feedback hidden></div>
      <div class="sc-card sc-card__body sc-stack" data-public-replacement-details hidden>
        <p class="sc-muted">شناسه نصب لوکال و حداقل نسل بعدی را در کنسول بازیابی پابلیک وارد کن. کد یک‌بارمصرف فقط در خود سامانه وارد شود.</p>
        <div class="sc-form-grid sc-form-grid--2"><div class="sc-field"><span class="sc-field__label">شناسه نصب لوکال</span><code dir="ltr" data-public-replacement-id></code></div><div class="sc-field"><span class="sc-field__label">حداقل نسل جدید</span><strong data-public-replacement-epoch></strong></div></div>
        <p><a data-public-replacement-console href="#" rel="noopener noreferrer" target="_blank">بازکردن کنسول بازیابی امن پابلیک</a></p>
        <label class="sc-field"><span class="sc-field__label">کد بازیابی یک‌بارمصرف</span><input class="sc-control" data-public-replacement-code autocomplete="off" dir="ltr"></label>
        <label class="sc-field"><span class="sc-field__label">برای تأیید، عبارت REPLACE_PUBLIC را وارد کن</span><input class="sc-control" data-public-replacement-confirm autocomplete="off" dir="ltr"></label>
        <div class="sc-actions"><button class="sc-button" type="button" data-public-replacement-execute disabled>آغاز بازیابی کنترل‌شده</button><button class="sc-button sc-button--secondary" type="button" data-public-replacement-resume style="display:none">ادامه امن بازیابی نیمه‌تمام</button></div>
      </div>
    </div></article>
    <article class="sc-card"><div class="sc-card__body sc-form" data-public-origin-rebind>
      <div class="sc-section-head"><div><h3>تغییر آدرس وب عمومی</h3><p>اگر نشانی وب عمومی تغییر کرده است، ابتدا آدرس جدید را بررسی کن. اتصال فعلی فقط پس از تأیید هویت همان سامانه تغییر می‌کند.</p></div><span class="sc-badge" data-public-origin-transition-badge>آماده</span></div>
      <label class="sc-field"><span class="sc-field__label">آدرس جدید وب عمومی</span><input class="sc-control" type="url" data-public-origin-target placeholder="https://new.example.com" dir="ltr"><small class="sc-help">قبل از اعمال تغییر، امنیت آدرس، هویت سامانه، ورود کارکنان و اعلان‌ها بررسی می‌شود.</small></label>
      <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-public-origin-preflight>پیش‌بررسی تغییر آدرس</button><button class="sc-button" type="button" data-public-origin-execute disabled>اعمال تغییر آدرس</button></div>
      <div class="sc-event-feedback sc-alert sc-alert--danger" data-public-origin-feedback hidden></div>
      <div class="sc-alert sc-alert--warning" data-public-origin-preview hidden></div>
      <div class="sc-alert sc-alert--warning" data-public-origin-transition hidden><strong>مسیر انتقال قدیمی هنوز فعال است.</strong><div data-public-origin-transition-detail></div><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-public-origin-transition-close>بستن مسیر انتقال قدیمی</button></div></div>
    </div></article>
    </details>
    <article class="sc-card"><form class="sc-card__body sc-form" data-lan-staff-form novalidate>
      <div class="sc-section-head"><div><h3>اتصال مستقیم کارکنان در شبکه کافه</h3><p>این آدرس فقط برای اتصال کارکنان در شبکه داخلی کافه است؛ نشانی عمومی سامانه تغییر نمی‌کند.</p></div><span class="sc-badge" data-lan-staff-badge>—</span></div>
      <label class="sc-inline"><input type="checkbox" data-lan-staff-enabled> در شبکه کافه از اتصال داخلی استفاده شود و در صورت قطع آن، وب عمومی خودکار ادامه دهد</label>
      <label class="sc-field"><span class="sc-field__label">آدرس سامانه در شبکه کافه</span><input class="sc-control" type="url" data-lan-staff-url placeholder="https://local.example.com" dir="ltr"><small class="sc-help">این آدرس باید از وب عمومی جدا باشد و اتصال امن معتبر (HTTPS) داشته باشد. اگر سامانه در یک مسیر جدا نصب شده، همان مسیر را هم وارد کن.</small></label>
      <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-lan-staff-preflight>آزمایش اتصال داخلی</button><button class="sc-button" type="submit" data-lan-staff-save>بررسی و ذخیره</button></div>
      <div class="sc-event-feedback sc-alert sc-alert--danger" data-lan-staff-feedback hidden></div>
      <small class="sc-help" data-lan-staff-detail></small>
    </form></article>
    <details class="sc-card sc-connection-advanced" data-public-reenroll-disclosure><summary class="sc-disclosure__summary"><span><strong>انتقال اتصال به دستگاه جدید</strong><small>فقط بعد از بازیابی نسخه پشتیبان یا تعویض دستگاه</small></span></summary><div class="sc-card__body sc-stack"><p class="sc-muted">برای دستگاه جایگزین، کد اتصال جدید بگیر. اتصال فعلی تنها پس از تأیید شما تغییر می‌کند.</p><div class="sc-form-grid sc-form-grid--2"><label class="sc-field"><span class="sc-field__label">آدرس وب عمومی</span><input class="sc-control" type="url" data-public-reenroll-url placeholder="https://example.com" dir="ltr"></label><label class="sc-field"><span class="sc-field__label">کد اتصال جدید</span><input class="sc-control" data-public-reenroll-code autocomplete="off" dir="ltr"></label></div><label class="sc-field"><span class="sc-field__label">نام این نصب</span><input class="sc-control" data-public-reenroll-name value="کافه سکنا"></label><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-public-reenroll>انتقال و اتصال دوباره</button></div><div class="sc-event-feedback sc-alert sc-alert--danger" data-public-reenroll-feedback hidden></div></div></details>
  </section>

  <section class="sc-work-panel" data-panel="windows" hidden>
    <div class="sc-section-head"><div><h2>سرویس‌های ویندوز</h2><p>وضعیت سرویس‌های اصلی و چاپ جداگانه نمایش داده می‌شود. کد اتصال فقط برای راه‌اندازی یا اتصال مجدد لازم است.</p></div><span class="sc-badge" data-windows-badge>—</span></div>
    <div class="sc-summary-grid"><article class="sc-card sc-metric"><span>سرویس اصلی ویندوز</span><strong data-windows-runtime-version dir="ltr">—</strong><small class="sc-help" data-windows-runtime-state>وضعیت در حال بررسی است.</small></article><article class="sc-card sc-metric"><span>سرویس چاپ</span><strong data-windows-print-version dir="ltr">—</strong><small class="sc-help" data-windows-print-state>وضعیت در حال بررسی است.</small></article><article class="sc-card sc-metric"><span>آدرس سامانه داخلی</span><strong data-windows-url dir="ltr">—</strong></article><article class="sc-card sc-metric"><span>وضعیت راه‌اندازی</span><strong data-windows-provisioning>—</strong><small class="sc-help" data-windows-expiry>—</small></article></div>
    <article class="sc-card"><div class="sc-card__body sc-form">
      <div class="sc-section-head"><div><h3>اتصال فعلی و اتصال مجدد</h3><p>اگر این دستگاه قبلاً ثبت شده است، از اتصال مجدد استفاده کن؛ تنظیمات و مقصدهای چاپ حفظ می‌شوند.</p></div></div>
      <label class="sc-field"><span class="sc-field__label">نام این دستگاه</span><input class="sc-control" data-windows-name value="دستگاه اصلی سکنا" maxlength="120"></label>
      <div class="sc-form-grid sc-form-grid--2"><label class="sc-field"><span class="sc-field__label">سرویس چاپ موجود</span><select class="sc-control" data-windows-agent><option value="">سرویس چاپ فعالی پیدا نشد</option></select><small class="sc-help">برای اتصال مجدد، سرویس چاپ همین دستگاه را انتخاب کن.</small></label><div class="sc-field"><span class="sc-field__label">عملیات پیشنهادی</span><div class="sc-actions"><button class="sc-button" type="button" data-windows-initial>ساخت کد اتصال اولیه</button><button class="sc-button" type="button" data-windows-reconnect hidden>اتصال مجدد / تعویض توکن</button><button class="sc-button sc-button--secondary" type="button" data-windows-cancel>لغو کد فعال</button></div></div></div>
      <div class="sc-alert sc-alert--warning sc-secret" data-windows-secret hidden><strong>کد اتصال:</strong><code data-windows-code dir="ltr"></code><button class="sc-button sc-button--secondary" type="button" data-windows-copy>کپی کد</button></div>
      <div class="sc-event-feedback sc-alert sc-alert--danger" data-windows-feedback hidden></div>
    </div></article>
    <article class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h3>دستگاه‌ها و اتصال‌های ثبت‌شده</h3><p>اتصال قدیمی حذف فیزیکی نمی‌شود؛ بازنشسته می‌شود تا سابقه بررسی باقی بماند و دسترسی چاپ آن دیگر معتبر نباشد.</p></div></div><div class="sc-list" data-windows-connections></div></div></article>
    <details class="sc-card sc-connection-advanced" data-windows-add-device-disclosure><summary class="sc-disclosure__summary"><span><strong>افزودن دستگاه ویندوز جدید</strong><small>فقط برای یک رایانه/سرویس چاپ مستقل جدید؛ برای همین دستگاه از «اتصال مجدد» استفاده کن.</small></span></summary><div class="sc-card__body sc-stack"><p class="sc-muted">این عملیات یک سرویس چاپ جدید می‌سازد. اگر دستگاه قبلی دیگر استفاده نمی‌شود، ابتدا همان اتصال را از فهرست بالا بازنشسته کن.</p><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-windows-create>ساخت کد برای دستگاه جدید</button></div></div></details>
    <details class="sc-card sc-connection-advanced" data-windows-history-disclosure><summary class="sc-disclosure__summary"><span><strong>سابقه اتصال</strong><small>کدهای مصرف‌شده، منقضی، لغوشده و اتصال‌های بازنشسته</small></span></summary><div class="sc-card__body"><div class="sc-list" data-windows-pairing-history></div></div></details>
  </section>
</section>
<script src="<?=ProductShell::asset('assets/connections-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
