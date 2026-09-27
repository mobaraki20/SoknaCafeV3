<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Remote;

final class RemoteStaffPageRenderer
{
    private const MODELS=[
        'operations'=>['cap'=>'operations.read','label'=>'عملیات'],
        'preparation'=>['cap'=>'preparation.read','label'=>'آماده‌سازی'],
        'inventory'=>['cap'=>'inventory.read','label'=>'انبار'],
        'inventory_cost'=>['cap'=>'inventory.cost.read','label'=>'بهای انبار'],
        'reports'=>['cap'=>'reports.read','label'=>'گزارش'],
        'deferred_context'=>['cap'=>'deferred.context','label'=>'کار آفلاین'],
    ];
    public function login(string $installationId,string $error=''): array
    {
        $e=$error!==''?'<div class="rs-alert" role="alert">'.$this->e($error).'</div>':'';
        $html='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>ورود همکار | سکنا</title><link rel="stylesheet" href="/assets/scds/remote-staff.css"></head><body class="rs-body"><main class="rs-login"><section class="rs-card"><h1>ورود همکار</h1><p>این بخش فقط برای حساب‌هایی است که دسترسی راه‌دورشان در Local فعال شده.</p>'.$e.'<form method="post" action="/staff/login" class="rs-stack"><input type="hidden" name="installation_id" value="'.$this->e($installationId).'"><label>نام کاربری<input name="username" autocomplete="username" required></label><label>رمز عبور<input name="password" type="password" autocomplete="current-password" required></label><button type="submit">ورود</button></form></section></main></body></html>';
        return ['status'=>200,'headers'=>['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store'],'body'=>$html];
    }
    public function dashboard(array $session): array
    {
        $caps=array_map('strval',(array)($session['capabilities']??[]));$all=in_array('*',$caps,true);$buttons='';
        foreach(self::MODELS as $key=>$meta){if(!$all&&!in_array($meta['cap'],$caps,true)&&!($key==='preparation'&&in_array('preparation.monitor',$caps,true)))continue;$buttons.='<button type="button" data-model="'.$this->e($key).'">'.$this->e($meta['label']).'</button>';}
        $name=trim((string)($session['display_name']??''));
        $html='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>دسترسی راه‌دور | سکنا</title><link rel="stylesheet" href="/assets/scds/remote-staff.css"><script src="/assets/scds/remote-staff.js" defer></script></head><body class="rs-body"><header class="rs-header"><div><strong>سکنا · راه‌دور</strong><span>'.$this->e($name).'</span></div><form method="post" action="/staff/logout"><button type="submit">خروج</button></form></header><main class="rs-main"><div class="rs-alert" data-connectivity>در حال دریافت آخرین projection…</div><nav class="rs-tabs" aria-label="بخش‌ها">'.$buttons.'</nav><section class="rs-card"><h1 data-title>وضعیت</h1><div data-state class="rs-state"></div><pre data-output class="rs-output">یک بخش را انتخاب کنید.</pre></section></main></body></html>';
        return ['status'=>200,'headers'=>['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store'],'body'=>$html];
    }
    private function e(string $v): string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
