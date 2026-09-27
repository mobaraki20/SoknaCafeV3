<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAdmin($core);ProductShell::start($core,$user,'مدیریت','admin','تنظیمات، کاربران و ابزارهای مدیریتی در checkpointهای بعدی روی همین shell تکمیل می‌شوند.');
?>
<section class="sc-grid"><a class="sc-launcher__item" href="/operations/"><strong>انبار، تأمین و هزینه‌ها</strong><span>موجودی، شمارش، نیاز خرید، تحویل و هزینه‌های کافه</span></a><a class="sc-launcher__item" href="/finance/"><strong>مالی و تسویه</strong><span>حساب‌ها، رسیدها، دوره مالی و مالیات</span></a><a class="sc-launcher__item" href="/subscribers/"><strong>مشتریان</strong><span>مانده، پرداخت و گردش حساب مشتری</span></a><article class="sc-card"><div class="sc-card__body sc-stack"><h2>بخش‌های بعدی</h2><p class="sc-muted">کاربران، میزها، آیتم‌ها و یکپارچه‌سازی‌ها در checkpointهای بعدی G1 تکمیل می‌شوند.</p></div></article><article class="sc-card"><div class="sc-card__body sc-stack"><h2>اصل دسترسی</h2><p class="sc-muted">هر action حساس روی سرور دوباره permission و CSRF را بررسی می‌کند.</p></div></article></section>
<?php ProductShell::end(); ?>
