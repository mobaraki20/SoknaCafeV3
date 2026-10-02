<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'منوی مهمان','guest-content','ظاهر، متن‌ها، تصاویر و انتشار منوی مهمان را از یک فضای کاری مدیریت کن.',true);
?>
<section class="sc-workspace" data-guest-content data-api="guest-content/api.php" data-upload="guest-content/upload.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-toolbar sc-local-nav"><div class="sc-tabs" role="tablist" aria-label="منوی مهمان"><button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="theme">ظاهر</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="copy">متن‌ها</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="media">تصاویر</button></div><div class="sc-actions"><a class="sc-button sc-button--secondary" href="catalog/">مدیریت آیتم‌های منو</a><span class="sc-badge" data-published>منتشرنشده</span><button class="sc-button" type="button" data-publish>انتشار پیش‌نویس</button></div></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت محتوای مهمان…</div>
  <section class="sc-summary-grid sc-guest-menu-summary" aria-label="خلاصه منوی مهمان">
    <article class="sc-metric"><span>انتشار</span><strong data-guest-metric="revision">—</strong><small data-guest-metric-note="published">وضعیت نسخه عمومی</small></article>
    <article class="sc-metric"><span>تم پیش‌نویس</span><strong data-guest-metric="theme">—</strong><small>ظاهر نسخه بعدی</small></article>
    <article class="sc-metric"><span>آیتم‌های مهمان</span><strong data-guest-metric="items">۰</strong><small>آیتم‌های فعال و عمومی</small></article>
    <article class="sc-metric"><span>دارای تصویر</span><strong data-guest-metric="images">۰</strong><small>از آیتم‌های مهمان</small></article>
  </section>

  <section class="sc-work-panel" data-panel="theme">
    <div class="sc-card-grid"><section class="sc-card"><form class="sc-card__body sc-form" data-theme-form><h2>ظاهر منوی مهمان</h2><label class="sc-field"><span class="sc-field__label">تم</span><select class="sc-control" name="theme_key" data-theme-select></select></label><div data-theme-settings class="sc-stack"></div><div class="sc-actions"><button class="sc-button" type="submit">ذخیره پیش‌نویس تم</button></div></form></section><section class="sc-card"><div class="sc-card__body sc-stack"><h2>پیش‌نمایش</h2><div class="sc-card" data-theme-preview><div class="sc-card__body"><div class="sc-section-head"><div><h3>سکنا</h3><p>منوی عمومی</p></div><span class="sc-badge">میز ۳</span></div><div class="sc-list-item"><strong>قهوه روز</strong><span>۱۸۰٬۰۰۰ تومان</span></div><button class="sc-button" type="button" disabled aria-disabled="true" title="فقط پیش‌نمایش">ثبت سفارش</button></div></div><small>پیش‌نمایش، نتیجه تغییرات ظاهری همین تم را نشان می‌دهد.</small></div></section></div>
  </section>

  <section class="sc-work-panel" data-panel="copy" hidden>
    <section class="sc-card"><form class="sc-card__body sc-form" data-copy-form><div class="sc-section-head"><div><h2>متن‌های مرکزی مهمان</h2><p>متن‌هایی که مهمان در منوی عمومی می‌بیند را اینجا ویرایش کن.</p></div></div><div class="sc-form-grid" data-copy-fields></div><div class="sc-actions"><button class="sc-button" type="submit">ذخیره پیش‌نویس متن‌ها</button></div></form></section>
  </section>

  <section class="sc-work-panel" data-panel="media" hidden>
    <section class="sc-media-summary" aria-label="خلاصه رسانه">
      <div class="sc-media-stat"><span>تصاویر فعال</span><strong data-media-metric="active">۰</strong></div>
      <div class="sc-media-stat"><span>در حال استفاده</span><strong data-media-metric="used">۰</strong></div>
      <div class="sc-media-stat"><span>بدون استفاده</span><strong data-media-metric="unused">۰</strong></div>
      <div class="sc-media-summary__action"><button class="sc-button" type="button" data-media-upload-open>افزودن تصویر</button></div>
    </section>

    <section class="sc-card sc-media-library-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>آلبوم تصاویر</h2><p>تصاویر فعال را یک‌جا ببین، جست‌وجو کن و مدیریت کن.</p></div></div>
      <div class="sc-media-toolbar">
        <label class="sc-media-search"><span class="sc-visually-hidden">جست‌وجوی تصویر</span><input class="sc-control" type="search" data-media-search placeholder="جست‌وجوی نام یا متن جایگزین…"></label>
        <div class="sc-segmented" role="group" aria-label="فیلتر تصاویر">
          <button type="button" class="is-active" data-media-filter="active">فعال</button>
          <button type="button" data-media-filter="used">استفاده‌شده</button>
          <button type="button" data-media-filter="unused">بدون استفاده</button>
          <button type="button" data-media-filter="archived">آرشیو</button>
        </div>
      </div>
      <div class="sc-media-grid" data-media-grid></div>
    </div></section>

    <section class="sc-card sc-media-items-card"><div class="sc-card__body sc-stack">
      <div class="sc-section-head"><div><h2>تصویر آیتم‌های منو</h2><p>تصویر هر آیتم را از آلبوم انتخاب کن؛ بدون فهرست‌های کشویی طولانی.</p></div></div>
      <label class="sc-media-search"><span class="sc-visually-hidden">جست‌وجوی آیتم</span><input class="sc-control" type="search" data-item-media-search placeholder="جست‌وجوی آیتم یا دسته…"></label>
      <div class="sc-item-media-list" data-item-media-list></div>
    </div></section>

    <details class="sc-card sc-media-maintenance sc-disclosure"><summary>نگهداری و پاک‌سازی رسانه</summary><div class="sc-card__body sc-stack"><p class="sc-muted">فقط فایل‌های آرشیوشده و بدون استفاده قابل حذف دائمی هستند.</p><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-gc-preview>بررسی فایل‌های قابل حذف</button><button class="sc-button sc-button--danger" type="button" data-gc-run>حذف فایل‌های بدون استفاده</button></div><div data-gc-result class="sc-muted"></div></div></details>

    <dialog class="sc-dialog sc-media-picker-dialog" data-media-picker-dialog aria-labelledby="mediaPickerTitle">
      <div class="sc-media-dialog__panel">
        <header class="sc-media-dialog__head"><div><h2 id="mediaPickerTitle">انتخاب تصویر</h2><p data-media-picker-context>یک تصویر از آلبوم انتخاب کن.</p></div><button class="sc-icon-button" type="button" data-media-picker-close aria-label="بستن">×</button></header>
        <div class="sc-media-dialog__toolbar"><input class="sc-control" type="search" data-media-picker-search aria-label="جست‌وجوی تصویر در آلبوم" placeholder="جست‌وجوی تصویر…"><button class="sc-button sc-button--secondary" type="button" data-media-picker-upload>بارگذاری تصویر جدید</button></div>
        <div class="sc-media-picker-current" data-media-picker-current></div>
        <div class="sc-media-picker-grid" role="listbox" aria-label="آلبوم تصاویر" data-media-picker-grid></div>
        <footer class="sc-media-dialog__actions"><button class="sc-button sc-button--secondary" type="button" data-media-picker-clear>بدون تصویر</button><span class="sc-grow"></span><button class="sc-button sc-button--secondary" type="button" data-media-picker-close>انصراف</button><button class="sc-button" type="button" data-media-picker-apply>استفاده از تصویر</button></footer>
      </div>
    </dialog>

    <dialog class="sc-dialog sc-media-upload-dialog" data-media-upload-dialog aria-labelledby="mediaUploadTitle">
      <form class="sc-media-dialog__panel" data-upload-form enctype="multipart/form-data">
        <header class="sc-media-dialog__head"><div><h2 id="mediaUploadTitle">افزودن تصویر</h2><p>JPG، PNG، WebP یا GIF تا ۸ مگابایت.</p></div><button class="sc-icon-button" type="button" data-media-upload-close aria-label="بستن">×</button></header>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
        <label class="sc-media-dropzone" data-media-dropzone><input type="file" name="media" accept="image/jpeg,image/png,image/webp,image/gif" required data-media-upload-file><span class="sc-media-dropzone__icon">＋</span><strong>انتخاب تصویر از دستگاه</strong><small>برای انتخاب فایل کلیک کن.</small></label>
        <div class="sc-media-upload-preview" data-media-upload-preview hidden><img alt="پیش‌نمایش تصویر"><div><strong data-media-upload-name></strong><small data-media-upload-meta></small></div></div>
        <label class="sc-field"><span class="sc-field__label">متن جایگزین</span><input class="sc-control" name="alt_text" maxlength="180" placeholder="توضیح کوتاه برای دسترس‌پذیری"></label>
        <footer class="sc-media-dialog__actions"><span class="sc-grow"></span><button class="sc-button sc-button--secondary" type="button" data-media-upload-close>انصراف</button><button class="sc-button" type="submit">افزودن به آلبوم</button></footer>
      </form>
    </dialog>
  </section>
</section>
<script src="<?=ProductShell::asset('assets/guest-content-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
