<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['orders_floor','cashier_accounts','shift_supervision']);
$csrf=WebAction::csrfToken();
$canOrder=$core->auth()->hasCapability('orders_floor',$user);
$canCashier=$core->auth()->hasCapability('cashier_accounts',$user);
ProductShell::start($core,$user,'سالن و میزها','operator','وضعیت میزها، حساب جاری و موارد نیازمند اقدام را در یک فضای عملیاتی ببین.');
?>
<section class="sc-hall-workspace" data-operator-workspace data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-api="operator/api.php" data-order-api="staff/api.php" data-finance-api="finance/api.php" data-subscriber-api="subscribers/api.php" data-can-order="<?= $canOrder?'1':'0' ?>" data-can-cashier="<?= $canCashier?'1':'0' ?>">
  <div data-hall-view>
    <div class="sc-hall-split">
      <aside class="sc-hall-account sc-detail-rail" aria-label="جزئیات میز و موارد نیازمند اقدام">
        <div class="sc-hall-account__detail" data-account-pane aria-live="polite">
          <div class="sc-hall-account__empty"><span class="sc-hall-account__empty-icon" aria-hidden="true">▧</span><strong>حساب میز</strong><p>یک میز در سرویس را انتخاب کنید تا حساب همان میز اینجا باز شود.</p></div>
        </div>
        <section class="sc-attention-inbox" data-attention-section hidden aria-labelledby="hallAttentionTitle">
          <header class="sc-attention-inbox__head"><div><strong id="hallAttentionTitle">نیازمند اقدام</strong><small data-attention-caption>اقدام‌های باز سالن</small></div><span class="sc-attention-inbox__count" data-attention-count>۰</span></header>
          <div class="sc-attention-list" data-attention-list></div>
        </section>
      </aside>
      <main class="sc-hall-tables">
        <header class="sc-hall-toolbar">
          <div class="sc-hall-toolbar__summary"><strong data-open-summary>—</strong><span>میز در سرویس</span><i></i><strong data-free-summary>—</strong><span>میز آزاد</span></div>
          <nav class="sc-hall-zones" aria-label="بخش سالن" data-zone-tabs></nav>
          <div class="sc-hall-toolbar__actions"><button class="sc-button sc-button--secondary" type="button" data-item-totals-open>جمع اقلام</button><button class="sc-icon-button" type="button" data-refresh aria-label="تازه‌سازی">↻</button></div>
        </header>
        <div class="sc-hall-status" role="status" aria-live="polite" data-status>در حال دریافت وضعیت سالن…</div>
        <div class="sc-hall-scroll" data-hall-scroll>
          <section class="sc-hall-section"><div class="sc-hall-section-head"><div><strong>در سرویس</strong><small>میزهای دارای حساب باز یا منتظر تأیید</small></div><span class="sc-hall-count is-open" data-open-count>۰</span></div><div class="sc-hall-open-grid" data-open-tables></div></section>
          <section class="sc-hall-section"><div class="sc-hall-section-head"><div><strong>میزهای آزاد</strong><small>آماده ثبت سفارش</small></div><span class="sc-hall-count" data-free-count>۰</span></div><div class="sc-hall-free-grid" data-free-tables></div></section>
        </div>
      </main>
    </div>
  </div>
  <section class="sc-order-route" data-order-view hidden><div data-order-workspace-host></div></section>

  <dialog class="sc-dialog sc-hall-items-dialog" data-item-totals-dialog aria-labelledby="hallItemsTitle"><form method="dialog" class="sc-card__body sc-stack"><div class="sc-section-head"><div><h2 id="hallItemsTitle">جمع اقلام حساب‌های باز</h2><p>فقط سفارش‌های تأییدشده در نشست‌های فعال.</p></div><button class="sc-icon-button" type="button" data-dialog-close aria-label="بستن">×</button></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>آیتم</th><th>تعداد</th><th>جمع</th></tr></thead><tbody data-item-totals></tbody></table></div></form></dialog>
</section>
<script src="<?=ProductShell::asset('assets/order-draft-workspace.js')?>" defer></script>
<script src="<?=ProductShell::asset('assets/operator-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
