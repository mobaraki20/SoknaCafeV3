<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
ProductShell::start($core,$user,'گزارش و تحلیل','reports','گزارش‌ها از داده‌های canonical Local ساخته می‌شوند و نسخه محدود آن برای دسترسی راه‌دور publish می‌شود.');
$to=gmdate('Y-m-d');$from=gmdate('Y-m-d',time()-6*86400);
?>
<section class="sc-workspace" data-reports data-api="/reports/api.php"><div class="sc-alert" data-status>در حال دریافت…</div><section class="sc-card"><form class="sc-card__body sc-form" data-report-form><div class="sc-form-grid"><label class="sc-field"><span class="sc-field__label">از</span><input class="sc-control" type="date" name="from" value="<?= $from ?>"></label><label class="sc-field"><span class="sc-field__label">تا</span><input class="sc-control" type="date" name="to" value="<?= $to ?>"></label></div><button class="sc-button" type="submit">نمایش گزارش</button></form></section><div class="sc-card-grid" data-summary></div><div class="sc-card-grid"><section class="sc-card"><div class="sc-card__body"><h2>روند روزانه</h2><div class="sc-table-wrap"><table class="sc-table"><thead><tr><th>روز</th><th>فروش خالص</th><th>تسویه</th></tr></thead><tbody data-daily></tbody></table></div></div></section><section class="sc-card"><div class="sc-card__body"><h2>آیتم‌های برتر</h2><div class="sc-table-wrap"><table class="sc-table"><thead><tr><th>آیتم</th><th>تعداد</th><th>فروش</th></tr></thead><tbody data-top></tbody></table></div></div></section></div></section><script src="/assets/reports-workspace.js" defer></script>
<?php ProductShell::end(); ?>
