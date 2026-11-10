# سکنا — تعیین تکلیف قطعیِ حادثهٔ قدیمی R125 و تفکیک مانع واقعی به‌روزرسانی کافه

**تاریخ مبنای شواهد:** ۱۰ نوامبر ۲۰۲۶  
**حکم دربارهٔ حادثهٔ R125:** `HISTORIC_RECOVERY_SETTLED` (نه نیازمند اجرای مجدد بازیابی و نه مجوز خودکار نصب).  
**حکم دربارهٔ نصب آخرین Local:** `NOT_YET_AUTHORIZED` (علت جداگانه: اثبات نشدن شرایط نصب میزبان، کیفیت بسته و بک‌آپ تازه).  
**حکم دربارهٔ Public:** R152/35hj آخرین سورس پیدا شده، نصب آزمایشی روی کپی 35hh و 35hi PASS، نه هاست زنده.

## ۱. شواهد مستقل که اتفاق قدیمی R125 را از وضعیت امروز جدا می‌کنند

۱. `SOKNA-R125-EMERGENCY-RESULT.txt`، ۷ نوامبر، بازگشت محدود به Local R112، migration head 0076، Apache/HTTP پاسخ‌گو؛ خود گزارش در همان لحظه مانع Maintenance را باقی گذاشته بود.

۲. `SOKNA-R129-RECONCILE-RESULT.txt`، ۷ نوامبر ساعت 14:10 UTC، پس از تأیید فایل‌های ۵۳۷تایی، بکاپ ۷۲۵٬۳۰۶٬۳۹۶ بایتی و دیتابیس زنده با migration 0076، کپی شاهد اصلی و ثبت intent، وضعیت routine را به `RECOVERED_TO_PREVIOUS_SLOT` و عملیات R125 را به failed after verified rollback تبدیل کرد؛ پیام پایان `SUCCESS: MAINTENANCE_R125_BLOCKER_SETTLED_LOCAL_R112_UNCHANGED`.

۳. `SOKNA-R130-CAFE-PREFLIGHT-RESULT.txt`، ۷ نوامبر، `MAINTENANCE_SETTLED_NO_BLOCKERS` و `DB_PREFLIGHT_PASS EXACT_R112_AND_0078_0079_GUARDS`. این فقط پیش‌بررسی خواندنی بود.

۴. فایل پشتیبانی جدید Local `support-20261110-134146-071bfba5.json.gz`، ساعت 13:41 UTC در ۱۰ نوامبر:
   - Local فعال: `3.0.0-g6.2-workspace.231dg-r112-guest-safe-append-uat`; خود نسخه و VERSION.txt منطبق، شمار فایل مدیریت‌شده ۵۳۷. هش همهٔ ۵۳۷ فایل در این بسته اندازه‌گیری نشده است.
   - دیتابیس: ۷۶ migration اعمال‌شده از ۷۶، `pending_migrations=0`.
   - `maintenance_update_diagnostics.operations`: `state=idle`, `blocking_count=0`, `active_count=0`, `requires_recovery=false`, `history_available=true`.
   - رکورد تاریخی `b26dc160ce42441ebf8f6e393f41b924` وضعیت `failed` با نتیجه `CODE_ONLY_ENGINE_ROLLED_BACK_VERIFIED` دارد؛ این همان عملیاتی است که R129 طبقه‌بندی کرد.
   - Local lifecycle: `activation_journal=null`, `staged=null`, `recovery_mode=null`, `last_failure=null`.

۵. فایل پشتیبانی Public `sokna-public-support-1794318123354.json`: routine Public `35hh` فعال، پروتکل ۲، migration ۸/۸، pending ۰، Local heartbeat و projection هماهنگ؛ این فقط یک snapshot است.

**نتیجهٔ لازم:** استناد به «R125 هنوز نیازمند بازیابی است» برای متوقف‌کردن کار بعدی اشتباه بوده؛ نباید فایل R125 را دوباره اجرا، پاک یا بازنویسی کرد. تاریخچه و پرچم «DB mutation historically performed» را برای حسابرسی حفظ کنید. از طرف دیگر، احراز وضعیت لحظه‌ای نصب و ناسازگاری‌های احتمالی جدید هنوز به کنترل خودکار موتور نیاز دارد.

## ۲. مانع فعلی واقعی، نه R125

