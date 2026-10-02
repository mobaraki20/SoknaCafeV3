<?php
declare(strict_types=1);
use Sokna\Local\UI\LocalPage;
use Sokna\Local\UI\ProductShell;
use Sokna\Local\UI\LocalUrl;
$core=require __DIR__.'/_app.php';
$user=LocalPage::user($core);
ProductShell::start($core,$user,'داشبورد','home','نمای مدیریتی امروز؛ روند، فعالیت و فقط مواردی که تصمیم می‌خواهند.');
function home_e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function home_icon(string $name): string{return '<svg class="sc-ui-icon" aria-hidden="true" focusable="false"><use href="'.home_e(\Sokna\Local\UI\AssetUrl::asset('/assets/ui-sprite.svg')).'#icon-'.home_e($name).'"></use></svg>';}
function home_url(string $path): string{return home_e(LocalUrl::path($path));}
function home_fa_num(string|int|float $v): string{return strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);}
function home_money(int $v): string{return home_fa_num(number_format($v)).' تومان';}
function home_weekday(DateTimeImmutable $d,bool $today=false): string{if($today)return 'امروز';$m=[1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه',6=>'شنبه',7=>'یکشنبه'];return $m[(int)$d->format('N')]??'';}
function home_delta(int $current,int $reference): ?int{return $reference>0?(int)round((($current-$reference)/$reference)*100):null;}
function home_relative(string $value,DateTimeZone $tz): string{
    if(trim($value)==='')return '—';
    try{$dt=new DateTimeImmutable($value,new DateTimeZone('UTC'));$dt=$dt->setTimezone($tz);$now=new DateTimeImmutable('now',$tz);$s=max(0,$now->getTimestamp()-$dt->getTimestamp());
        if($s<60)return 'همین حالا';if($s<3600)return home_fa_num(max(1,(int)floor($s/60))).' دقیقه پیش';if($s<86400)return home_fa_num((int)floor($s/3600)).' ساعت پیش';return home_fa_num((int)floor($s/86400)).' روز پیش';
    }catch(Throwable){return '—';}
}
function home_clock(string $value,DateTimeZone $tz): string{if(trim($value)==='')return '—';try{$dt=new DateTimeImmutable($value,new DateTimeZone('UTC'));return home_fa_num($dt->setTimezone($tz)->format('H:i'));}catch(Throwable){return '—';}}
function home_unit(int $quantity,string $unit): string{
    if($unit==='g'&&abs($quantity)>=1000)return home_fa_num(rtrim(rtrim(number_format($quantity/1000,2,'.',''),'0'),'.')).' کیلوگرم';
    if($unit==='ml'&&abs($quantity)>=1000)return home_fa_num(rtrim(rtrim(number_format($quantity/1000,2,'.',''),'0'),'.')).' لیتر';
    $labels=['g'=>'گرم','ml'=>'میلی‌لیتر','count'=>'عدد'];return home_fa_num($quantity).' '.($labels[$unit]??$unit);
}
function home_notification_icon(array $item): string{$u=strtolower((string)($item['target_url']??''));if(str_contains($u,'operator')||str_contains($u,'order'))return 'cart';if(str_contains($u,'operation')||str_contains($u,'inventory'))return 'archive';if(str_contains($u,'print')||str_contains($u,'integration'))return 'print';if(str_contains($u,'system'))return 'settings';return 'bell';}
function home_any($core,array $user,array $caps): bool{if((string)($user['role']??'')==='admin')return true;foreach($caps as $cap)if($core->auth()->hasCapability($cap,$user))return true;return false;}

$canReports=home_any($core,$user,['cashier_accounts','shift_supervision','staff_consumption_reports']);
$canOps=home_any($core,$user,['orders_floor','cashier_accounts','shift_supervision']);
$canInventory=home_any($core,$user,['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']);
$tzName=$core->config()->string('app.timezone','Asia/Tehran');try{$tz=new DateTimeZone($tzName);}catch(Throwable){$tz=new DateTimeZone('Asia/Tehran');}
try{$business=$core->businessClock()->assignment();$todayKey=(string)$business['business_date'];$today=new DateTimeImmutable($todayKey,$tz);}catch(Throwable){$today=new DateTimeImmutable('now',$tz);$todayKey=$today->format('Y-m-d');}$yesterdayKey=$today->modify('-1 day')->format('Y-m-d');$weekFrom=$today->modify('-6 days')->format('Y-m-d');
$todaySummary=['net_sales'=>0,'order_count'=>0,'avg_ticket'=>0];$yesterdaySummary=['net_sales'=>0,'avg_ticket'=>0];$weekDaily=[];$topItems=[];
if($canReports)try{
    $todayReport=$core->reporting()->report($todayKey,$todayKey);$todaySummary=array_merge($todaySummary,(array)($todayReport['summary']??[]));$topItems=array_slice((array)($todayReport['top_items']??[]),0,5);
    $yesterdayReport=$core->reporting()->report($yesterdayKey,$yesterdayKey);$yesterdaySummary=array_merge($yesterdaySummary,(array)($yesterdayReport['summary']??[]));
    $week=$core->reporting()->report($weekFrom,$todayKey);$map=[];foreach((array)($week['daily']??[]) as $r)$map[(string)$r['business_date']]=(int)($r['net_sales']??0);for($i=6;$i>=0;$i--){$d=$today->modify('-'.$i.' days');$k=$d->format('Y-m-d');$weekDaily[]=['label'=>home_weekday($d,$i===0),'value'=>$map[$k]??0];}
}catch(Throwable){}

$ops=['metrics'=>['open_tables'=>0,'attention_orders'=>0,'active_waiter_calls'=>0],'attention_orders'=>[],'waiter_calls'=>[],'tables'=>[]];
if($canOps)try{$ops=array_replace_recursive($ops,$core->orderWorkspace()->operatorSnapshot($user));}catch(Throwable){}

$inventory=[];$lowStock=[];
if($canInventory)try{
    $inventory=$core->operationsWorkspace()->inventoryItems();
    $lowStock=array_values(array_filter($inventory,static fn(array $r):bool=>(int)($r['active']??0)===1&&(int)($r['warning_threshold']??0)>0&&(int)($r['quantity_base']??0)<=(int)($r['warning_threshold']??0)));
    usort($lowStock,static fn(array $a,array $b):int=>(((int)$a['quantity_base']-(int)$a['warning_threshold'])<=>((int)$b['quantity_base']-(int)$b['warning_threshold']))?:strcmp((string)$a['name'],(string)$b['name']));
    $lowStock=array_slice($lowStock,0,5);
}catch(Throwable){}

$notificationItems=[];$unread=0;try{$ns=$core->notifications()->snapshot($user);$notificationItems=is_array($ns['items']??null)?$ns['items']:[];$unread=(int)($ns['unread']??0);}catch(Throwable){}

$metrics=[];
if($canReports){
    $metrics[]=['icon'=>'chart','tone'=>'success','label'=>'فروش خالص امروز','value'=>home_money((int)$todaySummary['net_sales']),'delta'=>home_delta((int)$todaySummary['net_sales'],(int)$yesterdaySummary['net_sales']),'helper'=>'نسبت به دیروز'];
    $metrics[]=['icon'=>'cart','tone'=>'info','label'=>'سفارش‌های امروز','value'=>home_fa_num((int)$todaySummary['order_count']),'delta'=>null,'helper'=>'کل سفارش‌های ثبت‌شده'];
}
if($canOps)$metrics[]=['icon'=>'table','tone'=>'neutral','label'=>'میزهای فعال','value'=>home_fa_num((int)($ops['metrics']['open_tables']??0)),'delta'=>null,'helper'=>'نشست باز در سالن'];
if($canReports)$metrics[]=['icon'=>'ticket','tone'=>'warning','label'=>'میانگین فاکتور','value'=>home_money((int)$todaySummary['avg_ticket']),'delta'=>home_delta((int)$todaySummary['avg_ticket'],(int)$yesterdaySummary['avg_ticket']),'helper'=>'نسبت به دیروز'];

$attention=[];
$pending=(array)($ops['attention_orders']??[]);if($pending){$oldest=$pending[0]??[];$attention[]=['tone'=>'danger','context'=>'سفارش','status'=>'فوری','title'=>home_fa_num(count($pending)).' سفارش منتظر رسیدگی','subtitle'=>($oldest?('قدیمی‌ترین · '.home_relative((string)($oldest['created_at']??''),$tz)):'در صف بررسی'),'href'=>'/operator/?work=attention&attention_filter=orders#attention'];}
$calls=(array)($ops['waiter_calls']??[]);if($calls){$oldest=$calls[0]??[];$attention[]=['tone'=>'warning','context'=>'سالن','status'=>'بررسی','title'=>home_fa_num(count($calls)).' فراخوان مهمان باز','subtitle'=>($oldest?('میز '.(string)($oldest['table_name']??'').' · '.home_relative((string)($oldest['created_at']??''),$tz)):'نیازمند بررسی'),'href'=>'/operator/?work=attention&attention_filter=calls#attention'];}
$usedNotificationIds=[];
if(count($attention)<4){foreach($notificationItems as $item){if(!empty($item['read_at']))continue;$id=(int)($item['id']??0);if($id>0)$usedNotificationIds[$id]=true;$attention[]=['tone'=>'info','context'=>'اعلان','status'=>'جدید','title'=>(string)($item['title']??'اعلان جدید'),'subtitle'=>(string)($item['body']??''),'href'=>(string)($item['target_url']??'/notifications/')];if(count($attention)>=4)break;}}
$recent=[];foreach($notificationItems as $item){$id=(int)($item['id']??0);if(isset($usedNotificationIds[$id]))continue;$recent[]=$item;if(count($recent)>=5)break;}
$maxTrend=max(1,...array_map(static fn(array $r):int=>(int)$r['value'],$weekDaily?:[['value'=>0]]));
$bottomPanels=($canReports?1:0)+($canInventory?1:0);
?>
<section class="sc-dashboard sc-dashboard--converged" aria-label="داشبورد مدیریتی">
  <?php if($metrics): ?>
  <div class="sc-dashboard-kpis" aria-label="شاخص‌های امروز">
    <?php foreach($metrics as $metric): $delta=$metric['delta']; ?>
    <article class="sc-card sc-dashboard-kpi" data-tone="<?=home_e((string)$metric['tone'])?>"><span class="sc-dashboard-kpi__icon"><?=home_icon((string)$metric['icon'])?></span><div><small><?=home_e((string)$metric['label'])?></small><strong><?=home_e((string)$metric['value'])?></strong><?php if(is_int($delta)): ?><span class="sc-dashboard-kpi__delta <?=$delta>=0?'is-up':'is-down'?>"><?=$delta>=0?'↑':'↓'?> <?=home_fa_num(abs($delta))?>٪ <?=home_e((string)$metric['helper'])?></span><?php else: ?><span><?=home_e((string)$metric['helper'])?></span><?php endif; ?></div></article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="sc-dashboard-main<?=$canReports?'':' is-compact'?>">
    <?php if($canReports): ?>
    <section class="sc-card sc-dashboard-trend"><div class="sc-card__body"><div class="sc-dashboard-panel-head"><div class="sc-panel-title-inline"><h2>روند فروش</h2><p>۷ روز اخیر؛ جهت حرکت بدون تکرار عدد امروز</p></div><a href="<?=home_url('/reports/')?>">گزارش کامل</a></div><div class="sc-dashboard-chart" aria-label="روند فروش هفت روز اخیر"><?php foreach($weekDaily as $row): $h=(int)$row['value']>0?max(5,(int)round(((int)$row['value']/$maxTrend)*100)):0; ?><div class="sc-dashboard-bar"><div class="sc-dashboard-bar__track"><i style="height:<?=$h?>%"></i></div><span><?=home_e($row['label'])?></span></div><?php endforeach; ?></div></div></section>
    <?php endif; ?>

    <section class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-dashboard-panel-head"><div class="sc-panel-title-inline"><h2>آخرین فعالیت‌ها</h2><p>رویدادهای اخیر این حساب</p></div><a href="<?=home_url('/notifications/')?>">مشاهده همه</a></div><div class="sc-dashboard-feed"><?php if(!$recent): ?><div class="sc-empty">فعالیت دیگری برای نمایش ثبت نشده است.</div><?php else: foreach($recent as $item): ?><a class="sc-dashboard-feed__item" href="<?=home_url((string)($item['target_url']??'/notifications/'))?>"><span class="sc-dashboard-feed__icon"><?=home_icon(home_notification_icon($item))?></span><span class="sc-dashboard-feed__copy"><strong><?=home_e((string)($item['title']??'اعلان'))?></strong><small><?=home_e((string)($item['body']??''))?></small></span><time><?=home_clock((string)($item['created_at']??''),$tz)?></time></a><?php endforeach; endif; ?></div></div></section>

    <section class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-dashboard-panel-head"><div class="sc-panel-title-inline"><h2>نیازمند اقدام</h2><p>فقط مواردی که تصمیم می‌خواهند</p></div></div><div class="sc-dashboard-attention"><?php if(!$attention): ?><div class="sc-dashboard-clear"><span><?=home_icon('check')?></span><div><strong>مورد بازی باقی نمانده</strong><small>در حال حاضر اقدامی برای این حساب لازم نیست.</small></div></div><?php else: foreach($attention as $item): ?><a class="sc-dashboard-attention__item" data-tone="<?=home_e((string)$item['tone'])?>" href="<?=home_url((string)$item['href'])?>"><i aria-hidden="true"></i><span class="sc-dashboard-attention__copy"><strong><?=home_e((string)$item['title'])?></strong><small><?=home_e((string)$item['subtitle'])?></small></span><span class="sc-dashboard-attention__tags"><span class="sc-dashboard-context"><?=home_e((string)$item['context'])?></span><span class="sc-badge sc-badge--<?=home_e((string)$item['tone'])?>"><?=home_e((string)$item['status'])?></span></span><span class="sc-dashboard-chevron"><?=home_icon('chevron-left')?></span></a><?php endforeach; endif; ?></div></div></section>
  </div>

  <?php if($bottomPanels>0): ?>
  <div class="sc-dashboard-bottom<?=$bottomPanels===1?' is-single':''?>">
    <?php if($canReports): ?><section class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-dashboard-panel-head"><div class="sc-panel-title-inline"><h2>پرفروش‌های امروز</h2><p>اقلام برتر بر اساس فروش خالص</p></div><a href="<?=home_url('/reports/')?>">تحلیل فروش</a></div><div class="sc-dashboard-ranked"><?php if(!$topItems): ?><div class="sc-empty">هنوز فروش ثبت‌شده‌ای برای امروز وجود ندارد.</div><?php else: foreach($topItems as $i=>$row): ?><div class="sc-dashboard-ranked__row"><b><?=home_fa_num($i+1)?></b><span><strong><?=home_e((string)($row['item_name']??'آیتم'))?></strong><small><?=home_fa_num((int)($row['quantity']??0))?> عدد</small></span><em><?=home_money((int)($row['net_sales']??0))?></em></div><?php endforeach; endif; ?></div></div></section><?php endif; ?>

    <?php if($canInventory): ?><section class="sc-card"><div class="sc-card__body sc-stack"><div class="sc-dashboard-panel-head"><div class="sc-panel-title-inline"><h2>اقلام نیازمند توجه</h2><p>فقط استثناهای واقعی انبار</p></div><a href="<?=home_url('/operations/?tab=inventory')?>">رفتن به انبار</a></div><div class="sc-dashboard-stock"><?php if(!$lowStock): ?><div class="sc-dashboard-clear"><span><?=home_icon('check')?></span><div><strong>کمبود فعالی ثبت نشده</strong><small>همه اقلام دارای حد هشدار بالاتر از حد خود هستند.</small></div></div><?php else: foreach($lowStock as $row): $qty=(int)($row['quantity_base']??0); ?><a href="<?=home_url('/operations/?tab=inventory&inventory_item='.(int)($row['id']??0))?>" class="sc-dashboard-stock__row"><span><strong><?=home_e((string)($row['name']??''))?></strong><small>حد هشدار <?=home_unit((int)($row['warning_threshold']??0),(string)($row['base_unit']??'count'))?></small></span><span class="sc-badge <?=$qty<=0?'sc-badge--danger':'sc-badge--warning'?>"><?=$qty<=0?'تمام‌شده':'کمبود'?></span><b><?=home_e(home_unit($qty,(string)($row['base_unit']??'count')))?></b><span class="sc-dashboard-chevron"><?=home_icon('chevron-left')?></span></a><?php endforeach; endif; ?></div></div></section><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php ProductShell::end(); ?>
