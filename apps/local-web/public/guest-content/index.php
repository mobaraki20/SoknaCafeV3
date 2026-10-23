<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'منوی مهمان','guest-content','ظاهر، متن‌ها، تصاویر و انتشار منوی مهمان را از یک فضای کاری مدیریت کن.',true);
?>
<section class="sc-workspace" data-guest-content data-api="guest-content/api.php" data-upload="guest-content/upload.php" data-theme-upload="guest-content/theme-upload.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-toolbar sc-local-nav"><div class="sc-tabs" role="tablist" aria-label="منوی مهمان"><button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="theme">ظاهر</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="copy">متن‌ها</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="settings">اطلاعات و امکانات</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="media">تصاویر</button></div><div class="sc-actions"><a class="sc-button sc-button--secondary" href="catalog/">مدیریت آیتم‌های منو</a><span class="sc-badge" data-published>منتشرنشده</span><button class="sc-button" type="button" data-publish>انتشار پیش‌نویس</button></div></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status hidden></div>
  <section class="sc-summary-grid sc-guest-menu-summary" aria-label="خلاصه منوی مهمان">
    <article class="sc-metric"><span>انتشار</span><strong data-guest-metric="revision">—</strong><small data-guest-metric-note="published">وضعیت نسخه عمومی</small></article>
    <article class="sc-metric"><span>تم پیش‌نویس</span><strong data-guest-metric="theme">—</strong><small>ظاهر نسخه بعدی</small></article>
    <article class="sc-metric"><span>آیتم‌های مهمان</span><strong data-guest-metric="items">۰</strong><small>آیتم‌های فعال و عمومی</small></article>
    <article class="sc-metric"><span>دارای تصویر</span><strong data-guest-metric="images">۰</strong><small>از آیتم‌های مهمان</small></article>
  </section>
  <section class="sc-card sc-public-sync-card" aria-label="وضعیت انتشار وب عمومی">
    <div class="sc-card__body sc-public-sync-row">
      <div class="sc-public-sync-copy"><div class="sc-actions"><strong>وب عمومی</strong><span class="sc-badge sc-badge--info" data-public-sync-badge>در حال بررسی</span></div><p class="sc-muted" data-public-sync-message>وضعیت انتشار نسخه روی وب عمومی در حال بررسی است.</p><small class="sc-muted" data-public-sync-meta></small></div>
      <div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-public-sync-retry hidden>تلاش دوباره همگام‌سازی</button></div>
    </div>
  </section>

  <section class="sc-work-panel" data-panel="theme">
    <div class="sc-guest-theme-layout">
      <section class="sc-card sc-guest-theme-editor"><form class="sc-card__body sc-form" data-theme-form><div class="sc-section-head"><div><h2>ظاهر منوی مهمان</h2><p>تم را انتخاب و قبل از انتشار بررسی کن. نصب تم، آن را خودکار عمومی نمی‌کند.</p></div><div class="sc-actions"><input type="file" accept=".soknatheme,application/json" data-theme-upload-file aria-label="انتخاب فایل تم سکنا" hidden><button class="sc-button sc-button--secondary" type="button" data-theme-contract-download>قرارداد ساخت قالب</button><button class="sc-button sc-button--secondary" type="button" data-theme-schema-download>اسکیما v2</button><button class="sc-button sc-button--secondary" type="button" data-theme-starter-download>نمونه v2</button><button class="sc-button sc-button--secondary" type="button" data-theme-export>خروجی قالب انتخاب‌شده</button><button class="sc-button sc-button--secondary" type="button" data-theme-upload-open>نصب .soknatheme</button></div></div><label class="sc-field"><span class="sc-field__label">تم و نسخه</span><select class="sc-control" name="theme_package_id" data-theme-select></select></label><div class="sc-muted" data-theme-package-meta></div><div data-theme-settings class="sc-stack"></div><details class="sc-disclosure sc-theme-technical" data-theme-advanced><summary>جزئیات فنی ظاهر</summary><div class="sc-stack" data-theme-technical-values></div></details><section class="sc-theme-composition" aria-labelledby="scThemeCompositionTitle"><div class="sc-section-head"><div><h3 id="scThemeCompositionTitle">چیدمان بخش‌های منو</h3><p>ترتیب بخش‌های منو را تنظیم کن. در قالب‌های نسل دوم، بخش‌های اصلی توسط قالب حذف نمی‌شوند و فقط در صورت نبود محتوای مربوط نمایش داده نمی‌شوند؛ قالب‌های قدیمی رفتار تاریخی خود را حفظ می‌کنند.</p></div></div><div class="sc-list" data-theme-composition></div></section><section class="sc-card sc-guest-menu-mode" aria-labelledby="scGuestMenuModeTitle"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h3 id="scGuestMenuModeTitle">گروه‌های منو</h3></div></div><label class="sc-check sc-guest-menu-mode__toggle"><input type="checkbox" data-menu-groups-visible checked><span><strong>نمایش گروه‌های منو</strong><small class="sc-muted">با خاموش‌کردن، آیتم‌های منوهای فعال در یک فهرست دیده می‌شوند.</small></span></label><label class="sc-check sc-guest-menu-mode__toggle"><input type="checkbox" data-staff-menu-groups-visible checked><span><strong>نمایش گروه‌ها در ثبت سفارش کارکنان</strong></span></label></div></section><div class="sc-theme-contrast" data-theme-contrast aria-live="polite" aria-label="بررسی خوانایی رنگ‌ها"></div><div class="sc-actions"><button class="sc-button" type="submit">ذخیره در پیش‌نویس</button></div></form></section>
      <aside class="sc-guest-theme-side">
        <section class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h2>پیش‌نمایش پیش از انتشار</h2><p>نمای نماینده با همان CSS منوی مهمان؛ انتشار خودکار انجام نمی‌شود.</p></div></div><div class="sc-actions sc-theme-preview-sizes" role="group" aria-label="اندازه پیش‌نمایش"><button class="sc-button sc-button--secondary is-active" type="button" data-theme-preview-size="390" aria-pressed="true">موبایل</button><button class="sc-button sc-button--secondary" type="button" data-theme-preview-size="768" aria-pressed="false">تبلت</button><button class="sc-button sc-button--secondary" type="button" data-theme-preview-size="1080" aria-pressed="false">دسکتاپ</button></div><div class="sc-theme-preview-stage" data-theme-preview-stage><iframe class="sc-theme-preview-frame" data-theme-preview title="پیش‌نمایش قالب منوی مهمان" sandbox=""></iframe></div><small>این پیش‌نمایش برای ارزیابی ظاهر است؛ سناریوهای واقعی سفارش، شبکه و گوشی در UAT جداگانه بررسی می‌شوند.</small></div></section>
        <section class="sc-card sc-theme-history-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h2>تاریخچه انتشار تم</h2></div></div><div class="sc-list" data-theme-history></div></div></section>
      </aside>
    </div>
  </section>

  <section class="sc-work-panel" data-panel="copy" hidden>
    <section class="sc-card"><form class="sc-card__body sc-form" data-copy-form><div class="sc-section-head"><div><h2>متن‌های مرکزی مهمان</h2><p>متن‌هایی که مهمان در منوی عمومی می‌بیند را اینجا ویرایش کن.</p></div></div><div class="sc-form-grid" data-copy-fields></div><div class="sc-actions"><button class="sc-button" type="submit">ذخیره پیش‌نویس متن‌ها</button></div></form></section>
  </section>

  <section class="sc-work-panel" data-panel="settings" hidden>
    <section class="sc-card"><form class="sc-card__body sc-form" data-public-settings-form>
      <div class="sc-section-head"><div><h2>اطلاعات و امکانات عمومی</h2><p>درباره سکنا، راه‌های ارتباط، اقامتگاه و قابلیت‌های منوی عمومی را در همان چرخه پیش‌نویس و انتشار مدیریت کن.</p></div></div>
      <div class="sc-stack" data-public-settings-fields></div>
      <div class="sc-alert sc-alert--info"><strong>اعمال روی وب عمومی</strong><span>ذخیره این بخش فقط پیش‌نویس را تغییر می‌دهد؛ برای اعمال روی منوی مهمان باید «انتشار پیش‌نویس» را بزنید.</span></div>
      <div class="sc-actions"><button class="sc-button" type="submit">ذخیره پیش‌نویس اطلاعات و امکانات</button></div>
    </form></section>
  </section>

  <section class="sc-work-panel" data-panel="media" hidden>
    <section class="sc-card sc-brand-identity-card" aria-label="هویت تصویری برند"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>نشان و آیکون مجموعه</h2><p>یک تصویر اصلی از آلبوم انتخاب کن. نسخه‌های لوگو و favicon خودکار از همان تصویر ساخته می‌شوند.</p></div><span class="sc-badge" data-identity-status>پیش‌فرض</span></div>
      <div class="sc-brand-identity">
        <div class="sc-brand-identity__preview" data-identity-preview><img data-identity-preview-img alt="پیش‌نمایش نشان مجموعه"><span data-identity-fallback>نشان پیش‌فرض سکنا</span></div>
        <div class="sc-brand-identity__copy"><strong data-identity-name>نشان پیش‌فرض</strong><span class="sc-muted" data-identity-meta>برای تغییر، یک تصویر از آلبوم انتخاب کن.</span><small class="sc-muted" data-identity-publish-state>وب عمومی فقط بعد از «انتشار پیش‌نویس» به‌روز می‌شود.</small></div>
        <div class="sc-actions sc-brand-identity__actions"><button class="sc-button" type="button" data-identity-pick>انتخاب از آلبوم</button><button class="sc-button sc-button--secondary" type="button" data-identity-reset>بازگشت به نشان پیش‌فرض</button></div>
      </div>
    </div></section>

    <section class="sc-media-summary" aria-label="نمای سریع کتابخانه رسانه">
      <button class="sc-media-stat is-active" type="button" data-media-quick-filter="active" aria-pressed="true"><span>همه تصاویر فعال</span><strong data-media-metric="active">۰</strong><small>کتابخانه جاری</small></button>
      <button class="sc-media-stat" type="button" data-media-quick-filter="used" aria-pressed="false"><span>در حال استفاده</span><strong data-media-metric="used">۰</strong><small>متصل به منو یا برند</small></button>
      <button class="sc-media-stat" type="button" data-media-quick-filter="unused" aria-pressed="false"><span>بدون استفاده</span><strong data-media-metric="unused">۰</strong><small>آماده آرشیو یا استفاده</small></button>
      <button class="sc-media-stat" type="button" data-media-quick-filter="archived" aria-pressed="false"><span>آرشیو</span><strong data-media-metric="archived">۰</strong><small>خارج از انتخاب روزمره</small></button>
    </section>

    <section class="sc-card sc-media-library-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>کتابخانه رسانه</h2><p>تصاویر را پیدا کن، وضعیت استفاده را ببین و جزئیات هر فایل را بدون شلوغی کارت‌ها مدیریت کن.</p></div><button class="sc-button" type="button" data-media-upload-open>افزودن تصویر</button></div>
      <div class="sc-media-toolbar">
        <label class="sc-media-search"><span class="sc-visually-hidden">جست‌وجوی تصویر</span><input class="sc-control" type="search" data-media-search placeholder="جست‌وجو در نام، متن جایگزین یا شناسه…"></label>
        <label class="sc-media-filter-field"><span class="sc-visually-hidden">نوع فایل</span><select class="sc-control" data-media-type><option value="all">همه فرمت‌ها</option><option value="image/jpeg">JPG</option><option value="image/png">PNG</option><option value="image/webp">WebP</option><option value="image/gif">GIF</option></select></label>
        <label class="sc-media-filter-field"><span class="sc-visually-hidden">جهت تصویر</span><select class="sc-control" data-media-orientation><option value="all">همه نسبت‌ها</option><option value="square">مربع</option><option value="landscape">افقی</option><option value="portrait">عمودی</option></select></label>
        <label class="sc-media-filter-field sc-media-sort"><span class="sc-visually-hidden">مرتب‌سازی</span><select class="sc-control" data-media-sort><option value="newest">جدیدترین</option><option value="oldest">قدیمی‌ترین</option><option value="name">نام فایل</option><option value="usage">بیشترین استفاده</option><option value="size_desc">بزرگ‌ترین فایل</option><option value="size_asc">کوچک‌ترین فایل</option></select></label>
        <div class="sc-segmented sc-media-view-toggle" role="group" aria-label="نوع نمایش">
          <button type="button" class="is-active" data-media-view="grid" aria-pressed="true">شبکه‌ای</button>
          <button type="button" data-media-view="list" aria-pressed="false">فهرستی</button>
        </div>
      </div>
      <div class="sc-media-results-bar"><strong data-media-result-count>۰ تصویر</strong><span class="sc-muted" data-media-active-filter-label>همه تصاویر فعال</span><button class="sc-button sc-button--secondary" type="button" data-media-reset-filters hidden>پاک‌کردن فیلترها</button></div>
      <div class="sc-media-grid" data-media-grid data-view="grid"></div>
      <nav class="sc-data-pager" data-media-pager aria-label="صفحه‌بندی کتابخانه رسانه" hidden>
        <button class="sc-button sc-button--secondary" type="button" data-media-page-prev>صفحه قبل</button>
        <span class="sc-muted" data-media-page-label></span>
        <button class="sc-button sc-button--secondary" type="button" data-media-page-next>صفحه بعد</button>
      </nav>
    </div></section>

    <section class="sc-card sc-media-owner-card"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h2>تصویر آیتم‌های منو</h2><p>تصویر هر آیتم را همان‌جا کنار نام، قیمت و دسته‌بندی ویرایش کن تا همه اطلاعات آیتم یک‌جا بماند.</p></div><a class="sc-button sc-button--secondary" href="catalog/?tab=items">رفتن به کاتالوگ</a></div><p class="sc-muted">این بخش برای آلبوم تصاویر و نشان مجموعه است؛ برای عوض‌کردن تصویر یک آیتم، از «ویرایش کامل آیتم» در کاتالوگ استفاده کن.</p></div></section>

    <details class="sc-card sc-media-maintenance sc-disclosure"><summary>نگهداری و پاک‌سازی رسانه</summary><div class="sc-card__body sc-stack"><p class="sc-muted">فقط فایل‌های آرشیوشده و بدون استفاده قابل حذف دائمی هستند.</p><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-gc-preview>بررسی فایل‌های قابل حذف</button><button class="sc-button sc-button--danger" type="button" data-gc-run>حذف فایل‌های بدون استفاده</button></div><div data-gc-result class="sc-muted"></div></div></details>

    <dialog class="sc-dialog sc-media-picker-dialog" data-media-picker-dialog aria-labelledby="mediaPickerTitle">
      <div class="sc-media-dialog__panel">
        <header class="sc-media-dialog__head"><div><h2 id="mediaPickerTitle">انتخاب تصویر</h2><p data-media-picker-context>یک تصویر از آلبوم انتخاب کن.</p></div><button class="sc-icon-button" type="button" data-media-picker-close aria-label="بستن"><svg class="sc-ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></header>
        <div class="sc-media-dialog__toolbar"><input class="sc-control" type="search" data-media-picker-search aria-label="جست‌وجوی تصویر در آلبوم" placeholder="جست‌وجوی تصویر…"><button class="sc-button sc-button--secondary" type="button" data-media-picker-upload>بارگذاری تصویر جدید</button></div>
        <div class="sc-media-picker-current" data-media-picker-current></div>
        <div class="sc-media-picker-grid" role="listbox" aria-label="آلبوم تصاویر" data-media-picker-grid></div>
        <footer class="sc-media-dialog__actions"><button class="sc-button sc-button--secondary" type="button" data-media-picker-clear>بدون تصویر</button><span class="sc-grow"></span><button class="sc-button sc-button--secondary" type="button" data-media-picker-close>انصراف</button><button class="sc-button" type="button" data-media-picker-apply>استفاده از تصویر</button></footer>
      </div>
    </dialog>


    <dialog class="sc-dialog sc-media-detail-dialog" data-media-detail-dialog aria-labelledby="mediaDetailTitle">
      <div class="sc-media-detail-panel">
        <header class="sc-media-dialog__head"><div><h2 id="mediaDetailTitle">جزئیات تصویر</h2><p data-media-detail-subtitle>اطلاعات فایل و نحوه استفاده را ببین و ویرایش کن.</p></div><button class="sc-icon-button" type="button" data-media-detail-close aria-label="بستن"><svg class="sc-ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></header>
        <div class="sc-media-detail-layout">
          <section class="sc-media-detail-preview"><div class="sc-media-detail-image"><img data-media-detail-image alt=""></div><div class="sc-media-detail-preview-meta"><span class="sc-badge" data-media-detail-usage>بدون استفاده</span><span class="sc-muted" data-media-detail-state>فعال</span></div></section>
          <section class="sc-media-detail-info">
            <dl class="sc-media-detail-meta">
              <div><dt>نام فایل</dt><dd data-media-detail-name>—</dd></div>
              <div><dt>فرمت</dt><dd data-media-detail-format>—</dd></div>
              <div><dt>ابعاد</dt><dd data-media-detail-dimensions>—</dd></div>
              <div><dt>کیفیت برای وب</dt><dd data-media-detail-quality>—</dd></div>
              <div><dt>حجم</dt><dd data-media-detail-size>—</dd></div>
              <div><dt>تاریخ افزودن</dt><dd data-media-detail-created>—</dd></div>
              <div><dt>شناسه</dt><dd data-media-detail-key dir="ltr">—</dd></div>
            </dl>
            <label class="sc-field"><span class="sc-field__label">متن جایگزین</span><textarea class="sc-control" rows="3" maxlength="180" data-media-detail-alt placeholder="توضیح کوتاه و کاربردی برای دسترس‌پذیری"></textarea><small class="sc-help">برای تصاویر تزئینی می‌تواند خالی باشد؛ برای تصویر آیتم، توصیف کوتاه و معنی‌دار بهتر است.</small></label>
            <div class="sc-media-detail-actions"><button class="sc-button" type="button" data-media-detail-save-alt>ذخیره متن جایگزین</button><button class="sc-button sc-button--secondary" type="button" data-media-detail-focal>تنظیم کانون ۱:۱</button></div>
            <div class="sc-media-correction"><div><strong>اصلاح بدون تغییر فایل اصلی</strong><small>هر اصلاح یک تصویر جدید می‌سازد؛ فایل اصلی همیشه برای بازگشت و استفاده‌های دیگر حفظ می‌شود.</small></div><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-media-transform="rotate_right">چرخش راست</button><button class="sc-button sc-button--secondary" type="button" data-media-transform="rotate_left">چرخش چپ</button><button class="sc-button sc-button--secondary" type="button" data-media-transform="crop_square">ساخت نسخه مربع</button></div></div>
            <div class="sc-media-detail-actions"><button class="sc-button sc-button--danger" type="button" data-media-detail-archive>آرشیو تصویر</button></div>
            <p class="sc-muted" data-media-detail-archive-note></p>
          </section>
        </div>
      </div>
    </dialog>

    <dialog class="sc-dialog sc-media-focal-dialog" data-media-focal-dialog aria-labelledby="mediaFocalTitle">
      <div class="sc-media-dialog__panel">
        <header class="sc-media-dialog__head"><div><h2 id="mediaFocalTitle">کانون تصویر منو</h2><p>روی تصویر اصلی کلیک کن تا نقطه مهم در برش مربع ۱:۱ حفظ شود.</p></div><button class="sc-icon-button" type="button" data-media-focal-close aria-label="بستن"><svg class="sc-ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></header>
        <div class="sc-media-focal-layout">
          <section class="sc-media-focal-master"><strong>تصویر اصلی</strong><button type="button" class="sc-media-focal-stage" data-media-focal-stage aria-label="انتخاب کانون تصویر"><img data-media-focal-master alt=""><span class="sc-media-focal-marker" data-media-focal-marker aria-hidden="true"></span></button><small class="sc-muted">مستر تغییر نمی‌کند؛ فقط نقطه کانون ذخیره می‌شود.</small></section>
          <section class="sc-media-focal-preview"><strong>پیش‌نمایش کارت ۱:۱</strong><div class="sc-media-focal-square"><img data-media-focal-preview alt=""></div><small class="sc-muted" data-media-focal-meta>کانون: ۵۰٪ × ۵۰٪</small></section>
        </div>
        <footer class="sc-media-dialog__actions"><button class="sc-button sc-button--secondary" type="button" data-media-focal-center>مرکز تصویر</button><span class="sc-grow"></span><button class="sc-button sc-button--secondary" type="button" data-media-focal-close>انصراف</button><button class="sc-button" type="button" data-media-focal-save>ذخیره کادر ۱:۱</button></footer>
      </div>
    </dialog>

    <dialog class="sc-dialog sc-media-upload-dialog" data-media-upload-dialog aria-labelledby="mediaUploadTitle">
      <form class="sc-media-dialog__panel" data-upload-form enctype="multipart/form-data">
        <header class="sc-media-dialog__head"><div><h2 id="mediaUploadTitle">بارگذاری تصاویر</h2><p>چند فایل را با هم انتخاب کن؛ وضعیت و خطای هر فایل جداگانه نمایش داده می‌شود.</p></div><button class="sc-icon-button" type="button" data-media-upload-close aria-label="بستن"><svg class="sc-ui-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></header>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
        <label class="sc-media-dropzone" data-media-dropzone><input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-media-upload-file aria-label="انتخاب تصاویر برای بارگذاری"><span class="sc-media-dropzone__icon">＋</span><strong>تصاویر را اینجا بکش و رها کن یا انتخاب کن</strong><small>JPG، PNG، WebP یا GIF؛ حداکثر ۸ مگابایت. برای منو بهتر است ضلع کوتاه حداقل ۱۲۰۰ پیکسل باشد. فایل اصلی حفظ و نسخه سبک وب جداگانه ساخته می‌شود.</small></label>
        <section class="sc-media-upload-batch" data-media-upload-batch aria-live="polite">
          <div class="sc-media-upload-batch__head"><div><strong data-media-upload-summary>هنوز فایلی انتخاب نشده است.</strong><small>آماده‌شدن در Local با انتشار در وب عمومی یکی نیست؛ همگام‌سازی Public جداگانه انجام می‌شود.</small></div><progress max="100" value="0" data-media-upload-overall aria-label="پیشرفت کل بارگذاری"></progress></div>
          <div class="sc-media-upload-queue" data-media-upload-queue></div>
        </section>
        <footer class="sc-media-dialog__actions"><button class="sc-button sc-button--secondary" type="button" data-media-upload-retry-all hidden>تلاش دوباره برای خطادارها</button><button class="sc-button sc-button--secondary" type="button" data-media-upload-clear hidden>پاک‌کردن انجام‌شده‌ها</button><span class="sc-grow"></span><button class="sc-button sc-button--secondary" type="button" data-media-upload-close>بستن</button><button class="sc-button" type="submit" data-media-upload-start>شروع بارگذاری</button></footer>
      </form>
    </dialog>
  </section>
</section>
<script src="<?=ProductShell::asset('assets/guest-content-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
