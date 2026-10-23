<?php
declare(strict_types=1);
/** Canonical shared Hall markup. Runtime hosts provide configuration only. */
$hallConfig=is_array($hallConfig??null)?$hallConfig:[];
$hallAsset=is_callable($hallAsset??null)?$hallAsset:static fn(string $path):string=>$path;
$hallCsrf=(string)($hallConfig['csrf']??'');
$hallMode=(string)($hallConfig['mode']??'local');
$canOrder=(bool)($hallConfig['can_order']??false);
$canCashier=(bool)($hallConfig['can_cashier']??false);
$canSupervise=(bool)($hallConfig['can_supervise']??false);
$hallFeatures=array_merge([
    'table_move'=>true,'committed_adjust'=>true,'reprint'=>true,'discount'=>true,'settlement'=>true,'settlement_itemized'=>true,'settlement_subscriber'=>true,'settlement_accommodation'=>true,'waiter_response'=>true,
],is_array($hallConfig['features']??null)?$hallConfig['features']:[]);
$hallApi=(string)($hallConfig['api']??'operator/api.php');
$hallOrderApi=(string)($hallConfig['order_api']??'staff/api.php');
$hallFinanceApi=(string)($hallConfig['finance_api']??'finance/api.php');
$hallSubscriberApi=(string)($hallConfig['subscriber_api']??'subscribers/api.php');
$hallPrintingUrl=(string)($hallConfig['printing_url']??'');
$hallAutoLoad=(bool)($hallConfig['auto_load']??true);
?>
<section class="sc-hall-workspace" data-operator-workspace data-csrf="<?= htmlspecialchars($hallCsrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-api="<?= htmlspecialchars($hallApi,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-order-api="<?= htmlspecialchars($hallOrderApi,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-finance-api="<?= htmlspecialchars($hallFinanceApi,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-subscriber-api="<?= htmlspecialchars($hallSubscriberApi,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-can-order="<?= $canOrder?'1':'0' ?>" data-can-cashier="<?= $canCashier?'1':'0' ?>" data-can-supervise="<?= $canSupervise?'1':'0' ?>" data-staff-ui-mode="<?= htmlspecialchars($hallMode,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-feature-table-move="<?= $hallFeatures['table_move']?'1':'0' ?>" data-feature-committed-adjust="<?= $hallFeatures['committed_adjust']?'1':'0' ?>" data-feature-reprint="<?= $hallFeatures['reprint']?'1':'0' ?>" data-feature-discount="<?= $hallFeatures['discount']?'1':'0' ?>" data-feature-settlement="<?= $hallFeatures['settlement']?'1':'0' ?>" data-feature-settlement-itemized="<?= $hallFeatures['settlement_itemized']?'1':'0' ?>" data-feature-settlement-subscriber="<?= $hallFeatures['settlement_subscriber']?'1':'0' ?>" data-feature-settlement-accommodation="<?= $hallFeatures['settlement_accommodation']?'1':'0' ?>" data-feature-waiter-response="<?= $hallFeatures['waiter_response']?'1':'0' ?>" data-auto-load="<?= $hallAutoLoad?'1':'0' ?>">
  <div data-hall-view class="sc-hall-view-v107">
    <div class="sc-hall-split">
      <aside class="sc-hall-account sc-detail-rail" aria-label="حساب میز انتخاب‌شده">
        <div class="sc-hall-account__detail" data-account-pane aria-live="polite">
          <div class="sc-hall-account__empty"><span class="sc-hall-account__empty-icon" aria-hidden="true">▧</span><strong>حساب میز</strong><p>یک میز در سرویس را انتخاب کنید تا حساب همان میز اینجا باز شود.</p></div>
        </div>
      </aside>
      <main class="sc-hall-tables">
        <header class="sc-hall-toolbar">
          <div class="sc-hall-toolbar__title"><span class="sc-hall-toolbar__title-icon" aria-hidden="true">▦</span><div><h2>سالن و میزها</h2><small>وضعیت زنده میزها</small></div></div>
          <div class="sc-hall-toolbar__grow" aria-hidden="true"></div>
          <nav class="sc-hall-zones" aria-label="بخش سالن" data-zone-tabs></nav>
          <div class="sc-hall-display-controls" aria-label="نحوه نمایش میزها">
            <label class="sc-hall-sort"><span class="sr-only">مرتب‌سازی حساب‌های باز</span><select class="sc-control" data-hall-sort aria-label="مرتب‌سازی حساب‌های باز"><option value="number">شماره میز</option><option value="time">مدت حضور</option></select></label>
            <div class="sc-hall-density" role="group" aria-label="تراکم نمایش کارت میزها">
              <button type="button" data-hall-density="normal" aria-label="نمای عادی" title="نمای عادی"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-table"></use></svg></button>
              <button type="button" data-hall-density="compact" aria-label="نمای فشرده" title="نمای فشرده"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-list"></use></svg></button>
              <button type="button" data-hall-density="overview" aria-label="نمای کلی" title="نمای کلی"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-more"></use></svg></button>
            </div>
          </div>
          <?php if($canSupervise): ?><button class="sc-button sc-button--secondary sc-hall-acceptance-button" type="button" data-order-acceptance-open aria-label="وضعیت پذیرش سفارش آنلاین"><span>پذیرش آنلاین</span><span class="sc-badge" data-order-acceptance-badge>—</span></button><?php endif; ?>
          <button class="sc-button sc-button--secondary sc-hall-refresh" type="button" data-refresh aria-label="تازه‌سازی وضعیت سالن" title="تازه‌سازی وضعیت سالن"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-refresh"></use></svg></button>
          <div class="sc-hall-tools" data-hall-tools>
            <button class="sc-hall-tools__trigger" type="button" data-hall-tools-toggle aria-haspopup="menu" aria-expanded="false"><span aria-hidden="true">☷</span><span>ابزارهای شیفت</span></button>
            <div class="sc-hall-tools__menu" data-hall-tools-menu role="menu" hidden>
              <button type="button" role="menuitem" data-item-totals-open><strong>جمع اقلام</strong><small>نمای سریع اقلام حساب‌های باز</small></button>
              <?php if($hallPrintingUrl!==''): ?><a role="menuitem" class="sc-hall-tools__link" href="<?= htmlspecialchars($hallPrintingUrl,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>"><strong>تنظیمات کامل چاپ</strong><small>مقصدها، قالب‌ها و صف چاپ</small></a><?php endif; ?>
            </div>
          </div>
        </header>
        <section class="sc-attention-inbox" data-attention-section hidden aria-labelledby="hallAttentionTitle">
          <header class="sc-attention-inbox__head"><span class="sc-attention-inbox__icon" aria-hidden="true">!</span><div><strong id="hallAttentionTitle">نیازمند اقدام</strong><small data-attention-caption>اقدام‌های باز سالن</small></div><span class="sc-attention-inbox__count" data-attention-count>۰</span></header>
          <div class="sc-attention-list" data-attention-list></div>
        </section>
        <div class="sc-hall-status" role="status" aria-live="polite" data-status>در حال دریافت وضعیت سالن…</div>
        <div class="sc-hall-scroll" data-hall-scroll>
          <section class="sc-hall-section sc-hall-section--pending" data-hall-pending-section hidden><div class="sc-hall-section-head"><div><strong>منتظر تأیید</strong><small>سفارش مهمان هنوز وارد حساب نشده است</small></div><span class="sc-hall-count is-pending" data-pending-count>۰</span></div><div class="sc-hall-open-grid" data-pending-tables></div></section>
          <section class="sc-hall-section" data-hall-open-section><div class="sc-hall-section-head"><div><strong>در سرویس</strong><small>میزهای دارای حساب تأییدشده یا سفارش موقت</small></div><span class="sc-hall-count is-open" data-open-count>۰</span></div><div class="sc-hall-open-grid" data-open-tables></div></section>
          <section class="sc-hall-section" data-hall-free-section><div class="sc-hall-section-head"><div><strong>میزهای آزاد</strong><small>آماده ثبت سفارش</small></div><span class="sc-hall-count" data-free-count>۰</span></div><div class="sc-hall-free-grid" data-free-tables></div></section>
        </div>
      </main>
    </div>
  </div>
  <section class="sc-order-route" data-order-view hidden><div data-order-workspace-host></div></section>

  <div class="sui-settlement-root" data-settlement-mount></div>

  <dialog class="sc-dialog sc-hall-discount-dialog" data-hall-discount-dialog aria-labelledby="hallDiscountTitle" data-dialog-size="sm">
    <form method="dialog" class="sc-dialog-frame is-short sc-hall-discount-form" data-hall-discount-form>
      <header class="sc-dialog-frame__head sc-hall-discount-head"><div><h2 id="hallDiscountTitle" tabindex="-1">تخفیف حساب</h2><p>مبلغ قابل پرداخت را پیش از ثبت بررسی کنید.</p></div><button class="sc-icon-button" type="button" data-hall-discount-close aria-label="بستن"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-close"></use></svg></button></header>
      <div class="sc-dialog-frame__body sc-hall-discount-body">
        <div class="sc-hall-discount-current" data-discount-current hidden><div><span>تخفیف فعلی</span><strong data-discount-current-label>—</strong></div><button class="sc-button sc-button--danger-quiet" type="button" data-discount-remove>حذف تخفیف فعلی</button></div>
        <div class="sc-hall-discount-types" role="group" aria-label="نوع تخفیف"><button type="button" data-discount-type="percent">درصد</button><button type="button" data-discount-type="fixed">مبلغ</button></div>
        <label class="sc-field"><span class="sc-field__label" data-discount-input-label>درصد تخفیف</span><input class="sc-control" type="text" inputmode="numeric" autocomplete="off" data-discount-value placeholder="مثلاً ۱۰"></label>
        <div class="sc-hall-discount-preview" aria-live="polite"><div><span>جمع سفارش‌ها</span><strong data-discount-base>—</strong></div><div><span>مبلغ تخفیف</span><strong data-discount-amount>—</strong></div><div data-discount-tax-row hidden><span>مالیات پس از تخفیف</span><strong data-discount-tax>—</strong></div><div class="is-final"><span data-discount-net-label>قابل پرداخت نهایی</span><strong data-discount-net>—</strong></div></div>
        <div class="sc-hall-discount-preview-status" data-discount-preview-status role="status" hidden></div>
      </div>
      <footer class="sc-dialog-frame__foot sc-hall-discount-actions"><button class="sc-button" type="submit" data-discount-apply>اعمال تخفیف</button><button class="sc-button sc-button--secondary" type="button" data-hall-discount-close>انصراف</button></footer>
    </form>
  </dialog>

  <dialog class="sc-dialog sc-hall-items-dialog" data-item-totals-dialog aria-labelledby="hallItemsTitle" data-dialog-size="lg"><form method="dialog" class="sc-dialog-frame"><header class="sc-dialog-frame__head"><div><h2 id="hallItemsTitle" tabindex="-1">جمع اقلام حساب‌های باز</h2><p>نمای عملیاتی سفارش‌های تأییدشده در نشست‌های فعال؛ برای تصمیم سریع، نه گزارش حسابداری نهایی.</p></div><button class="sc-icon-button" type="button" data-dialog-close aria-label="بستن"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-close"></use></svg></button></header><div class="sc-dialog-frame__body sc-hall-items-dialog__body"><div class="sc-hall-item-totals-summary" data-item-total-summary></div><nav class="sc-hall-items-dialog__tabs" data-item-total-tabs aria-label="فیلتر محل آماده‌سازی"></nav><table class="sc-table sc-table--responsive"><thead><tr><th>آیتم</th><th>تعداد</th><th>جمع</th></tr></thead><tbody data-item-totals></tbody></table></div><footer class="sc-dialog-frame__foot"><button class="sc-button sc-button--secondary" type="button" data-dialog-close>بستن</button></footer></form></dialog>
  <?php if($canSupervise): ?>
  <dialog class="sc-dialog sc-hall-acceptance-dialog" data-order-acceptance-dialog aria-labelledby="hallAcceptanceTitle" data-dialog-size="md">
    <form method="dialog" class="sc-dialog-frame is-short sc-hall-acceptance-frame" data-order-acceptance-form>
      <header class="sc-dialog-frame__head sc-hall-acceptance-head"><div><h2 id="hallAcceptanceTitle" tabindex="-1">پذیرش سفارش آنلاین</h2><p>تغییر فقط روی سفارش‌های تازه مهمان اثر می‌گذارد؛ حساب‌ها و سفارش‌های ثبت‌شده ادامه پیدا می‌کنند.</p></div><button class="sc-icon-button" type="button" data-order-acceptance-close aria-label="بستن"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-close"></use></svg></button></header>
      <div class="sc-dialog-frame__body sc-hall-acceptance-body">
        <div class="sc-hall-acceptance-list">
          <label class="sc-hall-acceptance-row is-master"><span><strong>سفارش‌گیری آنلاین</strong><small>کنترل اصلی ثبت سفارش تازه مهمان از وب عمومی.</small></span><input type="checkbox" name="cafe" value="1"></label>
          <section class="sc-hall-acceptance-children" data-order-acceptance-children aria-labelledby="hallAcceptanceSectionsTitle">
            <div class="sc-hall-acceptance-children__head"><strong id="hallAcceptanceSectionsTitle">پذیرش بر اساس بخش</strong><small>تنظیمات بخش‌ها مستقل حفظ می‌شوند.</small></div>
            <label class="sc-hall-acceptance-row"><span><strong>آشپزخانه</strong><small>سفارش تازه آیتم‌های آشپزخانه</small></span><input type="checkbox" name="kitchen" value="1"></label>
            <label class="sc-hall-acceptance-row"><span><strong>بار</strong><small>سفارش تازه آیتم‌های بار سرد و گرم</small></span><input type="checkbox" name="bar" value="1"></label>
          </section>
        </div>
        <div class="sc-hall-acceptance-note" data-order-acceptance-note>می‌توانید پذیرش آشپزخانه و بار را جداگانه تنظیم کنید.</div>
        <div class="sc-hall-acceptance-sync"><div><span>ذخیره در Local</span><strong>مرجع اصلی تنظیم</strong></div><div><span>وب عمومی</span><strong data-order-acceptance-public>پس از ذخیره بررسی می‌شود</strong></div></div>
        <div class="sc-alert" data-order-acceptance-feedback hidden></div>
      </div>
      <footer class="sc-dialog-frame__foot sc-hall-acceptance-actions"><button class="sc-button" type="submit" value="save" data-order-acceptance-save>ذخیره تنظیمات</button><button class="sc-button sc-button--secondary" type="button" data-order-acceptance-close>انصراف</button></footer>
    </form>
  </dialog>
  <?php endif; ?>

  <dialog class="sc-dialog sc-account-item-edit-dialog" data-account-item-edit-dialog aria-labelledby="accountItemEditTitle" data-dialog-size="md">
    <div class="sc-dialog-frame is-short">
      <header class="sc-dialog-frame__head"><div><h2 id="accountItemEditTitle" data-account-item-edit-title tabindex="-1">ویرایش آیتم حساب</h2></div><button class="sc-icon-button" type="button" data-account-item-edit-close aria-label="بستن"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-close"></use></svg></button></header>
      <div class="sc-dialog-frame__body sc-account-item-editor" data-account-item-edit-body></div>
      <footer class="sc-dialog-frame__foot"><button class="sc-button" type="button" data-account-item-edit-save>ثبت تغییرات</button><button class="sc-button sc-button--secondary" type="button" data-account-item-edit-close>انصراف</button></footer>
    </div>
  </dialog>

  <dialog class="sc-dialog sc-table-move-dialog" data-table-move-dialog aria-labelledby="tableMoveTitle" data-dialog-size="lg">
    <div class="sc-dialog-frame is-short">
      <header class="sc-dialog-frame__head"><div><h2 id="tableMoveTitle" data-table-move-title tabindex="-1">انتقال حساب به میز دیگر</h2><p data-table-move-copy>میز مقصد را انتخاب کنید.</p></div><button class="sc-icon-button" type="button" data-table-move-close aria-label="بستن"><svg class="sc-ui-icon" aria-hidden="true"><use href="<?=$hallAsset('assets/ui-sprite.svg')?>#icon-close"></use></svg></button></header>
      <div class="sc-dialog-frame__body sc-table-move-body"><div class="sc-table-move-context" data-table-move-context></div><div class="sc-table-move-grid" data-table-move-grid></div></div>
      <footer class="sc-dialog-frame__foot sc-table-move-actions"><button class="sc-button" type="button" data-table-move-confirm disabled>انتخاب میز مقصد</button><button class="sc-button sc-button--secondary" type="button" data-table-move-close>انصراف</button></footer>
    </div>
  </dialog>
</section>
