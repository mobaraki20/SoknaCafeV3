<?php
declare(strict_types=1);
/** Shared Reports workspace markup. */
$reportRangeEnabled=$reportRangeEnabled??true;
// Match the initial preset to the actual dates. A custom one-day range must not look like 7 days.
$reportRangePreset=0;
$reportFromDate=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)($from??''));
$reportToDate=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)($to??''));
if($reportFromDate instanceof \DateTimeImmutable && $reportToDate instanceof \DateTimeImmutable){
    $reportInterval=$reportFromDate->diff($reportToDate);
    if($reportInterval->invert===0){
        $reportRangePreset=match((int)$reportInterval->days){0=>1,6=>7,29=>30,default=>0};
    }
}
?>
<section class="sc-workspace sc-reports-workspace" data-reports data-api="<?=htmlspecialchars($workspaceApi,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" data-focus="<?= htmlspecialchars($focus,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <section class="sc-card sc-report-range-card">
    <form class="sc-card__body sc-report-range" data-report-form>
      <div class="sc-segmented sc-report-presets" role="group" aria-label="بازه سریع">
        <button type="button" class="<?= $reportRangePreset===1?'is-active':'' ?>" data-range-days="1" <?= $reportRangeEnabled?'':'disabled' ?>>امروز</button><button type="button" class="<?= $reportRangePreset===7?'is-active':'' ?>" data-range-days="7" <?= $reportRangeEnabled?'':'disabled' ?>>۷ روز</button><button type="button" class="<?= $reportRangePreset===30?'is-active':'' ?>" data-range-days="30" <?= $reportRangeEnabled?'':'disabled' ?>>۳۰ روز</button>
      </div>
      <label class="sc-field sc-report-range-field"><span class="sc-field__label">از</span><input class="sc-control" type="date" name="from" value="<?= htmlspecialchars($from,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" <?= $reportRangeEnabled?'':'disabled' ?>></label>
      <label class="sc-field sc-report-range-field"><span class="sc-field__label">تا</span><input class="sc-control" type="date" name="to" value="<?= htmlspecialchars($to,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" <?= $reportRangeEnabled?'':'disabled' ?>></label>
      <div class="sc-actions"><button class="sc-button" type="submit" <?= $reportRangeEnabled?'':'disabled' ?>>نمایش گزارش</button><?php if(($workspaceExportCurrent??'')!==''): ?><a class="sc-button sc-button--secondary" data-export-current href="<?=htmlspecialchars($workspaceExportCurrent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">خروجی اکسل همین گزارش</a><?php endif; ?><?php if($canAudit): ?><?php if(($workspaceExportAnalytical??'')!==''): ?><a class="sc-button sc-button--secondary" data-export-analytical href="<?=htmlspecialchars($workspaceExportAnalytical,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">خروجی کامل تحلیلی</a><?php endif; ?><?php endif; ?></div>
    </form>
  </section>
  <div class="sc-alert" data-status hidden></div>
  <nav class="sc-report-section-nav" aria-label="نوع گزارش" data-report-section-nav role="tablist">
    <a href="#report-overview" role="tab" aria-selected="true">خلاصه</a><a href="#report-operations" role="tab" aria-selected="false">عملیات</a><a href="#report-inventory" role="tab" aria-selected="false">انبار</a><a href="#report-margin" role="tab" aria-selected="false">حاشیه سود</a><a href="#report-financial" role="tab" aria-selected="false">مشتری و اقامتگاه</a><?php if($canAudit): ?><a href="#report-activity" role="tab" aria-selected="false">فعالیت</a><?php endif; ?><a href="#report-tenders" role="tab" aria-selected="false">پرداخت‌ها</a><a href="#report-trends" role="tab" aria-selected="false">روند</a>
  </nav>
  <section class="sc-report-section-anchor" id="report-overview" data-report-section="overview">
  <div class="sc-summary-grid sc-report-summary" data-summary></div>
  <div class="sc-report-insight-grid">
    <section class="sc-card sc-report-insight-card"><div class="sc-card__body"><div class="sc-section-head"><div><h2>ترکیب عملیات</h2><p>منبع سفارش، لغوها و وضعیت فراخوان گارسون در همین بازه.</p></div></div><div class="sc-report-compact-stats" data-order-mix></div></div></section>
    <section class="sc-card sc-report-insight-card"><div class="sc-card__body"><div class="sc-section-head"><div><h2>مقصد تسویه</h2><p>سهم پرداخت مستقیم، حساب مشتری و حساب اقامتگاه از اسناد ثبت‌شده.</p></div></div><div class="sc-report-destinations" data-destinations></div></div></section>
  </div>
  </section>
  <section class="sc-card sc-report-operations-card sc-report-section-anchor" id="report-operations" data-report-section="operations" data-operations-report>
    <div class="sc-card__body">
      <div class="sc-section-head"><div><h2>عملیات و کیفیت سرویس</h2><p>زمان پذیرش سفارش مهمان، مدت نشست میز و پاسخ فراخوان؛ میانگین‌ها فقط از timestampهای معتبر محاسبه می‌شوند.</p></div></div>
      <div class="sc-report-service-metrics" data-service-metrics></div>
      <div class="sc-report-operations-grid">
        <section><h3>وضعیت سفارش‌ها</h3><div class="sc-report-status-list" data-order-statuses></div></section>
        <section><h3>روز کاری / شیفت</h3><div class="sc-report-shift-list" data-operation-shifts></div></section>
      </div>
    </div>
  </section>
  <section class="sc-card sc-report-inventory-card sc-report-section-anchor" id="report-inventory" data-report-section="inventory" data-inventory-report>
    <div class="sc-card__body">
      <div class="sc-section-head"><div><h2>انبار و بهای مصرف</h2><p>هزینه‌های بازه بر مبنای زمان محلی وقوع/تحویل هستند؛ هزینه نامعلوم هرگز صفر فرض نمی‌شود.</p></div></div>
      <div class="sc-report-inventory-metrics" data-inventory-metrics></div>
      <div class="sc-alert sc-alert--warning" data-inventory-coverage hidden></div>
      <section class="sc-stack" id="purchase-price-changes" data-purchase-price-changes><div class="sc-section-head"><div><h3>تغییرات قیمت خرید</h3><p>مقایسه هر خرید با آخرین خرید معتبر و میانگین موزون قبل از همان خرید؛ snapshot تاریخی بعداً بازنویسی نمی‌شود.</p></div></div><form class="sc-toolbar sc-toolbar--wrap" data-purchase-price-filters><label class="sc-field sc-field--compact"><span class="sc-field__label">کالا</span><select class="sc-control" name="inventory_item_id"><option value="">همه کالاها</option></select></label><label class="sc-field sc-field--compact"><span class="sc-field__label">دسته‌بندی</span><select class="sc-control" name="category"><option value="">همه دسته‌ها</option></select></label><label class="sc-field sc-field--compact"><span class="sc-field__label">تأمین‌کننده</span><select class="sc-control" name="supplier"><option value="">همه تأمین‌کننده‌ها</option></select></label><label class="sc-field sc-field--compact"><span class="sc-field__label">جهت</span><select class="sc-control" name="direction"><option value="">همه</option><option value="increase">افزایش</option><option value="decrease">کاهش</option><option value="unchanged">بدون تغییر</option><option value="baseline">مبنای اولیه</option></select></label><label class="sc-field sc-field--compact"><span class="sc-field__label">مرتب‌سازی</span><select class="sc-control" name="sort"><option value="latest">جدیدترین</option><option value="percent">بیشترین درصد تغییر</option><option value="impact">بیشترین اثر ریالی</option></select></label></form><div class="sc-report-service-metrics" data-purchase-price-summary></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>زمان</th><th>کالا</th><th>قیمت جدید</th><th>نسبت به خرید قبلی</th><th>نسبت به میانگین</th><th>اثر ریالی</th><th>رسید</th></tr></thead><tbody data-purchase-price-rows></tbody></table></div><div class="sc-report-status-list" data-purchase-price-item-stats></div></section>
    </div>
  </section>
  <section class="sc-card sc-report-margin-card sc-report-section-anchor" id="report-margin" data-report-section="margin" data-margin-report>
    <div class="sc-card__body">
      <div class="sc-section-head"><div><h2>حاشیه مشارکت سفارش‌ها</h2><p>محور گزارش روز کاری سفارش است. درآمد و بهای مصرف با همان شناسهٔ آیتم سفارش تطبیق داده می‌شوند؛ مبنای قطعی، برآوردی و ناقص جداست. این عدد سود خالص کسب‌وکار نیست.</p></div></div>
      <div class="sc-report-service-metrics" data-margin-metrics></div>
      <div class="sc-alert sc-alert--warning" data-margin-coverage hidden></div>
      <div class="sc-report-operations-grid">
        <section><h3>آیتم‌ها</h3><div class="sc-report-status-list" data-margin-items></div></section>
        <section><h3>دسته‌بندی‌ها</h3><div class="sc-report-status-list" data-margin-categories></div></section>
      </div>
    </div>
  </section>
  <section class="sc-card sc-report-financial-context-card sc-report-section-anchor" id="report-financial" data-report-section="financial" data-financial-context-report>
    <div class="sc-card__body">
      <div class="sc-section-head"><div><h2>حساب مشتری و اقامتگاه</h2><p>مبالغ از اسناد مالی همین بازه می‌آیند؛ وضعیت انتقال اقامتگاه، وضعیت فعلی انتقال‌های مرتبط است.</p></div></div>
      <div class="sc-report-financial-context" data-financial-context></div>
      <div class="sc-alert sc-alert--warning" data-accommodation-attention hidden></div>
    </div>
  </section>
  <?php if($canAudit): ?>
  <section class="sc-card sc-report-activity-card sc-report-section-anchor" id="report-activity" data-report-section="activity" data-activity-report>
    <div class="sc-card__body">
      <div class="sc-section-head"><div><h2>فعالیت کاربران و رخدادهای سامانه</h2><p>رخدادهای قابل ممیزی در بازه انتخابی؛ جزئیات حساس، IP و user-agent در این نما نمایش داده نمی‌شوند.</p></div></div>
      <form class="sc-report-activity-filters" data-activity-form>
        <label class="sc-field"><span class="sc-field__label">کاربر</span><select class="sc-control" name="actor_id"><option value="">همه کاربران</option></select></label>
        <label class="sc-field"><span class="sc-field__label">حوزه</span><select class="sc-control" name="family"><option value="">همه حوزه‌ها</option></select></label>
        <label class="sc-field sc-report-activity-search"><span class="sc-field__label">جستجو</span><input class="sc-control" type="search" name="q" maxlength="120" placeholder="نام کاربر، عملیات یا شناسه"></label>
        <button class="sc-button sc-button--secondary" type="submit">اعمال فیلتر</button>
      </form>
      <div class="sc-report-activity-summary" data-activity-summary></div>
      <div class="sc-alert sc-alert--danger" data-activity-error hidden></div>
      <div class="sc-report-activity-list" data-activity-list></div>
      <div class="sc-actions sc-report-activity-more"><button class="sc-button sc-button--secondary" type="button" data-activity-more hidden>نمایش بیشتر</button></div>
    </div>
  </section>
  <?php endif; ?>
  <section class="sc-card sc-report-tender-card sc-report-section-anchor" id="report-tenders" data-report-section="tenders" data-tender-report>
    <div class="sc-card__body">
      <div class="sc-section-head"><div><h2>روش‌های پرداخت</h2><p>روز کاری برای عملیات کافه؛ روز بانکی ایران برای تطبیق کارتخوان و انتقال بانکی.</p></div></div>
      <div class="sc-report-tender-axes">
        <section><div class="sc-report-axis-head"><strong>بر مبنای روز کاری</strong><small>با cutoff عملیاتی مجموعه</small></div><div class="sc-report-tender-totals" data-tender-business></div></section>
        <section><div class="sc-report-axis-head"><strong>بر مبنای روز بانکی ایران</strong><small>۰۰:۰۰ تا ۲۳:۵۹:۵۹ به وقت ایران</small></div><div class="sc-report-tender-totals" data-tender-civil></div></section>
      </div>
      <div class="sc-report-tender-breakdowns">
        <section><h3>تفکیک روز کاری / شیفت</h3><div data-tender-shifts></div></section>
        <section><h3>تفکیک روز بانکی</h3><div data-tender-civil-daily></div></section>
      </div>
      <div class="sc-alert sc-alert--warning" data-tender-coverage hidden></div>
      <div class="sc-report-cross-day" data-tender-cross-day></div>
    </div>
  </section>
  <div class="sc-report-grid sc-report-section-anchor" id="report-trends" data-report-section="trends">
    <section class="sc-card sc-report-panel sc-report-panel--trend"><div class="sc-card__body"><div class="sc-section-head"><div><h2>روند روزانه</h2><p>فروش خالص و تعداد تسویه در بازه انتخابی.</p></div></div><div class="sc-report-trends" data-daily></div></div></section>
    <section class="sc-card sc-report-panel"><div class="sc-card__body"><div class="sc-section-head"><div><h2>آیتم‌های برتر</h2><p>آیتم‌هایی که بیشترین فروش را در این بازه ساخته‌اند.</p></div></div><div class="sc-report-ranking" data-top></div></div></section>
  </div>
</section><script src="<?=htmlspecialchars($workspaceAsset('assets/staff-app/data-panels.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script>
<script src="<?=htmlspecialchars($workspaceAsset('assets/reports-workspace.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script>
