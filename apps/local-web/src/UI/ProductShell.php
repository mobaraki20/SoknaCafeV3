<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

use Sokna\Local\Core\Bootstrap;

final class ProductShell
{
    public static function start(Bootstrap $core,array $user,string $title,string $active='home',string $subtitle=''): void
    {
        $name=SCDS::e((string)($user['display_name']??$user['username']??''));
        $safeTitle=SCDS::e($title);$safeSubtitle=SCDS::e($subtitle);
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>'.$safeTitle.' | سکنا</title><link rel="stylesheet" href="/scds.php?file=tokens.css"><link rel="stylesheet" href="/scds.php?file=components.css"></head><body>';
        echo '<div class="sc-shell"><aside class="sc-shell__nav"><a class="sc-shell__brand" href="/" aria-label="خانه سکنا"><strong>سکنا</strong><span>سامانه محلی</span></a>';
        echo '<div class="sc-shell__identity"><span>'.$name.'</span><small>'.SCDS::e(self::roleLabel((string)($user['role']??''))).'</small></div>';
        echo '<nav aria-label="ناوبری اصلی"><ul class="sc-shell__nav-list">';
        foreach(self::navigation($core,$user) as $item){$current=$item['id']===$active?' aria-current="page"':'';echo '<li><a class="sc-shell__nav-link"'.$current.' href="'.SCDS::e($item['href']).'"><span>'.SCDS::e($item['label']).'</span></a></li>';}
        echo '</ul></nav><div class="sc-shell__footer"><a class="sc-button sc-button--secondary" href="/logout.php">خروج از حساب</a></div></aside>';
        echo '<main class="sc-shell__main"><div class="sc-global-search" data-global-search data-api="/search/api.php"><label class="sc-global-search__field"><span class="sc-global-search__label">جست‌وجوی سراسری</span><input class="sc-control sc-global-search__input" type="search" autocomplete="off" spellcheck="false" placeholder="آیتم، فاکتور، مشتری، پرسنل، تنظیمات…" aria-label="جست‌وجوی سراسری" aria-expanded="false" data-global-search-input></label><div class="sc-command-palette" role="listbox" aria-label="نتایج جست‌وجو" hidden data-global-search-results></div></div>';
        echo '<header class="sc-page-head"><div><h1>'.$safeTitle.'</h1>'.($safeSubtitle!==''?'<p>'.$safeSubtitle.'</p>':'').'</div><a class="sc-button sc-button--secondary sc-page-head__home" href="/">خانه</a></header>';
    }

    public static function end(): void
    {
        echo '</main></div><script src="/assets/global-search.js" defer></script><script src="/scds.php?file=scds.js" defer></script></body></html>';
    }

    /** @return list<array{id:string,label:string,href:string}> */
    public static function navigation(Bootstrap $core,array $user): array
    {
        $items=[['id'=>'home','label'=>'خانه','href'=>'/']];
        if(self::any($core,$user,['orders_floor','cashier_accounts','shift_supervision']))$items[]=['id'=>'operator','label'=>'کار روزانه','href'=>'/operator/'];
        if($core->auth()->hasCapability('orders_floor',$user))$items[]=['id'=>'staff','label'=>'سفارش سریع','href'=>'/staff/'];
        if($core->auth()->hasCapability('preparation',$user))$items[]=['id'=>'preparation','label'=>'آماده‌سازی','href'=>'/waiter/'];
        if(self::any($core,$user,['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']))$items[]=['id'=>'operations','label'=>'انبار و تأمین','href'=>'/operations/'];
        if((string)($user['role']??'')==='admin'||$core->auth()->hasCapability('cashier_accounts',$user)){
            $items[]=['id'=>'finance','label'=>'مالی','href'=>'/finance/'];
            $items[]=['id'=>'subscribers','label'=>'مشتریان','href'=>'/subscribers/'];
            $items[]=['id'=>'integrations','label'=>'چاپ و اتصال‌ها','href'=>'/integrations/'];
        }
        if((string)($user['role']??'')==='admin'){$items[]=['id'=>'catalog','label'=>'کاتالوگ','href'=>'/catalog/'];$items[]=['id'=>'admin','label'=>'مدیریت','href'=>'/admin/'];}
        return $items;
    }

    private static function any(Bootstrap $core,array $user,array $capabilities): bool
    {
        if((string)($user['role']??'')==='admin')return true;
        foreach($capabilities as $capability)if($core->auth()->hasCapability($capability,$user))return true;
        return false;
    }

    private static function roleLabel(string $role): string
    {
        return match($role){'admin'=>'مدیر','operator'=>'اپراتور','waiter'=>'همکار سالن',default=>'کاربر'};
    }
}
