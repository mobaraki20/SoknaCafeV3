<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;use Sokna\Local\UI\WebAction;use Sokna\Local\UI\LocalUrl;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::user($core);$csrf=WebAction::csrfToken();$isAdmin=(string)($user['role']??'')==='admin';$canMarkRead=true;
$workspaceApi='notifications/api.php';$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);$notificationSettingsUrl=LocalUrl::path('/account/?section=notifications');$notificationAdminUrl=$isAdmin?LocalUrl::path('/admin/?tab=notifications'):'';
ProductShell::start($core,$user,'اعلان‌ها','notifications','اعلان‌های مهم را داخل سامانه یا روی همین دستگاه دریافت و مدیریت کن.');
require dirname(__DIR__,2).'/resources/shared-staff-ui/notifications-workspace.php';
ProductShell::end();
