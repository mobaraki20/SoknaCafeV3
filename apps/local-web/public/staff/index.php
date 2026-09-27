<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['orders_floor']);ProductShell::start($core,$user,'سفارش سریع','staff','ثبت سفارش همکاران و پیش‌نویس مشترک میز روی ownerهای canonical Local اجرا می‌شود.');
?>
<section class="sc-placeholder"><h2>پایه سفارش سریع آماده است</h2><p>فرم واقعی Quick Order و Table Draft در G1.3 روی همین shell مهاجرت می‌شود.</p></section>
<?php ProductShell::end(); ?>
