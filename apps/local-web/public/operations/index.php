<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;
$core=require dirname(__DIR__).'/_app.php';
$user=LocalPage::requireAny($core,['inventory_view','inventory_cost_view','inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']);
$isAdmin=(string)($user['role']??'')==='admin';$canOps=$isAdmin||$core->auth()->hasCapability('inventory_operations',$user);$canManage=$isAdmin||$core->auth()->hasCapability('inventory_manage',$user);$canFinalize=$isAdmin||$core->auth()->hasCapability('inventory_finalize',$user);$canBuy=$isAdmin||$canOps||$canManage||$canFinalize;$csrf=WebAction::csrfToken();
$canReport=$core->supplyAccess()->canReportNeeds($user);$allowedNeedDepartments=$canReport?$core->supplyAccess()->allowedNeedDepartments($user):[];$canCost=$isAdmin||$core->auth()->hasCapability('inventory_cost_view',$user);$workspaceHost='local';$workspaceActor=(string)$user['id'];
$workspaceApi='operations/api.php';$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);
ProductShell::start($core,$user,'انبار، تأمین و هزینه‌ها','operations','موجودی، نیازهای خرید و هزینه‌های کافه را از یک فضای کاری مدیریت کن.',true);
require dirname(__DIR__,2).'/resources/shared-staff-ui/operations-workspace.php';
ProductShell::end();
