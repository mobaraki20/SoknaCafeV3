# SOKNA / R272 — ممیزی دقیق کد و فرایند انتقال Public موجود (Local ↔ Public)

**تاریخ:** ۲۰۲۶-۱۱-۱۷ | **دامنه:** بررسی فقط‌خواندنی / به‌روزرسانی دفتر تصمیم‌ها؛ هیچ نصب، تغییر DB مشتری، به‌روزرسانی main/CURRENT یا آزادسازی بسته انجام نشده است.  
**مرجع تصمیم:** `r251/decisions.json` و `r251/DECISIONS-FA.md` روی شاخه `qa/sokna-r272-public-move-endtoend-audit-20261117`.  
**نتیجه:** `NO-GO` برای R271 و ادامهٔ سناریوی اتصال با صفحه `/move/approve` تا پیاده‌سازی و آزمون همهٔ بخش‌ها. **سبز بودن CI R271 به‌تنهایی اثبات فرایند واقعی نیست.**

## ۱. شواهد واقعی و مبنای نسخه

| مدرک | وضعیت |
|---|---|
| Local نصب‌شده در کافه | `3.0.0-g6.2-workspace.231fg-r262-public-move-qa`، ۸۲ Migration، صفر معلق؛ طبق بسته پشتیبانی ۲۰۲۶-۱۱-۱۷ و تصاویر ۱۰:۵۰ ایران |
| تنظیمات Public در Local | `https://www.soknaspace.ir`، خطای `public_authority_resync_required` / 409، ۴۴۴۲ **رویداد** projection در انتظار، نه لزوماً ۴۴۴۲ سفارش |
| Public مقصد | `35ig / R266`، ۱۱/۱۱ Migration و دیتابیس آماده طبق Health خود کاربر؛ `https://www.soknagroup.ir/public`، هنوز اثبات نشده pairing فعال آن با **کدام** Local و چند سفارش باز است |
| صفحه تأیید مقصد | اسکرین‌شات `/public/move/approve#challenge=...` با پیام عمومی «تأیید انجام نشد؛ ورود مدیر یا اعتبار درخواست را بررسی کنید»؛ هیچ HTTP status یا error code مستقل در تصویر نیست |
| نسخه نمایش‌داده‌شده `35ht` در Local | بازماندهٔ شناسایی قبلی/وضعیت اتصال قبلی؛ اثبات نسخه مقصد جدید نیست |

**بسته‌های واقعی پین‌شده (SHA-256):**

- Local R262: `3d12ea375a307e58c8e36b2036a4fea9dec9ef05330f4a046234102c32164b8c`؛ ۶۰۴ فایل مدیریت‌شده.
- Public R266: `b6d4d2236ab3da126a4070d75802a9cac6719c139a188808d1712b8c1827bd35`؛ ۲۴۴ فایل برنامه.
- Local R271 QA: `a6a9e54baabe310cd62bfff76e681f735c9e1162da5a6c7eae512a9b59fd0e42`؛ روی کافه **نصب نشده**.

۱۷ کنترل فایل/کد در `SOKNA-R272-EXACT-SOURCE-AUDIT-ASSERTIONS.json` از همین سه ZIP اجرا شد؛ این فقط اعتبار **بازخوانی سورس** را نشان می‌دهد، نه آزمون HTTP واقعی یا وضعیت بانک اطلاعاتی میدانی.

## ۲. کالبدشکافی همهٔ گام‌های زنجیره و تناقض‌ها

