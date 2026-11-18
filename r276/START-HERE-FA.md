# سکنا — R276: گام اصلاح دسترسی مالک Public

**تاریخ:** ۱۸ نوامبر ۲۰۲۶
**شاخه فقط QA:** `qa/sokna-r276-owner-setup-access-20261118`
**هدف یکتا:** باز کردن مسیر استاندارد ساخت/ورود مالک Public موجود برای تکمیل اتصال به Local کافه؛ نه نصب مجدد و نه تغییر منطق Sync.

## آخرین شواهد کافه
- Local در اسکرین‌شات `231fl/R275`؛ Public پس از Stage/Activate/Commit واقعی `35ij/R275`.
- آخرین اجرای میدانی تأیید مالک Public با پیام نبود رمز مستقل و HTTP 403 متوقف شد؛ endpoint دقیقِ 403 هنوز مشاهده نشده است.
- اتصال، Full Sync و یک سفارش مهمان واقعیِ دریافت‌شده در Local هنوز **اثبات نشده‌اند**.

## علت قابل اثبات از سورس دقیق R275
در `assets/sokna-public-move.js`، هنگام منقضی/نامعتبر شدن challenge، بخش `[data-owner-upgrade]` پنهان می‌شد و IIFE پیش از اتصال event handlerهای ساخت رمز `return` می‌کرد. در خود PHP صفحه امکان ساخت رمز وجود داشت، اما در این حالت برای کاربر ناپدید می‌شد. endpoint `upgrade_preflight` نیز به‌طور صحیح بدون نشست Staff admin همان Public کد 403 صادر می‌کند؛ علت 403 میدانی بدون نام endpoint قطعی نشده است.

## اصلاح محدود
- مسیر UI مستقل `GET /move/owner-setup` با نمایش همیشگی بخش ساخت رمز.
- گزینه ساخت رمز پس از منقضی‌شدن درخواست انتقال نیز قابل مشاهده و قابل استفاده است؛ برای ادامهٔ انتقال باید درخواست تازه‌ای از Local آغاز شود.
- پیام 403 روشن: ابتدا ورود مدیر کارکنان خود Public؛ بدون نمایش رازها/نشست‌ها.
- JS صفحه با query مبتنی بر هش فایل بارگذاری می‌شود تا JS ۵ دقیقه‌ای قبلی بعد از آپدیت استفاده نشود.
- امنیت سمت سرور تغییر نکرد: `POST upgrade_preflight/activate` همان Admin staff session + same-origin + CSRF و تک نصب فعال را اجباری نگه می‌دارد.
- هیچ تغییر SQL/دیتابیس، هیچ تغییر Local، هیچ تغییر دسترسی Emergency یا QR.

## بسته QA — نصب ممنوع تا گیت میدانی
- نسخه هدف Public: `3.0.0-g3.6-workspace.35ik-r276-owner-setup-access`
- نام: `SOKNA-R276-PUBLIC-35IK-OWNER-SETUP-QA-DO-NOT-INSTALL.zip`
- SHA256: `effbe0b41011807341c794b616c4a70c3a999bde730384d6fbee76680f1e2c8d`
- مبنای دقیق: `35ij/R275`، SHA بستهٔ قبلی `63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f`
- در ۲۴۴ فایل مدیریت‌شده فقط ۵ فایل تغییر کرده: `VERSION.txt`، JS مالک، `PublicHttpKernel.php`، `PublicMoveConsentPage.php` و release-version قرارداد Emergency.
- ۲۳۹ فایل دیگر byte-exact، ۱۱ Migration unchanged، protected Emergency owner SHA ثابت، Local unchanged.

## کنترل‌های انجام‌شده
- PHP lint و JS syntax: PASS.
- Chromium واقعی با PHP-rendered HTML و JS واقعی، HTTP پاسخ mock: (الف) صفحه مستقل بدون مدیر → راهنمای 403؛ سپس ادامه UI با مدیر آزمایشی، PASS؛ (ب) challenge منقضی → گزینه همچنان فعال، PASS؛ (ج) challenge معتبر + popup opener و consent owner موجود، بدون رگرسیون، PASS.
- بازتولید/بازبینی manifest، هر ۲۴۴ SHA و target tree: PASS.
- آزمون backend حقیقی HTTP/MariaDB، نصب Stage روی هاست، ورود مدیر فعلی، Sync/guest order در کافه: **NOT RUN**.
- `operator_install_allowed=false`; **Production/Go-live همچنان NO-GO**.

## حداقل مسیر مانده برای هدف اصلی
1. فقط با دسترسی مجاز، در Public فعلی نشست admin کارکنان را بررسی و کد دقیق 403 را مشخص کنیم (بدون ارسال رمز/کوکی).
2. بعد از qualification و Stage سالم بسته 35ik روی هاست، اپراتور مجاز می‌تواند رمز مستقل را از UI استاندارد بسازد؛ سپس در Local درخواست انتقال جدید و تأیید دوطرفه.
3. Full Sync داده/تصاویر و ACK صف؛ ثبت آدرس جدید فقط با readback سالم.
4. یک سفارش مهمان آزمایشی از QR و مشاهده همان سفارش در Local؛ سپس قبول نهایی.

**در این کار هیچ اقدام عملیاتی روی هاست کافه، Windows Local یا دیتابیس انجام نشده است.** تا حصول شواهد گیت، ZIP صرفاً QA است و نباید با تغییرنام/دورزدن مجوز به Production رفت.

مطابق MIG-001 تا MIG-020؛ آدرس و نسخهٔ کافه فقط fixture هستند.