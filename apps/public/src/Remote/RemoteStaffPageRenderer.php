<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Remote;

use Sokna\PublicEdge\Core\Bootstrap;
use Sokna\Local\UI\LocalUrl;
use Sokna\Local\UI\ProductShell;

/**
 * Public Staff host. The shell itself is the canonical Local ProductShell;
 * this class only supplies Public data/transport adapters and route content.
 */
final class RemoteStaffPageRenderer
{
    public function __construct(private readonly Bootstrap $core,private readonly string $basePath='')
    {
        LocalUrl::configure($basePath);
    }

    public function login(string $installationId,string $error='',string $selectedProjectionId='',string $manualUsername='',string $originTransition=''): array
    {
        LocalUrl::configure($this->basePath);
        $originTransition=preg_match('/^rebind-[a-f0-9]{24}$/D',trim($originTransition))===1?trim($originTransition):'';
        $transitionHtml=$originTransition!==''?'<div class="sc-alert sc-alert--warning" role="status"><strong>آدرس سامانه تغییر کرده است.</strong><br><span>پس از ورود، وضعیت اپ و اعلان روی این آدرس بررسی می‌شود.</span></div>':'';
        $errorHtml=$error!==''?'<div class="sc-alert sc-alert--danger" role="alert"><strong>ورود انجام نشد.</strong><br><span>'.$this->e($error).'</span></div>':'';
        $brandName='سکنا';$accounts=[];
        try{
            $stmt=$this->core->database()->prepare('SELECT display_name FROM installations WHERE installation_id=? LIMIT 1');$stmt->execute([$installationId]);$candidate=trim((string)($stmt->fetchColumn()?:''));if($candidate!=='')$brandName=$candidate;
            $q=$this->core->database()->prepare('SELECT p.projection_id,p.display_name,p.role FROM auth_projections p JOIN installations i ON i.installation_id=p.installation_id WHERE p.installation_id=? AND p.active=1 AND i.active=1 AND i.remote_enabled=1 ORDER BY COALESCE(NULLIF(p.display_name,\'\'),p.projection_id),p.projection_id LIMIT 24');$q->execute([$installationId]);$rows=$q->fetchAll(\PDO::FETCH_ASSOC);if(is_array($rows))$accounts=$rows;
        }catch(\Throwable){}
        $loginPrefs=[];try{$loginPrefs=$this->core->uiPreferences()->forInstallation($installationId,array_map(static fn(array $a):string=>(string)($a['projection_id']??''),$accounts));}catch(\Throwable){}
        $brand=$this->e($brandName);$base=$this->e($this->base());$asset=fn(string $path):string=>$this->e($this->u($path));$brandAsset=fn(string $variant):string=>$this->e($this->u('/brand-asset.php').'?variant='.rawurlencode($variant).'&iv=public');
        $icon=fn(string $name):string=>'<svg class="sc-ui-icon" aria-hidden="true" focusable="false"><use href="'.$asset('/assets/ui-sprite.svg').'#icon-'.$this->e($name).'"></use></svg>';
        $initial=static function(string $value):string{$value=trim($value);if($value==='')return 'س';return function_exists('mb_substr')?mb_substr($value,0,1,'UTF-8'):substr($value,0,1);};
        $accountHtml='';$explicit=$selectedProjectionId!==''?'1':'0';
        if($accounts!==[]){
            $chosen='';$cards='';foreach($accounts as $i=>$account){$id=trim((string)($account['projection_id']??''));if($id==='')continue;$display=trim((string)($account['display_name']??''));if($display==='')$display='عضو تیم';$checked=$selectedProjectionId!==''?$selectedProjectionId===$id:$i===0;if($checked)$chosen=$id;
                $cards.='<label class="sc-login-account'.($checked?' is-selected':'').'" data-login-account data-account-key="'.$this->e($id).'"'.(!empty($loginPrefs[$id]['theme'])?' data-theme-preference="'.$this->e((string)$loginPrefs[$id]['theme']).'"':'').'><input type="radio" name="account_picker" value="'.$this->e($id).'" aria-label="انتخاب '.$this->e($display).'"'.($checked?' checked':'').'><span class="sc-login-account__avatar" aria-hidden="true">'.$this->e($initial($display)).'</span><span class="sc-login-account__copy"><strong>'.$this->e($display).'</strong></span><span class="sc-login-account__check">'.$icon('check').'</span></label>';
            }if($chosen===''&&isset($accounts[0]))$chosen=(string)$accounts[0]['projection_id'];
            $accountHtml='<fieldset class="sc-login-accounts" data-login-accounts><legend>حساب</legend><div class="sc-login-account-grid'.(count($accounts)>4?' sc-login-account-grid--many':'').'" role="radiogroup" aria-label="انتخاب حساب کاربری">'.$cards.'</div></fieldset><input type="hidden" name="projection_id" value="'.$this->e($chosen).'" data-login-selection>';
        }else{
            $accountHtml='<div class="sc-alert sc-alert--warning" role="status">فهرست اعضای تیم هنوز همگام نشده است. در صورت داشتن حساب، نام کاربری را وارد کن.</div><label class="sc-field"><span class="sc-field__label">نام کاربری</span><input class="sc-control" name="username" value="'.$this->e($manualUsername).'" autocomplete="username" required></label>';
        }
        $html='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>ورود | '.$brand.'</title><meta name="theme-color" content="#0f6b66"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="application-name" content="'.$brand.'"><meta name="sokna-app-base" content="'.$base.'"><link rel="manifest" href="'.$asset('/manifest.php').'"><script src="'.$asset('/assets/product-boot.js').'"></script><link rel="icon" type="image/png" sizes="32x32" href="'.$brandAsset('icon-32').'"><link rel="apple-touch-icon" sizes="180x180" href="'.$brandAsset('icon-180').'"><link rel="stylesheet" href="'.$asset('/assets/scds/tokens.css').'"><link rel="stylesheet" href="'.$asset('/assets/scds/components.css').'"><link rel="stylesheet" href="'.$asset('/assets/product-ui.css').'"></head><body data-page="login" data-staff-ui-mode="public">'
            .'<main class="sc-auth"><button class="sc-icon-button sc-shell__theme sc-auth__theme" type="button" data-theme-toggle aria-label="فعال کردن حالت تیره" aria-pressed="false"><svg class="sc-theme-icon sc-theme-icon--moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.2A8.5 8.5 0 0 1 8.8 4a8.6 8.6 0 1 0 11.2 11.2Z"/></svg><svg class="sc-theme-icon sc-theme-icon--sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/></svg></button>'
            .'<div class="sc-auth__shell"><section class="sc-auth__story" aria-label="معرفی سامانه"><div class="sc-auth__story-brand"><span class="sc-auth__story-mark" aria-hidden="true"><img src="'.$brandAsset('logo-256').'" alt="" width="256" height="256" decoding="async"></span><span><strong>'.$brand.'</strong><small>فضای کاری تیم کافه</small></span></div><div class="sc-auth__story-copy"><span>یک مسیر، از سفارش تا تحویل</span><h1>هرکس با حساب خودش، سریع وارد کار شود.</h1><p>حساب خودت را انتخاب کن، رمز همان حساب کاری را وارد کن و مستقیم وارد فضای کاری روزانه شو.</p></div><div class="sc-auth__story-points"><span>'.$icon('operations').'عملیات</span><span>'.$icon('ticket').'مالی</span><span>'.$icon('archive').'انبار</span></div></section>'
            .'<section class="sc-auth__card" data-login-root data-login-storage-key="sokna.public.login.last-account" data-selected-explicit="'.$explicit.'"><div class="sc-auth__brand"><span>ورود اعضای تیم</span><h2>'.($accounts!==[]?'حساب خودت را انتخاب کن':'با حساب خودت وارد شو').'</h2><p>'.($accounts!==[]?'حساب انتخاب‌شده در این دستگاه به خاطر سپرده می‌شود؛ رمز ذخیره نمی‌شود.':'رمز حساب شما ذخیره نمی‌شود.').'</p></div>'.$transitionHtml.$errorHtml
            .'<form method="post" action="'.$asset('/staff/login').'" class="sc-form sc-login-form" autocomplete="on" data-login-form><input type="hidden" name="installation_id" value="'.$this->e($installationId).'"><input type="hidden" name="origin_transition" value="'.$this->e($originTransition).'">'.$accountHtml.'<label class="sc-field"><span class="sc-field__label">رمز عبور</span><input class="sc-control" name="password" type="password" autocomplete="current-password" required autofocus></label><label class="sc-check sc-login-remember"><input type="checkbox" name="remember_device" value="1"> در این دستگاه وارد بمان (۱۴ روز؛ فقط دستگاه شخصی)</label><button class="sc-button" type="submit">'.$icon('check').'ورود به سامانه</button></form><small class="sc-auth__footnote">ورود امن با همان حساب و رمز کارکنان</small></section></div></main><script src="'.$asset('/assets/product-theme.js').'" defer></script><script src="'.$asset('/assets/login.js').'" defer></script><script src="'.$asset('/assets/scds/scds.js').'" defer></script></body></html>';
        return $this->pageResponse($html);
    }

