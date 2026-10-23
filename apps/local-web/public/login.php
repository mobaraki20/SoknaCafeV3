<?php
declare(strict_types=1);
use Sokna\Local\UI\SCDS;
use Sokna\Local\UI\LocalUrl;
use Sokna\Local\UI\AssetUrl;
$core=require __DIR__.'/_app.php';
$error='';$errorCode='';
if($core->auth()->currentUser()!==null){header('Location: '.LocalUrl::path('/'));exit;}
$activeUsers=$core->auth()->activeLoginUsers();$loginUiPreferences=[];try{$loginUiPreferences=$core->uiPreferences()->forUsers(array_map(static fn(array $u):int=>(int)($u['id']??0),$activeUsers));}catch(Throwable){}
$brandName='سکنا';$brandVersion='fallback';try{$q=$core->database()->prepare("SELECT setting_value FROM settings WHERE setting_key='cafe.name' LIMIT 1");$q->execute();$v=trim((string)($q->fetchColumn()?:''));if($v!=='')$brandName=$v;$brandVersion=(string)($core->guestContent()->brandIdentity('draft')['version']??'fallback');}catch(Throwable){}
$brandAsset=static fn(string $variant):string=>LocalUrl::path('/brand-asset.php').'?variant='.rawurlencode($variant).'&iv='.rawurlencode($brandVersion);
$brandManifest=LocalUrl::path('/manifest.php').'?iv='.rawurlencode($brandVersion);
$restored=((string)($_GET['restored']??''))==='1';
$selectedUsername=trim((string)($_POST['username']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $attempt=$core->auth()->loginAttempt($selectedUsername,(string)($_POST['password']??''));
        if($attempt['ok']){header('Location: '.LocalUrl::path('/'));exit;}
        if($attempt['code']==='locked'){
            $seconds=max(1,(int)$attempt['retry_after']);$minutes=(int)ceil($seconds/60);
            $error=$minutes<=1?'این حساب موقتاً قفل شده است. حدود یک دقیقه دیگر دوباره تلاش کنید.':'این حساب موقتاً قفل شده است. '.strtr((string)$minutes,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']).' دقیقه دیگر دوباره تلاش کنید.';
            $errorCode='locked';
        }else{$error='رمز عبور درست نیست. دوباره تلاش کنید.';$errorCode='invalid';}
    }catch(Throwable){$error='ورود در حال حاضر انجام نشد. دوباره تلاش کنید.';$errorCode='system';}
}
function login_icon(string $name): string {
    return '<svg class="sc-ui-icon" aria-hidden="true" focusable="false"><use href="'.htmlspecialchars(AssetUrl::asset('/assets/ui-sprite.svg'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'#icon-'.htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"></use></svg>';
}
function login_role(string $role): string{return match($role){'admin'=>'مدیر','operator'=>'اپراتور','waiter'=>'همکار سالن',default=>'عضو تیم'};}
function login_initial(string $value): string{$value=trim($value);if($value==='')return 'س';return function_exists('mb_substr')?mb_substr($value,0,1,'UTF-8'):substr($value,0,1);}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><base href="<?=htmlspecialchars(LocalUrl::baseHref(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><script>(function(){try{var p=localStorage.getItem("sokna.theme")||"system";var d=p==="dark"||(p==="system"&&matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.dataset.theme=d?"dark":"light"}catch(e){}})();</script><title>ورود | <?=htmlspecialchars($brandName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></title><meta name="theme-color" content="#0f6b66"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="application-name" content="<?=htmlspecialchars($brandName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="manifest" href="<?=htmlspecialchars($brandManifest,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="icon" type="image/png" sizes="32x32" href="<?=htmlspecialchars($brandAsset('icon-32'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="apple-touch-icon" sizes="180x180" href="<?=htmlspecialchars($brandAsset('icon-180'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="stylesheet" href="<?=htmlspecialchars(AssetUrl::scds('tokens.css'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="stylesheet" href="<?=htmlspecialchars(AssetUrl::scds('components.css'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="stylesheet" href="<?=htmlspecialchars(AssetUrl::asset('/assets/product-ui.css'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></head><body data-page="login">
<main class="sc-auth"><button class="sc-icon-button sc-shell__theme sc-auth__theme" type="button" data-theme-toggle aria-label="فعال کردن حالت تیره" aria-pressed="false"><svg class="sc-theme-icon sc-theme-icon--moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.2A8.5 8.5 0 0 1 8.8 4a8.6 8.6 0 1 0 11.2 11.2Z"/></svg><svg class="sc-theme-icon sc-theme-icon--sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/></svg></button>
  <div class="sc-auth__shell">
    <section class="sc-auth__story" aria-label="معرفی سامانه">
      <div class="sc-auth__story-brand"><span class="sc-auth__story-mark" aria-hidden="true"><img src="<?=htmlspecialchars($brandAsset('logo-256'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" alt="" width="256" height="256" decoding="async"></span><span><strong><?=htmlspecialchars($brandName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></strong><small>فضای کاری تیم کافه</small></span></div>
      <div class="sc-auth__story-copy"><span>یک مسیر، از سفارش تا تحویل</span><h1>هرکس با حساب خودش، سریع وارد کار شود.</h1><p>حساب خودت را انتخاب کن، رمز را وارد کن و مستقیم وارد فضای کاری روزانه شو.</p></div>
      <div class="sc-auth__story-points"><span><?=login_icon('operations')?>عملیات</span><span><?=login_icon('ticket')?>مالی</span><span><?=login_icon('archive')?>انبار</span></div>
    </section>
    <section class="sc-auth__card" data-login-root data-login-storage-key="sokna.login.last-account" data-selected-explicit="<?= $selectedUsername!==''?'1':'0' ?>" data-error-code="<?=htmlspecialchars($errorCode,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">
      <div class="sc-auth__brand"><span>ورود اعضای تیم</span><h2>حساب خودت را انتخاب کن</h2><p>انتخاب آخر روی همین دستگاه یادآوری می‌شود؛ رمز ذخیره نمی‌شود.</p></div>
      <?php if($restored):?><div class="sc-alert sc-alert--success" role="status"><strong>بازیابی اطلاعات کامل شد.</strong><br><span>برای ادامه، دوباره وارد حساب خودت شو.</span></div><?php endif;?>
      <?php if($error!==''):?><?=SCDS::alert($error,'danger')?><?php endif;?>
      <form class="sc-form sc-login-form" method="post" autocomplete="on" data-login-form>
        <?php if($activeUsers):?>
        <fieldset class="sc-login-accounts" data-login-accounts><legend>حساب</legend>
          <div class="sc-login-account-grid" role="radiogroup" aria-label="انتخاب حساب کاربری">
          <?php foreach($activeUsers as $i=>$account): $username=(string)$account['username'];$display=trim((string)$account['display_name'])?:$username;$checked=$selectedUsername!==''?$selectedUsername===$username:$i===0;$accountTheme=(string)($loginUiPreferences[(int)($account['id']??0)]['theme']??''); ?>
            <label class="sc-login-account<?= $checked?' is-selected':'' ?>" data-login-account data-account-key="<?=htmlspecialchars($username,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" data-username="<?=htmlspecialchars($username,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"<?= $accountTheme!==''?' data-theme-preference="'.htmlspecialchars($accountTheme,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"':'' ?>><input type="radio" name="account_picker" value="<?=htmlspecialchars($username,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" aria-label="انتخاب حساب کاربری" <?= $checked?'checked':'' ?>><span class="sc-login-account__avatar" aria-hidden="true"><?=htmlspecialchars(login_initial($display),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></span><span class="sc-login-account__copy"><strong><?=htmlspecialchars($display,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></strong><small><?=htmlspecialchars(login_role((string)$account['role']),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></small></span><span class="sc-login-account__check"><?=login_icon('check')?></span></label>
          <?php endforeach; ?>
          </div>
        </fieldset>
        <input type="hidden" name="username" value="<?=htmlspecialchars($selectedUsername!==''?$selectedUsername:(string)$activeUsers[0]['username'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" data-login-selection>
        <?php else: ?>
        <?=SCDS::field('username','نام کاربری',$selectedUsername,['autocomplete'=>'username','required'=>true])?>
        <?php endif; ?>
        <?=SCDS::field('password','رمز عبور','',['type'=>'password','autocomplete'=>'current-password','required'=>true,'autofocus'=>true])?>
        <button class="sc-button" type="submit"><?=login_icon('check')?>ورود به سامانه</button>
      </form>
      <small class="sc-auth__footnote">سامانه داخلی <?=htmlspecialchars($brandName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></small>
    </section>
  </div>
</main><script src="<?=htmlspecialchars(AssetUrl::asset('/assets/product-theme.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script><script src="<?=htmlspecialchars(AssetUrl::asset('/assets/login.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script><script src="<?=htmlspecialchars(AssetUrl::scds('scds.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script></body></html>
