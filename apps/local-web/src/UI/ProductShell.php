<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

use Sokna\Local\Core\Bootstrap;

final class ProductShell
{
    public static function start(Bootstrap $core,array $user,string $title,string $active='home',string $subtitle='',bool $hasLocalTabs=false): void
    {
        $display=(string)($user['display_name']??$user['username']??'');
        $name=SCDS::e($display);
        $role=self::roleLabel((string)($user['role']??''));
        $safeRole=SCDS::e($role);
        $safeTitle=SCDS::e($title);
        $safeSubtitle=SCDS::e($subtitle);
        $brand=self::brand($core);
        $brandName=SCDS::e($brand['name']);
        $brandSubtitle=SCDS::e($brand['subtitle']);
        $items=self::navigation($core,$user);
        [$primaryItems,$secondaryItems]=self::navigationTiers($items);
        $groups=self::primaryNavigationGroups($primaryItems);
        $secondaryGroups=self::navigationGroups($secondaryItems);
        $currentSecondary=null;
        foreach($secondaryItems as $secondaryItem){if(($secondaryItem['id']??'')===$active){$currentSecondary=$secondaryItem;break;}}
        $timezone=SCDS::e($core->config()->string('app.timezone','Asia/Tehran'));
        $canOrder=$core->auth()->hasCapability('orders_floor',$user);
        $baseHref=SCDS::e(LocalUrl::baseHref());
        $appBase=SCDS::e(LocalUrl::basePath());
        $u=static fn(string $path): string=>SCDS::e(LocalUrl::path($path));
        $asset=static fn(string $path): string=>SCDS::e(AssetUrl::asset($path));
        $scds=static fn(string $file): string=>SCDS::e(AssetUrl::scds($file));
        $rawBuildVersion=AssetUrl::buildVersion();
        $buildVersion=SCDS::e($rawBuildVersion);
        $displayVersion=SCDS::e(self::displayVersion($rawBuildVersion));

        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><base href="'.$baseHref.'">';
        echo '<script>(function(){try{var p=localStorage.getItem("sokna.theme")||"system";var d=p==="dark"||(p==="system"&&matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.dataset.theme=d?"dark":"light";document.documentElement.dataset.themePreference=p}catch(e){}})();</script>';
        echo '<title>'.$safeTitle.' | '.$brandName.'</title><meta name="theme-color" content="#0f6b66"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="application-name" content="'.$brandName.'"><link rel="manifest" href="'.$asset('/manifest.webmanifest').'"><meta name="sokna-build" content="'.$buildVersion.'"><link rel="icon" href="'.$asset('/assets/favicon.ico').'" sizes="any"><link rel="icon" type="image/png" sizes="16x16" href="'.$asset('/assets/favicon-16.png').'"><link rel="icon" type="image/png" sizes="32x32" href="'.$asset('/assets/favicon-32.png').'"><link rel="icon" type="image/png" sizes="48x48" href="'.$asset('/assets/favicon-48.png').'"><link rel="icon" type="image/png" sizes="192x192" href="'.$asset('/assets/favicon-192.png').'"><link rel="apple-touch-icon" sizes="180x180" href="'.$asset('/assets/favicon-180.png').'"><link rel="stylesheet" href="'.$scds('tokens.css').'"><link rel="stylesheet" href="'.$scds('components.css').'"><link rel="stylesheet" href="'.$asset('/assets/product-ui.css').'"><script>window.SoknaAssets={version:'.json_encode(AssetUrl::buildVersion(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).',base:'.json_encode(LocalUrl::basePath(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).',path:function(p){var r=String(p||"");if(!r||/^(?:https?:)?\/\//i.test(r)||/^(?:data|blob):/i.test(r))return r;var h="",i=r.indexOf("#");if(i>=0){h=r.slice(i);r=r.slice(0,i)}var b=this.base||"";if(r.charAt(0)!=="/")r="/"+r;if(b&&r.indexOf(b+"/")!==0&&r!==b)r=b+r;var q=r.indexOf("?")>=0?"&":"?";return r+q+"v="+encodeURIComponent(this.version)+h}};</script><script src="'.$asset('/assets/product-theme.js').'" defer></script><script src="'.$asset('/assets/locale.js').'" defer></script><script src="'.$asset('/assets/ui-dialogs.js').'" defer></script><script src="'.$asset('/assets/product-shell.js').'" defer></script><script src="'.$asset('/assets/product-interactions.js').'" defer></script><script src="'.$asset('/assets/jalali-fields.js').'" defer></script><script src="'.$asset('/assets/global-search.js').'" defer></script><script src="'.$scds('scds.js').'" defer></script></head><body data-page="'.SCDS::e($active).'" data-app-base="'.$appBase.'" data-build-version="'.$buildVersion.'"'.($hasLocalTabs?' data-has-local-tabs="1"':'').'>';
        echo '<a class="sc-skip-link" href="#mainContent">پرش به محتوای اصلی</a><div class="sc-shell">';
        echo '<aside class="sc-shell__nav" data-product-nav id="productNav" aria-label="ناوبری اصلی">';
        echo '<a class="sc-shell__brand" href="'.$u('/').'" aria-label="خانه '.$brandName.'"><span class="sc-shell__brand-mark" aria-hidden="true"><img src="'.$asset('/assets/brand/Sokna-AppIcon-256.png').'" alt="" width="256" height="256" decoding="async"></span><span class="sc-shell__brand-copy"><strong>'.$brandName.'</strong><span>'.$brandSubtitle.'</span></span></a>';
        echo '<div class="sc-shell__nav-scroll" data-shell-primary-nav>';
        foreach($groups as $group=>$entries){
            echo '<section class="sc-shell__nav-group sc-shell__nav-group--primary"><div class="sc-shell__nav-title">'.SCDS::e($group).'</div><nav><ul class="sc-shell__nav-list">';
            foreach($entries as $item){
                $isCurrent=$item['id']===$active||($active==='staff'&&$item['id']==='operator');
                $current=$isCurrent?' aria-current="page"':'';
                echo '<li><a class="sc-shell__nav-link"'.$current.' href="'.$u($item['href']).'"><span class="sc-shell__nav-icon">'.self::icon(self::iconFor($item['id'])).'</span><span>'.SCDS::e($item['label']).'</span></a></li>';
            }
            echo '</ul></nav></section>';
        }
        if($secondaryItems!==[]){
            $moreLabel=$currentSecondary!==null?(string)$currentSecondary['label']:'بیشتر';
            $moreHint=$currentSecondary!==null?'بخش فعلی':'ابزارها و تنظیمات';
            $moreCurrent=$currentSecondary!==null?' is-current':'';
            echo '<details class="sc-shell__more'.$moreCurrent.'" data-shell-more-nav><summary class="sc-shell__more-summary"><span class="sc-shell__nav-icon">'.self::icon('more').'</span><span class="sc-shell__more-copy"><strong>'.SCDS::e($moreLabel).'</strong><small>'.SCDS::e($moreHint).'</small></span><span class="sc-shell__more-chevron">'.self::icon('chevron-down').'</span></summary><div class="sc-shell__more-panel">';
            foreach($secondaryGroups as $group=>$entries){
                echo '<section class="sc-shell__more-group"><div class="sc-shell__more-title">'.SCDS::e($group).'</div><nav><ul class="sc-shell__more-list">';
                foreach($entries as $item){
                    $current=$item['id']===$active?' aria-current="page"':'';
                    echo '<li><a class="sc-shell__more-link"'.$current.' href="'.$u($item['href']).'"><span class="sc-shell__nav-icon">'.self::icon(self::iconFor($item['id'])).'</span><span>'.SCDS::e($item['label']).'</span></a></li>';
                }
                echo '</ul></nav></section>';
            }
            echo '</div></details>';
        }
        echo '</div>';
        echo '<div class="sc-shell__nav-bottom"><div class="sc-shell__footer"><a class="sc-button sc-button--secondary" href="'.$u('/logout.php').'">'.self::icon('logout').'<span>خروج</span></a><a class="sc-icon-button" href="'.$u('/').'" aria-label="خانه">'.self::icon('home').'</a></div><div class="sc-shell__version" data-shell-version aria-label="نسخه نصب‌شده '.$displayVersion.'">'.$displayVersion.'</div></div>';
        echo '</aside>';
        echo '<button class="sc-shell__backdrop" type="button" data-product-nav-backdrop aria-label="بستن منو" tabindex="-1" hidden></button>';

        echo '<main class="sc-shell__main" id="mainContent">';
        echo '<header class="sc-shell__topbar" data-product-topbar>';
        echo '<div class="sc-shell__top-left">';
        echo '<a class="sc-shell__user-card" href="'.$u('/account/').'" aria-label="حساب من، '.$name.'"><span class="sc-shell__user-copy"><strong>'.$name.'</strong><small>'.$safeRole.'</small></span></a>';
        echo '<div class="sc-shell__date-card" data-shell-clock data-timezone="'.$timezone.'"><span class="sc-shell__date-icon" aria-hidden="true">'.self::icon('calendar').'</span><span class="sc-shell__date-copy"><strong data-shell-date>—</strong><span data-shell-time>—</span></span></div>';
        echo '<a class="sc-icon-button sc-shell__notify" href="'.$u('/notifications/').'" aria-label="اعلان‌ها">'.self::icon('bell').'</a>';
        echo '<div class="sc-theme-picker" data-theme-picker><button class="sc-icon-button sc-theme-picker__trigger" type="button" data-theme-menu-toggle aria-label="انتخاب پوسته" aria-haspopup="true" aria-expanded="false">'.self::icon('adjust').'</button><div class="sc-theme-picker__menu" role="radiogroup" aria-label="پوسته سامانه" hidden data-theme-menu><button type="button" role="radio" data-theme-option="light" aria-checked="false"><span>'.self::icon('sun').'</span><span>روشن</span></button><button type="button" role="radio" data-theme-option="dark" aria-checked="false"><span>'.self::icon('moon').'</span><span>تیره</span></button><button type="button" role="radio" data-theme-option="system" aria-checked="false"><span>'.self::icon('device').'</span><span>سیستم</span></button></div></div>';
        echo '</div>';
        echo '<div class="sc-shell__top-spacer" aria-hidden="true"></div>';
        echo '<div class="sc-shell__right-actions">';
        echo '<button class="sc-shell__toggle" type="button" data-product-nav-toggle aria-label="باز کردن منو" aria-controls="productNav" aria-expanded="false">'.self::icon('menu').'</button>';
        if($canOrder&&$active!=='staff')echo '<a class="sc-shell__order-button" href="'.$u('/staff/').'" data-global-order><span class="sc-shell__order-icon">'.self::icon('plus').'</span><span>ثبت سفارش</span></a>';
        echo '<div class="sc-shell__search"><div class="sc-global-search" data-global-search data-api="'.$u('/search/api.php').'"><span class="sc-global-search__icon" aria-hidden="true">'.self::icon('search').'</span><label class="sc-global-search__field"><span class="sc-global-search__label">جست‌وجوی سراسری</span><input class="sc-control sc-global-search__input" type="search" autocomplete="off" spellcheck="false" placeholder="جستجو در میز، سفارش، مشتری، منو و تنظیمات…" aria-label="جست‌وجوی سراسری" aria-expanded="false" data-global-search-input></label><span class="sc-global-search__shortcut" aria-hidden="true">Ctrl + K</span><div class="sc-command-palette" role="listbox" aria-label="نتایج جست‌وجو" hidden data-global-search-results></div></div></div>';
        echo '</div>';
        echo '</header>';
        echo '<div class="sc-shell__content"><section class="sc-page-header'.($hasLocalTabs?' sc-page-header--with-tabs':'').'" aria-labelledby="scPageTitle"><div class="sc-page-header__main"><div class="sc-page-header__copy sc-page-header__copy--inline"><h1 id="scPageTitle">'.$safeTitle.'</h1>'.($safeSubtitle!==''?'<p>'.$safeSubtitle.'</p>':'').'</div></div></section>';
    }


    public static function asset(string $path): string
    {
        return SCDS::e(AssetUrl::asset('/'.ltrim($path,'/')));
    }

    public static function end(): void
    {
        echo '</div></main></div></body></html>';
    }

    /** @return list<array{id:string,label:string,href:string}> */
    public static function navigation(Bootstrap $core,array $user): array
    {
        $items=[['id'=>'home','label'=>'داشبورد','href'=>'/']];
        if(self::any($core,$user,['orders_floor','cashier_accounts','shift_supervision']))$items[]=['id'=>'operator','label'=>'سالن و میزها','href'=>'/operator/'];
        if(self::any($core,$user,['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports']))$items[]=['id'=>'staff-consumption','label'=>'مصرف پرسنل','href'=>'/staff-consumption/'];
        if($core->auth()->hasCapability('preparation',$user))$items[]=['id'=>'preparation','label'=>'آماده‌سازی','href'=>'/waiter/'];
        if(self::any($core,$user,['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']))$items[]=['id'=>'operations','label'=>'انبار و تأمین','href'=>'/operations/'];
        if((string)($user['role']??'')==='admin'||$core->auth()->hasCapability('cashier_accounts',$user)){
            $items[]=['id'=>'finance','label'=>'مالی','href'=>'/finance/'];
            $items[]=['id'=>'subscribers','label'=>'مشتریان','href'=>'/subscribers/'];
            $items[]=['id'=>'integrations','label'=>'چاپ و اتصال‌ها','href'=>'/integrations/'];
        }
        if(self::any($core,$user,['cashier_accounts','shift_supervision','staff_consumption_reports']))$items[]=['id'=>'reports','label'=>'گزارش و تحلیل','href'=>'/reports/'];
        $items[]=['id'=>'notifications','label'=>'اعلان‌ها','href'=>'/notifications/'];
        if((string)($user['role']??'')==='admin'){
            $items[]=['id'=>'catalog','label'=>'کاتالوگ','href'=>'/catalog/'];
            $items[]=['id'=>'guest-content','label'=>'محتوای مهمان','href'=>'/guest-content/'];
            $items[]=['id'=>'marketing','label'=>'کمپین و رویداد','href'=>'/marketing/'];
            $items[]=['id'=>'admin','label'=>'مدیریت','href'=>'/admin/'];
            $items[]=['id'=>'system','label'=>'پشتیبانی و نگهداری','href'=>'/system/'];
        }
        return $items;
    }

    /** @param list<array{id:string,label:string,href:string}> $items
     *  @return array{0:list<array{id:string,label:string,href:string}>,1:list<array{id:string,label:string,href:string}>}
     */
    private static function navigationTiers(array $items): array
    {
        $primaryIds=['home','operator','preparation','operations','finance','subscribers','reports','catalog'];
        $primary=[];$secondary=[];
        foreach($items as $item){in_array((string)$item['id'],$primaryIds,true)?$primary[]=$item:$secondary[]=$item;}
        return [$primary,$secondary];
    }

    /** @param list<array{id:string,label:string,href:string}> $items
     *  @return array<string,list<array{id:string,label:string,href:string}>>
     */
    private static function primaryNavigationGroups(array $items): array
    {
        $groups=[];
        foreach($items as $item){
            $id=(string)$item['id'];
            $label=in_array($id,['home','operator','preparation','operations'],true)?'کار روزانه':'مدیریت';
            $groups[$label][]=$item;
        }
        return $groups;
    }

    /** @param list<array{id:string,label:string,href:string}> $items
     *  @return array<string,list<array{id:string,label:string,href:string}>>
     */
    private static function navigationGroups(array $items): array
    {
        $groups=[];
        foreach($items as $item){$groups[self::groupLabel($item['id'])][]=$item;}
        return $groups;
    }

    private static function groupLabel(string $id): string
    {
        return match($id){
            'home'=>'خلاصه',
            'operator','staff','preparation'=>'عملیات',
            'staff-consumption'=>'پرسنل',
            'operations'=>'انبار و تأمین',
            'finance','subscribers','reports'=>'مالی و گزارش',
            'catalog','guest-content','marketing'=>'منو و مهمان',
            'integrations','notifications','admin','system','account'=>'تنظیمات و پشتیبانی',
            default=>'سکنا',
        };
    }

    private static function iconFor(string $id): string
    {
        return match($id){
            'home'=>'dashboard','operator'=>'table','staff'=>'cart','staff-consumption'=>'users','preparation'=>'service',
            'operations'=>'archive','finance'=>'ticket','subscribers'=>'users','integrations'=>'print','reports'=>'chart',
            'notifications'=>'bell','catalog'=>'coffee','guest-content'=>'eye','marketing'=>'megaphone','admin'=>'settings','system'=>'adjust','account'=>'users',
            default=>'list',
        };
    }

    private static function icon(string $name): string
    {
        $safe=SCDS::e($name);
        return '<svg class="sc-ui-icon" aria-hidden="true" focusable="false"><use href="'.SCDS::e(AssetUrl::sprite('/assets/ui-sprite.svg','icon-'.$safe)).'"></use></svg>';
    }

    /** @return array{name:string,subtitle:string} */
    private static function brand(Bootstrap $core): array
    {
        $name='سکنا';$subtitle='سامانه مدیریت کافه';
        try{
            $q=$core->database()->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('cafe.name','brand.subtitle')");
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row){
                $value=trim((string)($row['setting_value']??''));if($value==='')continue;
                if((string)$row['setting_key']==='cafe.name')$name=$value;elseif((string)$row['setting_key']==='brand.subtitle')$subtitle=$value;
            }
        }catch(\Throwable){}
        return ['name'=>$name,'subtitle'=>$subtitle];
    }

    private static function initial(string $display): string
    {
        $display=trim($display);
        if($display==='')return 'س';
        if(function_exists('mb_substr'))return mb_substr($display,0,1,'UTF-8');
        return substr($display,0,1);
    }

    private static function any(Bootstrap $core,array $user,array $capabilities): bool
    {
        if((string)($user['role']??'')==='admin')return true;
        foreach($capabilities as $capability)if($core->auth()->hasCapability($capability,$user))return true;
        return false;
    }

    private static function displayVersion(string $version): string
    {
        $version=trim($version);
        if(preg_match('/^v?(\d+\.\d+\.\d+)/',$version,$semver)===1){
            $display=(string)$semver[1];
            if(preg_match('/(?:^|[-+])workspace\.(\d+)(?:[-+.]|$)/',$version,$build)===1)$display.='.'.(string)$build[1];
            return $display;
        }
        return $version!==''?$version:'—';
    }

    private static function roleLabel(string $role): string
    {
        return match($role){'admin'=>'مدیر','operator'=>'اپراتور','waiter'=>'همکار سالن',default=>'کاربر'};
    }
}