    public function page(array $session,string $page): array
    {
        LocalUrl::configure($this->basePath);
        $page=trim($page,'/');if($page==='')$page='home';
        $meta=$this->meta($page);if($meta===null)return ['status'=>404,'headers'=>['Content-Type'=>'text/plain; charset=utf-8'],'body'=>'Not found'];
        if(!$this->allowed($session,$page))return ['status'=>403,'headers'=>['Content-Type'=>'text/plain; charset=utf-8'],'body'=>'Forbidden'];
        $user=$this->shellUser($session);
        ob_start();
        ProductShell::start($this->core,$user,$meta['title'],$meta['active'],$meta['subtitle'],$page==='operations');
        $this->content($session,$page);
        echo '<script src="'.ProductShell::asset('assets/staff-app/public-page-host.js').'" defer></script>';
        ProductShell::end();
        return $this->pageResponse((string)ob_get_clean());
    }

    /**
     * Cache-safe Staff bootstrap used only after an authenticated online session warms the Service Worker.
     * It never embeds the LAN bearer token or operational data. Runtime authority is re-established
     * against Local with the previously persisted short-lived LAN credential before any adapter loads.
     */
    public function offlineBootstrap(array $session,string $view='home'): array
    {
        LocalUrl::configure($this->basePath);
        $view=trim($view);if(!in_array($view,['home','operator','preparation'],true))$view='home';
        if($view!=='home'&&!$this->allowed($session,$view))return ['status'=>403,'headers'=>['Content-Type'=>'text/plain; charset=utf-8','Cache-Control'=>'no-store'],'body'=>'Forbidden'];
        $canOrder=$this->has($session,'orders_floor');$canCashier=$this->has($session,'cashier_accounts');$canPrep=$this->hasAny($session,['preparation','shift_supervision']);
        $title=match($view){'operator'=>'سالن و میزها — شبکه داخلی','preparation'=>'آماده‌سازی — شبکه داخلی',default=>'حالت شبکه داخلی بدون اینترنت'};
        $asset=fn(string $path):string=>$this->e($this->u($path));$nav=function(string $path,string $label,string $cap='')use($asset):string{return '<a class="sc-button sc-button--secondary" href="'.$asset($path).'"'.($cap!==''?' data-offline-cap="'.$this->e($cap).'" hidden':'').'>'.$this->e($label).'</a>';};
        $html='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>'.$this->e($title).' | سکنا</title><meta name="theme-color" content="#0f6b66"><meta name="sokna-app-base" content="'.$this->e($this->base()).'"><link rel="manifest" href="'.$asset('/staff/manifest.webmanifest').'"><script src="'.$asset('/assets/product-boot.js').'"></script><link rel="stylesheet" href="'.$asset('/assets/scds/tokens.css').'"><link rel="stylesheet" href="'.$asset('/assets/scds/components.css').'"><link rel="stylesheet" href="'.$asset('/assets/scds/remote-staff.css').'"><link rel="stylesheet" href="'.$asset('/assets/product-ui.css').'"></head>';
        $html.='<body data-page="'.$this->e($view).'" data-app-base="'.$this->e($this->base()).'" data-staff-ui-mode="public" data-staff-offline-bootstrap="1" data-offline-view="'.$this->e($view).'" data-can-order="'.($canOrder?'1':'0').'" data-can-cashier="'.($canCashier?'1':'0').'" data-can-supervise="'.($this->has($session,'shift_supervision')?'1':'0').'" data-can-preparation-mutate="'.($canPrep?'1':'0').'">';
        $html.='<main class="sc-shell__main"><div class="sc-shell__content"><section class="sc-page-header"><div class="sc-page-header__main"><div class="sc-page-header__copy"><h1>'.$this->e($title).'</h1><p>این صفحه از قبل روی همین دستگاه آماده شده است؛ اطلاعات کاری پس از تأیید اتصال به سامانه داخلی نمایش داده می‌شود.</p></div></div><div class="sc-page-header__actions"><span data-offline-identity>عضو تیم</span></div></section>';
        $html.='<div class="sc-alert sc-alert--warning" role="status" aria-live="polite" data-offline-state>در حال بررسی اتصال داخلی…</div><nav class="sc-stack" aria-label="فضای کاری شبکه داخلی">'.$nav('/staff/','شروع کار').$nav('/staff/operator/','سالن و میزها','orders_floor,cashier_accounts,shift_supervision').$nav('/staff/preparation/','آماده‌سازی','preparation,shift_supervision').'<button class="sc-button sc-button--secondary" type="button" data-offline-clear>خروج از حالت شبکه داخلی</button></nav>';
        if($view==='home'){
            $html.='<section class="sc-card"><div class="sc-card__body"><h2>شروع امن بدون اینترنت</h2><p>این حالت روی دستگاهی کار می‌کند که قبلاً وارد سامانه شده و دسترسی داخلی آن هنوز معتبر است. برای ورود تازه، اتصال اینترنت لازم است.</p><p>در حالت بدون اینترنت، بخش‌های مدیریتی در دسترس نیستند. سالن، سفارش، تسویه و آماده‌سازی پس از تأیید اتصال داخلی قابل استفاده‌اند.</p></div></section>';
        }elseif($view==='operator'){
            ob_start();$hallConfig=['csrf'=>'','mode'=>'public','auto_load'=>true,'can_order'=>$canOrder,'can_cashier'=>$canCashier,'can_supervise'=>$this->has($session,'shift_supervision'),'api'=>$this->u('/staff/hall-adapter'),'order_api'=>$this->u('/staff/order-adapter'),'finance_api'=>$this->u('/staff/finance-adapter'),'subscriber_api'=>$this->u('/staff/subscriber-adapter'),'printing_url'=>'','features'=>$this->hallFeatures($canOrder,$canCashier)];$hallAsset=fn(string $path):string=>$this->u('/'.ltrim($path,'/'));require dirname(__DIR__,2).'/resources/shared-staff-ui/hall-workspace.php';$html.=(string)ob_get_clean();
        }else{
            $html.='<section class="sc-workspace" data-preparation-workspace data-staff-ui-mode="public" data-auto-load="1"><button class="sc-button sc-button--secondary" type="button" data-refresh hidden>تازه‌سازی</button><section class="sc-preparation-panel"><div class="sc-preparation-summary" data-preparation-summary aria-live="polite"></div><div class="sc-alert" role="status" aria-live="polite" data-status hidden></div><div class="sc-preparation-groups" data-preparation-list></div></section></section>';
        }
        $html.='</div></main><script src="'.$asset('/assets/staff-app/offline-bootstrap.js').'" defer></script></body></html>';
        return ['status'=>200,'headers'=>['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store','X-Sokna-Offline-Template'=>'1'],'body'=>$html];
    }

