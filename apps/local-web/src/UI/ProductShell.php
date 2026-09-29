<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

use Sokna\Local\Core\Bootstrap;
use Throwable;

/**
 * Canonical V3 shell with the approved visual/IA lineage of the historical
 * SOKNA panel. Architecture changed; the product language did not.
 */
final class ProductShell
{
    public static function start(Bootstrap $core,array $user,string $title,string $active='home',string $subtitle=''): void
    {
        $name=SCDS::e((string)($user['display_name']??$user['username']??''));
        $role=self::roleLabel((string)($user['role']??''));
        $safeTitle=SCDS::e($title);
        $safeSubtitle=SCDS::e($subtitle);
        $cafe=SCDS::e(self::setting($core,'cafe.name','سکنا'));
        $effectiveActive=$active==='system' && (string)($_GET['tab']??'')==='updates' ? 'updates' : $active;
        $meta=self::pageMeta($effectiveActive,$title,$subtitle);
        $groups=self::navigationGroups($core,$user);
        $hasQuick=self::hasItem($groups,'staff');
        $currentUri=(string)($_SERVER['REQUEST_URI']??'/');

        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
        echo '<title>'.$safeTitle.' | '.$cafe.'</title><link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png"><link rel="apple-touch-icon" sizes="180x180" href="/assets/favicon-180.png">';
        echo '<link rel="stylesheet" href="/scds.php?file=tokens.css"><link rel="stylesheet" href="/scds.php?file=components.css"></head><body class="sc-panel-body">';
        echo '<a class="sc-skip-link" href="#sc-main-content">رفتن به محتوای اصلی</a>';
        echo '<div class="sc-shell">';

        echo '<aside class="sc-shell__nav" id="sc-sidebar">';
        echo '<a class="sc-shell__brand" href="/" aria-label="خانه سکنا"><span class="sc-shell__brand-mark">'.self::icon('coffee').'</span><span class="sc-shell__brand-copy"><strong>'.$cafe.'</strong><small>سامانه عملیات و مهمان</small></span></a>';
        echo '<div class="sc-shell__nav-groups">';
        $groupIndex=0;
        foreach($groups as $groupTitle=>$items){
            if(!$items)continue;
            $groupIndex++;
            $groupActive=self::groupContains($items,$effectiveActive);
            $groupId='sc-nav-group-'.$groupIndex;
            echo '<section class="sc-shell__nav-group'.($groupActive?' is-open is-current':'').'" data-sc-nav-group>';
            echo '<button class="sc-shell__nav-group-toggle" type="button" aria-expanded="'.($groupActive?'true':'false').'" aria-controls="'.$groupId.'" data-sc-nav-group-toggle><span>'.SCDS::e($groupTitle).'</span>'.self::icon('chevron-left').'</button>';
            echo '<nav class="sc-shell__nav-list" id="'.$groupId.'"'.($groupActive?'':' hidden').'>';
            foreach($items as $item){
                $current=$item['id']===$effectiveActive?' aria-current="page"':'';
                echo '<a class="sc-shell__nav-link"'.$current.' href="'.SCDS::e($item['href']).'"><span class="sc-shell__nav-icon">'.self::icon($item['icon']).'</span><span class="sc-shell__nav-label">'.SCDS::e($item['label']).'</span></a>';
            }
            echo '</nav></section>';
        }
        echo '</div>';
        echo '<footer class="sc-shell__user"><div class="sc-shell__identity"><span class="sc-shell__avatar">'.SCDS::e(self::initial((string)($user['display_name']??$user['username']??'س'))).'</span><span><strong>'.$name.'</strong><small>'.SCDS::e($role).'</small></span></div>';
        echo '<div class="sc-shell__user-actions"><a href="/notifications/">'.self::icon('bell').'<span>اعلان‌ها</span></a><a href="/logout.php">'.self::icon('logout').'<span>خروج</span></a></div></footer>';
        echo '</aside><button class="sc-shell__backdrop" type="button" aria-label="بستن منو" data-sc-nav-backdrop></button>';

        echo '<main class="sc-shell__main">';
        echo '<header class="sc-topbar">';
        echo '<button class="sc-icon-button sc-topbar__menu" type="button" aria-label="بازکردن منو" aria-controls="sc-sidebar" aria-expanded="false" data-sc-nav-toggle>'.self::icon('menu').'</button>';
        echo '<div class="sc-topbar__heading"><span class="sc-topbar__page-icon">'.self::icon($meta['icon']).'</span><div><span class="sc-topbar__eyebrow">'.SCDS::e($meta['group']).'</span><h1>'.$safeTitle.'</h1>'.($safeSubtitle!==''?'<p>'.$safeSubtitle.'</p>':'').'</div></div>';
        echo '<div class="sc-topbar__actions"><span class="sc-topbar__user">'.$name.'</span>';
        if($hasQuick)echo '<a class="sc-button sc-topbar__quick" href="/staff/">'.self::icon('plus').'<span>ثبت سریع سفارش</span></a>';
        echo '<a class="sc-icon-button" href="/" aria-label="خانه">'.self::icon('home').'</a></div></header>';

        echo '<div class="sc-panel-content" id="sc-main-content" tabindex="-1">';
        echo '<div class="sc-global-search" data-global-search data-api="/search/api.php"><label class="sc-global-search__field"><span class="sc-global-search__label">جست‌وجوی سراسری</span><input class="sc-control sc-global-search__input" type="search" autocomplete="off" spellcheck="false" placeholder="آیتم، فاکتور، مشتری، پرسنل، تنظیمات…" aria-label="جست‌وجوی سراسری" aria-expanded="false" data-global-search-input></label><div class="sc-command-palette" role="listbox" aria-label="نتایج جست‌وجو" hidden data-global-search-results></div></div>';
    }

