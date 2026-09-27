<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports']);
$csrf=WebAction::csrfToken();
$has=static fn(string $cap):bool=>$core->auth()->hasCapability($cap,$user);
$canSelf=$has('staff_consumption_self');$canProxy=$has('staff_consumption_proxy');$canBenefit=$has('staff_benefit_manage');$canAccount=$has('staff_account_manage');$canReport=$has('staff_consumption_reports');
ProductShell::start($core,$user,'مصرف پرسنل','staff-consumption','مصرف، مزایا، حساب و گزارش پرسنل با هویت مصرف‌کننده و ثبت‌کننده مستقل نگهداری می‌شوند.');
?>
<section class="sc-workspace" data-staff-consumption-workspace data-api="/staff-consumption/api.php" data-csrf="<?=htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">
  <div class="sc-toolbar">
    <div class="sc-tabs" role="tablist" aria-label="مصرف پرسنل">
      <?php $first=true; ?>
      <?php if($canSelf): ?><button class="sc-tab" type="button" role="tab" aria-selected="<?=$first?'true':'false'?>" data-tab="self">مصرف من</button><?php $first=false; endif; ?>
      <?php if($canProxy): ?><button class="sc-tab" type="button" role="tab" aria-selected="<?=$first?'true':'false'?>" data-tab="proxy">ثبت برای پرسنل</button><?php $first=false; endif; ?>
      <?php if($canSelf||$canReport): ?><button class="sc-tab" type="button" role="tab" aria-selected="<?=$first?'true':'false'?>" data-tab="history">سوابق</button><?php $first=false; endif; ?>
      <?php if($canBenefit): ?><button class="sc-tab" type="button" role="tab" aria-selected="<?=$first?'true':'false'?>" data-tab="benefits">مزایا</button><?php $first=false; endif; ?>
      <?php if($canAccount): ?><button class="sc-tab" type="button" role="tab" aria-selected="<?=$first?'true':'false'?>" data-tab="accounts">حساب پرسنل</button><?php $first=false; endif; ?>
      <?php if($canReport): ?><button class="sc-tab" type="button" role="tab" aria-selected="<?=$first?'true':'false'?>" data-tab="reports">گزارش</button><?php endif; ?>
    </div>
    <button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button>
  </div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت اطلاعات…</div>

  <?php if($canSelf): ?>
  <section class="sc-work-panel" data-panel="self" data-consume-panel data-mode="self">
    <div class="sc-section-head"><div><h2>مصرف من</h2><p data-consumer-caption>هویت پرسنلی متصل به حساب شما در حال بررسی است.</p></div><span class="sc-badge" data-quote-state>بدون محاسبه</span></div>
    <div class="sc-workbench">
      <section class="sc-card"><div class="sc-card__head"><label class="sc-field"><span class="sc-field__label">جست‌وجوی آیتم</span><input class="sc-control" type="search" placeholder="نام آیتم" data-search></label></div><div class="sc-card__body"><div class="sc-catalog" data-catalog></div></div></section>
      <aside class="sc-card sc-cart"><div class="sc-card__head"><h2>سبد مصرف</h2></div><div class="sc-card__body sc-stack"><div data-cart></div><label class="sc-field"><span class="sc-field__label">یادداشت</span><textarea class="sc-control" rows="2" maxlength="500" data-note></textarea></label><?php if($canBenefit): ?><?php include __DIR__.'/runtime-override-fields.php'; ?><?php endif; ?><div class="sc-money-row"><span>ارزش منویی</span><strong data-menu-total>۰ تومان</strong></div><div class="sc-stack" data-quote></div></div><div class="sc-card__foot sc-actions"><button class="sc-button sc-button--secondary" type="button" data-quote-button>محاسبه مزایا</button><button class="sc-button" type="button" data-post-button>ثبت مصرف</button></div></aside>
    </div>
  </section>
  <?php endif; ?>

  <?php if($canProxy): ?>
  <section class="sc-work-panel" data-panel="proxy" data-consume-panel data-mode="proxy" <?=$canSelf?'hidden':''?>>
    <div class="sc-section-head"><div><h2>ثبت برای پرسنل</h2><p>مصرف‌کننده واقعی را انتخاب کنید؛ ثبت‌کننده از حساب واردشده ثبت می‌شود.</p></div><span class="sc-badge" data-quote-state>بدون محاسبه</span></div>
    <div class="sc-toolbar"><label class="sc-field sc-toolbar__field"><span class="sc-field__label">مصرف‌کننده</span><select class="sc-control" data-personnel-select><option value="">انتخاب پرسنل</option></select></label></div>
    <div class="sc-workbench">
      <section class="sc-card"><div class="sc-card__head"><label class="sc-field"><span class="sc-field__label">جست‌وجوی آیتم</span><input class="sc-control" type="search" placeholder="نام آیتم" data-search></label></div><div class="sc-card__body"><div class="sc-catalog" data-catalog></div></div></section>
      <aside class="sc-card sc-cart"><div class="sc-card__head"><h2>سبد مصرف</h2></div><div class="sc-card__body sc-stack"><div data-cart></div><label class="sc-field"><span class="sc-field__label">یادداشت</span><textarea class="sc-control" rows="2" maxlength="500" data-note></textarea></label><?php if($canBenefit): ?><?php include __DIR__.'/runtime-override-fields.php'; ?><?php endif; ?><div class="sc-money-row"><span>ارزش منویی</span><strong data-menu-total>۰ تومان</strong></div><div class="sc-stack" data-quote></div></div><div class="sc-card__foot sc-actions"><button class="sc-button sc-button--secondary" type="button" data-quote-button>محاسبه مزایا</button><button class="sc-button" type="button" data-post-button>ثبت مصرف</button></div></aside>
    </div>
  </section>
  <?php endif; ?>

  <?php if($canSelf||$canReport): ?>
  <section class="sc-work-panel" data-panel="history" <?=($canSelf||$canProxy)?'hidden':''?>>
    <div class="sc-section-head"><div><h2>سوابق مصرف</h2><p>مصرف‌کننده و ثبت‌کننده همیشه جدا نمایش داده می‌شوند.</p></div></div>
    <?php if($canReport): ?><div class="sc-toolbar"><label class="sc-field sc-toolbar__field"><span class="sc-field__label">پرسنل</span><select class="sc-control" data-history-personnel><option value="0">همه پرسنل</option></select></label><button class="sc-button sc-button--secondary" type="button" data-history-load>نمایش</button></div><?php endif; ?>
    <div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>سند</th><th>مصرف‌کننده</th><th>ثبت‌کننده</th><th>اقلام</th><th>ارزش منویی</th><th>مزیت</th><th>قابل پرداخت</th><th>هزینه شناخته‌شده</th><th>تاریخ</th></tr></thead><tbody data-history></tbody></table></div>
  </section>
  <?php endif; ?>

  <?php if($canBenefit): ?>
  <section class="sc-work-panel" data-panel="benefits" hidden>
    <div class="sc-section-head"><div><h2>مدیریت مزایا</h2><p>Policy، Rule، Profile و Override قبل از posting resolve می‌شوند؛ تغییر بعدی سابقه قبلی را تغییر نمی‌دهد.</p></div></div>
    <div class="sc-grid">
      <form class="sc-card sc-card__body sc-form" data-policy-form><h3>Policy</h3><input type="hidden" name="id"><label class="sc-field"><span class="sc-field__label">کلید</span><input class="sc-control" name="policy_key" required placeholder="staff-default"></label><label class="sc-field"><span class="sc-field__label">نام</span><input class="sc-control" name="name" required></label><label class="sc-field"><span class="sc-field__label">توضیح</span><textarea class="sc-control" name="description"></textarea></label><label class="sc-field"><span class="sc-field__label">اولویت</span><input class="sc-control" name="priority" type="number" min="0" max="65535" value="100"></label><label><input type="checkbox" name="is_default"> Policy پیش‌فرض</label><label><input type="checkbox" name="active" checked> فعال</label><div class="sc-actions"><button class="sc-button" type="submit">ذخیره Policy</button><button class="sc-button sc-button--secondary" type="button" data-form-reset="policy">پاک‌کردن</button></div></form>
      <form class="sc-card sc-card__body sc-form" data-rule-form><h3>Rule</h3><input type="hidden" name="id"><label class="sc-field"><span class="sc-field__label">Policy</span><select class="sc-control" name="policy_id" required></select></label><label class="sc-field"><span class="sc-field__label">محدوده</span><select class="sc-control" name="scope_type"><option value="all">همه اقلام</option><option value="category">دسته</option><option value="item">آیتم</option></select></label><label class="sc-field"><span class="sc-field__label">هدف</span><select class="sc-control" name="scope_id"></select></label><label class="sc-field"><span class="sc-field__label">نوع مزیت</span><select class="sc-control" name="benefit_type"><option value="none">بدون مزیت</option><option value="free">رایگان</option><option value="percent">درصدی</option><option value="fixed">مبلغ ثابت</option></select></label><label class="sc-field"><span class="sc-field__label">درصد</span><input class="sc-control" name="percent" type="number" min="0" max="100" step="0.01"></label><label class="sc-field"><span class="sc-field__label">مبلغ ثابت برای هر واحد</span><input class="sc-control" name="fixed_amount" type="number" min="0"></label><label class="sc-field"><span class="sc-field__label">اولویت</span><input class="sc-control" name="priority" type="number" min="0" max="65535" value="100"></label><label><input type="checkbox" name="active" checked> فعال</label><div class="sc-actions"><button class="sc-button" type="submit">ذخیره Rule</button><button class="sc-button sc-button--secondary" type="button" data-form-reset="rule">پاک‌کردن</button></div></form>
      <form class="sc-card sc-card__body sc-form" data-profile-form><h3>Profile پرسنل</h3><label class="sc-field"><span class="sc-field__label">پرسنل</span><select class="sc-control" name="personnel_id" required></select></label><label class="sc-field"><span class="sc-field__label">Policy</span><select class="sc-control" name="policy_id"><option value="0">بدون مزیت (صریح)</option></select></label><label class="sc-field"><span class="sc-field__label">از تاریخ</span><input class="sc-control" type="date" name="valid_from"></label><label class="sc-field"><span class="sc-field__label">تا تاریخ</span><input class="sc-control" type="date" name="valid_until"></label><label class="sc-field"><span class="sc-field__label">یادداشت</span><textarea class="sc-control" name="notes"></textarea></label><label><input type="checkbox" name="active" checked> فعال</label><div class="sc-actions"><button class="sc-button" type="submit">ذخیره Profile</button></div></form>
      <form class="sc-card sc-card__body sc-form" data-override-form><h3>Override پرسنل</h3><input type="hidden" name="id"><label class="sc-field"><span class="sc-field__label">پرسنل</span><select class="sc-control" name="personnel_id" required></select></label><label class="sc-field"><span class="sc-field__label">محدوده</span><select class="sc-control" name="scope_type"><option value="all">همه اقلام</option><option value="category">دسته</option><option value="item">آیتم</option></select></label><label class="sc-field"><span class="sc-field__label">هدف</span><select class="sc-control" name="scope_id"></select></label><label class="sc-field"><span class="sc-field__label">نوع مزیت</span><select class="sc-control" name="benefit_type"><option value="none">بدون مزیت</option><option value="free">رایگان</option><option value="percent">درصدی</option><option value="fixed">مبلغ ثابت</option></select></label><label class="sc-field"><span class="sc-field__label">درصد</span><input class="sc-control" name="percent" type="number" min="0" max="100" step="0.01"></label><label class="sc-field"><span class="sc-field__label">مبلغ ثابت برای هر واحد</span><input class="sc-control" name="fixed_amount" type="number" min="0"></label><label class="sc-field"><span class="sc-field__label">از زمان</span><input class="sc-control" type="datetime-local" name="valid_from"></label><label class="sc-field"><span class="sc-field__label">تا زمان</span><input class="sc-control" type="datetime-local" name="valid_until"></label><label class="sc-field"><span class="sc-field__label">دلیل</span><textarea class="sc-control" name="reason" required></textarea></label><label><input type="checkbox" name="active" checked> فعال</label><div class="sc-actions"><button class="sc-button" type="submit">ذخیره Override</button><button class="sc-button sc-button--secondary" type="button" data-form-reset="override">پاک‌کردن</button></div></form>
    </div>
    <div class="sc-stack"><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>Policy</th><th>کلید</th><th>اولویت</th><th>پیش‌فرض</th><th>Rule</th><th></th></tr></thead><tbody data-policy-list></tbody></table></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>Rule</th><th>Policy</th><th>محدوده</th><th>نوع</th><th>مقدار</th><th></th></tr></thead><tbody data-rule-list></tbody></table></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>پرسنل</th><th>Policy</th><th>بازه</th><th>وضعیت</th></tr></thead><tbody data-profile-list></tbody></table></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>پرسنل</th><th>محدوده</th><th>نوع</th><th>بازه</th><th>دلیل</th><th></th></tr></thead><tbody data-override-list></tbody></table></div></div>
  </section>
  <?php endif; ?>

  <?php if($canAccount): ?>
  <section class="sc-work-panel" data-panel="accounts" hidden>
    <div class="sc-section-head"><div><h2>حساب پرسنل</h2><p>Benefit قبل از بدهی است؛ Payment و Waiver و Reversal رویدادهای مستقل ledger هستند.</p></div></div>
    <div class="sc-toolbar"><label class="sc-field sc-toolbar__field"><span class="sc-field__label">پرسنل</span><select class="sc-control" data-account-personnel><option value="">انتخاب پرسنل</option></select></label><button class="sc-button sc-button--secondary" type="button" data-account-load>نمایش حساب</button></div>
    <div class="sc-summary-grid"><article class="sc-card sc-metric"><span>مانده</span><strong data-account-balance>—</strong><small>بدهی جاری پرسنل</small></article></div>
    <div class="sc-grid">
      <form class="sc-card sc-card__body sc-form" data-payment-form><h3>ثبت پرداخت</h3><label class="sc-field"><span class="sc-field__label">مبلغ</span><input class="sc-control" type="number" min="1" name="amount" required></label><label class="sc-field"><span class="sc-field__label">روش</span><select class="sc-control" name="payment_method"><option value="cash">نقد</option><option value="card">کارت</option><option value="bank">بانک</option><option value="payroll">حقوق</option><option value="other">سایر</option></select></label><label class="sc-field"><span class="sc-field__label">مرجع</span><input class="sc-control" name="reference" maxlength="120"></label><label class="sc-field"><span class="sc-field__label">توضیح</span><textarea class="sc-control" name="reason"></textarea></label><button class="sc-button" type="submit">ثبت پرداخت</button></form>
      <form class="sc-card sc-card__body sc-form" data-waiver-form><h3>بخشودگی</h3><label class="sc-field"><span class="sc-field__label">مبلغ</span><input class="sc-control" type="number" min="1" name="amount" required></label><label class="sc-field"><span class="sc-field__label">دلیل</span><textarea class="sc-control" name="reason" required></textarea></label><label class="sc-field"><span class="sc-field__label">مرجع</span><input class="sc-control" name="reference" maxlength="120"></label><button class="sc-button" type="submit">ثبت بخشودگی</button></form>
    </div>
    <div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>نوع</th><th>تغییر</th><th>مانده</th><th>دوره</th><th>مرجع/دلیل</th><th>ثبت‌کننده</th><th>زمان</th><th></th></tr></thead><tbody data-account-history></tbody></table></div>
  </section>
  <?php endif; ?>

  <?php if($canReport): ?>
  <section class="sc-work-panel" data-panel="reports" hidden>
    <div class="sc-section-head"><div><h2>گزارش مصرف پرسنل</h2><p>گزارش از snapshot تاریخی مصرف و ledger مستقل حساب پرسنل خوانده می‌شود.</p></div></div>
    <div class="sc-toolbar"><label class="sc-field"><span class="sc-field__label">از</span><input class="sc-control" type="date" data-report-from></label><label class="sc-field"><span class="sc-field__label">تا</span><input class="sc-control" type="date" data-report-to></label><label class="sc-field sc-toolbar__field"><span class="sc-field__label">پرسنل</span><select class="sc-control" data-report-personnel><option value="0">همه پرسنل</option></select></label><button class="sc-button" type="button" data-report-load>گزارش</button></div>
    <div class="sc-summary-grid"><article class="sc-card sc-metric"><span>ارزش منویی</span><strong data-report-metric="menu">—</strong><small>قبل از مزیت</small></article><article class="sc-card sc-metric"><span>مزیت</span><strong data-report-metric="benefit">—</strong><small>Policy/Profile/Override</small></article><article class="sc-card sc-metric"><span>قابل پرداخت</span><strong data-report-metric="payable">—</strong><small>بدهی ایجادشده</small></article><article class="sc-card sc-metric"><span>بخشودگی خالص</span><strong data-report-metric="waiver">—</strong><small>Ledger، جدا از Benefit</small></article><article class="sc-card sc-metric"><span>هزینه شناخته‌شده</span><strong data-report-metric="cost">—</strong><small>Snapshot انبار/Recipe</small></article></div>
    <div class="sc-grid"><section class="sc-card"><div class="sc-card__head"><h3>به تفکیک مصرف‌کننده</h3></div><div class="sc-table-wrap"><table class="sc-table"><thead><tr><th>پرسنل</th><th>سند</th><th>ارزش</th><th>مزیت</th><th>قابل پرداخت</th><th>هزینه</th></tr></thead><tbody data-report-consumers></tbody></table></div></section><section class="sc-card"><div class="sc-card__head"><h3>به تفکیک ثبت‌کننده</h3></div><div class="sc-table-wrap"><table class="sc-table"><thead><tr><th>ثبت‌کننده</th><th>سند</th><th>ارزش</th><th>مزیت</th><th>قابل پرداخت</th></tr></thead><tbody data-report-recorders></tbody></table></div></section></div>
    <div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>سند</th><th>مصرف‌کننده</th><th>ثبت‌کننده</th><th>اقلام</th><th>ارزش</th><th>مزیت</th><th>قابل پرداخت</th><th>هزینه</th><th>تاریخ</th></tr></thead><tbody data-report-rows></tbody></table></div>
  </section>
  <?php endif; ?>
</section>
<script src="/assets/staff-consumption-workspace.js" defer></script>
<?php ProductShell::end(); ?>
