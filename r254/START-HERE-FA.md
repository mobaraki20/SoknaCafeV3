# R254 — ادامهٔ موتور یونیورسال انتقال سکنا
**تاریخ:** ۲۰۲۶-۱۱-۱۶  
**شاخهٔ فعال QA:** `qa/sokna-r254-owner-consent-guard-20261116`  
**شاخهٔ پایه:** `qa/sokna-r253-migration-approval-ledger-20261116`

## هدف بدون تغییر
فرایند سادهٔ انتقال Local/Public برای هر مشتری مستقل و هر دامنه/هاست/دستگاه/نسخهٔ معتبر، نه مختص کافهٔ سکنا. معیار UI: واردکردن آدرس مقصد، تأیید مالکیت و مشاهدهٔ وضعیت؛ بدون اصطلاحات فنی در مسیر معمول. نمونه‌های سکنا فقط رگرسیون هستند.

## اول این فایل‌ها را بخوان
1. `r251/DECISIONS-FA.md` و `r251/decisions.json`؛ تصمیم‌های فعال MIG-001 تا MIG-014 و تاریخچهٔ OLD-*.
2. `r254/START-HERE-FA.md` و `r254/STATUS.json`.
3. `r253/START-HERE-FA.md`, `r253/STATUS.json`; فایل‌های Authorization Ledger.
4. `r252/SECURITY-BOUNDARY-FA.md`, R252 signer و verifier.
5. کدهای `PublicMoveOwnerConsentGuard.php`, `PublicMoveInstanceKeyStore.php`, آزمون `r254/test-public-move-owner-consent.php`.
6. نتیجهٔ CI `.github/workflows/sokna-r254-owner-consent.yml`.

## کارهای واقعاً انجام‌شده
- R251: تشخیص مقصد بدون side effect؛ ۵۵ assertion و چند مشتری فرضی.
- R252: امضای Ed25519، Local-only MACِ grant و جداسازی درخواست‌ها؛ ۵۷ assertion.
- R253: nonce و approval ledger در MariaDB 11.4 آزموده؛ تعویض واقعی Public ندارد.
- R254: `PublicMoveOwnerConsentGuard` به‌صورت خالص و بدون HTTP endpoint: بررسی مدیرِ دارای session تأییدشده در Public و متعلق به نصب مربوطه، CSRF اختصاصی session+challenge، Origin و مسیر/انقضای درخواست. **نشست باید در سمت سرور از PublicSessionStore::resolve گرفته شود؛ دادهٔ مرورگر کافی نیست.**
- R254: `PublicMoveInstanceKeyStore` محافظت کلید اختصاصی هر Public در مسیر خارج از document root با عدم بازنویسی بی‌اجازه و رد دسترسی‌های ناامن در Linux؛ Windows ACL و race-condition باید در محیط اصلی اثبات شوند.
- R254: ۴۳ آزمون QA محلی و CI روی فایل‌های واقعی؛ آدرس‌ها/نام‌ها/نسخه‌ها ثابت در کد نیستند.

## مسألهٔ مهم کشف‌شده
در Public تازه‌نصب‌شده ممکن است `auth_projections` خالی باشد، بنابراین **هیچ Staff admin session برای تأیید انتقال وجود ندارد**. استفادهٔ عادی از رمز Emergency برای این کار پذیرفته نیست. برای پوشش نصب مستقل تازه باید در نصب/Setup معمولی یک مرحلهٔ بسیار سادهٔ راه‌اندازی مالک/مدیر همراه کلید محافظت‌شده و نشست مستقل تعریف شود؛ تأیید فقط بعد از ورود همان مالک، با CSRF و scope درست پذیرفته شود. این بخش هنوز طراحی و پیاده‌سازی عملیاتی نشده است.

## مرزهای شفاف
- کد R254 **در PublicHttpKernel متصل نیست** و هیچ endpoint/رابط عمومی منتشر نکرده؛ مجوز ثبت‌شدهٔ R253 نیز هنوز از این کد تغذیه نمی‌شود.
- فقط guard/key primitives؛ انجام واقعی consent/grant، ذخیرهٔ key در نصب واقعی، PKCE callback امن، هماهنگ‌سازی داده و activation هنوز انجام نشده.
- به کافه، هاست یا دیتابیس واقعی دست نخورده؛ main/CURRENT نیز دست نخورده.
- نبودن session مدیر در Public تازه باید **وضعیت قابل‌فهمِ «ابتدا راه‌اندازی مالک»** نشان دهد، نه اینکه عملیات به‌اشتباه موفق شود.
- نتیجهٔ موفق guard هرگز به معنی permission برای cutover نیست: `safe_to_cutover=false`.

## قدم بعدی
1. ساخت احراز هویت مالک مستقل نصب Public تازه در Setup معمولی، بدون وابستگی به Staff projections و بدون استفاده از Emergency؛ قابلیت بازیابی با حساب مدیرِ همان مشتری.
2. اتصال Guard به نشست واقعی Public در endpoint با CSRF و Authorization و رسید یک‌بارمصرف مقصد؛ برقراری ارتباط Local R253 ledger با صاحب امضای مقصد و مسیر challenge امن.
3. آزمون کامل MariaDB هم‌زمانی، قطع اینترنت/برق و replay/tenant isolation.
4. پیش‌بررسی عمومی در GUI موجود، orchestrator idempotent، همگام‌سازی/authority/سفارش‌های معلق، سپس E2E چندمشتری و انتشار رسمی.

**NO-GO:** تا E2E نصب/انتقال و آزمون ZIP نهایی، هیچ بستهٔ عملیاتی یا اعلام انتقال موفق مجاز نیست. برای ادامهٔ چت، از همین مرحله و دفتر تصمیم‌های فعال شروع شود، نه از آدرس یا نسخهٔ موردی.