| گام | فایل و رفتار واقعی | مشکل/نتیجه | الزامی برای حل |
|---|---|---|---|
| ۱. ورود به «اتصال‌ها» Local | Local R262 `public/integrations/index.php` کارت «انتقال وب عمومی» را با برچسب «Public جدید باید نصب تازه ...» نمایش می‌دهد. | UI فقط مسیر تازه را به‌صورت شاخص توضیح می‌دهد؛ استقرار مقصد موجود را از ابتدا روشن نمی‌کند. | یک ورودی ساده، تشخیص خودکار حالت مقصد. |
| ۲. کلیک «بررسی و انتقال» | Local R262 `public/assets/connections-workspace.js`: `public_move_inspect` → اگر `awaiting_owner`، فراخوانی `public_move_begin` و ساخت challenge در Local ledger → بازکردن `/move/approve`. | «بررسی» صرفاً مشاهده نیست؛ challenge ثبت شده است، اما هنوز اتصال جابه‌جا نشده. | state machine و تفکیک preflight صرفاً خواندنی از شروع عملیات و گزارش دقیق. |
| ۳. ورود به صفحه مالک مقصد | Public R266 `src/Http/PublicMoveConsentPage.php` و `assets/sokna-public-move.js`: challenge باید مختص همین base URL، معتبر در بازه کوتاه و دارای `window.opener` باشد. | refresh، popup بسته، challenge منقضی، URL canonical متفاوت یا محدودیت مرورگر می‌تواند شکست مستقل از رمز بدهد. | خطای تشخیص‌پذیر غیرحساس + ازسرگیری بدون ساخت عملیات موازی. |
| ۴. ورود مدیر Public | Public R266 `assets/sokna-public-move.js` به `/api/v1/owner/login` POST می‌کند. `PublicMoveOwnerHttpAdapter::handle` از حساب مستقل `public_move_owner_accounts` استفاده می‌کند. | رمز مدیر Local، رمز Emergency و رمز مستقل مالک Public سه شناسهٔ مختلف‌اند. ۱۱ Migration و `health ok` به معنی وجود/درستی حساب مالک نیست. کد مرورگر 401/403/503/500 را با **یک متن کلی** نشان می‌دهد. **علت دقیق پیام عکس معلوم نیست.** | تشخیص owner uninitialized، invalid credentials، lockout، origin/cookie/CSRF بدون نمایش جزئیات حساس یا درخواست تکرار رمز از کاربر. |
| ۵. فعال‌سازی مالک Public نصب قبلی | Public R266 `PublicHttpKernel::publicOwnerUpgradeAction` و `PublicMoveExistingOwnerActivation`: نیازمند نشست واقعی مدیر staff در **تنها installation فعال** Public است؛ حساب مالک با تأیید جداگانه ساخته می‌شود. | اگر Public به نصب قدیمی/دیگری متصل، چندنصبی یا بدون staff session معتبر باشد، فعال‌سازی نمی‌شود. OTP یا پاکسازی اطلاعات راه‌حل مجاز نیست. | مسیر احراز مالک نصب موجود با چند نصب/عدم staff login به‌صورت امن و صاحب مجوز، بدون dependency دوری. |
| ۶. تأیید مالک | Public R266 `PublicHttpKernel::publicOwnerAction('consent_sign')` رسید امضاشده می‌سازد؛ فقط اگر `PublicSetupService::status()['paired']===false` باشد `issueOwnerPairingCode()` صادر می‌کند. | برای Public **paired** مقدار `pairing_code` خالی است. | مالکیت و اتصال مجدد Public موجود باید با قرارداد مستقل و امضای واقعی انجام شود، نه Pair اولیه. |
| ۷. بازگشت به Local | Public R266 `assets/sokna-public-move.js` نبودن `pairing_code` را رد می‌کند؛ Local R262 `connections-workspace.js` در پیام `postMessage` نیز همین کد را با regex اجباری می‌داند؛ `public_move_confirm` فقط این مسیر را می‌پذیرد. | **بن‌بست قطعی حتی با ورود درست مالک** برای مقصد paired. | همان جریان UI بتواند رسید «مالکیت نصب موجود» و «اجازه بازیابی authority» را جداگانه قبول و اعتبارسنجی کند؛ بدون اجبار pairing code. |
| ۸. بازیابی قدیمی Local | Local R262 `PublicReenrollmentService::replacementPreflight` فقط `active` یا `reenroll_required` را می‌پذیرد؛ `resync_required` با کد `public_replacement_busy` رد می‌شود. | پیام «بازیابی قبلی هنوز در جریان» می‌تواند غلط‌انداز باشد؛ دفتر/Journal واقعی هنوز جداگانه باید بررسی شود. | classification باید phase و journal را جدا بررسی کند؛ عملیات غیرموجود «در جریان» نامیده نشود. |
| ۹. مسیر R271 | R271 `existingDestinationPreflight` از Health و GET `setup/api.php` نصب/paired/installation_id را می‌خواند. اگر ID یکی باشد `existing_paired`، اگر خارجی باشد fail-closed. | این اطلاعات **پیش‌بررسی هویت و سازگاری** است، نه تأیید مالکیت. اگر نصب مقصد به Local دیگری متصل بوده باشد، مسیر کامل مالکیت مستقل هنوز وجود ندارد. | تشخیص «same Local»، «other Local»، «ambiguous» و امضای قابل اعتبارِ مالک. |
| ۱۰. وضعیت سفارش مقصد در R271 | در بدنه `existingDestinationPreflight` هیچ query برای سفارش‌های فعال/معلق، deferred/realtime queue یا `PublicMoveReplacementGate` وجود ندارد. | MIG-018 به **صفر بودن سفارش فعال/معلق** پیش از cutover نیاز دارد؛ تعداد صفرِ Migrationها یا Health کافی نیست. | preflight تراکنشی مستقل روی مقصد و اثبات signed عدم سفارش باز؛ بدون پاککردن هیچ رکورد تاریخی. |
| ۱۱. R271 اجرا | R271 صفحه Local از مسئول می‌خواهد وارد `authority-recovery.php` شود و کد یک‌بارمصرف بگیرد؛ Local `moveExistingDestination` با همان کد، endpoint recovery را اجرا می‌کند. | دورزدن تجربه ساده و برگشت به workflow اضطراری مغایر MIG-002 و MIG-006، و جایگزین ناقص امضا/consent مدیر مستقل Public است. | ابزار Emergency فقط مسیر تخصصی پشتیبانی باشد؛ عملیات عادی باید در همان صفحه Public/Local با approval و token یک‌بارمصرف پشت‌صحنه انجام شود. |
| ۱۲. ترتیب تغییرات/همگام‌سازی R271 | در `PublicReenrollmentService::complete` پس از استفاده از کد، secret و URL جدید Local ثبت می‌شود. سپس `integrations/api.php::public_move_existing_execute` فراخوانی `publicEdgePublisher()->syncAll(10)` دارد. | **اول تغییر ارتباط، بعد اولین Sync**؛ بر خلاف الزام «حفظ اتصال قبلی تا موفقیت اولین Sync» و خلاف pipeline نصب تازه R262. | راهکار two-phase staged projection، readback کامل، commit پایدار و سپس فعال‌سازی با journal/resume. اگر قطع شد نه data loss و نه دو اتصال فعال. |
| ۱۳. اثر بازیابی بر مقصد | Public R266 `AuthorityEpochService::createOffer` سفارش‌گیری را متوقف می‌کند. `complete` برای نسل جدید داده‌های projection/session را خالی می‌کند و pending realtime/deferred را به حالت بررسی منتقل می‌کند. | بدون بررسی سفارش‌های واقعی می‌تواند رکوردهایی برای بررسی دستی برجای گذارد؛ هرگز نباید به‌عنوان «فقط تغییر آدرس» اجرا شود. | تعیین تکلیف امن اقلام باز/در انتظار و عدم پاکسازی تاریخچه، سپس sync و approval. |
| ۱۴. گیت پایان R271 | API R271 `guest_intake_ready` را فقط بر پایهٔ `sync.success` و `!degraded` محاسبه می‌کند؛ مسیر R262 برای نصب تازه علاوه بر این موارد `guest_media.complete`, `media_ready` و endpoint `move/activate` را کنترل می‌کرد. | مدل پایان عملیاتی در دو مسیر نابرابر است؛ تبلیغ «آماده» بدون اثبات دقیق تصاویر و دریافت سفارش ممکن است. | readback همه کانال‌ها، media کامل، فعال‌شدن intake و سفارش مهمان آزمایشی. |
| ۱۵. آزمون CI R271 | `r271/tests/existing_destination_test.php` یک closure برای `transport` تزریق می‌کند و پاسخ `/authority-recovery.php`, `/authority/status`, `/health`, `/setup/api.php` را شبیه‌سازی می‌کند. | نتیجه `PASS` معتبر **فقط برای منطق محدود سرویس** است؛ HTTP مالک/مرورگر/کوکی/CSRF/DB واقعی مقصد، bridge، callback و پنل Windows stage را آزمایش نمی‌کند. | اجرای browser + HTTP + MariaDB + Windows روی **همان خروجی تجمیعی دقیق**، نه فقط سرویس mock. |

