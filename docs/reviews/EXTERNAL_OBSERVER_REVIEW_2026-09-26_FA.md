# بازبینی ناظر بیرونی — نقطه کنترل مهاجرت V3

تاریخ ثبت: 2026-09-26

نقش ثبت‌کننده: **ناظر فنی بیرونی، بدون مالکیت معماری یا اختیار تغییر محصول**

وضعیت: **OPEN — نیازمند بررسی و تعیین تکلیف توسط Agent مالک مهاجرت**

## 1. محدوده و Snapshot بررسی

- ریپوی مقصد: `mobaraki20/SoknaCafeV3`
- شاخه بررسی‌شده: `architecture/v3-foundation`
- HEAD بررسی‌شده: `56da377a40d2fac00dc01a70f337ddede17d2f3d`
- PR فعال در زمان بررسی: `#1`
- baseline تاریخی: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
  (`work/reconcile-dev39`)

این گزارش هیچ کد یا قراردادی را تغییر نمی‌دهد و هیچ finding آن به‌تنهایی مجوز
تغییر رفتار نیست. هدف آن جلوگیری از ادامه مهاجرت روی یک فرض بررسی‌نشده است.

## 2. قید محصول که این بازبینی بر اساس آن ثبت شده است

قید اعلام‌شده برای مهاجرت:

> هدف اصلی، تغییر معماری، جداسازی لایه‌ها و تعیین مالکیت صحیح componentها است.
> رفتارها و فرایندهای موجود نباید تغییر کنند، مگر آنکه تغییر مشخصی صریحاً در
> Handover/اسناد مورد تأیید درخواست شده باشد.

بنابراین «متفاوت بودن با legacy» به‌تنهایی نه اثبات regression است و نه اثبات یک
تصمیم درست V3. برای نمونه، درخواست صریح **Table Draft** می‌تواند به‌طور مشروع روی
مدل دامنه، سرویس‌ها، persistence، permission و contract اثر بگذارد. Agent مالک
مهاجرت باید اثر لازم آن درخواست را طراحی کند؛ ناظر بیرونی نباید از روی نام
`quick_order` یا `orders.table_draft` به‌تنهایی درباره درستی طراحی حکم بدهد.

قاعده تصمیم:

1. اگر تفاوت، پشتوانه صریح در Handover/ADR/تصمیم مالک محصول دارد، آن را به‌عنوان
   تغییر مورد انتظار ثبت و با تست اثبات کن.
2. اگر رفتار قدیمی باید حفظ شود و هیچ مجوز صریحی برای تغییر وجود ندارد، تفاوت را
   regression مهاجرتی در نظر بگیر.
3. اگر شواهد کافی نیست، implementation را حدس نزن؛ وضعیت را `OPEN_DECISION` ثبت کن
   و پیش از ساخت consumer/producer وابسته تعیین تکلیف کن.

## 3. روش الزامی تعیین تکلیف هر Finding

برای بستن هر مورد این گزارش، Agent مالک مهاجرت باید این زنجیره را ثبت کند:

| مرحله | مدرک موردنیاز |
|---|---|
| درخواست | فایل و بخش دقیق Handover/ADR یا تصمیم ثبت‌شده مالک محصول |
| رفتار مبنا | فایل/تابع/تست در baseline دقیق `a46435c...` |
| طراحی V3 | owner، contract و data owner جدید |
| اثر | اثر روی کاربر، API، داده، permission، retry و rolling upgrade |
| نتیجه | `PRESERVED`، `APPROVED_CHANGE`، `REGRESSION_FIXED` یا `OPEN_DECISION` |
| اثبات | تست مستقل، fixture و در صورت نیاز migration/compatibility adapter |

نباید صرفاً به‌خاطر این گزارش، نام capability یا پاسخ API عوض شود. ابتدا باید نسبت
آن با درخواست‌های Handover مشخص شود.

---

## EOR-01 — تطبیق Operation و Capabilityهای Relay

