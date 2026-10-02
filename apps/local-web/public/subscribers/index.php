<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['cashier_accounts']);$csrf=WebAction::csrfToken();$isAdmin=(string)($user['role']??'')==='admin';
ProductShell::start($core,$user,'مشتریان','subscribers','حساب مشتری، پرداخت‌ها و سابقه خرید را ساده و یک‌جا مدیریت کن.');
?>
<section class="sc-workspace" data-subscriber-workspace data-api="subscribers/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-admin="<?= $isAdmin?'1':'0' ?>">
  <div class="sc-toolbar"><label class="sc-toolbar__field"><span class="sc-field__label">جست‌وجو</span><input class="sc-control" type="search" data-search placeholder="نام یا موبایل"></label><label class="sc-inline"><input type="checkbox" data-debt-only> فقط بدهکار</label><label class="sc-field sc-toolbar__compact"><span class="sc-field__label">وضعیت</span><select class="sc-control" data-status-filter><option value="all">همه وضعیت‌ها</option><option value="active">فعال</option><option value="inactive">غیرفعال</option></select></label><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button><?php if($isAdmin): ?><button class="sc-button" type="button" data-new-subscriber>مشتری جدید</button><?php endif; ?></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت مشتریان…</div>
  <div class="sc-subscriber-layout">
    <section class="sc-data-panel sc-subscriber-list-panel">
      <div class="sc-section-head"><div><h2>مشتریان</h2><p>جست‌وجو و وضعیت حساب را در یک فهرست کوتاه و قابل اسکن ببین.</p></div><span class="sc-badge" data-result-count>—</span></div>
      <div class="sc-subscriber-list" data-subscriber-list></div>
    </section>
    <section class="sc-data-panel sc-subscriber-detail" data-subscriber-detail>
      <div class="sc-empty sc-subscriber-detail-empty">برای مشاهده مانده و گردش حساب، یک مشتری را انتخاب کن.</div>
    </section>
  </div>
  <?php if($isAdmin): ?><dialog class="sc-dialog" data-subscriber-dialog aria-labelledby="sc-dialog-title-subscriber"><form method="dialog" class="sc-card__body sc-stack" data-subscriber-form><h2 data-form-title id="sc-dialog-title-subscriber">مشتری جدید</h2><input type="hidden" data-form-id><label class="sc-field"><span class="sc-field__label">نام یا عنوان</span><input class="sc-control" data-form-name maxlength="160" required></label><label class="sc-field"><span class="sc-field__label">شماره موبایل</span><input class="sc-control" data-form-mobile type="tel" inputmode="tel" dir="ltr" maxlength="30" required></label><label class="sc-inline"><input type="checkbox" data-form-active checked> فعال باشد</label><div class="sc-actions"><button class="sc-button" type="submit">ذخیره</button><button class="sc-button sc-button--secondary" type="button" data-close-dialog>انصراف</button></div></form></dialog><?php endif; ?>
</section>
<script src="<?=ProductShell::asset('assets/subscribers-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
