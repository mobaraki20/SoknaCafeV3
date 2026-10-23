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
<?php
$preparationConfig=['mode'=>'local','csrf'=>$csrf,'api'=>'waiter/api.php','auto_load'=>true];
require dirname(__DIR__,2).'/resources/shared-staff-ui/preparation-workspace.php';
?>
<?php ProductShell::end(); ?>
