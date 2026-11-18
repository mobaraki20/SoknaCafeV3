# سکنا — R279 / نقطه تحویل اصلاحات ایمنی انتقال Local ↔ Public

**تاریخ:** ۲۰۲۶-۱۱-۱۸ | **شاخه:** `qa/sokna-r279-transfer-guardrails-20261118`  
**نتیجه:** `EXACT_R275_SOURCE_QA_PASS / R276_PUBLIC_HTTP_MARIADB_ROOT_SUBFOLDER_PASS / FIELD_NO_GO`

## هدف و محدودیت
بر اساس دفتر تصمیم‌های **MIG-001 تا MIG-020** در `r251/decisions.json` و `r251/DECISIONS-FA.md`، برای هر مشتری مستقل، رابط کم‌دخالت و توقف امن هنگام ابهام. این شاخه **بسته اپراتوری یا تغییر روی سیستم کافه نیست**. به هیچ‌وجه با نام دیگر ZIP را به‌عنوان Production معرفی نکنید.

## سورس واقعی، نه سورس قدیمی
- Local واقعی مبنا: **R275 / 231fl**، بسته SHA256 `f42d66e26dfee936f29c159bb7028adefcfedda43ddf2dc63e2c25d6a7d6dba9` با **۶۰۴ فایل مدیریت‌شده و ۸۲ Migration**.
- Public واقعی مبنا: **R275 / 35ij**، بسته SHA256 `63213185fcc037b620ea0dccf133d5c63672f01da7db7d3545eb54e617dd150f`، ۱۱ Migration.
- Public ادامه مورد آزمون: کاندید **R276 / 35ik**، SHA256 `effbe0b41011807341c794b616c4a70c3a999bde730384d6fbee76680f1e2c8d`؛ تا این سند روی هاست عملیاتی نصب نشده است.
- شاخه‌های R277/R278 مستقلاً جلو رفته‌اند؛ ادغام این وصله‌ها به بسته آینده باید **با مبدأ دقیق آن بسته** و گیت کارخانه انجام شود.

## تغییرهای واقعاً لازم در R275
۱. `src/Domain/PublicEdge/PublicMoveUiInspectionService.php`: اگر probe مربوط به همان Public رد شد، دیگر بدون بررسی سلامت و وضعیت نصب، مستقیماً مالک را باز نمی‌کنیم. Public ناسالم، TLS/HTTP نامطمئن، دیتابیس ناآماده، Migration معلق، path/base مغایر، Public متعلق به Local دیگر و وضعیت مبهم **fail closed** می‌شوند. Public تازه سالم یا paired همین Local فقط به مرحله تأیید مالک می‌رود، نه اجرای خودکار انتقال.

۲. `public/integrations/api.php`: وضعیت مجوز/اجرای نامشخص به کدهای غیرحساس `public_move_resume_required` یا `public_move_state_requires_review` با `next_action` تبدیل می‌شود. ادعای «اتصال قبلی دست‌نخورده» پس از پاسخ گم‌شدهٔ commit حذف شده؛ ابتدا ledger و مرحله پایدار بررسی می‌شود.

۳. `public/assets/connections-workspace.js`: نمایش تفاوت «آدرس قبلاً انتخاب شده» با «اتصال سالم»، وضعیت `finalize_pending`، نیاز به بازبینی PWA/QR/Push، و تمایز ادامه ایمن با پشتیبانی. منطق ازسرگیری ویژهٔ R274 **حفظ** شده و بازنویسی نشده است.

وصلهٔ قابل بازتولید روی ZIP بایتی R275:
- `r279/patches/r275-local-transfer-guardrails.patch`
- `r279/patches/r275-owner-lost-reply-ux.patch`

**اصلاح مبنای بازیابی:** `apps/local-web/src/Domain/Recovery/BusinessBackupService.php` در سورس پایه قدیمی‌تر فاقد Snapshot یکپارچه بود؛ اما فایل متناظر در **ZIP دقیق R275 از قبل** `innodb-repeatable-read-single-transaction-v1` را دارد. تغییر این فایل پایه در QA برای هم‌ترازی انجام شده؛ **به بسته واقعی R275 اضافه نشده** تا منطق جدیدتر بازنویسی نگردد. اعتبار این قابلیت با آزمون مستقیم روی R275 احراز شد، نه ادعای اصلاح جدید.

