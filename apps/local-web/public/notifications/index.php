<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::user($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'اعلان‌ها','notifications','اعلان‌های مهم را داخل سامانه یا روی همین دستگاه دریافت و مدیریت کن.');
?>
<section class="sc-workspace" data-notifications data-api="notifications/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-admin="<?= (string)($user['role']??'')==='admin'?'1':'0' ?>">
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت اعلان‌ها…</div>
  <div class="sc-notifications-layout">
    <section class="sc-data-panel sc-notifications-inbox">
      <div class="sc-section-head"><div><h2>صندوق اعلان</h2><p>موارد تازه و پیام‌های مرتبط با کار روزانه در این فهرست قرار می‌گیرند.</p></div><span class="sc-badge" data-unread>۰ خوانده‌نشده</span></div>
      <div class="sc-notifications-list" data-inbox></div>
    </section>
    <aside class="sc-notifications-side">
      <section class="sc-card">
        <form class="sc-card__body sc-form" data-pref-form>
          <div class="sc-section-title"><div><h2>ترجیحات</h2><p>روش دریافت اعلان‌ها را برای همین حساب و دستگاه تنظیم کن.</p></div></div>
          <label class="sc-check-line"><input type="checkbox" name="in_app_enabled"> اعلان داخل سامانه</label>
          <label class="sc-check-line"><input type="checkbox" name="push_enabled"> اعلان روی دستگاه</label>
          <div class="sc-actions"><button class="sc-button" type="submit">ذخیره ترجیحات</button></div>
          <button class="sc-button sc-button--secondary" type="button" data-enable-push>فعال‌سازی روی این دستگاه</button>
          <small class="sc-help" data-push-state></small>
        </form>
      </section>
      <?php if((string)($user['role']??'')==='admin'): ?>
      <section class="sc-card">
        <form class="sc-card__body sc-form" data-compose-form>
          <div class="sc-section-title"><div><h2>ارسال اعلان</h2><p>فقط پیام‌هایی را بفرست که برای کاربر نیاز به توجه یا اقدام دارند.</p></div></div>
          <label class="sc-field"><span class="sc-field__label">عنوان</span><input class="sc-control" name="title" maxlength="180" required></label>
          <label class="sc-field"><span class="sc-field__label">متن</span><textarea class="sc-control" name="body" maxlength="600" required></textarea></label>
          <label class="sc-field"><span class="sc-field__label">گروه</span><select class="sc-control" name="role"><option value="">همه کاربران فعال</option><option value="admin">مدیر</option><option value="operator">اپراتور</option><option value="waiter">همکار سالن</option></select></label>
          <label class="sc-field"><span class="sc-field__label">صفحه مرتبط</span><input class="sc-control" name="target_url" placeholder="مثلاً /reports/"></label>
          <div class="sc-actions"><button class="sc-button" type="submit">ارسال اعلان</button></div>
        </form>
      </section>
      <?php endif; ?>
    </aside>
  </div>
</section>
<script src="<?=ProductShell::asset('assets/notifications-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
