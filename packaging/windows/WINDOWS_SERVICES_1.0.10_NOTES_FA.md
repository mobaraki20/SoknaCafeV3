# Windows Services 1.0.10 — یادداشت تغییرات کاندید

## دامنه

این تغییر فقط بسته Windows Services را پوشش می‌دهد: Setup UI، lifecycle نصب/Repair، Pairing با Local Web، تشخیص نسخه و تست‌های همین بسته. Local Web، Public Edge، Apache، PHP و MariaDB تغییر نکرده‌اند.

## رابط کاربری فارسی

- فرم تب‌محور و اسکرول‌دار 1.0.9 با یک Dashboard وضعیت‌محور جایگزین شد.
- صفحه اصلی در اندازه‌های هدف 980×620، 1120×680 و 1280×720 بدون AutoScroll طراحی شده است.
- وضعیت نصب/نسخه، وضعیت سرویس‌ها و وضعیت اتصال Local Web در سه کارت خلاصه نمایش داده می‌شود.
- عملیات اصلی بر اساس وضعیت سیستم تغییر می‌کند: نصب، به‌روزرسانی، تعمیر یا فقط اتصال.
- URL، مسیر فایل و داده فنی در کنترل LTR مستقل نمایش داده می‌شوند و متن رابط RTL باقی می‌ماند.
- Vazirmatn موجود در payload Print Worker به‌صورت PrivateFont برای UI بارگذاری می‌شود؛ fallback فقط در صورت نبودن فایل فونت است.
- مسیرها و جزئیات کم‌کاربرد از صفحه اصلی حذف و به پنجره جزئیات منتقل شدند.
- Gate خودکار UI، overflow و AutoScroll را در اندازه‌های هدف بررسی می‌کند و از خود WinForms screenshot می‌سازد.

## تشخیص Install / Upgrade / Repair

- نسخه بسته از `package_version` در install state خوانده می‌شود.
- 1.0.10 پس از Install/Repair نسخه بسته را در state ثبت می‌کند.
- برای ارتقا از نسخه‌های قبلی که `package_version` نداشتند، Inno Setup قبل از overwrite شدن فایل‌ها `DisplayVersion` قبلی را ثبت می‌کند و Dashboard فقط به‌عنوان fallback از آن استفاده می‌کند.
- مسیر نصب از Windows Service ImagePath و مسیر DataRoot از Registry/Runtime config کشف می‌شود؛ بنابراین نصب‌های موجود با DataRoot سفارشی (مثلاً روی درایو غیرسیستمی) به ProgramData برگردانده نمی‌شوند.
- Downgrade خودکار در Dashboard مجاز نیست.

## Pairing مستقل

- Pairing دیگر mode نصب یا Repair نیست.
- عملیات Pair فقط bundle کوتاه‌عمر را از Local Web exchange می‌کند، token/config را به‌صورت محدود می‌نویسد، Print Agent را provision می‌کند و سرویس‌های لازم را restart می‌کند.
- Pairing هیچ Runtime/Print Agent payload را copy/replace نمی‌کند.
- Pairing Windows Service را delete/create نمی‌کند و ImagePath سرویس را تغییر نمی‌دهد.
- در خطا، config/token/state قبلی تا حد امکان rollback می‌شوند و exchange با cancel پایان می‌یابد.
- Repair/Upgrade بدون Pairing جدید، Pairing موجود و Runtime config/tokenهای قبلی را حفظ می‌کند و Runtime را دوباره به وضعیت Running برمی‌گرداند.

## Gateهای کاندید

کاندید 1.0.10 فقط در صورت عبور از این Gateها قابل انتشار است:

1. PowerShell 5.1 parser برای تمام scriptهای lifecycle/Pairing؛
2. build کامل Setup UI/Host/Runtime/Print Agent و Inno installer؛
3. ProductVersion دقیق 1.0.10؛
4. layout self-test و screenshot واقعی WinForms بدون overflow/AutoScroll؛
5. install و دو Repair متوالی با Print Worker فعال؛
6. ثبت `package_version=1.0.10` در state؛
7. Pairing با Local Web mock و اثبات byte-for-byte ثابت ماندن executable payloadها؛
8. اثبات ثابت ماندن Service ImagePath در Pairing؛
9. اثبات حفظ Pairing و token/config در Repair بعد از Pair؛
10. cleanup/uninstall موفق.

## وضعیت انتشار

این فایل مربوط به کاندید 1.0.10 در شاخه `work/windows-services-1.0.10-ui-pairing` است. انتشار نهایی باید بعد از PASS شدن Gateها و بازبینی screenshot واقعی انجام شود.
