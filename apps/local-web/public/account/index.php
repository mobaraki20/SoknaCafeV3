<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::user($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'حساب من','account','نام نمایشی و امنیت حسابی که با آن وارد شده‌ای را مدیریت کن.');
?>
<section class="sc-workspace" data-account data-api="account/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت اطلاعات حساب…</div>
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
  </div>
</section>
<script src="<?=ProductShell::asset('assets/account-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
