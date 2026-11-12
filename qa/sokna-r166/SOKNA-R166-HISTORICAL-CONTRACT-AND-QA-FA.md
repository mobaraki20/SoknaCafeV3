# سکنا — R166: ممیزی تاریخچهٔ ارتباطات و حفاظت از بازیابی سفارش مهمان

**تاریخ:** ۱۲ نوامبر ۲۰۲۶  
**وضعیت:** `SOURCE-ONLY / QA PASS LOCAL / NOT INSTALLABLE / NOT FIELD ACCEPTED`

## قراردادهای تاریخی که پیش از اصلاح بررسی شدند

1. **R70/R71 ـ ۳۱ اکتبر:** در مسیر snapshot/read-model، اعتبارسنجی JSON و هش، عدم overwrite ACK معتبر با پاسخ دیررس و ادامهٔ مدل‌های مستقل اضافه/آزمایش شده بودند. منشأ timeout شبکه در هاست واقعی کاملاً قطعی نبود. هیچ تغییری در این بخش‌ها مجاز نیست.
2. **R112 ـ ۵ نوامبر (`SOKNA-R112-QA-REPORT-FA.md`):** `order.append` به‌جای `order.edit` برای افزودن امن ساخته شد تا قیمت تاریخی، یادداشت، snapshot و ردیف‌های تکراری بازنویسی نشوند. شناسهٔ `request_id` و logical hash در Public/Local هماهنگ‌اند. Local قدیمی باید فرمان ناشناخته را fail-closed رد کند، نه به ویرایش کامل تبدیل نماید.
3. **R165 transport audit (GitHub run `43161874085`، سند `SOKNA-R165-CUMULATIVE-QA-HANDOFF-FA.md`):** بیست آزمون MariaDB روی receipt/ACK/claimed/transactional outbox و enqueueGuest موفق بود؛ تغییر در `RealtimeService`, `LanStaffRealtimeReceiptService` و schema بدون شکست مشخص مجاز نیست. آزمون full UI و شبکهٔ واقعی باقی است.
4. **R165 ownership audit (GitHub run `43170257026`):** ثبت مجدد `client_token` از همان دستگاه موفق است؛ رسید دستگاه دیگر یا پرسنل نباید برگردد. تغییر آن دقیقاً در `GuestOrderService.php` بوده است.
5. **سیاست تأییدشده:** فقط اصلاح خرابی قابل بازتولید روی آخرین سورس؛ Local/Public هم‌راستا؛ هیچ تغییری در `main`/`CURRENT`، موتور پاکسازی R162، migration، منو، رسپی، مالی/انبار و پروتکل ACK. تا پیش از پاکسازی کافه R162، بسته نصب تجمیعی منتشر نشود.

## ایراد جدید و قابل بازتولید

مسیر فعلی `assets/scds/guest.js` در Public و `public/assets/scds/guest.js` در Local در هنگام timeout برای افزودن اقلام، یک `pendingMutation` شامل payload اصلی (با `action:'append'` و `append_only:true`) در حافظهٔ مرورگر ثبت می‌کند و به‌درستی همان payload را برای بازیابی دوباره می‌فرستد. اگر کاربر در بازگشت به صفحه و پیش از اتمام بازیابی دوباره روی «ثبت» کلیک کند، تابع `submitOrder()` **وجود pendingMutation را در نگهبان اولیه بررسی نمی‌کند** و می‌تواند قبل از تعیین تکلیف درخواست قبلی، مسیر جدید ثبت/ویرایش را آغاز کند. `pendingSubmission` نوع دیگری از درخواست است و جایگزین این نگهبان نیست.

این نقص **لزوماً به معنی ایجاد دو ردیف سفارش در سرور نیست**؛ برخوردهای سرور با `request_id`, signature و receipt همچنان وجود دارند. اما یک بازهٔ رقابت رابط ایجاد می‌کند که باید بسته شود.

## وصلهٔ کاملاً محدود R166

در guard ابتدای تابع `submitOrder` هر دو نسخه فقط شرط `loadPendingMutation()` افزوده شده است. هیچ payload، request ID، API endpoint، retry policy، timeout، صف، ACK، snapshot، رندر منو یا مسیر تسویه تغییر نکرده است.

