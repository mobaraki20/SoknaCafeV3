<?php
declare(strict_types=1);
use Sokna\Local\UI\SCDS;
use Sokna\Local\UI\LocalUrl;
use Sokna\Local\UI\AssetUrl;
$core=require __DIR__.'/_app.php';
$error='';
if($core->auth()->currentUser()!==null){header('Location: '.LocalUrl::path('/'));exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $ok=$core->auth()->login(trim((string)($_POST['username']??'')),(string)($_POST['password']??''));
        if($ok){header('Location: '.LocalUrl::path('/'));exit;}
        $error='نام کاربری یا رمز عبور درست نیست.';
    }catch(Throwable){$error='ورود در حال حاضر انجام نشد. دوباره تلاش کنید.';}
}
function login_icon(string $name): string {
    return '<svg class="sc-ui-icon" aria-hidden="true" focusable="false"><use href="'.htmlspecialchars(AssetUrl::asset('/assets/ui-sprite.svg'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'#icon-'.htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"></use></svg>';
}
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><base href="<?=htmlspecialchars(LocalUrl::baseHref(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><script>(function(){try{var p=localStorage.getItem("sokna.theme")||"system";var d=p==="dark"||(p==="system"&&matchMedia("(prefers-color-scheme: dark)").matches);document.documentElement.dataset.theme=d?"dark":"light"}catch(e){}})();</script><title>ورود | سکنا</title><meta name="theme-color" content="#0f6b66"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="application-name" content="سکنا"><link rel="manifest" href="<?=htmlspecialchars(AssetUrl::asset('/manifest.webmanifest'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="icon" href="<?=htmlspecialchars(AssetUrl::asset('/assets/favicon.ico'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" sizes="any"><link rel="icon" type="image/png" sizes="16x16" href="<?=htmlspecialchars(AssetUrl::asset('/assets/favicon-16.png'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="icon" type="image/png" sizes="32x32" href="<?=htmlspecialchars(AssetUrl::asset('/assets/favicon-32.png'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="icon" type="image/png" sizes="48x48" href="<?=htmlspecialchars(AssetUrl::asset('/assets/favicon-48.png'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="apple-touch-icon" sizes="180x180" href="<?=htmlspecialchars(AssetUrl::asset('/assets/favicon-180.png'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="stylesheet" href="<?=htmlspecialchars(AssetUrl::scds('tokens.css'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="stylesheet" href="<?=htmlspecialchars(AssetUrl::scds('components.css'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><link rel="stylesheet" href="<?=htmlspecialchars(AssetUrl::asset('/assets/product-ui.css'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></head><body data-page="login">
<main class="sc-auth"><button class="sc-icon-button sc-shell__theme sc-auth__theme" type="button" data-theme-toggle aria-label="فعال کردن حالت تیره" aria-pressed="false"><svg class="sc-theme-icon sc-theme-icon--moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.2A8.5 8.5 0 0 1 8.8 4a8.6 8.6 0 1 0 11.2 11.2Z"/></svg><svg class="sc-theme-icon sc-theme-icon--sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/></svg></button>
  <div class="sc-auth__shell">
    <section class="sc-auth__story" aria-label="معرفی سامانه">
      <div class="sc-auth__story-brand"><span class="sc-auth__story-mark" aria-hidden="true"><img src="<?=htmlspecialchars(AssetUrl::asset('/assets/brand/Sokna-AppIcon-256.png'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" alt="" width="256" height="256" decoding="async"></span><span><strong>سکنا</strong><small>فضای کاری تیم کافه</small></span></div>
      <div class="sc-auth__story-copy"><span>یک مسیر، از سفارش تا تحویل</span><h1>کار روزانه، روشن و یک‌جا.</h1><p>سفارش، آماده‌سازی، مالی، انبار و مدیریت مجموعه در یک فضای محلی و هماهنگ.</p></div>
      <div class="sc-auth__story-points"><span><?=login_icon('operations')?>عملیات</span><span><?=login_icon('ticket')?>مالی</span><span><?=login_icon('archive')?>انبار</span></div>
    </section>
    <section class="sc-auth__card">
      <div class="sc-auth__brand"><span>ورود اعضای تیم</span><h2>خوش آمدید</h2><p>برای ورود به سامانه محلی، اطلاعات حساب خود را وارد کنید.</p></div>
      <?php if($error!==''):?><?=SCDS::alert($error,'danger')?><?php endif;?>
      <form class="sc-form" method="post" autocomplete="on"><?=SCDS::field('username','نام کاربری','',['autocomplete'=>'username','required'=>true])?><?=SCDS::field('password','رمز عبور','',['type'=>'password','autocomplete'=>'current-password','required'=>true])?><button class="sc-button" type="submit"><?=login_icon('check')?>ورود به سامانه</button></form>
      <small class="sc-auth__footnote">سامانه داخلی کافه سکنا</small>
    </section>
  </div>
</main><script src="<?=htmlspecialchars(AssetUrl::asset('/assets/product-theme.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script><script src="<?=htmlspecialchars(AssetUrl::scds('scds.js'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>" defer></script></body></html>
