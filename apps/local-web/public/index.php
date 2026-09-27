<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
$core=require __DIR__.'/_app.php';$user=LocalPage::user($core);ProductShell::start($core,$user,'خانه','home','مسیرهای کاری این حساب از همین‌جا در دسترس است.');
$links=ProductShell::navigation($core,$user);
?>
<section class="sc-stack" aria-label="دسترسی‌های اصلی">
  <div class="sc-launcher">
  <?php foreach($links as $item): if($item['id']==='home')continue; ?>
    <a class="sc-launcher__item" href="<?=htmlspecialchars($item['href'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><strong><?=htmlspecialchars($item['label'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></strong><span>ورود به فضای کاری</span></a>
  <?php endforeach; ?>
  </div>
  <?php if(count($links)===1): ?><div class="sc-alert">برای این حساب هنوز فضای کاری فعالی تعریف نشده است.</div><?php endif; ?>
</section>
<?php ProductShell::end(); ?>
