# سکنا — R261؛ انتقال ساده Public، گیت واقعی موفق

**تاریخ:** ۱۶ نوامبر ۲۰۲۶  
**شاخهٔ QA:** `qa/sokna-r261-real-local-public-smoke-20261116`  
**تصمیم لازم‌الاجرا:** `MIG-018`؛ Public قدیمی می‌تواند کاملاً خراب/خاموش باشد، Local مرجع داده‌هاست، Public مقصد نصب تازه و بدون سفارش است، Sync معمول تصاویر/منو را می‌فرستد. **نه موتور مهاجرت دیتابیس، نه انتقال سفارش‌های باقی‌مانده روی Public قدیمی.**

## نتیجهٔ کار واقعی (همه روی نصب مجزای غیرعملیاتی)
### ۱. مسیر ثبت سفارش واقعاً تکمیل شد
- دیتابیس MariaDB واقعی Local، **۷۶ migrationِ شاخهٔ موجود**، seed واقعی ۱۳۵ محصول و ۲۴ تصویر.
- نصب واقعاً تازهٔ Public بر مبنای payload بایتی R249/35IC و overlay R260، ۱۱ migration، زیرپوشهٔ `/new-public`.
- Pair اولیه با موتور فعلی و سفارش‌گیری بسته؛ بارگذاری منوی کامل و تصاویر در ۳ تکرار معمول publisher؛ فعال‌سازی امضاشده.
- ارسال سفارش از HTTP مهمان Public → صف Realtime → Relay واقعی Local → ثبت **۱ سفارش در جدول orders**.
- GitHub موفق: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43824665834

### ۲. **خودِ سرویس انتقال** با HTTPS و قطع اینترنت تأیید شد
- ورود مدیر مستقل Public، صدور challenge، receipt امضاشدهٔ مقصد و تأیید مستقل مدیر Local.
- شروع `PublicMoveSimpleReplacement` با استفاده از Pair عادی روی Public خالی.
- یک خطای HTTP شبیه‌سازی‌شده هنگام Sync؛ URL و secret قبلی Local دست‌نخورده و Public جدید هنوز بدون پذیرش سفارش.
- بازگشت اینترنت: ۳ نوبت `finish` با Sync معمول تا تکمیل تصاویر/کاتالوگ، سپس ذخیرهٔ اتمیک آدرس جدید Local و فعال‌سازی امضاشدهٔ سفارش‌گیری مقصد؛ ledger بسته و سفارش قدیمی Local حفظ شد.
- GitHub موفق: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43825524618
- **نکته:** وب‌سرور HTTP آزمایشی و stunnel TLS روی ماشین CI استفاده شد؛ آزمون کلیک مرورگر روی سیستم واقعی کافه یا هاست مشتری نیست.

## مانع دقیق انتشار نسخهٔ قابل نصب — کد ساده کامل است، سورس تجمیعی هنوز تطبیق نشده
**هیچ فایل قابل نصب R261 منتشر نشود تا این اختلاف حل شود:**
- مبنای سورس فعلی شاخهٔ GitHub `apps/local-web/VERSION.txt` = `3.0.0-g6.2-workspace.231bf-u01-iran-time-updater-rc1` است و کاتالوگ SQL آن تا 0075 برای این ویژگی QA تغییر یافته؛
- ولی بستهٔ Local واقعی موجود در Library، `SOKNA-R241-LOCAL-231FA-CUMULATIVE-CONNECTION-RECOVERY.zip`، دو‌لایهٔ Maintenance release set با `artifacts/local/web.zip`، حاوی **۵۹۳ فایل و ۸۱ migration** است. SHA256 بستهٔ بیرونی: `2ec84cff9481ca0350dea9401c5b1bb1437f29e7e96f1d1c80f0739f8c7c992b`.
- نسخهٔ Local `R241` تازه‌تر از درخت `231bf` موجود در GitHub است؛ جایگزین‌کردن کامل فایل‌های `Bootstrap`، `PublicReenrollmentService`، `PublicEdgeSyncClient`، JS یا migrationهای آن با نسخهٔ قدیمی QA می‌تواند اصلاحات پذیرفته‌شدهٔ قبلی را حذف کند.
- برای سورس واقعی R241، migration فقط **`0082_public_move_owner_approval.sql`** باید اضافه شود (در `r261/overlay/local/database/migrations/` ثبت شد) و تمامی ۸۱ migration قبلی **بدون تغییر بایتی** باقی بمانند؛ `0075_public_move_owner_approval.sql` QA نباید روی این مبنا به‌عنوان migration رسمی وارد شود.
- بستهٔ Public واقعی `R249/35IC`: ZIP بایتی pinned `5c887968b6fcec1126e2b1b3123552a9ba0ba2c7672d29774c05ec58fa5cd0e4`؛ R260 فقط سورس‌های افزایشی و migration 0011 را به آن اضافه می‌کند. محفوظ‌داشتن فایل‌های محافظت‌شدهٔ Emergency و owner فعلی الزامی است.

**کار واقعی بعدی بدون گسترش پیچیدگی محصول:** فقط merge کوچک و فایلی R260 روی جدیدترین Local تجمیعی پذیرفته‌شده و Source Public pinned، با تطبیق پوشش قبلی؛ سپس ساخت بسته به شکل native Maintenance Local و Public clean install، آزمون نصب واقعی/ارتقای سازگار، و در صورت موفقیت ارائهٔ لینک نصب. هیچ سناریوی بازیابی دیتابیس Public قدیمی به موتور اضافه نشود.

## صریحاً انجام نشده
- سورس تجمیعی Local R241 یا بالاتر هنوز با کد R260 ادغام/تأیید نشده؛ بستهٔ Local قابل نصب وجود ندارد.
- بستهٔ clean-install Public و بستهٔ به‌روزرسانی رسمی با manifest قابل‌نصب هنوز منتشر نشده‌اند.
- native Windows و هاست مشتری بعد از merge جدید باید **یک آزمون نصب/به‌روزرسانی واقعی و یک سفارش** را بگذرانند؛ آزمون‌های فرعی تکراری اضافه نشوند.

**محیط کافه، main و CURRENT دست‌نخورده‌اند. این مرحله را QA PASS برای مسیر منطقی، و Production NO-GO برای بسته نگه‌دار.**

مرجع وضعیت ماشینی: `r261/STATUS.json`، تصمیم‌ها `r251/decisions.json`.