    /** Backward-compatible entry used by older kernel/tests. */
    public function dashboard(array $session): array{return $this->page($session,'home');}

    private function shellUser(array $session): array
    {
        $canOrder=$this->has($session,'orders_floor');$canCashier=$this->has($session,'cashier_accounts');$canPrep=$this->has($session,'preparation');
        $sessionExpires=trim((string)($session['expires_at']??''));if($sessionExpires!==''){$ts=\Sokna\Shared\IranTime::epoch($sessionExpires,'UTC');if($ts!==false)$sessionExpires=gmdate('c',$ts);}
        $session['_staff_ui']=[
            'mode'=>'public','home_path'=>'/staff/','logout_path'=>'/staff/logout','account_path'=>'/staff/account/',
            'notifications_path'=>'/staff/notifications/','order_path'=>'/staff/operator/?view=order','search_path'=>'','ui_preferences_endpoint'=>'/api/staff/ui-preferences',
            'page_status_html'=>'<div class="sc-alert sc-alert--warning sc-public-connectivity" data-public-connectivity hidden>در حال بررسی اتصال سامانه کافه…</div>',
            'body_attrs'=>[
                'base-path'=>$this->base(),'can-order'=>$canOrder?'1':'0','can-cashier'=>$canCashier?'1':'0','can-supervise'=>$this->has($session,'shift_supervision')?'1':'0',
                'can-preparation-mutate'=>$canPrep?'1':'0','public-staff'=>'1','projection-id'=>(string)($session['projection_id']??''),
                'lan-base-url'=>(string)($session['lan_base_url']??''),'lan-token'=>(string)($session['lan_token']??''),'lan-token-expires-at'=>(string)($session['lan_token_expires_at']??''),'public-session-expires-at'=>$sessionExpires,'origin-transition'=>(string)($session['_origin_transition_id']??''),
            ],
        ];
        return $session;
    }

    private function content(array $session,string $page): void
    {
        if($page==='account'){$this->account($session);return;}
        if($page==='home'){$this->home($session);return;}
        if($page==='operator'){$this->operator($session);return;}
        if($page==='preparation'){$this->preparation();return;}
        if($page==='operations'){$this->operations($session);return;}
        if($page==='subscribers'){$this->subscribers($session);return;}
        if($page==='reports'){$this->reports($session);return;}
        if($page==='notifications'){$this->notifications();return;}
        if($page==='health'){$this->health($session);return;}
    }

