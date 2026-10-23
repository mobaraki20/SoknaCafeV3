<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\LocalUrl;
$core=require dirname(__DIR__).'/_app.php';$user=LocalPage::user($core);
ProductShell::start($core,$user,'راهنمای سکنا','help','راهنمای کوتاهِ کارهای روزانه؛ هر بخش مستقیم به همان فضای کاری وصل است.');
function help_e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function help_url(string $v): string{return help_e(LocalUrl::path($v));}
function help_any($core,array $user,array $caps): bool{if((string)($user['role']??'')==='admin')return true;foreach($caps as $cap)if($core->auth()->hasCapability($cap,$user))return true;return false;}
$sections=[];
if(help_any($core,$user,['orders_floor','cashier_accounts','shift_supervision']))$sections[]=['title'=>'سالن و سفارش','text'=>'برای دیدن میزهای در سرویس، حساب باز و ثبت سفارش از سالن شروع کن.','links'=>[['سالن و میزها','/operator/'],['ثبت سفارش','/staff/']]];
if($core->auth()->hasCapability('preparation',$user))$sections[]=['title'=>'آماده‌سازی','text'=>'صف آشپزخانه و بار، دریافت آیتم و وضعیت آماده‌سازی در این بخش است.','links'=>[['باز کردن آماده‌سازی','/waiter/']]];
if(help_any($core,$user,['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']))$sections[]=['title'=>'انبار و تأمین','text'=>'موجودی، شمارش، ضایعات و خریدهای تأمین را از یک فضای عملیاتی مدیریت کن.','links'=>[['رفتن به انبار و تأمین','/operations/']]];
if(help_any($core,$user,['cashier_accounts','shift_supervision','staff_consumption_reports']))$sections[]=['title'=>'مالی و گزارش','text'=>'تسویه، فاکتور، دوره مالی و گزارش‌های مجموعه اینجا قرار دارند.','links'=>[['مالی','/finance/'],['گزارش و تحلیل','/reports/']]];
if(help_any($core,$user,['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports']))$sections[]=['title'=>'مصرف پرسنل','text'=>'مصرف، مزایا و حساب پرسنل از این مسیر انجام می‌شود.','links'=>[['مصرف پرسنل','/staff-consumption/']]];
if((string)($user['role']??'')==='admin')$sections[]=['title'=>'مدیریت و اتصال‌ها','text'=>'اعضای تیم، امکانات، اتصال Public Edge و Windows Services، چاپ و نگهداری سامانه در این بخش‌ها هستند.','links'=>[['مدیریت','/admin/'],['اتصال‌ها','/integrations/'],['چاپ','/printing/'],['پشتیبانی و نگهداری','/system/']]];
?>
<section class="sc-workspace sc-help-workspace">
  <section class="sc-card sc-help-intro"><div class="sc-card__body sc-stack"><h2>از کجا شروع کنم؟</h2><p>راهنما فقط مسیر کار را روشن می‌کند؛ هر دکمه تو را به همان بخش واقعی سامانه می‌برد. برای پیدا کردن یک مشتری، میز، آیتم یا تنظیم هم می‌توانی از جست‌وجوی سراسری بالای صفحه استفاده کنی.</p></div></section>
  <div class="sc-help-grid">
    <?php foreach($sections as $section): ?>
    <article class="sc-card"><div class="sc-card__body sc-stack"><h2><?=help_e($section['title'])?></h2><p class="sc-muted"><?=help_e($section['text'])?></p><div class="sc-actions"><?php foreach($section['links'] as [$label,$href]): ?><a class="sc-button sc-button--secondary" href="<?=help_url($href)?>"><?=help_e($label)?></a><?php endforeach; ?></div></div></article>
    <?php endforeach; ?>
  </div>
  <section class="sc-card"><div class="sc-card__body sc-stack"><h2>اگر چیزی درست کار نکرد</h2><p class="sc-muted">ابتدا همان صفحه را تازه‌سازی کن. اگر مشکل ادامه داشت، مدیر می‌تواند از «پشتیبانی و نگهداری» وضعیت سلامت و فایل پشتیبانی را بررسی کند.</p><?php if((string)($user['role']??'')==='admin'): ?><div class="sc-actions"><a class="sc-button sc-button--secondary" href="<?=help_url('/system/')?>">باز کردن پشتیبانی و نگهداری</a></div><?php endif; ?></div></section>
</section>
<?php ProductShell::end(); ?>