## ۳. تصمیم‌های الزام‌آور، نقض و وضعیت

| تصمیم | الزام | وضعیت در این سناریو |
|---|---|---|
| MIG-001/010 | موتور برای همه مشتری‌ها، مسیر root/subfolder/هاست و نسخه‌های پشتیبانی‌شده | اصل ثبت‌شده؛ matrix کامل multi-tenant/real host هنوز گیت نشده |
| MIG-002/003 | کاربر فقط آدرس و تأیید؛ تشخیص خودکار نوع مقصد | **نقض در R262 و R271**؛ حالت فنی Emergency به کاربر واگذار شده |
| MIG-004/011 | runtime IDs, tenant boundary و کلید معتبر | R271 same-ID بررسی می‌کند؛ موارد multi-install/foreign هنوز ناقص |
| MIG-005 | journal، تراکنش و cutover بعد از readback/Sync | **نقض ترتیب R271**؛ session/remote پس از خطا قابل اثبات نیست |
| MIG-006 | بدون کد دستی Emergency/SQL/cPanel در مسیر عادی | **نقض R271** |
| MIG-007 | دفتر تصمیم با وضعیت و supersession به‌روز بماند | **انجام شد در شاخه ممیزی R272**؛ MIG-019/020 append شدند، تاریخچه حذف نشد |
| MIG-009/020 | اثبات end-to-end روی سورس/ZIP دقیق هر دو سمت | **ناکامل**؛ R271 فقط transport mock و MariaDB محدود |
| MIG-012/013/014/015/017 | مالک مستقل، receipt امضاشده، تأیید Local، auth/CSRF/nonce یک‌بارمصرف | برای نصب تازه اجزایی دارند؛ برای موجودِ cross-URL چرخه کامل اجرا نشده |
| MIG-018/019 | بدون انتقال دیتابیس قدیم؛ شرط صفر سفارش باز مقصد و Sync کامل قبل از intake | در R271 سفارش‌های باز و کنترل پایان کامل نشده |
| OLD-005/006/010 | قاطی‌کردن Pair/Rebind/Recovery یا اجبار Emergency/نصب تازه | همچنان مردود و نباید با workaround احیا شوند |

