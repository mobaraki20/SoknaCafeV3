<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\WebAction;
use Sokna\Local\UI\LocalUrl;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['orders_floor','cashier_accounts','shift_supervision']);
$csrf=WebAction::csrfToken();
$canOrder=$core->auth()->hasCapability('orders_floor',$user);
$canCashier=$core->auth()->hasCapability('cashier_accounts',$user);
$canSupervise=$core->auth()->hasCapability('shift_supervision',$user);
ProductShell::start($core,$user,'سالن و میزها','operator','وضعیت میزها، حساب جاری و موارد نیازمند اقدام را در یک فضای عملیاتی ببین.');
?>
<?php
$hallConfig=[
    'csrf'=>$csrf,'mode'=>'local','can_order'=>$canOrder,'can_cashier'=>$canCashier,'can_supervise'=>$canSupervise,
    'api'=>'operator/api.php','order_api'=>'staff/api.php','finance_api'=>'finance/api.php','subscriber_api'=>'subscribers/api.php','printing_url'=>LocalUrl::path('/printing/'),
    'features'=>['table_move'=>true,'committed_adjust'=>true,'reprint'=>true,'discount'=>true,'settlement'=>true,'waiter_response'=>true],
];
$hallAsset=static fn(string $path):string=>ProductShell::asset($path);
require dirname(__DIR__,2).'/resources/shared-staff-ui/hall-workspace.php';
?>
<script src="<?=ProductShell::asset('assets/order-line-ui.js')?>" defer></script>
<script src="<?=ProductShell::asset('assets/staff-app/order-draft-workspace.js')?>" defer></script>
<script src="<?=ProductShell::asset('assets/hall-settlement-v32.js')?>" defer></script>
<script src="<?=ProductShell::asset('assets/staff-app/hall-workspace.js')?>" defer></script>
<?php ProductShell::end(); ?>
