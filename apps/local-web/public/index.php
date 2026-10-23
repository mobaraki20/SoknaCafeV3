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
function home_money(int $v): string{return home_fa_num(str_replace(',', '٬', number_format($v))).' تومان';}
function home_weekday(DateTimeImmutable $d,bool $today=false): string{if($today)return 'امروز';$m=[1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه',6=>'شنبه',7=>'یکشنبه'];return $m[(int)$d->format('N')]??'';}
function home_delta(int $current,int $reference): ?int{return $reference>0?(int)round((($current-$reference)/$reference)*100):null;}
function home_relative(string $value,DateTimeZone $tz): string{
    if(trim($value)==='')return '—';
    try{$dt=new DateTimeImmutable($value,new DateTimeZone('Asia/Tehran'));$dt=$dt->setTimezone($tz);$now=new DateTimeImmutable('now',$tz);$s=max(0,$now->getTimestamp()-$dt->getTimestamp());
        if($s<60)return 'همین حالا';if($s<3600)return home_fa_num(max(1,(int)floor($s/60))).' دقیقه پیش';if($s<86400)return home_fa_num((int)floor($s/3600)).' ساعت پیش';return home_fa_num((int)floor($s/86400)).' روز پیش';
    }catch(Throwable){return '—';}
}
function home_clock(string $value,DateTimeZone $tz): string{if(trim($value)==='')return '—';try{$dt=new DateTimeImmutable($value,new DateTimeZone('Asia/Tehran'));return home_fa_num($dt->setTimezone($tz)->format('H:i'));}catch(Throwable){return '—';}}
function home_unit(int $quantity,string $unit): string{
    if($unit==='g'&&abs($quantity)>=1000)return home_fa_num(rtrim(rtrim(number_format($quantity/1000,2,'.',''),'0'),'.')).' کیلوگرم';
    if($unit==='ml'&&abs($quantity)>=1000)return home_fa_num(rtrim(rtrim(number_format($quantity/1000,2,'.',''),'0'),'.')).' لیتر';
    $labels=['g'=>'گرم','ml'=>'میلی‌لیتر','count'=>'عدد'];return home_fa_num($quantity).' '.($labels[$unit]??$unit);
}
function home_notification_icon(array $item): string{$u=strtolower((string)($item['target_url']??''));if(str_contains($u,'operator')||str_contains($u,'order'))return 'cart';if(str_contains($u,'operation')||str_contains($u,'inventory'))return 'archive';if(str_contains($u,'print')||str_contains($u,'integration'))return 'print';if(str_contains($u,'system'))return 'settings';return 'bell';}
function home_any($core,array $user,array $caps): bool{if((string)($user['role']??'')==='admin')return true;foreach($caps as $cap)if($core->auth()->hasCapability($cap,$user))return true;return false;}
function home_degraded(?array $error,string $fallback): string{if($error===null)return '';$message=trim((string)($error['message']??''));if($message==='')$message=$fallback;$support=trim((string)($error['support_id']??''));return '<div class="sc-empty sc-dashboard-degraded"><strong>'.home_e($message).'</strong>'.($support!==''?'<small>کد پشتیبانی '.home_e($support).'</small>':'').'</div>';}

$canReports=home_any($core,$user,['cashier_accounts','shift_supervision','staff_consumption_reports']);
$canOps=home_any($core,$user,['orders_floor','cashier_accounts','shift_supervision']);
$canInventory=home_any($core,$user,['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']);
$tzName='Asia/Tehran';try{$tz=new DateTimeZone($tzName);}catch(Throwable){$tz=new DateTimeZone('Asia/Tehran');}
$dashboardErrors=[];
$clockSection=$core->snapshotGuard()->capture('dashboard','business_clock',null,'تاریخ روز کاری فعلاً در دسترس نیست.',fn()=> $core->businessClock()->assignment());
if(is_array($clockSection['value'])&&isset($clockSection['value']['business_date'])){$todayKey=(string)$clockSection['value']['business_date'];$today=new DateTimeImmutable($todayKey,$tz);}else{$today=new DateTimeImmutable('now',$tz);$todayKey=$today->format('Y-m-d');if($clockSection['error']!==null)$dashboardErrors['business_clock']=$clockSection['error'];}
$yesterdayKey=$today->modify('-1 day')->format('Y-m-d');$weekFrom=$today->modify('-6 days')->format('Y-m-d');

$todaySummary=['net_sales'=>0,'order_count'=>0,'avg_ticket'=>0];$yesterdaySummary=['net_sales'=>0,'avg_ticket'=>0,'order_count'=>0];$weekDaily=[];$topItems=[];$reportsError=null;
if($canReports){
    $reportsSection=$core->snapshotGuard()->capture('dashboard','reports',[
        'today_summary'=>$todaySummary,'yesterday_summary'=>$yesterdaySummary,'week_daily'=>[],'top_items'=>[],
    ],'داده‌های فروش و گزارش امروز فعلاً در دسترس نیست.',function() use ($core,$todayKey,$yesterdayKey,$weekFrom,$today): array {
        $todayReport=$core->reporting()->report($todayKey,$todayKey);$todaySummary=array_merge(['net_sales'=>0,'order_count'=>0,'avg_ticket'=>0],(array)($todayReport['summary']??[]));$topItems=array_slice((array)($todayReport['top_items']??[]),0,5);
        $yesterdayReport=$core->reporting()->report($yesterdayKey,$yesterdayKey);$yesterdaySummary=array_merge(['net_sales'=>0,'avg_ticket'=>0,'order_count'=>0],(array)($yesterdayReport['summary']??[]));
        $week=$core->reporting()->report($weekFrom,$todayKey);$map=[];foreach((array)($week['daily']??[]) as $r)$map[(string)$r['business_date']]=(int)($r['net_sales']??0);$weekDaily=[];for($i=6;$i>=0;$i--){$d=$today->modify('-'.$i.' days');$k=$d->format('Y-m-d');$weekDaily[]=['label'=>home_weekday($d,$i===0),'value'=>$map[$k]??0];}
        return ['today_summary'=>$todaySummary,'yesterday_summary'=>$yesterdaySummary,'week_daily'=>$weekDaily,'top_items'=>$topItems];
    });
    $todaySummary=(array)$reportsSection['value']['today_summary'];$yesterdaySummary=(array)$reportsSection['value']['yesterday_summary'];$weekDaily=(array)$reportsSection['value']['week_daily'];$topItems=(array)$reportsSection['value']['top_items'];$reportsError=$reportsSection['error'];if($reportsError!==null)$dashboardErrors['reports']=$reportsError;
}

$opsFallback=['metrics'=>['open_tables'=>null,'attention_orders'=>null,'active_waiter_calls'=>null],'attention_orders'=>[],'waiter_calls'=>[],'tables'=>[]];$ops=$opsFallback;$opsError=null;
if($canOps){$opsSection=$core->snapshotGuard()->capture('dashboard','operations',$opsFallback,'وضعیت سالن و سفارش‌ها فعلاً در دسترس نیست.',fn(): array=>$core->orderWorkspace()->operatorSnapshot($user));$ops=array_replace_recursive($opsFallback,(array)$opsSection['value']);$opsError=$opsSection['error'];if($opsError!==null)$dashboardErrors['operations']=$opsError;}

$inventory=[];$lowStock=[];$inventoryError=null;
if($canInventory){$inventorySection=$core->snapshotGuard()->capture('dashboard','inventory',[],'وضعیت اقلام انبار فعلاً در دسترس نیست.',fn(): array=>$core->operationsWorkspace()->inventoryItems());$inventory=(array)$inventorySection['value'];$inventoryError=$inventorySection['error'];if($inventoryError!==null)$dashboardErrors['inventory']=$inventoryError;if($inventoryError===null){$lowStock=array_values(array_filter($inventory,static fn(array $r):bool=>(int)($r['active']??0)===1&&(int)($r['warning_threshold']??0)>0&&(int)($r['quantity_base']??0)<=(int)($r['warning_threshold']??0)));usort($lowStock,static fn(array $a,array $b):int=>(((int)$a['quantity_base']-(int)$a['warning_threshold'])<=>((int)$b['quantity_base']-(int)$b['warning_threshold']))?:strcmp((string)$a['name'],(string)$b['name']));$lowStock=array_slice($lowStock,0,5);}}

$notificationItems=[];$unread=0;$notificationsError=null;$notificationsSection=$core->snapshotGuard()->capture('dashboard','notifications',['items'=>[],'unread'=>0],'فعالیت‌ها و اعلان‌های اخیر فعلاً در دسترس نیست.',fn(): array=>$core->notifications()->snapshot($user));$ns=(array)$notificationsSection['value'];$notificationItems=is_array($ns['items']??null)?$ns['items']:[];$unread=(int)($ns['unread']??0);$notificationsError=$notificationsSection['error'];if($notificationsError!==null)$dashboardErrors['notifications']=$notificationsError;

$todayHasTickets=(int)($todaySummary['order_count']??0)>0;$yesterdayHasTickets=(int)($yesterdaySummary['order_count']??0)>0;
$metrics=[];
if($canReports){
    $metrics[]=['icon'=>'chart','tone'=>'success','label'=>'فروش خالص امروز','value'=>$reportsError?'—':home_money((int)$todaySummary['net_sales']),'delta'=>($reportsError||!$todayHasTickets||!$yesterdayHasTickets)?null:home_delta((int)$todaySummary['net_sales'],(int)$yesterdaySummary['net_sales']),'helper'=>$reportsError?'موقتاً در دسترس نیست':(!$todayHasTickets?'هنوز فروشی ثبت نشده':(!$yesterdayHasTickets?'دیروز فروشی ثبت نشده':'نسبت به دیروز')),'href'=>'/reports/?from='.$todayKey.'&to='.$todayKey.'&focus=sales'];
    $metrics[]=['icon'=>'cart','tone'=>'info','label'=>'سفارش‌های امروز','value'=>$reportsError?'—':home_fa_num((int)$todaySummary['order_count']),'delta'=>null,'helper'=>$reportsError?'موقتاً در دسترس نیست':'کل سفارش‌های ثبت‌شده','href'=>'/reports/?from='.$todayKey.'&to='.$todayKey.'&focus=orders'];
}
if($canOps)$metrics[]=['icon'=>'table','tone'=>'neutral','label'=>'میزهای فعال','value'=>$opsError?'—':home_fa_num((int)($ops['metrics']['open_tables']??0)),'delta'=>null,'helper'=>$opsError?'موقتاً در دسترس نیست':'نشست باز در سالن','href'=>'/operator/?view=open'];
if($canReports)$metrics[]=['icon'=>'ticket','tone'=>'warning','label'=>'میانگین فاکتور','value'=>$reportsError?'—':($todayHasTickets?home_money((int)$todaySummary['avg_ticket']):'—'),'delta'=>($reportsError||!$todayHasTickets||!$yesterdayHasTickets)?null:home_delta((int)$todaySummary['avg_ticket'],(int)$yesterdaySummary['avg_ticket']),'helper'=>$reportsError?'موقتاً در دسترس نیست':(!$todayHasTickets?'هنوز فاکتوری ثبت نشده':(!$yesterdayHasTickets?'دیروز فاکتوری ثبت نشده':'نسبت به دیروز')),'href'=>'/reports/?from='.$todayKey.'&to='.$todayKey.'&focus=avg_ticket'];

$attention=[];
$pending=(array)($ops['attention_orders']??[]);if($pending){$oldest=$pending[0]??[];$attention[]=['tone'=>'danger','context'=>'سفارش','status'=>'فوری','title'=>home_fa_num(count($pending)).' سفارش منتظر رسیدگی','subtitle'=>($oldest?('قدیمی‌ترین · '.home_relative((string)($oldest['created_at']??''),$tz)):'در صف بررسی'),'href'=>'/operator/?work=attention&attention_filter=orders#attention'];}
$calls=(array)($ops['waiter_calls']??[]);if($calls){$oldest=$calls[0]??[];$attention[]=['tone'=>'warning','context'=>'سالن','status'=>'بررسی','title'=>home_fa_num(count($calls)).' فراخوان مهمان باز','subtitle'=>($oldest?(home_fa_num((string)($oldest['table_name']??'')) .' · '.home_relative((string)($oldest['created_at']??''),$tz)):'نیازمند بررسی'),'href'=>'/operator/?work=attention&attention_filter=calls#attention'];}
$usedNotificationIds=[];
if(count($attention)<4){foreach($notificationItems as $item){if(!empty($item['read_at']))continue;$id=(int)($item['id']??0);if($id>0)$usedNotificationIds[$id]=true;$attention[]=['tone'=>'info','context'=>'اعلان','status'=>'جدید','title'=>(string)($item['title']??'اعلان جدید'),'subtitle'=>(string)($item['body']??''),'href'=>(string)($item['target_url']??'/notifications/')];if(count($attention)>=4)break;}}
$recent=[];foreach($notificationItems as $item){$id=(int)($item['id']??0);if(isset($usedNotificationIds[$id]))continue;$recent[]=$item;if(count($recent)>=5)break;}
$attentionErrors=array_values(array_filter([$opsError,$notificationsError]));
$maxTrend=max(1,...array_map(static fn(array $r):int=>(int)$r['value'],$weekDaily?:[['value'=>0]]));
$bottomPanels=($canReports?1:0)+($canInventory?1:0);
?>
<?php
$dashE=static fn(string $v):string=>home_e($v);
$dashUrl=static fn(string $path):string=>home_url($path);
$dashIcon=static fn(string $name):string=>home_icon($name);
$dashFaNum=static fn(string|int|float $v):string=>home_fa_num($v);
$dashDegraded=static fn(?array $error,string $fallback):string=>home_degraded($error,$fallback);
$dashClock=static fn(string $value):string=>home_clock($value,$tz);
$dashNotificationIcon=static fn(array $item):string=>home_notification_icon($item);
$dashMoney=static fn(int $v):string=>home_money($v);
$dashUnit=static fn(int $quantity,string $unit):string=>home_unit($quantity,$unit);
require dirname(__DIR__).'/resources/shared-staff-ui/dashboard-workspace.php';
?>
<?php ProductShell::end(); ?>