**اولویت بررسی:** P0، پیش از پیاده‌سازی consumer/producerهای M3

**حکم ناظر:** اختلاف مستند شده؛ علت آن هنوز باید توسط مالک مهاجرت تعیین شود.

### مشاهده

در قرارداد Realtime فعلی نگاشت‌هایی از این جنس وجود دارد:

| operation در V3 | capability درج‌شده در V3 | capability مورد استفاده baseline |
|---|---|---|
| `guest_order.submit` | `guest_orders` | `guest.order.submit` |
| `waiter_call.create` | `guest_waiter_call` | `guest.waiter_call.create` |
| `order.edit` | `order_edit` | `orders.mutate` |
| `settlement.commit` | `settlement` | `finance.settle` |
| `preparation.mutate` | `preparation` | `preparation.mutate` |
| `table_draft.*` | `quick_order` | `orders.table_draft` |

در Deferred نیز قرارداد V3 از نام‌هایی مانند `supply_need_create` استفاده می‌کند،
درحالی‌که projection مبنا نام‌هایی مانند `supply.need.defer` دارد.

شواهد V3:

- `contracts/local-public-realtime/wire-v1.json`
- `contracts/local-public-deferred/wire-v1.json`
- `tests/relay-wire-contract.py`

شواهد baseline:

- `public_edge/bootstrap.php`
- `includes/relay_projection.php`

### چرا نیازمند بررسی است؟

اگر تغییر نام بخشی از یک درخواست تأییدشده، بازطراحی permission یا نیاز Table Draft
باشد، ممکن است درست باشد؛ اما باید migration داده، projection، adapter و تست‌های
producer/consumer آن را اثبات کنند. اگر چنین تصمیمی وجود ندارد، یک کاربر دارای
`orders.mutate` ممکن است در V3 با بررسی `order_edit` به‌اشتباه forbidden شود.

### شرط بستن

- برای تمام operationها جدول `legacy capability -> V3 capability -> approval source`
  تکمیل شود.
- موارد بدون تغییر تأییدشده، رفتار و کلید مبنا را حفظ کنند.
- موارد تأییدشده migration/compatibility روشن داشته باشند.
- تست از fixture مستقل baseline استفاده کند، نه از تکرار مقدار قرارداد V3.

---

## EOR-02 — Taxonomy و HTTP Status خطاهای HMAC

**اولویت بررسی:** P0، پیش از اتصال واقعی Local و Public در M3

**حکم ناظر:** اختلاف مستند شده؛ تغییر عمدی بودن آن اثبات نشده است.

### مشاهده

قرارداد V3 این مقادیر را مجاز می‌داند:

- `400 installation_required`
- `401 unknown_installation`
- `401 invalid_signature`
- `401 bad_signature`
- `409 replay_detected`

رفتار مشاهده‌شده در baseline:

- installation گمشده/ناشناخته: `401 unknown_installation`
- timestamp، nonce یا signature نامعتبر: `401 bad_signature`
- nonce تکراری: `409 replay_detected`

شواهد:

- V3: `contracts/local-public-realtime/wire-v1.json`
- V3 gate: `tests/relay-wire-contract.py`
- baseline: `public_edge/bootstrap.php`، تابع verification درخواست signed Local

### چرا نیازمند بررسی است؟

تغییر فقط متن نیست؛ `400` و `401` می‌توانند retry، log، UI و تشخیص نصب/امنیت را
متفاوت کنند. اگر نسخه جدید API عمداً taxonomy جدیدی دارد، باید versioning و
compatibility آن ثبت شود. در غیر این صورت باید پاسخ observable قبلی حفظ شود.

### شرط بستن

- برای هر شاخه خطا، status و body مورد انتظار با تست request/response واقعی ثبت شود.
- هر تغییر عمدی به ADR/approval و compatibility policy متصل شود.
- catch عمومی خطای DB به‌عنوان `replay_detected` جداگانه بررسی شود تا خطای زیرساخت
  به‌اشتباه replay گزارش نشود، بدون اینکه سازگاری ناخواسته شکسته شود.