    private function account(array $session): void
    {
        $q=$this->core->database()->prepare('SELECT username,display_name,role FROM auth_projections WHERE installation_id=? AND projection_id=? AND active=1 LIMIT 1');
        $q->execute([(string)($session['installation_id']??''),(string)($session['projection_id']??'')]);$account=$q->fetch(\PDO::FETCH_ASSOC)?:[];
        $label=$this->e((string)($account['display_name']??$session['display_name']??'عضو تیم'));
        $username=$this->e((string)($account['username']??''));
        $csrf=$this->e((string)($session['_account_csrf']??''));
        echo '<section class="sc-page sc-account-page" data-public-account-root data-csrf="'.$csrf.'" data-account-base="'.$this->e($this->base()).'">';
        echo '<div class="sc-page-header"><div><h1>حساب کاربری</h1><p>رمز حساب کاری خود را در موبایل یا دسکتاپ تغییر دهید.</p></div></div>';
        echo '<div class="sc-card"><div class="sc-card__body sc-stack"><h2>مشخصات حساب</h2><div class="sc-grid sc-grid--two"><div class="sc-field"><span class="sc-field__label">نام نمایشی</span><strong>'.$label.'</strong></div><div class="sc-field"><span class="sc-field__label">نام کاربری</span><strong dir="ltr">'.$username.'</strong></div></div></div></div>';
        echo '<div class="sc-card"><div class="sc-card__body sc-stack"><h2>تغییر رمز عبور</h2><p class="sc-muted">رمز جدید در سامانه اصلی کافه ثبت می‌شود. هنگام قطع ارتباط، تغییر رمز انجام نمی‌شود.</p>';
        echo '<form class="sc-form sc-stack" data-public-password-form autocomplete="off">';
        echo '<label class="sc-field"><span class="sc-field__label">رمز فعلی</span><input class="sc-control" type="password" name="current_password" autocomplete="current-password" required></label>';
        echo '<label class="sc-field"><span class="sc-field__label">رمز جدید (حداقل ۸ کاراکتر)</span><input class="sc-control" type="password" name="new_password" autocomplete="new-password" minlength="8" required></label>';
        echo '<label class="sc-field"><span class="sc-field__label">تکرار رمز جدید</span><input class="sc-control" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></label>';
        echo '<div class="sc-alert" role="status" data-public-account-status hidden></div><div class="sc-actions"><button class="sc-button" type="submit" data-account-submit>ذخیره رمز جدید</button></div>';
        echo '</form></div></div></section><script src="'.ProductShell::asset('assets/staff-app/public-account.js').'" defer></script>';
    }

    /**
     * A present model is not necessarily current. Staff dashboard summaries must
     * not use stale Public read models as proof of live operational state.
     */
    private static function dashboardProjectionAvailable(array $read): bool
    {
        return (int)($read['status']??0)===200
            && is_array($read['body']['payload']??null)
            && ($read['body']['stale']??true)===false;
    }