## ۴. داده‌های نامشخص که نمی‌توان از تصویر/Health نتیجه گرفت

- آیا حساب مستقل مالک Public در جدول `public_move_owner_accounts` برای `public_instance_id` فعلی ساخته شده یا نه؟
- پاسخ دقیق `/api/v1/owner/login` چند بوده و `code` غیرحساس آن چه بوده؟ (اسکرین‌شات فقط پیام عمومی دارد.)
- `setup.status` مقصد در همان لحظه کدام `installation_id`/`paired` داشته و آیا با شناسه Local کافه یکی بوده است؟
- آیا نصب مقصد سفارش فعال/درانتظار، `realtime_requests` و `deferred_work` نیازمند بررسی دارد؟
- آیا در Local، journal فعال برای `public_move_*` یا `public_recovery_pending` ثبت شده است؟ فایل Support قبل از آخرین کلیک ایجاد شده و این مرحله را پوشش نمی‌دهد.
- آیا Health و readback پس از همگام‌سازی آینده تمام مدل‌ها، تصاویر و سفارش آزمایشی را تأیید می‌کنند؟ هنوز Sync واقعی رخ نداده است.

**با وجود این ابهام‌ها، لازم نیست کاربر دوباره رمز یا فایل پشتیبانی بفرستد.** API پیش‌بررسی آینده باید خودِ اطلاعات لازم را با هویت معتبر و بدون نمایش secret جمع کند. اجرای دستی SQL یا رمزگشایی جداول مشتری به‌عنوان راه عادی مجاز نیست.

## ۵. مسیر استاندارد نهایی محصول، نه workaround کافه

**در Local تنها یک فرم:** آدرس مقصد و «بررسی و اتصال». سامانه به‌طور خودکار پنج حالت را تفکیک می‌کند: (A) نصب تازه و بی‌سفارش؛ (B) Public موجود متعلق به همین Local با URL جدید؛ (C) همان Public که فقط origin/path تغییر کرده؛ (D) Public متعلق به Local دیگر یا مالکیت مبهم؛ (E) مقصد ناسالم یا دارای سفارش فعال/معلق.

