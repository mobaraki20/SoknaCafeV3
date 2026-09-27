<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['orders_floor']);
$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'سفارش سریع','staff','سبد سفارش به‌صورت پیش‌نویس مشترک روی سرور ذخیره می‌شود و ثبت نهایی از owner سفارش سریع عبور می‌کند.');
?>
<section class="sc-workspace" data-staff-workspace data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-api="/staff/api.php">
  <div class="sc-toolbar">
    <label class="sc-field sc-toolbar__field" for="staff-table"><span class="sc-field__label">میز</span><select class="sc-control" id="staff-table" data-table-select><option value="">در حال دریافت میزها…</option></select></label>
    <label class="sc-field sc-toolbar__field" for="staff-search"><span class="sc-field__label">جست‌وجوی آیتم</span><input class="sc-control" id="staff-search" type="search" inputmode="search" autocomplete="off" placeholder="نام آیتم" data-search></label>
    <button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button>
  </div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت میزها و منو…</div>
  <div class="sc-workbench">
    <section class="sc-card"><div class="sc-card__head"><h2>منوی کارکنان</h2><p class="sc-muted">فقط آیتم‌های فعال و قابل سفارش در همین لحظه.</p></div><div class="sc-card__body"><div class="sc-catalog" data-catalog></div></div></section>
    <aside class="sc-card sc-cart"><div class="sc-card__head"><div class="sc-section-head"><div><h2>سبد و پیش‌نویس میز</h2><p class="sc-muted" data-draft-meta>یک میز را انتخاب کنید.</p></div></div></div><div class="sc-card__body sc-stack"><div data-cart-list></div><label class="sc-field" for="staff-note"><span class="sc-field__label">یادداشت کلی</span><textarea class="sc-control" id="staff-note" rows="3" maxlength="500" data-note placeholder="مثلاً نوشیدنی‌ها بعد از غذا"></textarea></label><div class="sc-money-row"><span>جمع سفارش جدید</span><strong data-cart-total>۰ تومان</strong></div></div><div class="sc-card__foot sc-actions"><button class="sc-button sc-button--secondary" type="button" data-save>ذخیره پیش‌نویس</button><button class="sc-button" type="button" data-finalize>ثبت سفارش</button><button class="sc-button sc-button--danger" type="button" data-cancel>لغو پیش‌نویس</button></div></aside>
  </div>
</section>
<script src="/assets/staff-workspace.js" defer></script>
<?php ProductShell::end(); ?>
