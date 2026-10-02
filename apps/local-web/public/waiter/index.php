<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['preparation','shift_supervision']);
$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'آماده‌سازی','preparation','سفارش‌های تأییدشده را ببین، دریافت کن و مراحل آماده‌سازی را سریع ثبت کن.');
?>
<section class="sc-workspace" data-preparation-workspace data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-api="waiter/api.php">
  <section class="sc-preparation-panel">
    <div class="sc-section-head"><div><h2>صف آماده‌سازی</h2><p>آیتم‌های تأییدشده امروز؛ دریافت هر بخش به نام همکار ثبت می‌شود.</p></div><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button></div>
    <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت صف…</div>
    <div class="sc-stack sc-preparation-list" data-preparation-list></div>
  </section>
</section>
<script src="<?=ProductShell::asset('assets/preparation-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