---

## EOR-03 — استقلال تست‌های Contract از سندی که تست می‌کنند

**اولویت بررسی:** P0، پیش از اتکا به M1 به‌عنوان contract extraction کامل

**حکم ناظر:** ضعف evidence؛ مستقل از اینکه EOR-01 و EOR-02 عمدی باشند یا نه.

### مشاهده

`tests/relay-wire-contract.py` بخشی از capabilityها و taxonomy خطا را به‌صورت
hard-coded با JSON فعلی مقایسه می‌کند. در نتیجه اگر هنگام استخراج، همان مقدار
اشتباه هم در JSON و هم در تست وارد شده باشد، CI همچنان سبز می‌شود.

operation schema/vectorهای فعلی نیز تمام مسیرهای اعلام‌شده، از جمله enqueue/list،
auth projection و capability enforcement واقعی را به‌صورت producer/consumer اجرا
نمی‌کنند.

### شرط بستن

- یک fixture طلایی و immutable با provenance دقیق از baseline ساخته شود؛ یا ابزار
  extraction قابل تکرار از SHA ثابت وجود داشته باشد.
- تست‌های contract، validator و حداقل producer/consumer adapter واقعی را اجرا کنند.
- مسیرهای `remote_enqueue` و `remote_list` و auth/capability flow پوشش داده شوند.

---

## EOR-04 — یکسان‌سازی نقطه ادامه و وضعیت M1/M2

**اولویت بررسی:** P0، قبل از شروع مجدد Agent

**حکم ناظر:** drift مستندات؛ تصمیم محصولی نیست.

### مشاهده

- `docs/migration/M1_CONTRACT_EXTRACTION_STATUS_FA.md`، M1 را COMPLETE و M2 را قدم
  بعدی معرفی می‌کند.
- `docs/migration/M2_LOCAL_CORE_AUDIT_FA.md`، M2 را COMPLETE و M3 را قدم بعدی
  معرفی می‌کند.
- `README.md` هنوز M1 را in progress معرفی می‌کند.
- `docs/migration/MIGRATION_SLICES.md` هنوز M2 را next slice نشان می‌دهد.
- `MIGRATION_MATRIX.csv` شواهد اجرای M2 را در وضعیت‌ها منعکس نکرده است.

### شرط بستن

یک continuation point واحد شامل آخرین SHA معتبر، آخرین CI run معتبر، slice کامل،
slice بعدی و موارد باز نوشته شود و تمام entrypointها به آن ارجاع دهند. تاریخچه
گزارش‌ها می‌تواند immutable بماند، ولی باید banner واضح superseded/current داشته
باشد.

---

## EOR-05 — بررسی برابری رفتاری Local Core در M2

**اولویت بررسی:** P1، پیش از freeze نهایی M2 و وابسته شدن domain sliceها

**حکم ناظر:** candidateهای parity؛ هر مورد باید با Handover بررسی شود.

### موارد مشاهده‌شده

1. baseline در failure دریافت capability و preparation area به‌صورت fail-closed
   مقدار خالی برمی‌گرداند؛ repository فعلی V3 exception را عبور می‌دهد.
2. ترتیب preparation area در baseline عملاً `kitchen, bar` است؛ V3 ترتیب الفبایی
   `bar, kitchen` می‌دهد.
3. baseline correlation ID ورودی HTTP را دریافت و response header را تنظیم می‌کند؛
   V3 فعلی فقط candidate دستی می‌پذیرد.
4. fallback log در V3 correlation ID را داخل پیام تضمین نمی‌کند.
5. baseline session cookie path را از mount path نصب محدود می‌کند؛ default فعلی V3
   برابر `/` است.