- پاسخ امضاشدهٔ Maintenance در همان فایل Support Local: `LOCAL_HOST_PRECONDITIONS_UNPROVEN`, `install_ready=false`, `verification_ready=true`, `inbox_transport_available=true`. طبق سورس `UnifiedUpdaterCapabilities.Current` و `InactiveLocalHostReadiness`، این کد به مجموعه پیش‌شرط‌های آدرس Local، HTTP Apache، junction، نصب و managed tree، machine binding، overlay پایدار و حالت lifecycle مربوط است. در فایل Support موجود **لیست دقیق host blockers صادر نشده**؛ بنابراین هیچ‌یک از این زیرعلت‌ها را نباید حدس زد یا از روی اسناد گذشته پاک‌شده فرض کرد.
- `SOKNA-Support-v2-20261110-170943.zip`, `maintenance/backup-status-safe.json`: `evidence_available=false`, `state=DISABLED`, `code=NO_BACKUP_STATE`, بدون آخرین SHA یا receipt پشتیبان تأییدشده. این **به معنی نبود تمام بکاپ‌ها نیست**؛ اما شواهد یک بکاپ تازه و سازگار با داده‌های ۱۰ نوامبر وجود ندارد. فایل ۷۲۵MB بازگردانی R125 مربوط به ۷ نوامبر است و برای بازگردانی فروش جدید امروز قابل فرض نیست.
- ۴۴ مبدأ Local و ۳۱ مبدأ Public در R152 فقط **مبدأهای شناخته‌شده با fingerprint دقیق** هستند، نه wildcard. Local R152 هنوز عمداً `for_install=false`, `production_pass=false`, `install_plan=[]` دارد؛ Public در provenance `QA_ONLY_NOT_FOR_PRODUCTION`. این‌ها باید پس از qualification واقعی از مسیر ابزار انتشار استاندارد تعیین شوند، نه تغییر دستی فلگ.
- native PHP روی کپی Public 35hh و 35hi در GitHub run `42838078277` PASS شد، ولی UAT روی Public واقعی/احراز Emergency و خواندن نتیجهٔ زنده انجام نشده است.

## ۳. تصمیم اجرایی بدون بازگشت به آزمون‌های تکراری

**بسته‌های درخواستی:** Local R152/231ec و Public R152/35hj، دو ZIP مستقل. در پنل هر فایل را جدا انتخاب و یک بار «به‌روزرسانی» بزنید. هیچ کاتالوگ دانلود خودکار و دکمه مشترک اضافه نشود. Local 231dg کافه و Public 35hh در فهرست مبدأهای شناخته‌شده هستند؛ این فقط پیش‌نیاز شناخت مبدأ است.

**برای نصب قابل تأیید و بدون خطر:**

- «R125 recovery required» از مانع جاری برداشته شود؛ به جایش خود موتور لحظهٔ شروع، امضای Supervisor، هویت بایت‌دقیق Local، junction/overlay، `operation ledger` و `lifecycle idle` را بدون تغییر داده تأیید کند و اگر blocker جدید وجود دارد، کد و علت *دقیق* را از پاسخ امضاشده نمایش دهد.
- پیش از مرز دیتابیس، بکاپ واقعیِ امروز با آزمون صحت dump/امکان Restore و hash ایجاد و تأیید شود. از snapshot هفت نوامبر برای امروز استفاده نشود. هیچ افت داده/rollback DB نامتناسب مجاز نیست.
- بسته‌های R152 از طریق Release Gate واقعی، Windows/MariaDB و Public host qualification عبور کنند؛ صرف `Source QA` مجوز نصب نیست.
- نیاز به تکرار فایل‌های پشتیبانی قبلاً ارسال‌شده **نیست**. از این سه فایل و نتیجهٔ R129/R130 برای بازرسی استفاده شود. در این جلسه نه نصب/تغییر میزبان انجام شده و نه مجوز Production صادر شده است.

## ۴. مسیرهای مرجع

- GitHub QA PR: https://github.com/mobaraki20/SoknaCafeV3/pull/71
- GitHub Public R152 native QA: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/42838078277
- Latest R152 source QA in Library: `/Cafe/SOKNA-Release-Quality/R152-Guest-UX-QA-20261110/`

**قابل اتکا:** این سند خاتمهٔ پروندهٔ `R125 unsettled` به‌عنوان *مانع تاریخی* است، نه ادعای پذیرش محیط Production یا انجام بی‌خطر هر migration جدید.