<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'مدیریت','admin','کاربران، پرسنل، تنظیمات عملیاتی، امکانات، میزها و امنیت QR از ownerهای Local مدیریت می‌شوند.');
?>
<section class="sc-workspace" data-admin-workspace data-api="/admin/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-toolbar"><div class="sc-tabs" role="tablist" aria-label="مدیریت"><button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="users">کاربران</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="personnel">پرسنل</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="settings">تنظیمات</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="modules">امکانات</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="tables">میزها و QR</button></div><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت تنظیمات…</div>

  <section class="sc-work-panel" data-panel="users">
    <div class="sc-section-head"><div><h2>حساب‌های ورود</h2><p>حساب ورود با هویت پرسنلی یکی نیست؛ اتصال آن در بخش «پرسنل» اختیاری است.</p></div><button class="sc-button" type="button" data-open="user">حساب جدید</button></div>
    <div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>دسترسی‌ها</th><th>وضعیت</th><th></th></tr></thead><tbody data-users></tbody></table></div>
  </section>

  <section class="sc-work-panel" data-panel="personnel" hidden>
    <div class="sc-section-head"><div><h2>پرسنل</h2><p>پرسنل می‌تواند بدون حساب ورود وجود داشته باشد. این هویت پایه برای مزایا، مصرف پرسنلی و حساب مستقل پرسنل است.</p></div><button class="sc-button" type="button" data-open="personnel">پرسنل جدید</button></div>
    <div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>نام</th><th>سمت</th><th>کد</th><th>حساب متصل</th><th>وضعیت</th><th></th></tr></thead><tbody data-personnel></tbody></table></div>
  </section>

  <section class="sc-work-panel" data-panel="settings" hidden>
    <section class="sc-card"><form class="sc-card__body sc-form" data-settings-form><h2>تنظیمات عملیاتی Local</h2><label class="sc-field"><span class="sc-field__label">نام مجموعه</span><input class="sc-control" name="cafe_name" maxlength="120" required></label><label class="sc-field"><span class="sc-field__label">شروع روز کاری</span><input class="sc-control" name="business_day_cutoff" type="time" required></label><label class="sc-field"><span class="sc-field__label">فراخوان همکار سالن</span><span><input type="checkbox" name="waiter_call_enabled"> فعال باشد</span></label><div class="sc-actions"><button class="sc-button" type="submit">ذخیره تنظیمات</button></div></form></section>
    <div class="sc-alert sc-alert--info"><strong>مرز scope:</strong> Theme/Media/Public Copy و URL نهایی Public در G3/G4 تکمیل می‌شوند و این صفحه آن‌ها را مالک نمی‌شود.</div>
  </section>

  <section class="sc-work-panel" data-panel="modules" hidden>
    <div class="sc-section-head"><div><h2>امکانات قابل مدیریت</h2><p>فقط ماژول‌هایی اینجا نمایش داده می‌شوند که setting آن‌ها واقعاً در runtime فعلی مصرف می‌شود.</p></div></div><div class="sc-card-grid" data-modules></div>
  </section>

  <section class="sc-work-panel" data-panel="tables" hidden>
    <div class="sc-section-head"><div><h2>میزها و امنیت QR</h2><p>توکن میز در Local مالکیت دارد؛ URL/چاپ نهایی QR همراه Public deploy در G3 تکمیل می‌شود.</p></div><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-open="bulk">ساخت گروهی</button><button class="sc-button" type="button" data-open="table">میز جدید</button></div></div>
    <div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>میز</th><th>بخش</th><th>وضعیت</th><th>QR</th><th></th></tr></thead><tbody data-tables></tbody></table></div>
  </section>

  <dialog class="sc-dialog" data-dialog="user"><form method="dialog" class="sc-card__body sc-form" data-user-form><h2 data-user-title>حساب کاربری</h2><input type="hidden" name="id"><label class="sc-field"><span class="sc-field__label">نام نمایشی</span><input class="sc-control" name="display_name" maxlength="120" required></label><label class="sc-field"><span class="sc-field__label">نام کاربری</span><input class="sc-control" name="username" maxlength="80" required autocomplete="off"></label><label class="sc-field"><span class="sc-field__label">رمز عبور</span><input class="sc-control" name="password" type="password" minlength="8" autocomplete="new-password"><small>برای حساب موجود فقط در صورت تغییر رمز پر شود.</small></label><label class="sc-field"><span><input type="checkbox" name="active" checked> حساب فعال باشد</span></label><fieldset class="sc-field"><legend>دسترسی‌ها</legend><div class="sc-check-grid" data-capabilities></div></fieldset><fieldset class="sc-field"><legend>ناحیه آماده‌سازی</legend><label><input type="checkbox" name="preparation_areas" value="kitchen"> آشپزخانه</label><label><input type="checkbox" name="preparation_areas" value="bar"> بار</label></fieldset><div class="sc-actions"><button class="sc-button" value="submit">ذخیره</button><button class="sc-button sc-button--secondary" value="cancel">انصراف</button></div></form></dialog>

  <dialog class="sc-dialog" data-dialog="personnel"><form method="dialog" class="sc-card__body sc-form" data-personnel-form><h2>پرسنل</h2><input type="hidden" name="id"><label class="sc-field"><span class="sc-field__label">نام</span><input class="sc-control" name="display_name" maxlength="120" required></label><label class="sc-field"><span class="sc-field__label">سمت / عنوان</span><input class="sc-control" name="job_title" maxlength="120"></label><label class="sc-field"><span class="sc-field__label">کد پرسنلی (اختیاری)</span><input class="sc-control" name="personnel_code" maxlength="40"></label><label class="sc-field"><span class="sc-field__label">حساب ورود مرتبط (اختیاری)</span><select class="sc-control" name="linked_user_id" data-personnel-user><option value="0">بدون حساب ورود</option></select></label><label class="sc-field"><span class="sc-field__label">یادداشت</span><textarea class="sc-control" name="notes" maxlength="500"></textarea></label><label class="sc-field"><span><input type="checkbox" name="active" checked> پرسنل فعال باشد</span></label><div class="sc-actions"><button class="sc-button" value="submit">ذخیره</button><button class="sc-button sc-button--secondary" value="cancel">انصراف</button></div></form></dialog>

  <dialog class="sc-dialog" data-dialog="table"><form method="dialog" class="sc-card__body sc-form" data-table-form><h2>میز</h2><input type="hidden" name="id"><label class="sc-field"><span class="sc-field__label">نام میز</span><input class="sc-control" name="name" maxlength="100" required></label><label class="sc-field"><span class="sc-field__label">شماره میز</span><input class="sc-control" name="table_number" inputmode="numeric" required></label><label class="sc-field"><span class="sc-field__label">بخش / سالن</span><input class="sc-control" name="zone_label" maxlength="80"></label><label class="sc-field"><span><input type="checkbox" name="active" checked> میز فعال باشد</span></label><div class="sc-actions"><button class="sc-button" value="submit">ذخیره</button><button class="sc-button sc-button--secondary" value="cancel">انصراف</button></div></form></dialog>

  <dialog class="sc-dialog" data-dialog="bulk"><form method="dialog" class="sc-card__body sc-form" data-bulk-form><h2>ساخت گروهی میز</h2><label class="sc-field"><span class="sc-field__label">از شماره</span><input class="sc-control" name="from_number" inputmode="numeric" required></label><label class="sc-field"><span class="sc-field__label">تا شماره</span><input class="sc-control" name="to_number" inputmode="numeric" required></label><label class="sc-field"><span class="sc-field__label">پیشوند نام</span><input class="sc-control" name="name_prefix" value="میز"></label><label class="sc-field"><span class="sc-field__label">بخش / سالن</span><input class="sc-control" name="zone_label" maxlength="80"></label><div class="sc-actions"><button class="sc-button" value="submit">ساخت</button><button class="sc-button sc-button--secondary" value="cancel">انصراف</button></div></form></dialog>
</section>
<script src="/assets/admin-workspace.js" defer></script>
<?php ProductShell::end(); ?>