## آزمون‌ها و مرز ادعا
| گیت | نتیجه | مدرک |
|---|---|---|
| ۱۹ مورد تشخیص سالم/ناسالم، Public خارجی، نصب تازه، خطای TLS، بازیابی و پیش‌بررسی امضاشده | PASS | `r279/tests/public_move_inspection_selftest.php` |
| نصب صفر-fuzz سه تغییر روی ۶۰۴ فایل ZIP دقیق Local 231fl + lint PHP/JS + حفظ Migrationها | PASS | [CI 44315959003](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44315959003) |
| تهیه و بازیابی فایل رمزگذاری‌شدهٔ Local با MariaDB 11.4، داده‌های سفارش/موجودی، منع مقصد غیـرخالی و MyISAM، منع Sync پس از restore تا reenroll | PASS | [همان CI 44315959003](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44315959003) |
| Public R276/35ik و Local R275 اصلاح‌شده با HTTP واقعی، نشست مدیر، رضایت مستقل Public، تأیید Local، بازنشر و سفارش مهمان exactly-once، root و /new-public | **PASS در هر دو job** | [CI 44315829189](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44315829189) |
| نصب روی هاست/Local واقعی کافه، پس‌خوانی صف/کلید و سفارش تست میدانی | **NOT RUN** | فاقد دسترسی مجاز عملیاتی |
| انتقال کامل کامپیوتر Local با Windows Services/PrintAgent/هویت مستقل ماشین، بازیابی و rebind عمومی | **NOT END-TO-END PROVEN** | نیازمند گیت جدید |
| تطبیق با بسته نهایی R277/R278 + کارخانه یکپارچه + SHA ZIP خروجی + مجوز بهره‌برداری | **NOT RUN / BLOCKING** | باید قبل از پیشنهاد نصب پاس شود |

## قرارداد رفتار انتهابه‌انتها
- فرم ساده URL → شناسایی فقط‌خواندنی → استعلام نصب و هویت؛ اگر ناسالم یا مبهم است تغییر نده؛ اگر Public نصب تازه/paired همین Local است رضایت Public و سپس مدیر Local.
- قبل از هر commit، Public destination unresolved guest work/lease و مالکیت باید کنترل شود؛ در Public قدیمی داده‌ای به‌زور حذف نشود، ۴۴۴۲ رویداد outbox Local پاک نشوند.
- Sync کامل Local → Public با media کامل و readback، سپس commit و فعال‌سازی سفارش مهمان؛ مرحله نصفه باید `safe_resume` یا `support_review` داشته باشد؛ هیچ auto-repair کورکورانه و retry کد مصرف‌شده مجاز نیست.
- URL/QR و PWA/Push حوزه‌های جداگانه برای پذیرش نهایی هستند؛ health 200 به‌تنهایی اتصال را ثابت نمی‌کند.
- برای Local-new-machine، بازیابی داده **هویت ماشین، PrintAgent و صف چاپ** را منتقل نمی‌کند. قبل از قطع Local قدیمی باید دستگاه جدید، سرویس‌ها، re-enrollment Public، چاپ و انحصار authority بررسی شود.

## گیت‌های بسته‌نشده و اولویت بعدی
**P0:** ادغام فقط روی سورس تجمیعی جدید و کارخانه انتشار؛ سناریوهای قطع شبکه قبل/بعد commit و مالکیت ابهام‌دار با اصل ZIP کاندید، readback مقصد واقعی، enforce صفر سفارش مهمان معلق، snapshot و restore روی مقصد.

**P0:** جابه‌جایی Local به Windows جدید همراه رمزنگاری بکاپ، هویت دستگاه تازه، خدمات Windows/PrintAgent و جلوگیری از دو Local فعال؛ تا اثبات، به‌عنوان سناریوی مستقل «کاملاً آماده» فروخته نشود.

**P1:** آزمون هم‌زمان تغییر Local و Public، انتقال PWA/Push/QR، دوره گذار نشانی قدیم، خطاهای فرم، timeout، popup بسته و idempotent resume.

**تصمیم انتشار:** `operator_install_allowed=false`، `production_authorized=false`، `field_cutover_proven=false`. `main/CURRENT`، دیتابیس، هاست و PC کافه تغییر نکردند. این ثبت **پایان گیت‌های قابل اجرا برای R279** است، نه گواه آمادگی جهانی انتقال.
