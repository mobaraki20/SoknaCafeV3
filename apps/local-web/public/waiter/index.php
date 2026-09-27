<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['preparation']);ProductShell::start($core,$user,'آماده‌سازی','preparation','نمایش و اقدام فقط طبق محدوده آماده‌سازی مجاز همین حساب انجام می‌شود.');
?>
<section class="sc-placeholder"><h2>پایه آماده‌سازی آماده است</h2><p>Feed و actionهای آشپزخانه/بار در G1.3 روی PreparationService canonical متصل می‌شوند.</p></section>
<?php ProductShell::end(); ?>