    private function home(array $session): void
    {
        $reportsRead=$this->core->remoteReadModels()->read($session,'reports');
        $reportsModel=(int)($reportsRead['status']??0)===200&&is_array($reportsRead['body']['payload']??null)?(array)$reportsRead['body']:[];
        $read=function(string $key) use ($session): array {$r=$this->core->remoteReadModels()->read($session,$key);return (int)($r['status']??0)===200&&is_array($r['body']['payload']??null)?(array)$r['body']['payload']:[];};
        $reports=(array)($reportsModel['payload']??[]);$operationsRead=$this->core->remoteReadModels()->read($session,'operations');$operationsModel=(int)($operationsRead['status']??0)===200&&is_array($operationsRead['body']['payload']??null)?(array)$operationsRead['body']:[];$operations=(array)($operationsModel['payload']??[]);$ops=is_array($operations['hall']??null)?$operations['hall']:[];$notificationRead=$this->core->remoteReadModels()->read($session,'notifications');
        $notificationModel=(int)($notificationRead['status']??0)===200&&is_array($notificationRead['body']['payload']??null)?(array)$notificationRead['body']:[];
        $notifications=(array)($notificationModel['payload']??[]);$inventoryRead=$this->core->remoteReadModels()->read($session,'operations_workspace');$inventoryModel=(int)($inventoryRead['status']??0)===200&&is_array($inventoryRead['body']['payload']??null)?(array)$inventoryRead['body']:[];$inventoryWorkspace=(array)($inventoryModel['payload']??[]);$opsProjectionUnavailable=!self::dashboardProjectionAvailable($operationsRead);$inventoryProjectionUnavailable=!self::dashboardProjectionAvailable($inventoryRead);
        $canReports=$reports!==[];$canOps=$ops!==[];$inventoryItems=is_array($inventoryWorkspace['inventory']['items']??null)?$inventoryWorkspace['inventory']['items']:[];$canInventory=$inventoryItems!==[];
        $dashboard=is_array($reports['dashboard']??null)?$reports['dashboard']:[];$range=is_array($dashboard['range']??null)?$dashboard['range']:[];$tz=new \DateTimeZone('Asia/Tehran');$today=new \DateTimeImmutable('now',$tz);$sourceBusinessDate=PublicDashboardFinance::businessDate($reports);
        $businessDateUnavailable=$sourceBusinessDate===null||(string)($reports['business_clock']['business_date']??'')!==$sourceBusinessDate;
        if($sourceBusinessDate!==null)$today=new \DateTimeImmutable($sourceBusinessDate,$tz);
        $todayKey=$today->format('Y-m-d');$weekFrom=$today->modify('-6 days')->format('Y-m-d');
        $financeView=PublicDashboardFinance::reconcile($reports,$todayKey);
        $todaySummary=$financeView['today'];$yesterdaySummary=$financeView['yesterday'];$map=$financeView['daily'];$financeConflictDays=(array)($financeView['daily_conflicts']??[]);
        $todayHasReceipt=$financeView['today_has_receipt'];$yesterdayHasReceipt=$financeView['yesterday_has_receipt'];$todayProjectionStale=($reportsModel['stale']??true)===true;$todayProjectionUnavailable=$todayProjectionStale||$businessDateUnavailable||!$financeView['today_available'];
        $publicSnapshot=PublicDashboardFinance::lastKnownSnapshot($reports,$reportsModel,$todayKey);
        $publicDayContext=['business_day'=>$todayKey,'cutoff'=>(string)($reports['business_clock']['cutoff']??''),'old_snapshot'=>$businessDateUnavailable];
        $weekDaily=[];$weekday=[1=>'دوشنبه',2=>'سه‌شنبه',3=>'چهارشنبه',4=>'پنجشنبه',5=>'جمعه',6=>'شنبه',7=>'یکشنبه'];for($i=6;$i>=0;$i--){$d=$today->modify('-'.$i.' days');$k=$d->format('Y-m-d');$coverage=$financeView['daily_coverage'];$known=array_key_exists($k,$map)&&$sourceBusinessDate!==null&&((!$businessDateUnavailable&&!$todayProjectionStale)||$k<$todayKey)&&!in_array($k,$financeConflictDays,true);$weekDaily[]=['label'=>$i===0?'امروز':($weekday[(int)$d->format('N')]??''),'value'=>$known?($map[$k]??0):0,'known'=>$known];}$topItems=(string)($range['to']??'')===$todayKey?array_slice((array)($dashboard['top_items']??[]),0,5):[];if(!$topItems&&is_array($reports['archive']['items']??null)){foreach($reports['archive']['items'] as $item){if(is_array($item)&&(string)($item['date']??'')===$todayKey)$topItems[]=$item;}usort($topItems,static fn(array $a,array $b):int=>(int)($b['net_sales']??0)<=>(int)($a['net_sales']??0));$topItems=array_slice($topItems,0,5);}
        $todayNotice=PublicNotificationToday::preview($notifications,
            ['stale'=>!self::dashboardProjectionAvailable($notificationRead)]);
        $notificationItems=($todayNotice['today_available']??false)===true
            ?array_values(array_filter((array)($todayNotice['today_items']??[]),static function($row):bool{
                $title=trim((string)($row['title']??''));return !str_starts_with($title,'QA Notification ');
            })) : [];$reportsError=$canReports?null:['message'=>'داده‌های فروش و گزارش امروز فعلاً در دسترس نیست.'];$opsError=!$canOps?['message'=>'وضعیت سالن و سفارش‌ها فعلاً در دسترس نیست.']:($opsProjectionUnavailable?['message'=>'ارتباط زنده با لوکال برقرار نیست؛ داده‌های سالن ممکن است قدیمی باشند. برای عملیات و وضعیت جاری، اتصال باید دوباره برقرار شود.']:null);$inventoryError=!$canInventory?['message'=>'وضعیت اقلام انبار فعلاً در دسترس نیست.']:($inventoryProjectionUnavailable?['message'=>'اطلاعات موجودی هنوز با لوکال همگام نشده؛ کمبود یا نبود کمبود قابل تأیید نیست.']:null);$notificationsError=$notifications!==[]?null:['message'=>'فعالیت‌ها و اعلان‌های اخیر فعلاً در دسترس نیست.'];
        $fa=static fn(string|int|float $v):string=>strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹',','=>'٬']);$money=static fn(int $v):string=>$fa(number_format($v)).' تومان';$delta=static fn(int $current,int $reference):?int=>$reference>0?(int)round((($current-$reference)/$reference)*100):null;$relative=static function(string $value)use($tz,$fa):string{if(trim($value)==='')return '—';try{$dt=(new \DateTimeImmutable($value,new \DateTimeZone('Asia/Tehran')))->setTimezone($tz);$now=new \DateTimeImmutable('now',$tz);$sec=max(0,$now->getTimestamp()-$dt->getTimestamp());if($sec<60)return 'همین حالا';if($sec<3600)return $fa(max(1,(int)floor($sec/60))).' دقیقه پیش';if($sec<86400)return $fa((int)floor($sec/3600)).' ساعت پیش';return $fa((int)floor($sec/86400)).' روز پیش';}catch(\Throwable){return '—';}};
        $lastFinanceSyncAt=(string)($reportsModel['last_sync_at']??'');$financeAsOf=$todayProjectionStale?('آخرین گزارش دریافت‌شده'.($lastFinanceSyncAt!==''?' · '.$relative($lastFinanceSyncAt):'')):null;$metrics=[];$todayHas=$todayHasReceipt;$yesterdayHas=$yesterdayHasReceipt;if($canReports){$metrics[]=['icon'=>'chart','tone'=>'success','label'=>'فروش خالص امروز','value'=>($reportsError||$todayProjectionUnavailable)?'—':$money((int)$todaySummary['net_sales']),'delta'=>($reportsError||$todayProjectionUnavailable||!$todayHas||!$yesterdayHas)?null:$delta((int)$todaySummary['net_sales'],(int)$yesterdaySummary['net_sales']),'helper'=>$todayProjectionUnavailable?'اطلاعات روز کاری جاری تأیید نشده':(!$todayHas?'تسویهٔ قطعی برای این روز ثبت نشده':(!$yesterdayHas?'دیروز تسویه‌ای ثبت نشده':'نسبت به دیروز')),'href'=>'/reports/?from='.$todayKey.'&to='.$todayKey.'&focus=sales'];$metrics[]=['icon'=>'cart','tone'=>'info','label'=>'سفارش‌های امروز','value'=>($reportsError||$todayProjectionUnavailable||!$financeView['today_orders_available'])?'—':$fa((int)$todaySummary['order_count']),'delta'=>null,'helper'=>$todayProjectionUnavailable?'اطلاعات روز کاری جاری تأیید نشده':'سفارش‌های مشتری؛ بدون مصرف پرسنل','href'=>'/reports/?from='.$todayKey.'&to='.$todayKey.'&focus=orders'];}if($canOps)$metrics[]=['icon'=>'table','tone'=>'neutral','label'=>'میزهای فعال','value'=>$opsProjectionUnavailable?'—':$fa((int)($ops['metrics']['open_tables']??0)),'delta'=>null,'helper'=>$opsProjectionUnavailable?'منتظر همگام‌سازی وضعیت سالن':'نشست باز در سالن','href'=>'/operator/?view=open'];if($canReports)$metrics[]=['icon'=>'ticket','tone'=>'warning','label'=>'میانگین فاکتور','value'=>!$reportsError&&!$todayProjectionUnavailable&&$todayHas&&$financeView['today_avg_available']?$money((int)$todaySummary['avg_ticket']):'—','delta'=>($reportsError||$todayProjectionUnavailable||!$todayHas||!$yesterdayHas||!$financeView['today_avg_available']||!$financeView['yesterday_avg_available'])?null:$delta((int)$todaySummary['avg_ticket'],(int)$yesterdaySummary['avg_ticket']),'helper'=>$todayProjectionUnavailable?'منتظر همگام‌سازی اطلاعات امروز':(!$todayHas?'هنوز تسویهٔ کامل ثبت نشده':(!$yesterdayHas?'میانگین '. $fa((int)($todaySummary['completed_settlements']??0)) . ' تسویهٔ موفق':'میانگین '. $fa((int)($todaySummary['completed_settlements']??0)) . ' تسویهٔ موفق · نسبت به دیروز')),'href'=>'/reports/?from='.$todayKey.'&to='.$todayKey.'&focus=avg_ticket'];
        $attention=[];if($financeAsOf!==null&&!$todayProjectionUnavailable){foreach($metrics as &$metric){if(in_array($metric['label'],['فروش خالص امروز','سفارش‌های امروز','میانگین فاکتور'],true)&&$metric['value']!=='—'){$metric['helper']=$financeAsOf;$metric['delta']=null;}}unset($metric);}$pending=$opsProjectionUnavailable?[]:(array)($ops['attention_orders']??[]);if($pending){$oldest=$pending[0]??[];$attention[]=['tone'=>'danger','context'=>'سفارش','status'=>'فوری','title'=>$fa(count($pending)).' سفارش منتظر رسیدگی','subtitle'=>$oldest?('قدیمی‌ترین · '.$relative((string)($oldest['created_at']??''))):'در صف بررسی','href'=>'/operator/?work=attention&attention_filter=orders#attention'];}$calls=$opsProjectionUnavailable?[]:(array)($ops['waiter_calls']??[]);if($calls){$oldest=$calls[0]??[];$attention[]=['tone'=>'warning','context'=>'سالن','status'=>'بررسی','title'=>$fa(count($calls)).' فراخوان مهمان باز','subtitle'=>$oldest?($fa((string)($oldest['table_name']??'')).' · '.$relative((string)($oldest['created_at']??''))):'نیازمند بررسی','href'=>'/operator/?work=attention&attention_filter=calls#attention'];}$used=[];if(count($attention)<4){foreach($notificationItems as $item){if(!empty($item['read_at']))continue;$id=(int)($item['id']??0);if($id>0)$used[$id]=true;$attention[]=['tone'=>'info','context'=>'اعلان','status'=>'جدید','title'=>(string)($item['title']??'اعلان جدید'),'subtitle'=>(string)($item['body']??''),'href'=>(string)($item['target_url']??'/notifications/')];if(count($attention)>=4)break;}}// R313: Notifications are not a substitute for operational activity.
        // When the Local model has not yet projected this stream, show unavailable,
        // not an empty/false 'no activity today' result or old bell items.
        $recentResult=PublicDashboardOperationalActivity::forCurrentDay(
            (array)($reports['dashboard_activity']??[]),$todayKey,
            (string)($reports['business_clock']['cutoff']??''),
            $businessDateUnavailable||$todayProjectionStale);
        $recent=$recentResult['items'];$recentError=$recentResult['error'];
        $lowStock=$inventoryProjectionUnavailable?[]:array_values(array_filter($inventoryItems,static fn(array $r):bool=>(int)($r['active']??1)===1&&(int)($r['warning_threshold']??0)>0&&(int)($r['quantity_base']??0)<=(int)($r['warning_threshold']??0)));usort($lowStock,static fn(array $a,array $b):int=>(((int)$a['quantity_base']-(int)$a['warning_threshold'])<=>((int)$b['quantity_base']-(int)$b['warning_threshold']))?:strcmp((string)$a['name'],(string)$b['name']));$lowStock=array_slice($lowStock,0,5);$attentionErrors=array_values(array_filter([$opsError])); /* Notification failure already appears in the recent-activity card. */$maxTrend=max(1,...array_map(static fn(array $r):int=>(int)$r['value'],$weekDaily?:[['value'=>0]]));$bottomPanels=($canReports?1:0)+($canInventory?1:0);
        $dashE=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$dashUrl=function(string $path):string{if(in_array($path,['/preparation','/waiter','/staff/preparation'],true))$path='/staff/preparation/';$map=['/preparation/'=>'/staff/preparation/','/waiter/'=>'/staff/preparation/','/operator/'=>'/staff/operator/','/reports/'=>'/staff/reports/','/operations/'=>'/staff/operations/','/notifications/'=>'/staff/notifications/'];foreach($map as $from=>$to)if(str_starts_with($path,$from)){$path=$to.substr($path,strlen($from));break;}return htmlspecialchars($this->u($path),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};$dashIcon=static fn(string $name):string=>'<svg class="sc-ui-icon" aria-hidden="true" focusable="false"><use href="'.htmlspecialchars(ProductShell::asset('/assets/ui-sprite.svg'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'#icon-'.htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"></use></svg>';$dashFaNum=$fa;$dashMoney=$money;$dashClock=static function(string $value)use($tz,$fa):string{if(trim($value)==='')return '—';try{return $fa((new \DateTimeImmutable($value,new \DateTimeZone('Asia/Tehran')))->setTimezone($tz)->format('H:i'));}catch(\Throwable){return '—';}};$dashNotificationIcon=static function(array $item):string{$u=strtolower((string)($item['target_url']??''));if(str_contains($u,'operator')||str_contains($u,'order'))return 'cart';if(str_contains($u,'operation')||str_contains($u,'inventory'))return 'archive';if(str_contains($u,'print')||str_contains($u,'integration'))return 'print';if(str_contains($u,'system'))return 'settings';return 'bell';};$dashUnit=static function(int $quantity,string $unit)use($fa):string{if($unit==='g'&&abs($quantity)>=1000)return $fa(rtrim(rtrim(number_format($quantity/1000,2,'.',''),'0'),'.')).' کیلوگرم';if($unit==='ml'&&abs($quantity)>=1000)return $fa(rtrim(rtrim(number_format($quantity/1000,2,'.',''),'0'),'.')).' لیتر';$labels=['g'=>'گرم','ml'=>'میلی‌لیتر','count'=>'عدد'];return $fa($quantity).' '.($labels[$unit]??$unit);};$dashDegraded=static function(?array $error,string $fallback)use($dashE):string{if($error===null)return '';$message=trim((string)($error['message']??''))?:$fallback;return '<div class="sc-empty sc-dashboard-degraded"><strong>'.$dashE($message).'</strong></div>';};
        // General connectivity/freshness is already available from the status dot
        // in ProductShell on mobile AND desktop. Keep action-specific warnings below.
        if($publicSnapshot!==null)require dirname(__DIR__,2).'/resources/public-dashboard-stale-snapshot.php';
        require dirname(__DIR__,2).'/resources/shared-staff-ui/dashboard-workspace.php';
    }

    /** The same existing Hall controls are available on Public and its LAN bootstrap.
     * Local remains the authority for every mutation and rechecks current permissions.
     */
    private function hallFeatures(bool $canOrder,bool $canCashier): array
    {
        return ['table_move'=>$canOrder,'committed_adjust'=>$canOrder,'reprint'=>$canOrder||$canCashier,'discount'=>$canCashier,
            'settlement'=>$canCashier,'settlement_itemized'=>$canCashier,'settlement_subscriber'=>$canCashier,'settlement_accommodation'=>false,'waiter_response'=>$canOrder];
    }

    private function operator(array $session): void
    {
        $canOrder=$this->has($session,'orders_floor');$canCashier=$this->has($session,'cashier_accounts');
        $hallConfig=[
            'csrf'=>'','mode'=>'public','auto_load'=>true,'can_order'=>$canOrder,'can_cashier'=>$canCashier,'can_supervise'=>$this->has($session,'shift_supervision'),
            'api'=>$this->u('/staff/hall-adapter'),'order_api'=>$this->u('/staff/order-adapter'),'finance_api'=>$this->u('/staff/finance-adapter'),'subscriber_api'=>$this->u('/staff/subscriber-adapter'),'printing_url'=>'',
            'features'=>$this->hallFeatures($canOrder,$canCashier),
        ];
        $hallAsset=static fn(string $path):string=>ProductShell::asset($path);
        require dirname(__DIR__,2).'/resources/shared-staff-ui/hall-workspace.php';
        echo '<script src="'.ProductShell::asset('assets/order-line-ui.js').'" defer></script>';
        echo '<script src="'.ProductShell::asset('assets/staff-app/order-draft-workspace.js').'" defer></script>';
        echo '<script src="'.ProductShell::asset('assets/hall-settlement-v32.js').'" defer></script>';
        echo '<script src="'.ProductShell::asset('assets/staff-app/hall-workspace.js').'" defer></script>';
    }

    private function preparation(): void
    {
        $preparationConfig=['mode'=>'public','auto_load'=>true];
        require dirname(__DIR__,2).'/resources/shared-staff-ui/preparation-workspace.php';
    }

    private function operations(array $session): void
    {
        // Read models stay on the workspace adapter; approved supply intents use
        // the existing durable Deferred lane and canonical Local owners.
        $isAdmin=false;$canOps=false;$canManage=false;$canFinalize=false;$canBuy=$this->has($session,'supply.manage.defer');$canExpenses=$this->has($session,'expense.create.defer');$canCost=$this->has($session,'inventory.cost.read');$csrf='';$workspaceReadOnly=!$canBuy&&!$this->has($session,'supply.need.defer')&&!$canExpenses;
        $workspaceApi=$this->u('/staff/operations-adapter');$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);
        require dirname(__DIR__,2).'/resources/shared-staff-ui/operations-workspace.php';
    }

    private function subscribers(array $session): void
    {
        $isAdmin=false;$canPayment=$this->has($session,'subscriber.payment.defer');$csrf='';
        $workspaceApi=$this->u('/staff/subscribers-adapter');$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);
        require dirname(__DIR__,2).'/resources/shared-staff-ui/subscribers-workspace.php';
    }

    private function reports(array $session): void
    {
        $payload=[];$model=$this->core->remoteReadModels()->read($session,'reports');if((int)($model['status']??0)===200&&is_array($model['body']['payload']??null))$payload=$model['body']['payload'];
        $range=is_array($payload['range']??null)?$payload['range']:[];$today=PublicDashboardFinance::businessDate($payload)??(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tehran')))->format('Y-m-d');$to=(string)($range['to']??$today);$from=(string)($range['from']??$to);$focus='';$canAudit=false;$canInvoiceHistory=$this->has($session,'cashier_accounts');$reportRangeEnabled=true; // A missing archive affects data availability, never form usability.
        $workspaceApi=$this->u('/staff/reports-adapter');$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);$workspaceExportCurrent='';$workspaceExportAnalytical='';
        require dirname(__DIR__,2).'/resources/shared-staff-ui/reports-workspace.php';
        if($canInvoiceHistory)echo '<script src="'.ProductShell::asset('assets/staff-app/public-invoice-history.js').'" defer></script>';
    }

    private function notifications(): void
    {
        $isAdmin=false;$canMarkRead=false;$csrf='';$notificationSettingsUrl='';$notificationAdminUrl='';
        $workspaceApi=$this->u('/staff/notifications-adapter');$workspaceAsset=static fn(string $path):string=>ProductShell::asset($path);
        // Public-specific layout; shared Local markup, data panel and interactions remain intact.
        echo '<style data-public-notifications-ux>body[data-public-staff="1"] .sc-notifications-inbox{max-inline-size:900px;margin-inline:auto;min-block-size:0}'
          .'body[data-public-staff="1"] .sc-notifications-list>.sc-list-item{min-block-size:0;padding:11px 17px!important;display:block}'
          .'body[data-public-staff="1"] .sc-notifications-list>.sc-list-item>div{max-inline-size:100%;display:grid;gap:2px}'
          .'body[data-public-staff="1"] .sc-notifications-list strong{font-size:.82rem;line-height:1.6}'
          .'body[data-public-staff="1"] .sc-notifications-list p{font-size:.74rem;line-height:1.65;margin:0!important}'
          .'body[data-public-staff="1"] .sc-notifications-list small{font-size:.67rem;margin:0}'
          .'body[data-public-staff="1"] .sc-notifications-list>.sc-list-item:hover{background:var(--sc-page-hover,rgba(0,105,95,.04))!important}'
          .'@media(max-width:640px){body[data-public-staff="1"] .sc-notifications-list>.sc-list-item{padding:12px!important}}'
          .'</style>';
        require dirname(__DIR__,2).'/resources/shared-staff-ui/notifications-workspace.php';
    }

    private function health(array $session): void
    {
        $api=$this->e($this->u('/api/staff/connectivity'));$probe=$this->e($this->u('/api/staff/health/probe'));$system=$this->e($this->u('/api/staff/health/system'));
        echo '<section class="sc-workspace sc-system-workspace" data-public-health data-health-api="'.$api.'" data-health-probe="'.$probe.'" data-health-system-api="'.$system.'">';
        echo '<div class="sc-toolbar sc-local-nav" aria-label="ابزارهای پشتیبانی"><div class="sc-toolbar__main"><div class="sc-tabs" role="tablist" aria-label="پشتیبانی و نگهداری"><button class="sc-tab is-active" type="button" role="tab" aria-selected="true" data-health-tab="health">وضعیت سامانه</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-health-tab="relay">درخواست‌ها</button><button class="sc-tab" type="button" role="tab" aria-selected="false" data-health-tab="technical">اطلاعات پشتیبانی</button></div></div><div class="sc-toolbar__actions"><button class="sc-button sc-button--secondary" type="button" data-health-refresh>تازه‌سازی</button><button class="sc-button sc-button--secondary" type="button" data-health-probe-button hidden>آزمایش ارتباط</button><button class="sc-button sc-button--secondary" type="button" data-health-download hidden>دریافت گزارش فنی</button></div></div>';
        echo '<div class="sc-system-health-stack" data-health-panel="health"><div class="sc-public-health__operator-status" role="status" aria-live="polite" data-health-summary>در حال بررسی ارتباط…</div><div class="sc-public-health__metrics sc-public-health__metrics--simple">';
        foreach([['heartbeat','اتصال به سامانهٔ کافه'],['queue','درخواست‌های در انتظار'],['roundtrip','آخرین ارتباط موفق']] as [$key,$label])echo '<article class="sc-public-health__metric"><small>'.$this->e($label).'</small><strong data-health-metric="'.$this->e($key).'">—</strong><small data-health-helper="'.$this->e($key).'">—</small></article>';
        echo '</div></div>';
        echo '<div class="sc-system-health-stack" data-health-panel="relay" hidden><section class="sc-data-panel sc-system-health-panel"><div class="sc-data-panel__header"><div><h2>درخواست‌های در راهِ سامانه کافه</h2><p>درخواست‌های در انتظار و در حال رسیدگی را از همین‌جا ببین.</p></div></div><div class="sc-data-panel__body sc-public-health__table" data-health-queue-kinds></div></section><section class="sc-data-panel sc-system-health-panel"><div class="sc-data-panel__header"><div><h2>درخواست‌های منتظر</h2><p>درخواست‌هایی که هنوز پاسخ نهایی نگرفته‌اند.</p></div></div><div class="sc-data-panel__body sc-public-health__table" data-health-pending></div></section><section class="sc-data-panel sc-system-health-panel"><div class="sc-data-panel__header"><div><h2>نتیجهٔ درخواست‌های اخیر</h2><p>وضعیت درخواست‌هایی که بررسی آن‌ها پایان یافته است.</p></div></div><div class="sc-data-panel__body sc-public-health__table" data-health-events></div></section><section class="sc-data-panel sc-system-health-panel"><div class="sc-data-panel__header"><div><h2>آخرین درخواست ناموفق</h2><p>فقط آخرین مشکلِ نیازمند رسیدگی نمایش داده می‌شود.</p></div></div><div class="sc-data-panel__body" data-health-failure></div></section></div>';
        echo '<div class="sc-system-health-stack" data-health-panel="technical" hidden><section class="sc-data-panel sc-system-health-panel"><div class="sc-data-panel__header"><div><h2>اطلاعات مخصوص پشتیبانی</h2><p>این بخش در استفادهٔ روزمره لازم نیست. هنگام گزارش مشکل، فایل فنی را دریافت کنید.</p></div></div><div class="sc-data-panel__body"><div class="sc-public-health__metrics"><article class="sc-public-health__metric"><small>نسخهٔ Public</small><strong data-system-metric="version">—</strong><small data-system-helper="version">—</small></article><article class="sc-public-health__metric"><small>دیتابیس</small><strong data-system-metric="database">—</strong><small data-system-helper="database">—</small></article><article class="sc-public-health__metric"><small>هویت فایل‌ها</small><strong data-system-metric="tree">—</strong><small data-system-helper="tree">—</small></article><article class="sc-public-health__metric"><small>وضعیت به‌روزرسانی</small><strong data-system-metric="lifecycle">—</strong><small data-system-helper="lifecycle">—</small></article><article class="sc-public-health__metric"><small>سرویس ویندوز</small><strong data-health-metric="runtime">—</strong><small data-health-helper="runtime">—</small></article></div></div></section></div>';
        echo '</section><script src="'.ProductShell::asset('assets/staff-app/public-health.js').'" defer></script>';
    }

    private function allowed(array $session,string $page): bool
    {
        return match($page){
            'home','notifications','account'=>true,
            'health'=>(string)($session['role']??'')==='admin',
            'operator'=>$this->hasAny($session,['orders_floor','cashier_accounts','shift_supervision']),
            'preparation'=>$this->hasAny($session,['preparation','shift_supervision']),
            'operations'=>$this->hasAny($session,['inventory_view','inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']),
            'subscribers'=>$this->has($session,'cashier_accounts'),
            'reports'=>$this->hasAny($session,['cashier_accounts','shift_supervision','staff_consumption_reports']),
            default=>false,
        };
    }
    private function has(array $session,string $cap): bool{$caps=array_map('strval',(array)($session['capabilities']??[]));return in_array('*',$caps,true)||in_array($cap,$caps,true);}
    private function hasAny(array $session,array $caps): bool{foreach($caps as $cap)if($this->has($session,$cap))return true;return false;}
    private function meta(string $page): ?array{return match($page){
        'account'=>['title'=>'حساب کاربری','active'=>'account','subtitle'=>'اطلاعات و امنیت حساب شخصی.'],
        'home'=>['title'=>'داشبورد','active'=>'home','subtitle'=>'مرور وضعیت امروز و کارهای در انتظار.'],
        'operator'=>['title'=>'سالن و میزها','active'=>'operator','subtitle'=>'وضعیت میزها، حساب جاری و موارد نیازمند اقدام را در یک فضای عملیاتی ببین.'],
        'preparation'=>['title'=>'آماده‌سازی','active'=>'preparation','subtitle'=>'سفارش‌های تأییدشده را ببین، دریافت کن و مراحل آماده‌سازی را سریع ثبت کن.'],
        'operations'=>['title'=>'انبار، تأمین و هزینه‌ها','active'=>'operations','subtitle'=>'وضعیت موجودی و نیازهای خرید کافه.'],
        'subscribers'=>['title'=>'مشتریان','active'=>'subscribers','subtitle'=>'حساب‌ها و مانده‌های مشتریان.'],
        'reports'=>['title'=>'گزارش و تحلیل','active'=>'reports','subtitle'=>'فروش و عملکرد مجموعه در یک نگاه.'],
        'notifications'=>['title'=>'اعلان‌ها','active'=>'notifications','subtitle'=>'اعلان‌های مهم حساب کاربری.'],
        'health'=>['title'=>'پشتیبانی و نگهداری','active'=>'system','subtitle'=>'وضعیت سامانه و ابزارهای بررسی مشکل.'],default=>null};}
    private function pageResponse(string $html): array{return ['status'=>200,'headers'=>['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store'],'body'=>$html];}
    private function base(): string{$b='/' . trim(str_replace('\\','/',$this->basePath),'/');return $b==='/'?'':rtrim($b,'/');}
    private function u(string $path): string{return $this->base().'/'.ltrim($path,'/');}
    private function e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