6. نگه‌داشتن user cache هنگام failure دیتابیس می‌تواند کاربر disable‌شده را موقتاً
   فعال نگه دارد و در مسیر فعلی event/grace-window صریحی ندارد.

شواهد V3:

- `apps/local-web/src/Core/PdoIdentityRepository.php`
- `apps/local-web/src/Core/Observability.php`
- `apps/local-web/src/Core/Session.php`
- `apps/local-web/src/Core/Auth.php`

شواهد baseline:

- `includes/functions.php`
- `includes/observability.php`
- `bootstrap.php`

### شرط بستن

برای هر مورد مشخص شود رفتار قبلی باید حفظ شود یا تغییر صریح تأییدشده وجود دارد؛ سپس
happy path و failure path هر دو تست شوند. این گزارش نسخه مطلوب رفتار را به‌جای
مالک مهاجرت تعیین نمی‌کند.

---

## EOR-06 — تست Upgrade روی دیتابیس موجود

**اولویت بررسی:** P1، لازم پیش از ادعای پذیرش migration/upgrade؛ مانع طراحی اولیه M3 نیست

**حکم ناظر:** پوشش فعلی clean-install است، نه upgrade کامل.

### مشاهده مثبت

CI فعلی lint و self-testهای Local/Auth/Migration و اجرای migration روی MariaDB واقعی
و rerun آن را با موفقیت اجرا کرده است.

### فاصله باقی‌مانده

- upgrade از fixture واقعی dev39 با داده؛
- حفظ user/capability/audit data؛
- recovery پس از partial DDL failure؛
- concurrent migration runner؛
- schema ناسازگار از قبل موجود؛
- ماتریس نسخه‌های پشتیبانی‌شده PHP/MariaDB.

### شرط بستن

یک fixture sanitized از schema/data مبنا و تست upgrade + rollback/recovery اضافه شود.

---

## EOR-07 — تصمیم امنیتی Auth Projection پیش از M3

**اولویت بررسی:** P0، پیش از مهاجرت login عمومی

**حکم ناظر:** تصمیم باز؛ legacy نباید بدون audit کپی شود.

### مشاهده

baseline، `password_hash` را در auth projection سمت Public ذخیره می‌کند و login
Public همان‌جا `password_verify` انجام می‌دهد. در اسناد فعلی V3، «minimal auth
projection» آمده ولی تصمیم صریح درباره credential strategy، throttling، revocation
و audit پیدا نشد.

شواهد baseline:

- `includes/relay_projection.php`
- `public_edge/api/v1/local/projection_sync.php`
- `public_edge/api/v1/auth/login.php`

### شرط بستن

قبل از پیاده‌سازی، ADR امنیتی مشخص کند که رفتار login برای کاربر چگونه حفظ می‌شود
ولی credential و abuse-control در معماری جدید چگونه مالکیت و حفاظت می‌شوند. این
یافته درخواست تغییر رفتار login نیست.

---

## EOR-08 — Reproducibility و Governance در CI

**اولویت بررسی:** P2 پیش از merge/release پایدار

**حکم ناظر:** ریسک فرایندی، نه نقص قابلیت محصول.

### مشاهده

- branch protection فعال نیست و green checks اجباری نیستند.
- PR در زمان بررسی review نداشت.
- commitهای بررسی‌شده امضای قابل تأیید نداشتند.
- workflow از `ubuntu-latest`، tag شناور MariaDB و runtime PHP غیرصریح استفاده می‌کند.
- GitHub برای `actions/checkout@v4` هشدار Node.js 20 و برای مهاجرت آینده runner هشدار
  ثبت کرده است.

### شرط بستن

نسخه‌های runner/PHP/MariaDB/action policy صریح و branch protection با required
checks/review مطابق فرایند پروژه فعال شود. این مورد لازم نیست منطق مهاجرت domain را
متوقف کند، ولی پیش از ادغام پایدار باید تعیین تکلیف شود.

---

## EOR-09 — محدوده Print Agent در این بازبینی

