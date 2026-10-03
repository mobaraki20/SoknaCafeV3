# استاندارد دائمی Self-Service برای Windows Services سکنا

این سند مکمل `WINDOWS_INSTALLER_ENGINEERING_PLAYBOOK_FA.md` است و برای هر نسخه‌ی بعدی Windows Services الزام‌آور است.

هدف: کاربر عادی باید بتواند بدون دسترسی به ایجنت هوش مصنوعی یا برنامه‌نویس، وضعیت سرویس‌ها را بفهمد، خطاهای رایج را تشخیص دهد و اقدامات امن و مشخص را انجام دهد.

## 1) خود Installer باید Installation-Aware باشد

Setup بیرونی باید پیش از overwrite فایل‌ها تشخیص دهد که آیا همین محصول قبلاً نصب است یا نه.

حالت‌های اجباری:

- Fresh Install: نسخه‌ای نصب نیست.
- Upgrade: نسخه نصب‌شده قدیمی‌تر از بسته فعلی است؛ نسخه مبدا و مقصد باید صریح نمایش داده شوند.
- Same Version: همان نسخه نصب است؛ Setup باید به کاربر بگوید ادامه به معنی Repair/Reapply فایل‌های خود برنامه است.
- Newer Installed: نسخه جدیدتری نصب است؛ downgrade خودکار باید مسدود شود.

AppId ثابت به‌تنهایی کافی نیست. تشخیص باید در متن Wizard برای کاربر قابل مشاهده باشد.

## 2) Dashboard باید Self-Service باشد

Dashboard اصلی باید فقط وضعیت و actionهای روزمره را نشان دهد؛ تشخیص‌های فنی در «عیب‌یابی» قرار می‌گیرند.

الزامات:

- دکمه‌ای با عنوان روشن «عیب‌یابی» در صفحه اصلی وجود داشته باشد.
- کاربر برای فهمیدن مشکل مجبور به خواندن raw log یا ZIP نباشد.
- Support Bundle ابزار مرحله دوم پشتیبانی است، نه تنها راه فهمیدن مشکل.
- متن‌های فارسی + عبارت‌های فنی انگلیسی باید با BiDi isolation نمایش داده شوند.
- متن دکمه‌ها نباید clip شود؛ gate UI باید علاوه بر bounds، text clipping را نیز کنترل کند.

## 3) مدل کد خطا/یافته

هر یافته قابل اقدام باید کد پایدار داشته باشد. الگوی فعلی:

- `WS-PREREQ-*` پیش‌نیازها
- `WS-SVC-*` وضعیت Windows Service
- `WS-FILE-*` payload/file
- `WS-STATE-*` state/config
- `WS-VERSION-*` نسخه
- `WS-PAIR-*` Pairing
- `WS-LOCAL-*` دسترسی Local Web
- `WS-DATA-*` DataRoot
- `WS-DIAG-*` خود موتور عیب‌یابی
- `WS-SUPPORT-*` ساخت Support Bundle
- `WS-UAC-*` UAC/Administrator

کد خطا باید همراه با سه بخش نمایش داده شود:

1. چه مشکلی پیدا شد؟
2. کاربر الان چه کاری انجام دهد؟
3. جزئیات فنی فقط در صورت نیاز.

## 4) سناریوهای اجباری عیب‌یابی بدون AI

مرکز عیب‌یابی باید حداقل این سناریوها را تشخیص دهد:

- Visual C++ x64 Runtime موجود نیست.
- Runtime یا Print Agent ثبت نشده است.
- executable سرویس از مسیر ImagePath حذف/گم شده است.
- Print Agent متوقف است.
- Runtime بعد از Pairing متوقف است.
- state نصب خوانده نمی‌شود یا JSON خراب است.
- نسخه نصب‌شده قابل تشخیص نیست.
- DataRoot وجود ندارد یا برای کاربر فعلی قابل نوشتن نیست.
- سرویس‌ها نصب‌اند ولی Local Web هنوز Pair نشده است.
- Pairing وجود دارد ولی URL محلی نامعتبر است.
- Local Web روی host/port ثبت‌شده پاسخ TCP نمی‌دهد.

اگر هیچ مشکل مهمی دیده نشد باید صریحاً وضعیت سالم نمایش داده شود؛ صفحه خالی یا «لاگ را بررسی کنید» کافی نیست.

## 5) اصلاح خودکار امن

فقط remediationهایی که deterministic و کم‌خطر هستند خودکار شوند.

مجاز:

- Start کردن Runtime متوقف‌شده در سیستم Pair شده.
- Start کردن Print Agent متوقف‌شده.
- اجرای Repair موجود از Dashboard با تأیید کاربر.
- ساخت Support Bundle.
- باز کردن log folder.

غیرمجاز بدون تأیید/اطمینان بیشتر:

- حذف state/token.
- Re-pair خودکار.
- پاک‌کردن DataRoot.
- reset کردن MariaDB/Local Web.
- kill کردن process ناشناس فقط برای آزاد کردن lock.

## 6) Support Bundle

Support Bundle v2 همچنان باید شامل service/process snapshot، Event Log، file-lock diagnostics/Restart Manager و logهای امن باشد و secret/token/pairing code را جمع نکند.

UI باید بعد از ساخت، محل فایل را واضح به کاربر نشان دهد. کاربر باید بتواند فقط با گفتن کد خطا و در مرحله بعد ZIP، پشتیبانی دریافت کند.

## 7) Gateهای جدید از 1.0.11

قبل از Release علاوه بر gateهای قبلی باید این‌ها PASS شوند:

- Inno Setup روی Fresh Install متن نصب جدید را نشان دهد.
- Upgrade از 1.0.10 به نسخه candidate متن مبدا/مقصد را نشان دهد.
- اجرای Setup همان نسخه حالت Repair/Reapply را به کاربر اعلام کند.
- نصب نسخه قدیمی‌تر روی نسخه جدیدتر مسدود شود.
- دکمه «عیب‌یابی» در Dashboard قابل مشاهده باشد و Support Bundle از داخل آن قابل ساخت باشد.
- سناریوهای service stopped / service missing / executable missing / no pairing / Local Web offline / bad state / missing prerequisite در self-service test پوشش داده شوند.
- نتیجه هر سناریو باید code + title + guidance داشته باشد.
- auto-fix فقط برای start serviceها اجرا شود و پس از آن diagnostics دوباره اجرا شود.
- screenshot واقعی Dashboard باید BiDi صحیح و متن بدون clipping داشته باشد.

## 8) اصل انتشار

GitHub Actions محیط توسعه نیست. تمام تغییرات Self-Service روی `work/...` جمع می‌شوند؛ بعد یک candidate واحد به qualification می‌رود. Release فقط از همان artifact qualified منتشر می‌شود.
