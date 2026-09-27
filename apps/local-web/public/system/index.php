<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'وضعیت سیستم','system','سلامت اجزا، تشخیص خطا و بسته پشتیبانی Local در یک نمای واحد.');
?>
<section class="sc-workspace" data-system-workspace data-api="/system/api.php" data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>">
  <div class="sc-toolbar"><div class="sc-actions"><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button><button class="sc-button" type="button" data-support>ساخت بسته پشتیبانی</button></div></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال بررسی وضعیت سیستم…</div>
  <section class="sc-card"><div class="sc-card__body"><div class="sc-section-head"><div><h2>خلاصه سلامت</h2><p>موارد بحرانی مانع اتکای عملیاتی هستند؛ هشدارها نیاز به بررسی دارند.</p></div><span class="sc-badge" data-overall>—</span></div><div class="sc-card-grid" data-checks></div></div></section>
  <section class="sc-card-grid" data-components></section>
  <section class="sc-card"><div class="sc-card__body"><div class="sc-section-head"><div><h2>رخدادهای اخیر</h2><p>فقط log ساخت‌یافته Local و با redaction نمایش داده می‌شود.</p></div></div><div class="sc-table-wrap"><table class="sc-table sc-table--responsive"><thead><tr><th>زمان</th><th>سطح</th><th>رخداد</th><th>Correlation</th></tr></thead><tbody data-logs></tbody></table></div></div></section>
  <div class="sc-alert sc-alert--info"><strong>مرز scope:</strong> Public Edge هنوز در G3 است؛ در این مرحله فقط config status آن نمایش داده می‌شود و active remote probing/update انجام نمی‌شود.</div>
</section>
<script src="/assets/system-diagnostics.js" defer></script>
<?php ProductShell::end(); ?>