- Local R165: ۵۸۸ فایل، **فقط** `public/assets/scds/guest.js` تغییر می‌کند؛ ۵۸۷ فایل و تمام ۸۰ migration بایتی یکسان‌اند.
- Public R164: ۲۲۵ فایل، **فقط** `assets/scds/guest.js` تغییر می‌کند؛ ۲۲۴ فایل و تمام ۸ migration بایتی یکسان‌اند.
- JSONهای release identity و `VERSION.txt` عیناً حفظ شده‌اند؛ بنابراین این آرشیو **بسته نصب نیست** و نباید وارد پنل به‌روزرسانی شود.

## شواهد آزمون

آزمون `qa/test_guest_append_recovery_lock.js` تابع **واقعی** `submitOrder` را مستقیم از هر یک از فایل‌های اصلی/اصلاح‌شدهٔ JS استخراج و در VM مستقل Node با وابستگی‌های مصنوعی اجرا می‌کند. ۸ سناریو برای هر محیط:

1. سورس قبلی: وجود pendingMutation مانع شروع read مجدد نبود (بازتولید شکست).
2. سورس قبلی: ویرایش جدید نیز با وجود pendingMutation تا نقطهٔ ارسال request می‌رفت (بازتولید شکست).
3. سورس جدید: هم‌پوشانی append رد شد.
4. سورس جدید: هم‌پوشانی edit رد شد.
5. هر دو pending هم‌زمان رد شدند.
6. حفاظت pendingSubmission قبلی سالم ماند.
7. حفاظت offline فعلی سالم ماند.
8. مسیر عادی بدون pending همچنان ادامه یافت.

**نتیجهٔ محلی: ۱۶ از ۱۶ پاس.** اسکریپت در این آزمون هیچ درخواست شبکه‌ای به کافه نمی‌فرستد. آزمون مرورگر واقعی، هنگ‌کردن کامل ارتباط، قطع/وصل چند دستگاه، MariaDB کامل روی آخرین سورس و تأیید میدانی هنوز انجام نشده‌اند. آزمون‌های MariaDB قبلی فقط برای قراردادهای دست‌نخورده قابل استنادند.

## هش‌ها و مبنای تجمیعی

- Parent cumulative archive R165 SHA256: `dae1c14c71f66dd0294b8aa04368921bc3981b3a16d3d73a827d08d2f7187462`
- Local parent tree: `1a86354d558f8544349efc65333871ce1a5376ce37de957e523dd2e2c42e8b88`
- Local candidate tree: `f4d6511b6bf961cc66d43ed56a4d3c6575de022959b44a3d6f76b7f8883ff495`
- Public parent tree: `1ba7d1b2e2795ed6f084877cd88fe2efef919d9d0299a20de4fe51e5c2e14207`
- Public candidate tree: `a4797d25cbd1fc4cc2b5c3ff5ac4856966bd319fa60626a5e9a7f06830fe068f`
- QA output: `SOKNA-R166-POST-GOLIVE-CUMULATIVE-SOURCE-QA-NOT-INSTALL.zip`

## ادامه و محدودیت

R162 برای پاکسازی به‌عنوان prerelease مستقل حفظ می‌شود و هنوز روی دستگاه کافه اجرا نشده است. R163 تا R166 صرفاً تجمیع سورسی بعد از پاکسازی هستند؛ برای نصب نیازمند تولید پکیج باینری/ZIP طبق source identity، preflight و تست حقیقی Maintenance و Public receiver هستند. ادعای رفع قطعی خطاهای ارتباطی یا پاک‌شدن اطلاعات تستی نداریم.

## نتیجهٔ CI مستقل پس از ثبت این چک‌پوینت

- **GitHub Actions `43172963261`: completed / success**  
  https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43172963261
- والدهای واقعیِ منتشرشدهٔ R162 با SHA-256 رسمی بازخوانی و کاندیدهای تجمیعی R163، R164 و R165 بازسازی شدند.
- آزمون MariaDB R165 مالکیت `client_token` روی دو نسخهٔ قبل/بعد دوباره PASS شد.
- پیش‌/پس از اصلاح JS در Local و Public با اجرای تابع واقعی `submitOrder` آزموده شد: **۱۶/۱۶ PASS**؛ دو پنجرهٔ ارسال هم‌پوشان در سورس قبلی بازتولید شدند.
- مقایسهٔ tree کامل Local/Public و کنترل تعداد فایل‌ها، ۸۰/۸ migration و حفظ موتور پاکسازی R162 موفق بود.
- آرشیو R166 فقط سورس QA است؛ **هیچ انتشار Installable، دسترسی به کافه یا تغییر در Production انجام نشد**.
