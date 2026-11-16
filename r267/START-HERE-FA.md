# سامانه سکنا | R267 — اصلاح شکست ارتقای پابلیک از Emergency

**چک‌پوینت: ۲۰۲۶-۱۱-۱۷ | وضعیت: QA PASS / HOST PRODUCTION = NOT QUALIFIED**

## ۱. حادثه واقعی، بدون بازنویسی تاریخچه

- **Local کافه:** کاربر تأیید کرده که بسته R262 نصب شده؛ هدف `3.0.0-g6.2-workspace.231fg-r262-public-move-qa` با ۸۲ مهاجرت است. readback پس از نصب محلی هنوز در همین گزارش مستقل موجود نیست؛ نسخه را مجدداً از Support v2 استخراج کنید، نه از حدس.
- **Public عملیاتی در SoknaGroup:** `https://www.soknagroup.ir/public`، نسخه `3.0.0-g3.6-workspace.35ic-r249-cumulative-forward-gate` با ۸ مهاجرت اعمال‌شده، ۰ معلق، Emergency مستقل نسل `35dx`، پایگاه داده آماده.
- **Public قدیمی:** `https://www.soknaspace.ir`؛ از جمله نصب R233/35hz و خطای قدیمی `authority_epoch_mismatch`. مسیر QR چاپی قبلی `/Menu/` و هدایت آن باید جداگانه محافظت شوند.
- **بسته ردشده:** R262 Public / `35id` (هش `5aef4cd781fbd450d3e454f324e294da6f4180bfe1e5eb1871cb4818dc20c93a`) روی Emergency جدید بارگذاری و Stage شد، اما Activate با `emergency_schema_mutation_not_qualified` رد شد. اسنپ‌شات ارسالی نشان داد: کد روی دیسک همچنان R249، `pending_verification=null`، `activation_journal=null`، `db_forward_fence=null`، `staged` مربوط به R262 با SHA یکسان و بدون تغییر داده.
- **علت از سورس R249:** `IndependentEmergencyRoutineBridge::requireCodeOnlyStagedChange` هر بسته دارای تغییر `database/migrations/` را رد می‌کند. R262 سه SQL جدید `0009` تا `0011` دارد. قبولی CI نصب Routine هرگز به معنی قبولی Emergency نبوده است.

## ۲. تصمیم‌های الزام‌آور پیشین

- **MIG-001..MIG-011:** موتور مستقل از مشتری/دامنه/مسیر/مبدأ؛ UI ساده؛ تصمیم‌گیری خودکار نوع اتصال؛ هویت و کلید per-installation؛ preflight پیش از mutation؛ عملیات پایدار و تکرارایمن؛ هیچ SQL یا cPanel در مسیر عادی؛ تست چندسناریویی پیش از انتشار.
- **MIG-017:** مالک Public موجود فقط با مدیر مجاز و تأیید مستقل Local فعال شود. Receipt به‌تنهایی مجوز تغییر اتصال نیست.
- **MIG-018 (مرجع اصلی):** Local مرجع اطلاعات است؛ مقصد Public برای عملیات *جایگزینی مستقل* باید فاقد سفارش فعال یا معلق باشد؛ پابلیک قدیم می‌تواند در دسترس نباشد؛ DB/تصاویر/سفارش‌های منحصراً Public قدیمی منتقل نمی‌شود؛ سفارش‌های Local حفظ؛ Sync عادی منو/تصویر/مجوزها پس از تأیید دوطرفه؛ سفارش‌گیری مقصد تا health/sync کامل باز نشود.
- **SOKNA-REL-GATE-001 / 04-RELEASE-SAFETY-GATES:** برای هر انتشار Public یک ZIP فیزیکی یکسان در Routine و Emergency، با تطبیق Source→Payload، نسخه/هش دقیق مبدأ، مهاجرت append-only، حفاظت از فایل‌های owner/recovery و آزمون Restore.
- **منسوخ/ممنوع:** hardcode کردن نام کافه، دامنه `soknagroup.ir`، QR `soknaspace.ir/Menu`، نسخه R241/R249؛ اشتباه گرفتن rebind با جابه‌جایی به نصب مستقل؛ حذف DB یا دستکاری epoch و secret دستی.

## ۳. اصلاح فنی پیاده‌سازی‌شده در شاخه QA

