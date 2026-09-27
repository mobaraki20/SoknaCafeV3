<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);ProductShell::start($core,$user,'مدیریت','admin','تنظیمات، کاربران و ابزارهای مدیریتی در checkpointهای بعدی روی همین shell تکمیل می‌شوند.');
?>
<section class="sc-grid"><article class="sc-card"><div class="sc-card__body sc-stack"><h2>محصول و تنظیمات</h2><p class="sc-muted">ساختار مدیریتی آمادهٔ اتصال به صفحات کاربران، میزها، آیتم‌ها، مالی و یکپارچه‌سازی‌هاست.</p></div></article><article class="sc-card"><div class="sc-card__body sc-stack"><h2>اصل دسترسی</h2><p class="sc-muted">این بخش فقط برای نقش مدیر باز است و permission check روی سرور انجام می‌شود.</p></div></article></section>
<?php ProductShell::end(); ?>
