# R132 — اصلاح حداقلی ایمنی ارتقای سکنا (QA ONLY)

تاریخ: ۷ نوامبر ۲۰۲۶
مبنا: شاخه `qa/sokna-r130-preflight-syntax-20261107`
وضعیت: **سورس QA؛ نه نصب‌کننده، نه نسخه مجاز کافه و نه تغییر main**.

## تغییرات واقعی
1. `ReleaseSetVerifierStager.cs`: هنگام Stage هر بسته Local (حتی از مسیر Operator Upload) باید برای نصب و Production مجاز و واجد شروط همان `ReleaseInstallQualificationGate` باشد. بسته‌های UAT/NOT_APPROVED پیش از ایجاد Stage و تغییر sequence رد می‌شوند؛ VerifyOnly همچنان برای تشخیص مجاز است.
2. `RoutineLocalDatabase.php`: با اتصال واقعی MariaDB، فقط SELECTهای آغازین مهاجرت‌های جدید را داخل تراکنش فقط‌خواندنی پیش‌بررسی می‌کند؛ نه SQL تغییر‌دهنده اجرا می‌شود و نه دفتر مهاجرت نوشته می‌شود. در خطای شناخته‌شده فقط ID مهاجرت، شماره statement، SQLSTATE و errno گزارش می‌شود.
3. `RoutineLocalDatabase.cs` و `ProductionLocalSlotRoutine.cs`: اجرای کنترل فوق قبل از توقف Apache و بار دوم بعد از تهیه بک‌آپ و قبل از `DATABASE_FORWARD_INTENT`.
4. `SupervisorLocalCodeOnlyPreExecution.cs`: همان گیت برای بررسی پیش‌اجرای کاندید اجرا می‌شود.
5. `release-gate.py`: وارسی آفلاین فایل ZIP نهایی و رد ناسازگاری Inventory/Hash/Manifest و بسته‌های غیرعملیاتی. خروجی ۰ هم مجوز نصب نیست.

## آزمون CI
- `test-source-contract.py`: ترتیب مراحل، منع UAT و خطای بدون افشای SQL.
- `test-preflight-mariadb.sh`: MariaDB 11.4 واقعیِ آزمایشی؛ رد SELECT محافظ، تأیید no-op، تغییر شرط بین preflight و apply، ثبت RUNNING، مسدود ماندن تلاش تازه، Resume پس از اصلاح و تکرار بی‌اثر.
- `test-release-gate.py`: سه ZIP ساختگی (inventory ناقص، UAT، و دارای فیلدهای تأیید آفلاین بدون مجوز deploy).
- `sokna-r132-maintenance-hardening-qa.yml`: ساخت .NET 8 روی Windows، PHP syntax و MariaDB در GitHub.

CI موفق: https://github.com/mobaraki20/SoknaCafeV3/actions/runs/42399134475

## قیود حل‌نشده و اجبار قبل از Production
- این preflight صرفاً SELECTهای ابتدای SQL را اجرا می‌کند. هر مهاجرت با شرط پس از DDL/DML یا وابسته به جدول هنوز ایجادنشده، به بازبینی خاص و آزمون واقعی نیاز دارد؛ نمی‌توان آن را پوشش عمومی تمام مهاجرت‌ها معرفی کرد.
- Restore واقعی بک‌آپ، قطع/راه‌اندازی Apache و Recovery Console در چرخه کامل Windows/MariaDB با شکست تزریق‌شده، هنوز در این تغییر آزموده نشده‌اند.
- مسیر forward recovery بعد از DB عمداً fail-closed باقی مانده؛ بازگشت خودکار به schema قدیمی یا بازکردن کورکورانه قفل اضافه نشده است.
- سیاست آپلود بدون امضای ناشر برای منشأ بسته همچنان در کد وجود دارد؛ تفاوت آن با تأیید تولید اکنون در Stage اعمال می‌شود. برای عرضه عمومی به گیت امضا/منشأ اختصاصی نیاز است.
- انتشار نصب‌کننده Maintenance و بسته به‌روزرسانی Local/Public، آزمون نسخه دقیق Public کافه و تأیید هویت/بک‌آپ هنوز انجام نشده است.
- بسته‌های R125/R126/R130 به‌خودی‌خود تأیید نصب نشده‌اند؛ R112 کافه دست‌نخورده بماند.
- ساخت نهایی باید `release-gate.py` را به artifact نهایی و نه فقط سورس یا ZIP اولیه اعمال کند.

هیچ رمز یا داده واقعی کارکنان و فروش در این شاخه QA قرار داده نشده است.
