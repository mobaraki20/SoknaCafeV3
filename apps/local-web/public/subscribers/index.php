<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['cashier_accounts']);$csrf=WebAction::csrfToken();$isAdmin=(string)($user['role']??'')==='admin';$canPayment=true;
$workspaceApi='subscribers/api.php';$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);
ProductShell::start($core,$user,'مشتریان','subscribers','حساب مشتری، پرداخت‌ها و سابقه خرید را ساده و یک‌جا مدیریت کن.');
require dirname(__DIR__,2).'/resources/shared-staff-ui/subscribers-workspace.php';
ProductShell::end();
