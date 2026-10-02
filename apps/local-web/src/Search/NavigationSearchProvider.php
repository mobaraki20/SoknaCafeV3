<?php
declare(strict_types=1);

namespace Sokna\Local\Search;

use Sokna\Local\Core\Auth;

final class NavigationSearchProvider implements SearchProvider
{
    private const ENTRIES=[
        ['id'=>'home','title'=>'خانه','terms'=>'خانه داشبورد خلاصه امروز وضعیت سامانه','href'=>'/','context'=>'بخش سامانه','any'=>[]],
        ['id'=>'operator','title'=>'کار روزانه','terms'=>'کار روزانه میزها سفارش فاکتور حساب باز سالن صندوق','href'=>'/operator/','context'=>'عملیات','any'=>['orders_floor','cashier_accounts','shift_supervision']],
        ['id'=>'staff','title'=>'سفارش سریع','terms'=>'سفارش سریع ثبت سفارش آیتم منو فروش','href'=>'/staff/','context'=>'عملیات','any'=>['orders_floor']],
        ['id'=>'staff-consumption','title'=>'مصرف پرسنل','terms'=>'مصرف پرسنل مزایا سهمیه حساب کارکنان','href'=>'/staff-consumption/','context'=>'پرسنل','any'=>['staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports']],
        ['id'=>'preparation','title'=>'آماده‌سازی','terms'=>'آماده سازی آشپزخانه بار سفارش آماده گارسون','href'=>'/waiter/','context'=>'عملیات','any'=>['preparation']],
        ['id'=>'operations','title'=>'انبار و تأمین','terms'=>'انبار تامین تأمین خرید موجودی شمارش هزینه رسید سفارش خرید','href'=>'/operations/','context'=>'انبار و تأمین','any'=>['inventory_operations','inventory_finalize','inventory_manage','preparation','shift_supervision']],
        ['id'=>'finance','title'=>'مالی','terms'=>'مالی فاکتور تسویه صندوق مالیات دوره مالی رسید','href'=>'/finance/','context'=>'مالی و گزارش','any'=>['cashier_accounts']],
        ['id'=>'subscribers','title'=>'مشتریان','terms'=>'مشتریان مشترک حساب مشتری بدهی اعتبار شماره موبایل','href'=>'/subscribers/','context'=>'مالی و گزارش','any'=>['cashier_accounts']],
        ['id'=>'integrations','title'=>'چاپ و اتصال‌ها','terms'=>'چاپ اتصال پرینتر بریج قالب چاپ دستگاه یکپارچه سازی','href'=>'/integrations/','context'=>'تنظیمات و پشتیبانی','any'=>['cashier_accounts']],
        ['id'=>'reports','title'=>'گزارش و تحلیل','terms'=>'گزارش تحلیل فروش روزانه آیتم برتر عملکرد','href'=>'/reports/','context'=>'مالی و گزارش','any'=>['cashier_accounts','shift_supervision','staff_consumption_reports']],
        ['id'=>'notifications','title'=>'اعلان‌ها','terms'=>'اعلان اطلاع رسانی نوتیفیکیشن پیام push','href'=>'/notifications/','context'=>'تنظیمات و پشتیبانی','any'=>[]],
        ['id'=>'catalog','title'=>'کاتالوگ','terms'=>'کاتالوگ منو آیتم کالا قیمت دسته بندی سرو','href'=>'/catalog/','context'=>'منو و مهمان','admin'=>true],
        ['id'=>'guest-content','title'=>'محتوای مهمان','terms'=>'محتوای مهمان عکس تصویر مدیا آلبوم تم منوی عمومی','href'=>'/guest-content/','context'=>'منو و مهمان','admin'=>true],
        ['id'=>'marketing','title'=>'کمپین و رویداد','terms'=>'کمپین رویداد event marketing بازاریابی برنامه زمان بندی','href'=>'/marketing/','context'=>'منو و مهمان','admin'=>true],
        ['id'=>'admin','title'=>'مدیریت','terms'=>'مدیریت تنظیمات کاربران پرسنل امکانات میزها QR نام مجموعه برند','href'=>'/admin/','context'=>'تنظیمات و پشتیبانی','admin'=>true],
        ['id'=>'system','title'=>'پشتیبانی و نگهداری','terms'=>'پشتیبانی نگهداری بروزرسانی آپدیت بکاپ سلامت عیب یابی سیستم','href'=>'/system/','context'=>'تنظیمات و پشتیبانی','admin'=>true],
        ['id'=>'account','title'=>'حساب من','terms'=>'حساب من پروفایل نام نمایشی رمز عبور امنیت کاربر','href'=>'/account/','context'=>'حساب کاربری','any'=>[]],
        ['id'=>'settings-brand','title'=>'نام و هویت مجموعه','terms'=>'نام مجموعه برند عنوان زیر لوگو سکنا هویت مجموعه','href'=>'/admin/?tab=settings','context'=>'تنظیم مدیریت','admin'=>true],
        ['id'=>'settings-day','title'=>'شروع روز کاری','terms'=>'شروع روز کاری ساعت cutoff تنظیم زمان','href'=>'/admin/?tab=settings','context'=>'تنظیم مدیریت','admin'=>true],
        ['id'=>'settings-users','title'=>'کاربران و دسترسی‌ها','terms'=>'کاربران دسترسی نقش permission capability حساب ورود رمز','href'=>'/admin/?tab=users','context'=>'تنظیم مدیریت','admin'=>true],
        ['id'=>'settings-personnel','title'=>'پرسنل','terms'=>'پرسنل کارکنان سمت کد پرسنلی حساب مرتبط','href'=>'/admin/?tab=personnel','context'=>'تنظیم مدیریت','admin'=>true],
        ['id'=>'settings-modules','title'=>'امکانات سامانه','terms'=>'امکانات ماژول انبار تامین مالیات چاپ فعال غیرفعال','href'=>'/admin/?tab=modules','context'=>'تنظیم مدیریت','admin'=>true],
        ['id'=>'settings-tables','title'=>'میزها و QR','terms'=>'میزها میز QR کیو آر توکن سالن بخش شماره میز','href'=>'/admin/?tab=tables','context'=>'تنظیم مدیریت','admin'=>true],
    ];

    public function __construct(private readonly Auth $auth){}

    public function search(string $query,array $user,int $limit): array
    {
        $limit=max(1,min(10,$limit));$out=[];
        foreach(self::ENTRIES as $entry){
            if(!$this->allowed($entry,$user))continue;
            $hay=$entry['title'].' '.$entry['terms'].' '.$entry['context'];
            if(!SearchNormalizer::matches($hay,$query))continue;
            $title=SearchNormalizer::normalize($entry['title']);$q=SearchNormalizer::normalize($query);
            $score=$title===$q?150:(str_starts_with($title,$q)?135:118);
            $out[]=['id'=>'nav:'.$entry['id'],'type'=>'navigation','title'=>$entry['title'],'subtitle'=>'رفتن مستقیم به این بخش','context'=>$entry['context'],'actions'=>[['label'=>'بازکردن','href'=>$entry['href']]],'score'=>$score];
        }
        usort($out,static fn(array $a,array $b)=>(int)$b['score']<=>(int)$a['score']);
        return array_slice($out,0,$limit);
    }

    private function allowed(array $entry,array $user): bool
    {
        $admin=(string)($user['role']??'')==='admin';
        if(($entry['admin']??false)===true)return $admin;
        $caps=$entry['any']??[];
        if($caps===[])return true;
        if($admin)return true;
        foreach($caps as $cap)if($this->auth->hasCapability((string)$cap,$user))return true;
        return false;
    }
}
