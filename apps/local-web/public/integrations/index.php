<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['cashier_accounts']);$isAdmin=(string)($user['role']??'')==='admin';$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'چاپ و اتصال‌ها','integrations','اتصال اقامتگاه و چاپگرهای مجموعه را از همین بخش مدیریت کن.',true);
?>
<section class="sc-workspace" data-integrations-workspace data-api="integrations/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-admin="<?= $isAdmin?'1':'0' ?>">
  <div class="sc-toolbar sc-local-nav"><div class="sc-tabs" role="tablist" aria-label="چاپ و اتصال‌ها"><button class="sc-tab" type="button" role="tab" aria-selected="true" data-tab="accommodation">اقامتگاه</button><?php if($isAdmin): ?><button class="sc-tab" type="button" role="tab" aria-selected="false" data-tab="printing">چاپ</button><?php endif; ?></div><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت وضعیت…</div>

  <section class="sc-work-panel" data-panel="accommodation">
    <div class="sc-section-head"><div><h2>انتقال حساب به اقامتگاه</h2><p data-accommodation-endpoint>وضعیت اتصال در حال دریافت است.</p></div></div>
    <div class="sc-grid"><article class="sc-card"><div class="sc-card__body sc-form"><h3>ساخت انتقال</h3><label class="sc-field"><span class="sc-field__label">حساب باز</span><select class="sc-control" data-accommodation-account></select></label><label class="sc-field"><span class="sc-field__label">جست‌وجوی رزرو</span><input class="sc-control" data-reservation-query placeholder="نام، موبایل یا کد رزرو"></label><button class="sc-button sc-button--secondary" type="button" data-reservation-search>جست‌وجو</button><div class="sc-list" data-reservation-results></div></div></article><article class="sc-card"><div class="sc-card__body sc-stack"><h3>انتقال‌های اخیر</h3><div class="sc-list" data-transfer-list></div></div></article></div>
  </section>

  <?php if($isAdmin): ?><section class="sc-work-panel" data-panel="printing" hidden>
    <div class="sc-section-head"><div><h2>مدیریت چاپ</h2><p>دستگاه‌ها، مقصدها، قالب‌ها و وضعیت صف چاپ را در یک فضای کاری ببین.</p></div><button class="sc-button" type="button" data-create-agent>افزودن دستگاه چاپ</button></div>
    <div class="sc-print-shell">
      <aside class="sc-print-aside" aria-label="راهنمای چاپ">
        <article class="sc-card sc-print-hero"><div class="sc-card__body"><span class="sc-kicker">فضای چاپ</span><h3>چاپ بدون توقف، با اطلاعات روشن</h3><p>وضعیت روزمره را در یک نگاه ببین؛ جزئیات فنی فقط وقتی لازم است.</p></div></article>
      </aside>
      <div class="sc-print-main">
        <div class="sc-summary-grid sc-print-kpis"><article class="sc-card sc-metric"><span>دستگاه چاپ فعال</span><strong data-print-metric="agents">—</strong><small>دستگاه‌های متصل و فعال</small></article><article class="sc-card sc-metric"><span>نیازمند بررسی</span><strong data-print-metric="unknown">—</strong><small>کارهایی که تصمیم انسانی می‌خواهند</small></article><article class="sc-card sc-metric"><span>کارهای در انتظار</span><strong data-print-metric="backlog">—</strong><small>صف فعلی چاپ</small></article></div>
        <div class="sc-grid sc-print-pairs" id="print-devices"><article class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-panel-title-inline"><h3>دستگاه‌های چاپ</h3><p>رایانه‌ها و Agentهای متصل</p></div><div class="sc-list" data-agent-list></div></div></article><article class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-panel-title-inline"><h3>مقصدهای چاپ</h3><p>مسیر هر سند تا چاپگر ویندوز</p></div><div class="sc-list" data-destination-list></div></div></article></div>
        <section class="sc-card" id="print-templates"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h3>قالب‌های چاپ</h3><p>قالب رسید را وارد، پیش‌نمایش و فعال کن؛ نسخه‌های قبلی حفظ می‌شوند.</p></div><label class="sc-button sc-button--secondary" for="print-template-file">وارد کردن قالب</label><input id="print-template-file" type="file" accept=".soknaprint,application/json" data-print-template-file hidden></div><div class="sc-list" data-print-template-list></div></div></section>
        <section class="sc-card" id="print-history"><div class="sc-card__body sc-stack"><div class="sc-panel-title-inline"><h3>تاریخچه چاپ</h3><p>آخرین درخواست‌ها و وضعیت نهایی آن‌ها</p></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>مقصد</th><th>نوع</th><th>وضعیت</th><th>توضیح</th><th><span class="sc-visually-hidden">عملیات</span></th></tr></thead><tbody data-print-jobs></tbody></table></div></div></section>
      </div>
    </div>
  </section>

  <?php endif; ?>

  <?php if($isAdmin): ?><dialog class="sc-dialog" data-dialog="preview" aria-labelledby="sc-dialog-title-integrations-preview"><div class="sc-card__body sc-stack"><div class="sc-section-head"><div><h2 id="sc-dialog-title-integrations-preview">پیش‌نمایش قالب چاپ</h2><p class="sc-muted" data-preview-meta></p></div><button class="sc-button sc-button--secondary" type="button" data-preview-close>بستن</button></div><div class="sc-empty" data-preview-empty>پیش‌نمایش در حال دریافت است…</div><img data-preview-image alt="پیش‌نمایش رسید چاپ" class="sc-print-preview" hidden></div></dialog><dialog class="sc-dialog" data-dialog="destination" aria-labelledby="sc-dialog-title-integrations-destination"><form method="dialog" class="sc-card__body sc-form" data-form="destination"><h2 id="sc-dialog-title-integrations-destination">تنظیم مقصد چاپ</h2><input type="hidden" name="destination_key"><p class="sc-muted" data-destination-label></p><label class="sc-field"><span class="sc-field__label">دستگاه چاپ</span><select class="sc-control" name="agent_id" data-agent-select></select></label><label class="sc-field"><span class="sc-field__label">نام چاپگر در ویندوز</span><input class="sc-control" name="windows_queue_name" required></label><label class="sc-field"><span class="sc-field__label">تعداد نسخه چاپی</span><input class="sc-control" name="copies" type="number" min="1" max="5" value="1"></label><label class="sc-field"><span class="sc-field__label">عرض کاغذ (میلی‌متر)</span><input class="sc-control" name="paper_width_mm" inputmode="decimal" value="80"></label><label class="sc-field"><span class="sc-field__label">عرض قابل چاپ (میلی‌متر)</span><input class="sc-control" name="printable_width_mm" inputmode="decimal" value="72.1"></label><label class="sc-inline"><input type="checkbox" name="active" value="1"> فعال</label><div class="sc-actions"><button class="sc-button" type="submit" value="submit">ذخیره</button><button class="sc-button sc-button--secondary" type="button" data-dialog-close value="cancel">انصراف</button></div></form></dialog><?php endif; ?>
</section>
<script src="<?=ProductShell::asset('assets/integrations-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
