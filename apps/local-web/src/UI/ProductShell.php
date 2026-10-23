<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

final class ProductShell
{
    public static function start(object $core,array $user,string $title,string $active='home',string $subtitle='',bool $hasLocalTabs=false): void
    {
        $staffUi=is_array($user['_staff_ui']??null)?$user['_staff_ui']:[];
        $staffMode=(string)($staffUi['mode']??'local');
        $isPublicStaff=$staffMode==='public';
        $uiPreferences=[];try{$uiPreferences=$core->uiPreferences()->forUserContext($user);}catch(\Throwable){}
        $uiPreferencesEndpoint=(string)($staffUi['ui_preferences_endpoint']??($isPublicStaff?'/api/staff/ui-preferences':'/ui-preferences/api.php'));
        $uiPreferencesCsrf='';if(!$isPublicStaff){try{$uiPreferencesCsrf=WebAction::csrfToken();}catch(\Throwable){}}
        $homePath=(string)($staffUi['home_path']??'/');
        $logoutPath=(string)($staffUi['logout_path']??'/logout.php');
        $accountPath=(string)($staffUi['account_path']??'/account/');
        $notificationsPath=(string)($staffUi['notifications_path']??'/notifications/');
        $orderPath=(string)($staffUi['order_path']??'/operator/?view=order');
        $searchPath=(string)($staffUi['search_path']??'/search/api.php');
        $pageStatusHtml=(string)($staffUi['page_status_html']??'');
        $extraBodyAttrs=is_array($staffUi['body_attrs']??null)?$staffUi['body_attrs']:[];
        if(!isset($extraBodyAttrs['projection-id'])&&(int)($user['id']??0)>0)$extraBodyAttrs['projection-id']='user:'.(int)$user['id'];
        $display=(string)($user['display_name']??$user['username']??'');
        $name=SCDS::e($display);
        $role=self::roleLabel((string)($user['role']??''));
        $safeRole=SCDS::e($role);
        $safeTitle=SCDS::e($title);
        $safeSubtitle=SCDS::e($subtitle);
        $brand=self::brand($core);
        $brandName=SCDS::e($brand['name']);
        $brandSubtitle=SCDS::e($brand['subtitle']);
        $identityVersion=(string)($brand['identity_version']??'fallback');
        $items=self::navigation($core,$user);
        $groups=self::sidebarNavigationGroups($items);
        $timezone=SCDS::e($core->time()->timezone());
        $serverNow=SCDS::e($core->time()->nowUtcIso());
        $canOrder=$core->auth()->hasCapability('orders_floor',$user);
        $baseHref=SCDS::e(LocalUrl::baseHref());
        $appBase=SCDS::e(LocalUrl::basePath());
        $u=static fn(string $path): string=>SCDS::e(LocalUrl::path($path));
        $asset=static fn(string $path): string=>SCDS::e(AssetUrl::asset($path));
        $scds=static fn(string $file): string=>SCDS::e(AssetUrl::scds($file));
        $rawBuildVersion=AssetUrl::buildVersion();
        $buildVersion=SCDS::e($rawBuildVersion);
        $displayVersion=SCDS::e(self::displayVersion($rawBuildVersion));
        $runtimeAdapter=$isPublicStaff?'/assets/staff-app/public-hall-transport.js':'/assets/staff-app/local-runtime-adapter.js';
        $publicStaffCss=$isPublicStaff?'<link rel="stylesheet" href="'.$asset('/assets/scds/remote-staff.css').'">':'';

        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><base href="'.$baseHref.'">';
        $brandAsset=static fn(string $variant): string=>SCDS::e(LocalUrl::path('/brand-asset.php').'?variant='.rawurlencode($variant).'&iv='.rawurlencode($identityVersion));
        $brandManifest=SCDS::e(LocalUrl::path('/manifest.php').'?iv='.rawurlencode($identityVersion));
        $uiPreferenceOwner=$isPublicStaff?('public:'.(string)($user['installation_id']??'').':'.(string)($user['projection_id']??'')):('local:user:'.(int)($user['id']??0));
        $uiPreferenceConfig=['values'=>$uiPreferences,'endpoint'=>LocalUrl::path($uiPreferencesEndpoint),'csrf'=>$uiPreferencesCsrf,'mode'=>$staffMode,'owner'=>$uiPreferenceOwner];
        $uiPreferenceJson=json_encode($uiPreferenceConfig,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);if(!is_string($uiPreferenceJson))$uiPreferenceJson='{}';
        echo '<script>window.__SOKNA_UI_PREFERENCES__='.$uiPreferenceJson.';(function(c){try{var v=c.values||{},m={theme:"sokna.theme",nav_compact:"sokna.nav.compact.v1",nav_groups:"sokna.nav.groups.v1",hall_sort:"sokna.hall.sort",hall_density:"sokna.hall.density"},ok="sokna.ui.preferences.owner.v1",before=localStorage.getItem(ok)||"",owner=String(c.owner||"");if(before&&owner&&before!==owner)Object.keys(m).forEach(function(k){localStorage.removeItem(m[k])});Object.keys(v).forEach(function(k){if(!m[k])return;var x=v[k];if(k==="nav_compact")x=x?"1":"0";else if(k==="nav_groups")x=JSON.stringify(x);localStorage.setItem(m[k],String(x))});if(owner)localStorage.setItem(ok,owner)}catch(e){}})(window.__SOKNA_UI_PREFERENCES__);</script>';
        echo '<title>'.$safeTitle.' | '.$brandName.'</title><meta name="theme-color" content="#0f6b66"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="application-name" content="'.$brandName.'"><link rel="manifest" href="'.$brandManifest.'"><meta name="sokna-build" content="'.$buildVersion.'"><meta name="sokna-app-base" content="'.$appBase.'"><script src="'.$asset('/assets/product-boot.js').'"></script><link rel="icon" type="image/png" sizes="16x16" href="'.$brandAsset('icon-16').'"><link rel="icon" type="image/png" sizes="32x32" href="'.$brandAsset('icon-32').'"><link rel="icon" type="image/png" sizes="48x48" href="'.$brandAsset('icon-48').'"><link rel="icon" type="image/png" sizes="192x192" href="'.$brandAsset('icon-192').'"><link rel="apple-touch-icon" sizes="180x180" href="'.$brandAsset('icon-180').'"><link rel="stylesheet" href="'.$scds('tokens.css').'"><link rel="stylesheet" href="'.$scds('components.css').'"><link rel="stylesheet" href="'.$asset('/assets/product-ui.css').'"><script src="'.$asset('/assets/user-ui-preferences.js').'" defer></script><script src="'.$asset('/assets/product-theme.js').'" defer></script><script src="'.$asset('/assets/locale.js').'" defer></script><script src="'.$asset('/assets/staff-app/runtime.js').'" defer></script><script src="'.$asset($runtimeAdapter).'" defer></script><script src="'.$asset('/assets/staff-app/sellable-picker.js').'" defer></script><script src="'.$asset('/assets/settlement-tender.js').'" defer></script><script src="'.$asset('/assets/ui-dialogs.js').'" defer></script><script src="'.$asset('/assets/product-shell.js').'" defer></script><script src="'.$asset('/assets/product-interactions.js').'" defer></script><script src="'.$asset('/assets/jalali-fields.js').'" defer></script><script src="'.$asset('/assets/global-search.js').'" defer></script><script src="'.$scds('scds.js').'" defer></script></head><body data-page="'.SCDS::e($active).'" data-app-base="'.$appBase.'" data-app-timezone="'.$timezone.'" data-server-now="'.$serverNow.'" data-build-version="'.$buildVersion.'" data-staff-ui-mode="'.SCDS::e($staffMode).'"'.($hasLocalTabs?' data-has-local-tabs="1"':'').self::bodyDataAttrs($extraBodyAttrs).'>';
        echo '<a class="sc-skip-link" href="#mainContent">پرش به محتوای اصلی</a><div class="sc-shell">';
        echo '<aside class="sc-shell__nav" data-product-nav id="productNav" aria-label="ناوبری اصلی">';
        echo '<a class="sc-shell__brand" href="'.$u($homePath).'" aria-label="خانه '.$brandName.'"><span class="sc-shell__brand-mark" aria-hidden="true"><img src="'.$brandAsset('logo-256').'" alt="" width="256" height="256" decoding="async"></span><span class="sc-shell__brand-copy"><strong>'.$brandName.'</strong><span>'.$brandSubtitle.'</span></span></a>';
        echo '<div class="sc-shell__nav-scroll" data-shell-primary-nav>';
        foreach($groups as $group=>$entries){
            $groupCurrent=false;foreach($entries as $entry){if($entry['id']===$active||($active==='staff'&&$entry['id']==='operator')){$groupCurrent=true;break;}}
            $groupKey=self::groupKey($group);$open=$groupCurrent||$group==='کار روزانه'?' open':'';
            echo '<details class="sc-shell__nav-group sc-shell__nav-group--primary'.($groupCurrent?' is-current':'').'" data-shell-nav-group data-nav-group-key="'.SCDS::e($groupKey).'"'.$open.'><summary class="sc-shell__nav-summary" data-shell-nav-group-toggle><span class="sc-shell__nav-title">'.SCDS::e($group).'</span><span class="sc-shell__nav-chevron" aria-hidden="true">'.self::icon('chevron-down').'</span></summary><nav aria-label="'.SCDS::e($group).'"><ul class="sc-shell__nav-list">';
            foreach($entries as $item){
                $isCurrent=$item['id']===$active||($active==='staff'&&$item['id']==='operator');
                $current=$isCurrent?' aria-current="page"':'';$label=SCDS::e($item['label']);
                echo '<li><a class="sc-shell__nav-link"'.$current.' href="'.$u($item['href']).'" title="'.$label.'"><span class="sc-shell__nav-icon">'.self::icon(self::iconFor($item['id'])).'</span><span>'.$label.'</span></a></li>';
            }
            echo '</ul></nav></details>';
        }
        echo '</div>';
        $itemById=[];foreach($items as $item)$itemById[(string)$item['id']]=$item;
        echo '<div class="sc-shell__nav-bottom">';
        echo '<nav class="sc-shell__utility" aria-label="راهنما و سلامت سامانه">';
        if(isset($itemById['system'])){$current=$active==='system'?' aria-current="page"':'';$systemLabel=SCDS::e((string)$itemById['system']['label']);echo '<a class="sc-shell__utility-link"'.$current.' href="'.$u((string)$itemById['system']['href']).'" title="'.$systemLabel.'"><span class="sc-shell__nav-icon">'.self::icon('adjust').'</span><span>'.$systemLabel.'</span></a>';}
        if(isset($itemById['help'])){$current=$active==='help'?' aria-current="page"':'';echo '<a class="sc-shell__utility-link"'.$current.' href="'.$u((string)$itemById['help']['href']).'" title="راهنما"><span class="sc-shell__nav-icon">'.self::icon('info').'</span><span>راهنما</span></a>';}
        echo '</nav>';
        echo '<div class="sc-shell__footer"><a class="sc-button sc-button--secondary" href="'.$u($logoutPath).'">'.self::icon('logout').'<span>خروج</span></a><button class="sc-icon-button sc-shell__compact-toggle" type="button" data-shell-nav-compact aria-label="جمع‌کردن نوار کناری" aria-pressed="false" title="جمع‌کردن نوار کناری">'.self::icon('menu').'</button></div><div class="sc-shell__version" data-shell-version aria-label="نسخه نصب‌شده '.$displayVersion.'">'.$displayVersion.'</div></div>';
        echo '</aside>';
        echo '<button class="sc-shell__backdrop" type="button" data-product-nav-backdrop aria-label="بستن منو" tabindex="-1" hidden></button>';

        echo '<main class="sc-shell__main" id="mainContent">';
        echo '<header class="sc-shell__topbar'.($searchPath===''?' sc-shell__topbar--without-search':'').'" data-product-topbar>';
        echo '<div class="sc-shell__top-left">';
        $accountLink=$accountPath!==''?'<a class="sc-user-menu__link" href="'.$u($accountPath).'" data-user-menu-primary><span>'.self::icon('users').'</span><span><strong>حساب کاربری</strong><small>پروفایل و امنیت حساب</small></span></a>':'';
        echo '<div class="sc-user-menu sc-theme-picker" data-theme-picker data-user-menu><button class="sc-shell__user-card sc-user-menu__trigger" type="button" data-theme-menu-toggle data-theme-menu-label="preserve" aria-label="منوی کاربر، '.$name.'" aria-haspopup="true" aria-expanded="false"><span class="sc-shell__user-copy"><strong>'.$name.'</strong><small>'.$safeRole.'</small></span><span class="sc-user-menu__chevron" aria-hidden="true">'.self::icon('chevron-down').'</span></button><div class="sc-theme-picker__menu sc-user-menu__menu" aria-label="منوی کاربر" hidden data-theme-menu data-user-menu-panel>'.$accountLink.'<div class="sc-user-menu__section"><span class="sc-user-menu__section-label">پوسته</span><div class="sc-user-menu__theme" role="radiogroup" aria-label="پوسته سامانه"><button type="button" role="radio" data-theme-option="light" aria-checked="false"><span>'.self::icon('sun').'</span><span>روشن</span></button><button type="button" role="radio" data-theme-option="dark" aria-checked="false"><span>'.self::icon('moon').'</span><span>تیره</span></button><button type="button" role="radio" data-theme-option="system" aria-checked="false"><span>'.self::icon('device').'</span><span>سیستم</span></button></div></div><a class="sc-user-menu__link sc-user-menu__link--logout" href="'.$u($logoutPath).'"><span>'.self::icon('logout').'</span><span><strong>خروج</strong><small>پایان نشست جاری</small></span></a></div></div>';
        echo '<div class="sc-shell__date-card" data-shell-clock data-timezone="'.$timezone.'" data-server-now="'.$serverNow.'"><span class="sc-shell__date-icon" aria-hidden="true">'.self::icon('calendar').'</span><span class="sc-shell__date-copy"><strong data-shell-date>—</strong><span data-shell-time>—</span></span></div>';
        echo '<a class="sc-icon-button sc-shell__notify" href="'.$u($notificationsPath).'" aria-label="اعلان‌ها">'.self::icon('bell').'</a>';
        echo '</div>';
        echo '<div class="sc-shell__top-spacer" aria-hidden="true"></div>';
        echo '<div class="sc-shell__right-actions">';
        echo '<button class="sc-shell__toggle" type="button" data-product-nav-toggle aria-label="باز کردن منو" aria-controls="productNav" aria-expanded="false">'.self::icon('menu').'</button>';
        if($canOrder&&$active!=='staff')echo '<a class="sc-shell__order-button" href="'.$u($orderPath).'" data-global-order aria-label="ثبت سفارش"><span class="sc-shell__order-icon" aria-hidden="true">'.self::icon('plus').'</span><span>ثبت سفارش</span></a>';
        if($searchPath!=='')echo '<div class="sc-shell__search"><div class="sc-global-search" data-global-search data-api="'.$u($searchPath).'"><span class="sc-global-search__icon" aria-hidden="true">'.self::icon('search').'</span><label class="sc-global-search__field"><span class="sc-global-search__label">جست‌وجوی سراسری</span><input class="sc-control sc-global-search__input" type="search" autocomplete="off" spellcheck="false" placeholder="جستجو در میز، سفارش، مشتری، منو و تنظیمات…" aria-label="جست‌وجوی سراسری" role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="sc-global-search-results" aria-expanded="false" data-global-search-input></label><span class="sc-global-search__shortcut" aria-hidden="true"><bdi dir="ltr">Ctrl + K</bdi></span><div class="sc-command-palette" id="sc-global-search-results" role="listbox" aria-label="نتایج جست‌وجو" hidden data-global-search-results></div></div></div>';
        echo '</div>';
        echo '</header>';
        echo '<div class="sc-shell__content"><section class="sc-page-header'.($hasLocalTabs?' sc-page-header--with-tabs':'').'" aria-labelledby="scPageTitle"><div class="sc-page-header__main"><div class="sc-page-header__copy sc-page-header__copy--inline"><h1 id="scPageTitle">'.$safeTitle.'</h1>'.($safeSubtitle!==''?'<p>'.$safeSubtitle.'</p>':'').'</div></div><div class="sc-page-header__actions" data-page-header-actions>'.$pageStatusHtml.'</div></section>';
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
    public static function navigation(object $core,array $user): array
    {
        $staffUi=is_array($user['_staff_ui']??null)?$user['_staff_ui']:[];$public=(string)($staffUi['mode']??'local')==='public';
        $path=static function(string $local,string $remote)use($public):string{return $public?$remote:$local;};
        $items=[['id'=>'home','label'=>'داشبورد','href'=>$path('/','/staff/')]];
        if(self::any($core,$user,['orders_floor','cashier_accounts','shift_supervision']))$items[]=['id'=>'operator','label'=>'سالن و میزها','href'=>$path('/operator/','/staff/operator/')];
        if(!$public&&self::any($core,$user,['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports']))$items[]=['id'=>'staff-consumption','label'=>'مصرف پرسنل','href'=>'/staff-consumption/'];
        if($core->auth()->hasCapability('preparation',$user))$items[]=['id'=>'preparation','label'=>'آماده‌سازی','href'=>$path('/waiter/','/staff/preparation/')];
        if(self::any($core,$user,['inventory_view','inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']))$items[]=['id'=>'operations','label'=>'انبار و تأمین','href'=>$path('/operations/','/staff/operations/')];
        if((string)($user['role']??'')==='admin'||$core->auth()->hasCapability('cashier_accounts',$user)){
            if(!$public)$items[]=['id'=>'finance','label'=>'مالی','href'=>'/finance/'];
            $items[]=['id'=>'subscribers','label'=>'مشتریان','href'=>$path('/subscribers/','/staff/subscribers/')];
            if(!$public)$items[]=['id'=>'integrations','label'=>'اتصال‌ها','href'=>'/integrations/'];
        }
        if(self::any($core,$user,['cashier_accounts','shift_supervision','staff_consumption_reports']))$items[]=['id'=>'reports','label'=>'گزارش و تحلیل','href'=>$path('/reports/','/staff/reports/')];
        $items[]=['id'=>'notifications','label'=>'اعلان‌ها','href'=>$path('/notifications/','/staff/notifications/')];
        if($public&&(string)($user['role']??'')==='admin')$items[]=['id'=>'system','label'=>'پشتیبانی و نگهداری','href'=>'/staff/health/'];
        if(!$public)$items[]=['id'=>'help','label'=>'راهنما','href'=>'/help/'];
        if(!$public&&(string)($user['role']??'')==='admin'){
            $items[]=['id'=>'catalog','label'=>'کاتالوگ','href'=>'/catalog/'];
            $items[]=['id'=>'guest-content','label'=>'محتوای مهمان','href'=>'/guest-content/'];
            $items[]=['id'=>'marketing','label'=>'کمپین و رویداد','href'=>'/marketing/'];
            $items[]=['id'=>'admin','label'=>'مدیریت','href'=>'/admin/'];
            $items[]=['id'=>'printing','label'=>'چاپ','href'=>'/printing/'];
            $items[]=['id'=>'system','label'=>'پشتیبانی و نگهداری','href'=>'/system/'];
        }
        return $items;
    }

    /** @param list<array{id:string,label:string,href:string}> $items
     *  @return array<string,list<array{id:string,label:string,href:string}>>
     */
    private static function sidebarNavigationGroups(array $items): array
    {
        $order=['کار روزانه','عملیات و مالی','منو و مهمان','مدیریت']; // «مدیریت و پشتیبانی» split: health/help stay fixed in footer.
        $groups=array_fill_keys($order,[]);
        foreach($items as $item){
            $id=(string)$item['id'];
            if(in_array($id,['system','help','notifications'],true))continue;
            $group=match($id){
                'home','operator','staff','staff-consumption','preparation'=>'کار روزانه',
                'operations','finance','subscribers','reports'=>'عملیات و مالی',
                'catalog','guest-content','marketing'=>'منو و مهمان',
                default=>'مدیریت',
            };
            $groups[$group][]=$item;
        }
        return array_filter($groups,static fn(array $entries): bool=>$entries!==[]);
    }

    private static function groupKey(string $label): string
    {
        return match($label){'کار روزانه'=>'daily','عملیات و مالی'=>'operations','منو و مهمان'=>'guest','مدیریت'=>'management',default=>'other'};
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
            'integrations','printing','notifications','admin','system','account','help'=>'تنظیمات و پشتیبانی',
            default=>'سکنا',
        };
    }