1. **Preflight فقط‌خواندنی و بدون ایجاد claim**: URL معتبر TLS، شناسه و امضای مقصد، نقش نصب، سلامت DB و migration، صفر بودن سفارش‌های باز مقصد و وضعیت journal.
2. **در صورت نیاز پنجرهٔ مالک Public مقصد**: ورود مستقل مالک/ارتقای مالک از نشست مدیر مجاز؛ challenge با CSRF و origin و nonce؛ تفاوت 401/403/409 به‌صورت امن قابل فهم است. هیچ pairing code از مسیر fresh برای Public paired مطالبه نشود.
3. **تأیید مدیر Local**: یک هشدار کوتاه درباره سفارش‌هایی که فقط در Public قدیم بوده‌اند؛ receipt و authorization، بدون ورود دستی secret/epoch/SQL.
4. **آماده‌سازی در مقصد و sync**: intake بسته، authority تازه فقط پس از approval، خروجی catalog/144 و media/36 *از داده واقعی نصب نه fixture*، staff/availability، projection cursors و ACK، با journal durable و idempotent resume.
5. **Commit و readback دوطرفه**: فعال‌کردن یک authority پس از Sync، Verify Local config + Public signed status، عدم دو Public فعال؛ تأیید سفارش مهمان آزمایشی دقیقاً یک‌بار در Local. تنها حالا نتیجه «متصل» و QR قدیمی قابل سوئیچ اعلام شود.
6. **در قطع/خطا**: قطع شدن مسیر، کار کافه را به بازیابی دستی/رمز اضطراری وارد نکند. UI فقط «ادامهٔ ایمن» یا «نیازمند بررسی پشتیبانی» با کد رهگیری نشان دهد. هیچ retry با کد مصرف‌شده یا ویرایش دیتابیس مجاز نیست.

## ۶. ماتریس پذیرش قبل از بسته نهایی

۱. Public fresh/unpaired؛ ۲. Public paired به همین Local و آدرس جدید؛ ۳. Public paired به Local دیگر؛ ۴. نصب multi-install؛ ۵. مقصد دارای سفارش مهمان باز/معلق؛ ۶. مالک مستقل تعریف‌نشده؛ ۷. رمز نادرست/قفل ورود؛ ۸. انقضا/بسته‌شدن popup/دو بار کلیک؛ ۹. فقدان Public قدیم؛ ۱۰. URL در root و subfolder؛ ۱۱. عدم هماهنگی migration/secret/epoch؛ ۱۲. قطع قبل/بعد از remote commit و ازسرگیری امن؛ ۱۳. انتشار منو/تصاویر کامل و ناقص؛ ۱۴. ثبت guest order پس از اتصال بدون duplicate؛ ۱۵. تغییر QR و origin/PWA؛ ۱۶. ارتقای مبدأ دقیق Local با Maintenance/Windows + تأیید source→ZIP byte-exact و بازیابی. **رد هر یک از سناریوهای ایمنی، مانع انتشار است.**

## ۷. اقدام‌ها و مالکیت

- **اصلاح سورس ضروری (هنوز انجام نشده):** unified automatic classifier، مستقل‌کردن owner approval موجود از pairing code اولیه، گیت سفارش‌های زنده، non-Emergency owner flow، two-phase sync-before-commit، signed health/readback، UX خطاها و idempotent resume.
- **گیت CI ضروری:** اجرای واقعی UI→Public owner HTTP→Local approval→DB/epoch→Media/Sync→order؛ شامل negative cases و source/zip/native Windows؛ CI سبز R271 جایگزین این نیست.
- **انتشار:** R271 با پرچم `QA_ONLY_NOT_OPERATOR_INSTALL` بماند؛ هیچ ZIP یا SQL برای سیستم کافه تا گیت واقعی معرفی نشود.
- **میدان:** Local 231fg و Public 35ig را بدون تغییر نگه دارید؛ صفحه فعلی `/move/approve` را تکرار نکنید؛ QR را هنوز جابه‌جا نکنید؛ رویدادهای pending را حذف نکنید.

**دفتر تصمیم:** MIG-019 و MIG-020 در `r251/decisions.json` و نسخه فارسی همان دفتر به تاریخ ۱۷ نوامبر append شده‌اند؛ هیچ تصمیم OLD-* بازفعال نشده است. شاخه QA مستقل است؛ main/CURRENT دست‌نخورده‌اند.
