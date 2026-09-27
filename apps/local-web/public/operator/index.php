<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['orders_floor','cashier_accounts','shift_supervision']);
$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'کار روزانه','operator','نیازمند اقدام، وضعیت میزها و فراخوان‌های مهمان در یک نمای عملیاتی تازه می‌شوند.');
?>
<section class="sc-workspace" data-operator-workspace data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-api="/operator/api.php">
  <div class="sc-summary-grid" aria-label="خلاصه کار روزانه">
    <article class="sc-card sc-metric"><span>نیازمند اقدام</span><strong data-metric="attention">—</strong><small>سفارش‌های مهمان منتظر تأیید</small></article>
    <article class="sc-card sc-metric"><span>میزهای باز</span><strong data-metric="tables">—</strong><small>نشست فعال یا منتظر تأیید</small></article>
    <article class="sc-card sc-metric"><span>فراخوان فعال</span><strong data-metric="calls">—</strong><small>جدید یا پذیرفته‌شده</small></article>
  </div>
  <div class="sc-toolbar"><div class="sc-tabs" role="tablist" aria-label="نمای کار روزانه"><button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="attention">نیازمند اقدام</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="tables">میزها</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="items">جمع اقلام</button></div><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت وضعیت…</div>
  <section class="sc-work-panel" data-panel="attention"><div class="sc-section-head"><div><h2>نیازمند اقدام</h2><p>سفارش‌های مهمان و فراخوان‌هایی که هنوز بسته نشده‌اند.</p></div></div><div class="sc-stack" data-attention-list></div></section>
  <section class="sc-work-panel" data-panel="tables" hidden><div class="sc-section-head"><div><h2>میزها و حساب جاری</h2><p>میز آزاد، نشست فعال و موارد منتظر تأیید از یک read model واحد نمایش داده می‌شوند.</p></div></div><div class="sc-card-grid" data-table-list></div></section>
  <section class="sc-work-panel" data-panel="items" hidden><div class="sc-section-head"><div><h2>جمع اقلام حساب‌های باز</h2><p>فقط اقلام سفارش‌های تأییدشده در نشست‌های فعال.</p></div></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>آیتم</th><th>تعداد</th><th>جمع</th></tr></thead><tbody data-item-totals></tbody></table></div></section>
</section>
<script src="/assets/operator-workspace.js" defer></script>
<?php ProductShell::end(); ?>