**وضعیت:** OUT OF SCOPE برای ادامه فعلی مهاجرت SoknaCafe


Print Agent یک deployable مستقل است. تأخیر چاپ، installer، preview/paper parity و
physical printer UAT در این checkpoint به‌عنوان مانع M2/M3 اعلام نمی‌شوند. تنها
موضوع مرتبط با V3، حفظ boundary و contract versioned میان Local/Runtime/Print است.
هر کار عملیاتی Print باید در milestone و پذیرش مستقل خودش انجام شود.

---

## EOR-10 — مواردی که نقص این مرحله محسوب نمی‌شوند

موارد زیر با توجه به مرحله‌ای بودن مهاجرت، به‌خودی‌خود defect نیستند:

- کامل نبودن `apps/public`؛
- placeholder بودن Windows Runtime/Packaging؛
- نبود release/installer نهایی؛
- نبود end-to-end و operational acceptance کل محصول؛
- migrate نشدن domainهای Orders، Preparation، Inventory و Finance در M2.

این موارد باید در slice مربوطه تکمیل شوند، نه اینکه Agent برای رفع ظاهری گزارش،
ترتیب migration را دور بزند.

---

## EOR-11 — مشاهدات فنی کم‌اولویت‌تر

این موارد blocker فعلی نیستند، ولی هنگام گسترش Local Core بررسی شوند:

- `Auth::loginUser(array)` یک user shape مورد اعتماد می‌پذیرد؛ بهتر است boundary
  اعتماد آن محدود/صریح باشد تا بعداً از controller عمومی role injection رخ ندهد.
- `Config::all()` می‌تواند secretهای DB را برگرداند؛ نباید وارد log/diagnostic شود.
- parser migration فعلی برای SQL ساده مناسب است؛ محدودیت comment/delimiter و DDLهای
  پشتیبانی‌شده باید مستند شود.
- gate معماری Local Core فقط مسیر مستقیم فعلی را scan می‌کند؛ با رشد زیرشاخه‌ها باید
  recursive/semantic شود تا Windows API ownership قابل دور زدن نباشد.
- ریپو public است؛ secret آشکار در snapshot بررسی‌شده پیدا نشد، ولی public بودن و
  انتشار جزئیات contract باید تصمیم آگاهانه مالک پروژه باشد.

---

## 4. ترتیب پیشنهادی ادامه، بدون دخالت در تصمیم معماری

1. ابتدا EOR-04 را ببند: یک continuation point واحد ایجاد کن.
2. EOR-01 تا EOR-03 را به‌عنوان **Contract Reconciliation** بررسی کن.
3. candidateهای EOR-05 را با Handover و behavior baseline دسته‌بندی کن.
4. پیش از login/transport واقعی M3، EOR-07 را با ADR ببند.
5. پیاده‌سازی M3 را فقط بر قراردادهای تعیین‌تکلیف‌شده سوار کن.
6. EOR-06 و EOR-08 را پیش از ادعای upgrade/release acceptance تکمیل کن.

## 5. قالب ثبت پاسخ Agent مالک مهاجرت

Agent می‌تواند برای هر finding این جدول را در انتهای همین فایل یا یک فایل response
مرتبط تکمیل کند:

| Finding | نتیجه | سند درخواست/تصمیم | تغییر انجام‌شده | تست/CI | SHA |
|---|---|---|---|---|---|
| EOR-XX | `PRESERVED` / `APPROVED_CHANGE` / `REGRESSION_FIXED` / `OPEN_DECISION` | path + section | path/ADR | test + run | commit |

بستن finding بدون سند درخواست و evidence اجرایی کافی نیست. اگر تحلیل Agent نشان داد
مشاهده ناظر ناشی از یک درخواست صریح مانند Table Draft بوده است، finding باید با
`APPROVED_CHANGE` و همان سند بسته شود، نه اینکه برای شبیه‌سازی بی‌دلیل legacy کد
عقب‌گرد کند.
