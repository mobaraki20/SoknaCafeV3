<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
ProductShell::start($core,$user,'گزارش و تحلیل','reports','فروش، عملکرد و روندهای مجموعه را در یک نمای مدیریتی بررسی کن.');
$to=gmdate('Y-m-d');$from=gmdate('Y-m-d',time()-6*86400);
?>
<section class="sc-workspace sc-reports-workspace" data-reports data-api="reports/api.php">
  <section class="sc-card sc-report-range-card">
    <form class="sc-card__body sc-report-range" data-report-form>
      <div class="sc-segmented sc-report-presets" role="group" aria-label="بازه سریع">
        <button type="button" data-range-days="1">امروز</button><button type="button" class="is-active" data-range-days="7">۷ روز</button><button type="button" data-range-days="30">۳۰ روز</button>
      </div>
      <label class="sc-field"><span class="sc-field__label">از</span><input class="sc-control" type="date" name="from" value="<?= $from ?>"></label>
      <label class="sc-field"><span class="sc-field__label">تا</span><input class="sc-control" type="date" name="to" value="<?= $to ?>"></label>
      <button class="sc-button" type="submit">نمایش گزارش</button>
    </form>
  </section>
  <div class="sc-alert" data-status>در حال دریافت…</div>
  <div class="sc-summary-grid sc-report-summary" data-summary></div>
  <div class="sc-report-grid">
    <section class="sc-card sc-report-panel sc-report-panel--trend"><div class="sc-card__body"><div class="sc-section-head"><div><h2>روند روزانه</h2><p>فروش خالص و تعداد تسویه در بازه انتخابی.</p></div></div><div class="sc-report-trends" data-daily></div></div></section>
    <section class="sc-card sc-report-panel"><div class="sc-card__body"><div class="sc-section-head"><div><h2>آیتم‌های برتر</h2><p>آیتم‌هایی که بیشترین فروش را در این بازه ساخته‌اند.</p></div></div><div class="sc-report-ranking" data-top></div></div></section>
  </div>
</section><script src="<?=ProductShell::asset('assets/reports-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
