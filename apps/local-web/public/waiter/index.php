<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['preparation','shift_supervision']);
$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'آماده‌سازی','preparation','دیدن سفارش‌ها و اقدام روی آشپزخانه/بار دقیقاً طبق محدوده قابل‌مشاهده و قابل‌اقدام همین حساب انجام می‌شود.');
?>
<section class="sc-workspace" data-preparation-workspace data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-api="/waiter/api.php">
  <div class="sc-toolbar"><div><strong>صف آماده‌سازی</strong><p class="sc-muted">آیتم‌های تأییدشده امروز؛ دریافت هر بخش به نام همکار ثبت می‌شود.</p></div><button class="sc-button sc-button--secondary" type="button" data-refresh>تازه‌سازی</button></div>
  <div class="sc-alert" role="status" aria-live="polite" data-status>در حال دریافت صف…</div>
  <div class="sc-stack" data-preparation-list></div>
</section>
<script src="/assets/preparation-workspace.js" defer></script>
<?php ProductShell::end(); ?>
