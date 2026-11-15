# قرارداد هماهنگی Print Agent مستقل با Windows Services — R237 QA

وضعیت: **DESIGN / QA ONLY — PRODUCTION BLOCKED**. سند اجرایی برای نسخه 6.2.12، بدون نصب و تغییر سرویس.

## شواهد مرجع
- Maintenance مستقل 0.3.26 دو فایل Supervisor/Engine را تغییر می‌داد و Recovery را حفظ می‌کرد؛ ولی اعتبارسنج قدیمی فقط رسید سه‌جزئی `maintenance-components-v2/active.json` را قبول داشت. ناسازگاری رسید مستقل `maintenance-independent-pair-v1/active.json` منجر به R125_IDENTITY_EVIDENCE_FILE_UNSAFE شد؛ اصلاح 0.3.27 هر دو نسل رسید را با هویت بایت‌دقیق کنترل کرد.
- در `packaging/windows/windows-services-component-manifest-v2.json` مؤلفه print-agent دارای service_name=`SoknaPrintWorker` و installed_primary=`PrintAgent/Service/Sokna.PrintAgent.Service.exe` است، اما نسخه بسته مشترک هنوز 6.2.10 است؛ بدون شواهد پذیرش هویت مستقل، نمایش این نسخه نباید جایگزین نسخه واقعی نصب‌شده یا موجب بازنویسی سرویس شود.
- گزارش فقط‌خواندنی سیستم کافه ۱۵ نوامبر: نسخه نصب‌شده 6.2.11، Manifest معتبر و هش همه فایل‌ها مطابق. این فقط **یک fixture** برای آزمون چندمبدأیی است و نسخه 6.2.11 نباید مبدأ اجباری و انحصاری شود.

## قرارداد الزام‌آور پیش از انتشار مستقل EXE
1. **مالکیت واحد و دامنه محدود:** فقط مسیر `%ProgramFiles%\SOKNA Windows Services\PrintAgent` و سرویس `SoknaPrintWorker` قابل تغییر است. نصب‌کننده مستقل نباید Windows Services setup، Runtime، Maintenance، Recovery، Local، Public یا پایگاه داده را تغییر دهد. نسخه مشترک و `main/CURRENT` ثابت بماند.
2. **سازگاری دوطرفه:** پس از ارتقا، یک رسید مستقل نسخه‌دار در زیر `%ProgramData%\SOKNA\setup\print-agent-independent-v1\active.json` با شناسه تراکنش، old/new version و SHA/size هر فایل Service/Worker، image path مالک، preimage، outcome و rollback evidence ثبت شود. اعتبارسنج Windows Services باید **هم** receipt قدیمی خود را (بدون پاک‌کردن تاریخچه) **و هم** این receipt جدید را با تطبیق فایل واقعی و سرویس بشناسد. مسیر جدید صرفاً *پیشنهاد قرارداد* است؛ تا زمانی که consumer آن پیاده و آزمایش نشده، انتشار ممنوع.
3. **نگهداشت هویت در دو مسیر:** مدل وضعیت Windows Services باید نسخه فعال Service و Worker و هویت managed component را از روی فایل و receipt معتبر بخواند؛ صرف شماره VersionInfo یا `component-manifest-v2` قدیمی برای KEEP/UPDATE/REPAIR کافی نیست. نسخه بالاتر ناشناخته یا owner ناشناس BLOCKED و بدون تغییر بماند.
4. **قفل هماهنگ عملیات:** پیش از هر تغییر سرویس Print Agent همان قفل سراسری OS استفاده‌شده توسط Maintenance مستقل (`machine-mutation.lock`) و وضعیت transactionهای همزمان Windows Services بررسی شود. عملیات فعال/نامعلوم مالک دیگر باید fail-closed شود؛ رکوردهای تاریخی Local که به Print مربوط نیستند نباید خودبه‌خود مانع نصب مستقل باشند و هرگز به‌خاطر نصب Print پاک نشوند.
5. **داده پایدار مقدس:** `%ProgramData%\Sokna\PrintAgent` (queue.db، config، secret، receipts و reconciliation) قابل پاک‌سازی نیست؛ پیش‌بررسی باید وضعیت انجام‌نشده/درحال‌چاپ و نامعلوم را بررسی کند و از چاپ تکراری در حین توقف سرویس جلوگیری کند. اگر نتوان ایمنی را اثبات کرد، نصب متوقف شود.
6. **تراکنش و rollback:** snapshot رجیستری سرویس، استارت‌تایپ، owner، فایل‌ها و hashها؛ backup بایت‌دقیق قبل از توقف؛ staged replacement از جای امن؛ readback/health واقعی؛ rollback باینری و سرویس با حفظ صف و شواهد در هر شکست. خطای rollback باید صریح گزارش شود، نه SUCCESS.
7. **نصب تمیز و یونیورسال:** یک EXE مستقل برای clean install و ورژن‌های مختلف تحت پشتیبانی، با آزمون واقعی ویندوز. هیچ وابستگی به مبدا 6.2.11 یا رایانه کافه نباشد؛ مبدأ بدون lineage معتبر fail-closed شود. تکرار نصب باید idempotent و downgrade مسدود باشد.
8. **آزمون پذیرش:** Win64 clean install، 6.2.10/6.2.11/6.2.12 و چند نسخه تاریخی مجاز، مسیر unknown/foreign، صف با job نیمه‌تمام، قطع برق یا قفل فایل، rollback و حفظ PID/هش Runtime و Maintenance. مرحله Windows Services consumer قرارداد receipt باید با همان باینری و سورس نسخه‌بندی‌شده آزمایش شود، نه با JSON فرضی.

## وضعیت فعلی R237
- پیش‌بررسی چندمبدأیی بدون mutation، SHA باینری 6.2.12 و شش سناریوی synthetic روی Windows PASS هستند.
- **فعلاً consumer سازگار با receipt مستقل، نصاب EXE و آزمون تغییر واقعی سرویس/صف/rollback وجود ندارد.** `Apply` در R237 عمداً BLOCKED است و نباید باز شود تا موارد 2، 4، 5، 6 و 8 در CI واقعی پاس شوند.
- این قرارداد با سابقه Maintenance تطبیق داده شده اما قابلیت عملیاتی جدیدی را اثبات نمی‌کند.