    public static function end(): void
    {
        echo '</div></main></div><script src="/assets/global-search.js" defer></script><script src="/scds.php?file=scds.js" defer></script></body></html>';
    }

    /** @return array<string,list<array{id:string,label:string,href:string,icon:string}>> */
    public static function navigationGroups(Bootstrap $core,array $user): array
    {
        $admin=(string)($user['role']??'')==='admin';
        $groups=['خلاصه'=>[['id'=>'home','label'=>'خلاصه مدیریت','href'=>'/','icon'=>'dashboard']]];

        $operations=[];
        if(self::any($core,$user,['orders_floor','cashier_accounts','shift_supervision']))$operations[]=['id'=>'operator','label'=>'کار روزانه','href'=>'/operator/','icon'=>'operations'];
        if($core->auth()->hasCapability('orders_floor',$user))$operations[]=['id'=>'staff','label'=>'سفارش سریع','href'=>'/staff/','icon'=>'plus'];
        if($core->auth()->hasCapability('preparation',$user))$operations[]=['id'=>'preparation','label'=>'آماده‌سازی','href'=>'/waiter/','icon'=>'service'];
        if($operations)$groups['عملیات']=$operations;

        $management=[];
        if(self::any($core,$user,['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports']))$management[]=['id'=>'staff-consumption','label'=>'مصرف پرسنل','href'=>'/staff-consumption/','icon'=>'users'];
        if(self::any($core,$user,['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']))$management[]=['id'=>'operations','label'=>'انبار و تأمین','href'=>'/operations/','icon'=>'archive'];
        if($management)$groups['مدیریت مجموعه']=$management;

        if($admin||$core->auth()->hasCapability('cashier_accounts',$user)){
            $groups['مالی']=[
                ['id'=>'finance','label'=>'مالی','href'=>'/finance/','icon'=>'ticket'],
                ['id'=>'subscribers','label'=>'مشتریان','href'=>'/subscribers/','icon'=>'users'],
            ];
        }

        if(self::any($core,$user,['cashier_accounts','shift_supervision','staff_consumption_reports']))
            $groups['گزارش‌ها']=[['id'=>'reports','label'=>'گزارش و تحلیل','href'=>'/reports/','icon'=>'chart']];

        if($admin){
            $groups['منو و مهمان']=[
                ['id'=>'catalog','label'=>'کاتالوگ','href'=>'/catalog/','icon'=>'coffee'],
                ['id'=>'guest-content','label'=>'محتوای مهمان','href'=>'/guest-content/','icon'=>'eye'],
                ['id'=>'marketing','label'=>'کمپین و رویداد','href'=>'/marketing/','icon'=>'megaphone'],
            ];
            $groups['سامانه و زیرساخت']=[
                ['id'=>'admin','label'=>'مدیریت','href'=>'/admin/','icon'=>'settings'],
                ['id'=>'integrations','label'=>'چاپ و اتصال‌ها','href'=>'/integrations/','icon'=>'print'],
                ['id'=>'system','label'=>'وضعیت سیستم','href'=>'/system/','icon'=>'info'],
                ['id'=>'updates','label'=>'به‌روزرسانی و بازیابی','href'=>'/system/?tab=updates','icon'=>'refresh'],
            ];
        }elseif($core->auth()->hasCapability('cashier_accounts',$user)){
            $groups['سامانه و زیرساخت']=[['id'=>'integrations','label'=>'چاپ و اتصال‌ها','href'=>'/integrations/','icon'=>'print']];
        }

        return $groups;
    }