- **پل اضطراری ارتقای سازگاری R265 / 35if:** بسته بدون تغییر ساختار پایگاه داده، بر پایه سورس بایتی R249 و ۸ مهاجرت همانند قدیم. فقط قرارداد Bridge را به کنترل دقیق بسته SQLدار ارتقا می‌دهد، با حفظ Owner و Recovery محافظت‌شده.
- **ارتقای اصلی R266 / 35ig:** مبنای تجمیعی واقعی R262، سه migration جدید 0009..0011، `RoutinePublicUpdateService` با state پایدار، `ResumablePublicSchema` با SQL های SHA-pinned، حفظ ترتیب preflight→stage→activate→commit، lock و DB-forward fence.
- **چرایی دو مرحله:** Emergency قدیمی بسته SQLدار را قبل از فعال شدن کد جدید می‌بندد. نیاز به *یک* پل بدون SQL برای نصب‌های قدیمی است. این یک استثنای بازیابی سازگار است و نباید به طراحی دائمیِ دو بسته Public تبدیل شود. بعد از قابلیت جدید، ZIP معمول Public باید روی هر دو مسیر Routine و Emergency با همان هویت و بایت کنترل شود.
- **رفتار خرابی:** خطا پس از اعمال بخشی از DDL → ثبت fence و حفظ حالت pending؛ فقط Resume رو‌به‌جلو پس از بررسی شِما مجاز؛ Rollback به کد پیشامهاجرت یا حذف فایل state ممنوع.
- **پیشگیری:** `r267/gates/check_public_emergency_schema_route.py` قبل از *انتشار* بسته اختلاف migrations نسخه فعلی و مقصد را می‌سنجد، توانایی schema در Emergency را از Snapshot واقعی درخواست می‌کند و در نبود capability دقیقاً با `emergency_schema_mutation_not_qualified` بلوکه می‌کند. Test پنج‌سناریویی در workflow شاخه QA الزامی شده است. این gate **هرگز** به‌تنهایی اجازه نصب نمی‌دهد.

## ۴. شواهد

- R249، R262 و R263 از آرشیو SHA-pinned گرفته شده‌اند، نه `main` قدیمی.
- SHA R265: `692aa40939d788aea56efa4e3b62c46a568da0ce5c508cc87844aa63366b8892`، ۲۲۸ فایل مدیریت‌شده، ۸ migration.
- SHA R266: `b6d4d2236ab3da126a4070d75802a9cac6719c139a188808d1712b8c1827bd35`، ۲۴۴ فایل مدیریت‌شده، ۱۱ migration.
- Windows/Local، Print Agent، `main`، `CURRENT` و سایت واقعی کافه در این اصلاح دست نخورده‌اند.
- GitHub run 43969473960: lifecycle direct bridge با failure/resume `PASS`، MariaDB 11.4 ارتقای ۸→۱۱ با حفظ ردیف سابق، replay پس از DDL و رد شِمای ناسازگار `PASS`.
- GitHub run جدید دارای release-route guard: وضعیت آن را جداگانه راستی‌آزمایی کنید.

## ۵. گیت‌های باز پیش از نصب عملیاتی

1. بکاپ **هم فایل/پیکربندی/کلیدهای دستگاه پابلیک و هم دیتابیس** با *آزمون بازیابی*؛ نه صرفاً وجود فایل zip.
2. read-only health/ledger Public و نبود active/pending order یا اتصال مانع جایگزینی مستقل. روی Public موجود، **MIG-017 و MIG-018** را صریح اعمال کنید؛ وصل کردن با URL تنها مجاز نیست.
3. آزمون actual HTTP Emergency console + مالکیت/مجوز/CSRF/timeout روی هاستی مشابه، نه فقط direct class؛ آزمون عملیات واقعی Stage→Activate→Commit و خوانش دقیق `8→8`، `8→11` و هش فایل‌ها.
4. تطبیق `emergency-next.php` فعال، `emergency_owner` و protected runtime و config مشتری با بسته‌های آزموده‌شده. عدم قطع دسترسی مالک در restore.
5. پس از R265 تنها وقتی health `35if` و migrations 8 و state بدون pending/fence شد وارد R266 شوید؛ پس از R266 باید `35ig`، migrations 11، pending 0، DB ready و verified current/active برابری کنند.
6. فقط بعد از آن اتصال با «اتصال‌ها ← وب عمومی» و تأیید مالک Public/Local انجام شود؛ پس از sync منو/تصویر و موفقیت آزمون سفارش مهمان و Local، QR قدیمی را روی مسیر جدید هدایت کنید.

## ۶. دستور ایمنی برای اپراتور کافه اکنون

- فایل R262/35id **Stage** شده ولی فعال نیست؛ دوباره Activate نزنید.
- ZIP های R265/R266 در این چک‌پوینت `QA ONLY / NO HOST DEPLOY` هستند. در Emergency آنها را **فعلاً Activate نکنید**؛ مجوز Production هنوز نیازمند گیت‌های بند ۵ است.
- Public موجود را پاک یا Clean Install نکنید؛ حذف جدول، اجرای دستی SQL، تغییر فایل روی هاست و دستکاری Secret/Epoch ممنوع.
- اگر نسخه یا هش فعال با مبنای درج‌شده تفاوت داشت، عملیات را متوقف کنید. هیچ نسخه‌ای را با تغییر نام به صورت دستی واجد شرایط نکنید.

**مرجع ادامه:** شاخه `qa/sokna-r267-emergency-schema-forward-gate-20261117`، فایل‌های `r267/...`، دستور MIG-018، و Library همین پوشه.
