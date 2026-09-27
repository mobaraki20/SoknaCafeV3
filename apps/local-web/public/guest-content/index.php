<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'محتوای مهمان','guest-content','تم، رسانه و متن‌های منوی عمومی در Local مالکیت دارند؛ Public فقط نسخه منتشرشده را دریافت می‌کند.');
?>
<section class="sc-workspace" data-guest-content data-api="/guest-content/api.php" data-upload="/guest-content/upload.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت محتوای مهمان…</div>
  <div class="sc-toolbar"><div class="sc-tabs" role="tablist"><button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="theme">تم</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="copy">متن‌ها</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="media">رسانه</button></div><div class="sc-actions"><span class="sc-badge" data-published>منتشرنشده</span><button class="sc-button" type="button" data-publish>انتشار پیش‌نویس</button></div></div>

  <section class="sc-work-panel" data-panel="theme">
    <div class="sc-card-grid"><section class="sc-card"><form class="sc-card__body sc-form" data-theme-form><h2>بسته تم</h2><label class="sc-field"><span class="sc-field__label">تم</span><select class="sc-control" name="theme_key" data-theme-select></select></label><div data-theme-settings class="sc-stack"></div><div class="sc-actions"><button class="sc-button" type="submit">ذخیره پیش‌نویس تم</button></div></form></section><section class="sc-card"><div class="sc-card__body sc-stack"><h2>پیش‌نمایش</h2><div class="sc-card" data-theme-preview><div class="sc-card__body"><div class="sc-section-head"><div><h3>سکنا</h3><p>منوی عمومی</p></div><span class="sc-badge">میز ۳</span></div><div class="sc-list-item"><strong>قهوه روز</strong><span>۱۸۰٬۰۰۰ تومان</span></div><button class="sc-button" type="button">ثبت سفارش</button></div></div><small>تم فقط tokenهای مجاز را تغییر می‌دهد؛ HTML/PHP/JS از بسته تم اجرا نمی‌شود.</small></div></section></div>
  </section>

  <section class="sc-work-panel" data-panel="copy" hidden>
    <section class="sc-card"><form class="sc-card__body sc-form" data-copy-form><div class="sc-section-head"><div><h2>متن‌های مرکزی مهمان</h2><p>فقط متن ساده ذخیره می‌شود و Public آن را escape می‌کند.</p></div></div><div class="sc-form-grid" data-copy-fields></div><div class="sc-actions"><button class="sc-button" type="submit">ذخیره پیش‌نویس متن‌ها</button></div></form></section>
  </section>

  <section class="sc-work-panel" data-panel="media" hidden>
    <div class="sc-card-grid"><section class="sc-card"><form class="sc-card__body sc-form" data-upload-form enctype="multipart/form-data"><h2>افزودن تصویر</h2><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><label class="sc-field"><span class="sc-field__label">فایل</span><input class="sc-control" type="file" name="media" accept="image/jpeg,image/png,image/webp,image/gif" required></label><label class="sc-field"><span class="sc-field__label">متن جایگزین</span><input class="sc-control" name="alt_text" maxlength="180"></label><button class="sc-button" type="submit">افزودن به کتابخانه</button></form></section><section class="sc-card"><div class="sc-card__body sc-stack"><h2>پاک‌سازی</h2><p>فقط رسانه‌های archive‌شده و بدون reference حذف می‌شوند.</p><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-gc-preview>بررسی فایل‌های قابل حذف</button><button class="sc-button sc-button--danger" type="button" data-gc-run>حذف قطعی archiveها</button></div><div data-gc-result class="sc-muted"></div></div></section></div>
    <section class="sc-card"><div class="sc-card__body"><h2>کتابخانه رسانه</h2><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>تصویر</th><th>اطلاعات</th><th>استفاده</th><th>Alt</th><th></th></tr></thead><tbody data-media-list></tbody></table></div></div></section>
    <section class="sc-card"><div class="sc-card__body"><h2>تصویر آیتم‌های منو</h2><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>آیتم</th><th>دسته</th><th>رسانه</th><th></th></tr></thead><tbody data-item-media></tbody></table></div></div></section>
  </section>
</section>
<script src="/assets/guest-content-workspace.js" defer></script>
<?php ProductShell::end(); ?>