    /** @return list<array{id:string,label:string,href:string,icon:string}> */
    public static function navigation(Bootstrap $core,array $user): array
    {
        $flat=[];
        foreach(self::navigationGroups($core,$user) as $items)foreach($items as $item)$flat[]=$item;
        return $flat;
    }

    public static function icon(string $name,string $label=''): string
    {
        $safe=preg_replace('/[^a-z0-9_-]+/i','',$name)?:'info';
        $aria=$label!==''?' role="img" aria-label="'.SCDS::e($label).'"':' aria-hidden="true" focusable="false"';
        return '<svg class="sc-ui-icon"'.$aria.'><use href="/assets/ui-sprite.svg#icon-'.$safe.'"></use></svg>';
    }

    /** @param list<array{id:string,label:string,href:string,icon:string}> $items */
    private static function groupContains(array $items,string $active): bool
    {
        foreach($items as $item)if($item['id']===$active)return true;
        return false;
    }

    /** @param array<string,list<array{id:string,label:string,href:string,icon:string}>> $groups */
    private static function hasItem(array $groups,string $id): bool
    {
        foreach($groups as $items)foreach($items as $item)if($item['id']===$id)return true;
        return false;
    }

    /** @return array{group:string,icon:string} */
    private static function pageMeta(string $active,string $title,string $subtitle): array
    {
        $map=[
            'home'=>['خلاصه','dashboard'],'operator'=>['عملیات','operations'],'staff'=>['عملیات','plus'],'preparation'=>['عملیات','service'],
            'staff-consumption'=>['مدیریت مجموعه','users'],'operations'=>['مدیریت مجموعه','archive'],
            'finance'=>['مالی','ticket'],'subscribers'=>['مالی','users'],'reports'=>['گزارش‌ها','chart'],
            'catalog'=>['منو و مهمان','coffee'],'guest-content'=>['منو و مهمان','eye'],'marketing'=>['منو و مهمان','megaphone'],
            'admin'=>['سامانه و زیرساخت','settings'],'integrations'=>['سامانه و زیرساخت','print'],'system'=>['سامانه و زیرساخت','info'],'updates'=>['سامانه و زیرساخت','refresh'],
            'notifications'=>['حساب کاربری','bell'],
        ];
        [$group,$icon]=$map[$active]??['سامانه سکنا','dashboard'];
        return ['group'=>$group,'icon'=>$icon];
    }

    private static function any(Bootstrap $core,array $user,array $capabilities): bool
    {
        if((string)($user['role']??'')==='admin')return true;
        foreach($capabilities as $capability)if($core->auth()->hasCapability($capability,$user))return true;
        return false;
    }

    private static function setting(Bootstrap $core,string $key,string $default): string
    {
        try{
            $stmt=$core->database()->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
            $stmt->execute([$key]);
            $v=$stmt->fetchColumn();
            return $v===false||trim((string)$v)===''?$default:(string)$v;
        }catch(Throwable){return $default;}
    }

    private static function initial(string $name): string
    {
        $name=trim($name);
        if($name==='')return 'س';
        return function_exists('mb_substr')?mb_substr($name,0,1,'UTF-8'):substr($name,0,1);
    }

    private static function roleLabel(string $role): string
    {
        return match($role){'admin'=>'مدیر سامانه','operator'=>'عضو تیم','waiter'=>'عضو تیم',default=>'کاربر'};
    }
}
