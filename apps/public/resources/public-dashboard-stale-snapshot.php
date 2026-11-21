<?php
declare(strict_types=1);
/** Public-only historical read-only summary; never a live KPI. */
$displayBusinessDay=static function(string $date): string {
    try { return explode(' · ',\Sokna\Shared\IranTime::display($date.' 00:00:00'),2)[0]; }
    catch (\Throwable) { return str_replace('-', '/', $date); }
};
?>
  <section class="sc-card sc-dashboard-snapshot" role="status" aria-label="آخرین اطلاعات ثبت‌شده، غیرزنده">
    <div class="sc-card__body">
      <div class="sc-dashboard-snapshot__head">
        <div class="sc-panel-title-inline">
          <h2>آخرین گزارش ثبت‌شده</h2>
          <p>روز کاری <time datetime="<?=$dashE((string)$publicSnapshot['business_date'])?>"><?=$displayBusinessDay((string)$publicSnapshot['business_date'])?></time> · آخرین دریافت ساعت <?=$dashClock((string)$publicSnapshot['last_sync_at'])?></p>
        </div>
        <span class="sc-badge sc-badge--warning">غیرزنده</span>
      </div>
      <p class="sc-dashboard-snapshot__notice">این ارقام مربوط به گزارش ذخیره‌شده هستند، نه فروش زنده روز کاری فعلی. اطلاعات امروز تا برقراری ارتباط تازه تأیید نمی‌شوند.</p>
      <div class="sc-dashboard-snapshot__stats">
        <div><small>فروش خالص در آن روز</small><strong><?=$dashMoney((int)$publicSnapshot['net_sales'])?></strong></div>
        <?php if($publicSnapshot['order_count']!==null): ?><div><small>سفارش‌های ثبت‌شده</small><strong><?=$dashFaNum((int)$publicSnapshot['order_count'])?></strong></div><?php endif; ?>
      </div>
      <div class="sc-dashboard-snapshot__foot"><span>روز کاری فعلی: <?=$displayBusinessDay((string)$publicDayContext['business_day'])?> · تغییر روز ساعت <?=$dashFaNum((string)$publicDayContext['cutoff'])?></span><a href="<?=$dashUrl('/reports/?from='.$publicSnapshot['business_date'].'&to='.$publicSnapshot['business_date'].'&focus=sales')?>">گزارش همین روز</a></div>
    </div>
  </section>
