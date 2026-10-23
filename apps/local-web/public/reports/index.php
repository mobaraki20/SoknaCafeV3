<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;use Sokna\Local\UI\ProductShell;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::requireAny($core,['cashier_accounts','shift_supervision','staff_consumption_reports']);
ProductShell::start($core,$user,'گزارش و تحلیل','reports','فروش، عملکرد و روندهای مجموعه را در یک نمای مدیریتی بررسی کن.');
$civilToday=$core->time()->civilDate();$civilWeekStart=(new DateTimeImmutable($civilToday))->modify('-6 days')->format('Y-m-d');$to=(string)($_GET['to']??$civilToday);$from=(string)($_GET['from']??$civilWeekStart);$focus=preg_replace('/[^a-z_]/','',(string)($_GET['focus']??''));$canAudit=(string)($user['role']??'')==='admin'||$core->auth()->hasCapability('shift_supervision',$user);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=$civilWeekStart;if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=$civilToday;
$workspaceApi='reports/api.php';$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);$workspaceExportCurrent='reports/export.php';$workspaceExportAnalytical='reports/export-analytical.php';$reportRangeEnabled=true;
require dirname(__DIR__,2).'/resources/shared-staff-ui/reports-workspace.php';
ProductShell::end();
