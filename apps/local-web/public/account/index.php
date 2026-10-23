<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;use Sokna\Local\UI\LocalUrl;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::user($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'حساب من','account','نام نمایشی و امنیت حسابی که با آن وارد شده‌ای را مدیریت کن.');
?>
<section class="sc-workspace" data-account data-api="account/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-alert" role="status" aria-live="polite" data-status hidden></div>
  <div class="sc-account-layout">
    <section class="sc-data-panel sc-account-profile">
      <form class="sc-card__body sc-form" data-profile-form>
        <div class="sc-section-title"><div><span class="sc-kicker">هویت کاربری</span><h2>اطلاعات حساب</h2><p>نام نمایشی در سربرگ، گزارش‌ها و ثبت رویدادها استفاده می‌شود.</p></div></div>
        <label class="sc-field"><span class="sc-field__label">نام نمایشی</span><input class="sc-control" name="display_name" maxlength="120" required autocomplete="name"></label>
        <label class="sc-field"><span class="sc-field__label">نام کاربری</span><input class="sc-control" name="username" readonly aria-readonly="true"></label>
        <label class="sc-field"><span class="sc-field__label">نقش</span><input class="sc-control" name="role_label" readonly aria-readonly="true"></label>
        <div class="sc-actions"><button class="sc-button" type="submit">ذخیره نام نمایشی</button></div>
      </form>
    </section>
    <section class="sc-card sc-account-security">
      <form class="sc-card__body sc-form" data-password-form>
        <div class="sc-section-title"><div><span class="sc-kicker">امنیت</span><h2>تغییر رمز عبور</h2><p>برای تغییر رمز، رمز فعلی و رمز تازه را وارد کن.</p></div></div>
        <label class="sc-field"><span class="sc-field__label">رمز فعلی</span><input class="sc-control" type="password" name="current_password" autocomplete="current-password" required></label>
        <label class="sc-field"><span class="sc-field__label">رمز تازه</span><input class="sc-control" type="password" name="new_password" autocomplete="new-password" minlength="8" required><small>حداقل ۸ کاراکتر</small></label>
        <label class="sc-field"><span class="sc-field__label">تکرار رمز تازه</span><input class="sc-control" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>
        <div class="sc-actions"><button class="sc-button" type="submit">تغییر رمز</button></div>
      </form>
    </section>
    <section class="sc-card sc-account-notifications" data-notification-preferences data-api="<?=htmlspecialchars(LocalUrl::path('/notifications/api.php'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" id="account-notifications">
      <form class="sc-card__body sc-form" data-notification-pref-form>
        <div class="sc-section-title"><div><span class="sc-kicker">اعلان‌ها</span><h2>روش دریافت</h2><p>مشخص کن اعلان‌های حساب در سامانه و روی این دستگاه چگونه دریافت شوند.</p></div></div>
        <label class="sc-check-line"><input type="checkbox" name="in_app_enabled"> اعلان داخل سامانه</label>
        <label class="sc-check-line"><input type="checkbox" name="push_enabled"> اعلان روی دستگاه‌های ثبت‌شده</label>
        <fieldset class="sc-notification-category-prefs" data-notification-categories><legend>کدام اعلان‌ها روی دستگاه نمایش داده شوند؟</legend><label class="sc-check-line"><input type="checkbox" name="push_operations_enabled"> سفارش، فراخوان و آماده‌سازی</label><label class="sc-check-line"><input type="checkbox" name="push_management_enabled"> مدیریت، مالی و تغییر قیمت</label></fieldset>
        <small class="sc-help" data-notification-push-state>فعال‌سازی یا قطع هر دستگاه از «اپ سکنا» در منوی حساب همان دستگاه انجام می‌شود.</small>
        <div class="sc-actions"><button class="sc-button" type="submit">ذخیره تنظیم دریافت</button></div>
      </form>
    </section>
  </div>
</section>
<script src="<?=ProductShell::asset('assets/account-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
