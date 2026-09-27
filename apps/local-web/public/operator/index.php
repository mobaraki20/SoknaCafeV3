<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['orders_floor','cashier_accounts','shift_supervision']);ProductShell::start($core,$user,'کار روزانه','operator','سفارش‌های تازه، میزها، فراخوان مهمان و تسویه روی این فضای کاری تجمیع می‌شوند.');
?>
<section class="sc-placeholder"><h2>پایه کار روزانه آماده است</h2><p>در G1.3، وضعیت سفارش‌ها، میزها و فراخوان‌ها با ownerهای Local موجود به این صفحه متصل می‌شوند.</p></section>
<?php ProductShell::end(); ?>
