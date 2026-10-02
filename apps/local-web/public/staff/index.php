<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['orders_floor']);
$csrf=WebAction::csrfToken();
ProductShell::start($core,$user,'ثبت سفارش','staff','سفارش میز را سریع، دقیق و بدون خروج از جریان سالن ثبت کن.');
?>
<section class="sc-workspace sc-order-route" data-staff-order-route data-csrf="<?= htmlspecialchars($csrf,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" data-api="staff/api.php">
  <div data-order-workspace-host></div>
</section>
<script src="<?=ProductShell::asset('assets/order-draft-workspace.js')?>" defer></script>
<script src="<?=ProductShell::asset('assets/staff-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