    private static function iconFor(string $id): string
    {
        return match($id){
            'home'=>'dashboard','operator'=>'table','staff'=>'cart','staff-consumption'=>'users','preparation'=>'service',
            'operations'=>'archive','finance'=>'ticket','subscribers'=>'users','integrations'=>'device','reports'=>'chart',
            'printing'=>'print','notifications'=>'bell','help'=>'info','catalog'=>'coffee','guest-content'=>'eye','marketing'=>'megaphone','admin'=>'settings','system'=>'adjust','account'=>'users',
            default=>'list',
        };
    }

    private static function icon(string $name): string
    {
        $safe=SCDS::e($name);
        return '<svg class="sc-ui-icon" aria-hidden="true" focusable="false"><use href="'.SCDS::e(AssetUrl::sprite('/assets/ui-sprite.svg','icon-'.$safe)).'"></use></svg>';
    }

    /** @return array{name:string,subtitle:string} */
    private static function brand(object $core): array
    {
        $name='سکنا';$subtitle='سامانه مدیریت کافه';$identityVersion='fallback';
        try{
            $q=$core->database()->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('cafe.name','brand.subtitle')");
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row){
                $value=trim((string)($row['setting_value']??''));if($value==='')continue;
                if((string)$row['setting_key']==='cafe.name')$name=$value;elseif((string)$row['setting_key']==='brand.subtitle')$subtitle=$value;
            }
            $identityVersion=(string)($core->guestContent()->brandIdentity('draft')['version']??'fallback');
        }catch(\Throwable){}
        return ['name'=>$name,'subtitle'=>$subtitle,'identity_version'=>$identityVersion];
    }

    private static function initial(string $display): string
    {
        $display=trim($display);
        if($display==='')return 'س';
        if(function_exists('mb_substr'))return mb_substr($display,0,1,'UTF-8');
        return substr($display,0,1);
    }

    private static function any(object $core,array $user,array $capabilities): bool
    {
        if((string)($user['role']??'')==='admin')return true;
        foreach($capabilities as $capability)if($core->auth()->hasCapability($capability,$user))return true;
        return false;
    }

    /** @param array<string,scalar|null> $attrs */
    private static function bodyDataAttrs(array $attrs): string
    {
        $out='';foreach($attrs as $key=>$value){$safe=preg_replace('/[^a-z0-9_-]/i','',(string)$key);if($safe===''||$value===null)continue;$out.=' data-'.SCDS::e($safe).'="'.SCDS::e((string)$value).'"';}return $out;
    }

    private static function displayVersion(string $version): string
    {
        $version=trim($version);
        if(preg_match('/^v?(\d+\.\d+\.\d+)/',$version,$semver)===1){
            $display=(string)$semver[1];
            if(preg_match('/(?:^|[-+])workspace\.([0-9]+[a-z]*)(?:[-+.]|$)/i',$version,$build)===1)$display.='.'.(string)$build[1];
            return $display;
        }
        return $version!==''?$version:'—';
    }

    private static function roleLabel(string $role): string
    {
        return match($role){'admin'=>'مدیر','operator'=>'اپراتور','waiter'=>'همکار سالن',default=>'کاربر'};
    }
}
