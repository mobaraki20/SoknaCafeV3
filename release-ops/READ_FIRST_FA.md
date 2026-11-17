# راهنمای رسمی مسیر انتشار سکنا — بخوان قبل از ساخت هر بسته
**تاریخ:** ۲۰۲۶-۱۱-۱۶ | **دامنه این شاخه:** GitHub / Release QA فقط | **وضعیت:** مرحلهٔ اولِ کنترل قبل از انتشار، نه مجوز نصب

## ۱. مرز تغییرات (الزامی)
این پروژه فقط ساخت، کنترل و مستندسازی فرآیند انتشار را مجاز می‌کند. هیچ فایل متعلق به `apps/**`، `windows/**`، `platform/**`، `packaging/**`، `contracts/**`، نصب‌کننده، Maintenance، Print Agent، Runtime، پایگاه داده، `CURRENT` یا سیستم‌های عملیاتی تغییر نمی‌کند. هیچ Workflow حق استقرار روی هاست، مصرف secret عملیاتی یا ساخت GitHub Release قابل‌نصب را ندارد.

**این فایل برای تمام ایجنت‌ها مرجع کشف مسیر است؛ برای اجرای واقعی به‌روزرسانی مجوز ایجاد نمی‌کند.**

## ۲. تقدم قوانین و شواهد
1. تصمیم فعال کاربر و نسخهٔ معتبر سند **SOKNA Updater Canonical v1.0.3** (۲۵ اکتبر): یک ZIP پابلیک با بایت یکسان برای Routine و Emergency؛ یک ZIP لوکال مستقل برای تمام مبدأهای *اثبات‌شدهٔ سازگار*؛ بدون الزام امضای ناشر یا کلید/بسته مخصوص دستگاه.
2. [Issue #48](https://github.com/mobaraki20/SoknaCafeV3/issues/48)، `SOKNA-REL-GATE-001` (۳۱ اکتبر؛ هویت واقعی Full و Delta)، `SOKNA-QA-001` و `SOKNA-RELEASE-PREFLIGHT-AGENT-RULES.md` (۵ نوامبر)، و تصمیم `REG-003` (۱۱ نوامبر؛ حفظ اصلاحات پذیرفته‌شده از سورس تا payload).
3. `START_HERE.md`، `ARCHITECTURE.md`، `PROJECT_LINEAGE.md` و [الزامات Windows](../docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md) در شاخه‌ای که واقعاً کد آن خوانده می‌شود.
4. اسناد و PRهای قدیمی صرفاً **مدرک تاریخچه** هستند؛ دستور امضای ناشر PR #47 یا بسته تک‌مبنا نباید دوباره فعال شود.

هیچ نام فایل، عبارت «Final»، آخرین شاخه، `VERSION.txt` یا خروجی موفق CI به تنهایی مرجع حقیقت نیست. `main` فعلی حاوی سورس تجمیعی زندهٔ Local/Public نیست. شاخهٔ R257 هم شاخهٔ آزمایش انتقال است و دارای `VERSION.txt`های قدیمی در دایرکتوری `apps` است. **بنابراین از ریشهٔ main یا R257 به‌صورت پیش‌فرض بسته‌سازی ممنوع است.** ابتدا سورس واقعی و پذیرفته‌شدهٔ Workspace/Library و هشِ دقیقِ همان snapshot باید وارد ورودی قابل بازتولید GitHub شوند. اگر این ورودی فراهم نیست، انتشار `BLOCKED` است.

## ۳. جریان واحدی که ایجنت باید اجرا کند
1. **ثبت مبنای دقیق:** component و نوع خروجی (Local Update / Public Update / Local Clean / Public Clean / Windows component)، سورسِ نسخهٔ مقصد، SHA256 و در صورت Update آرشیو هویت‌های Full/Delta/Adoption واجد شرایط. منبع تاریخی باید SHA256 داشته باشد؛ رقم نسخه یا نام رایانه کافی نیست.
2. **حفظ تصمیم‌ها:** موارد تأییدشده، خروج از دامنه، فهرست فایل فعال/بارگذاری واقعی و ماتریس رگرسیون REG-003 از snapshot معتبر خوانده شود. قرار نیست با کپی سورس قدیمی، اصلاحی محو شود.
3. **استفاده از سازندهٔ معتبر موجود:** فقط builder مخصوص قالب **واقعی نصب‌کننده**، نه هر فایل به نام `*-update.zip`. دو مسیر Local وجود دارد: در نصب slot-managed، بستهٔ رسمی Maintenance از نوع `sokna-maintenance-release-set-v2.1` با `release-set.json` و `artifacts/local/web.zip` است؛ مسیر Legacy LocalUpdateService نیز `sokna-component-package-v1` / component=`local` را می‌پذیرد، ولی `LegacySlotUpdateGuard` ممکن است نصب را روی slot-managed رد کند. بنابراین انتخاب قالب با تشخیص مالک واقعی نصب است، نه صرف نام «پنل». R233 بسته Legacy QA ساخته بود و برای Maintenance مناسب نبود. Public مقصد `sokna-component-package-v1` با `manifest.json` و `payload/` است. نصب تمیز Local در سازندهٔ تاریخی از `sokna-install-manifest.json` استفاده می‌کند؛ Public دو قالب تاریخی داشته: deploy-v1 با manifest و **R233 Root Clean مسطح، بدون manifest اضافه**، شامل `index.php`, `setup/` و `.htaccess` در ریشه. قالب را باید بر اساس سورس واقعی و قرارداد میزبان مقصد آزمود.
4. **ممیزی همان بایت‌های نهایی:** `python3 release-ops/audit.py <EXACT-ZIP> --expected-sha256 <SHA256> --baseline-catalog <VERIFIED-ORIGINS.json> --source-dir <PINNED-UNPACKED-SOURCE> --accepted-fixes <ACCEPTED-FILE-MATRIX.json> --version-registry release-ops/version-registry.json --route-evidence <DUAL-ROUTE-PROOF.json> --paired-package <SAME-VERSION-CLEAN-OR-UPDATE.zip> --report <JSON>`. این ابزار نسخه را از خود ZIP تشخیص می‌دهد؛ فقط خواندنی است و نصب نمی‌کند. برچسب `STATIC PASS` هرگز «نصب شد» نیست.
5. **تکمیل آزمون‌های مسیر واقعی:** استفاده از گیت‌های موجود برای سازگاری نصب Local در Maintenance Windows، آزمون Native Windows/MariaDB، Public Routine و Emergency با **همان SHA** روی مبدأهای واجد شرایط، DB-forward و recovery، سپس گردش‌های حساس/موبایل/چاپ فقط وقتی دامنهٔ تغییر ایجاب می‌کند. هر آزمون انجام‌نشده `NOT_RUN`؛ اگر حیاتی است بسته نصب‌پذیر تحویل نده.
6. **تحویل بی‌ابهام:** همان ZIP آزموده‌شده را بدون بازسازی و تغییر منتشر کن، با منبع و هش و وضعیت `STATIC / CI / HOME_UAT / REAL_HOST / PRODUCTION` جدا. GitHub Release نهایی فقط پس از اثبات و مجوز واقعی از فرایند موجود، نه از این ممیزی به‌تنهایی.

برای کاربر نهایی، فرایند باید ساده بماند: «ساخت بستهٔ انتشار»؛ انتخاب دستی نسخهٔ مبنای نصب، CMD، کلید ناشر یا بستهٔ جدا برای Emergency لازم نیست. جزئیات فنی کار ایجنت و GitHub است.

## ۴. نقاط بازیابی، نه بازسازی از صفر
| ابزار/مدرک موجود | وضعیت و نحوه استفاده |
|---|---|
| `packaging/windows/release-gates/check_local_release_set.py` روی شاخه R241 | گیت موجود برای ZIP واقعی Local؛ پس از تشخیص قالب اجرا شود؛ اثبات نصب زنده نیست |
| `qa/sokna-r183/release_gate.py` | رد wrapper، ZIP خراب، mismatch، UAT و backup ناامن؛ کنترل استاتیک، نه permit |
| `r240/upgrade_inventory.py` | تجربه اختلاف ۵۹۰/۵۹۳ در managed inventory؛ فقط پرونده/آزمون شکست، نه builder عمومی |
| `r248/sokna_public_release_gate.py` | کنترل مؤثر پابلیک اما **پین‌شده به R171/R246**؛ بدون جایگزینی مقادیر ثابت به همه نسخه‌ها تعمیم نده |
| `r249/build_r249.py` | نمونه بازتولیدپذیر full ZIP با مبدأ دقیق 35HK/35HT؛ سازنده عمومی تمام نسخه‌ها نیست |
| `apps/*/tools/build-*.py` | چهار سازنده تاریخی clean/update با فرمت‌های متفاوت؛ قبل از معرفی به Installer قالب واقعی بررسی شود |
| `release/qualification/uup01_standard_support_gate.py` | QA تاریخیِ فقط‌خواندنی پشتیبانی؛ همیشه BLOCK می‌کرد، جایگزین Gate جاری نیست |
| `SOKNA-REL-GATE-001` در Library | الگوریتم و دفتر مبدأ تاریخی Full/Delta؛ هنگام موجودبودن آرشیو از ابزار قبلی استفاده کن |
| `.github/workflows/final-uat-package-freeze.yml` | نمونه قفل کردن commit و چهار بستهٔ یک هدف؛ **به commit قدیمی پین شده** و عمومی نیست |
| `.github/workflows/v3-component-gates.yml` | الگوی آزمون انتخابی برحسب مالک تغییر؛ روی main فعلی موجود نیست |
| `docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md` | قواعد Version مجزا، KEEP بدون تغییر، PS5.1، FileHash، backup، UAC، native UAT؛ در این کار هیچ تغییری در Windows داده نمی‌شود |

فهرست رویدادها و علت خطا: [INCIDENTS_FA.md](INCIDENTS_FA.md).

## ۵. تفاوت خروجی‌ها
- **Local Update** یک Release Set واقعی است؛ افزون بر هش باید کل هویت نصب‌شده شامل `release_id`، نسخه، tree، schema، migration و epoch را مدیریت کند. تاریخچه Full/Delta ممکن است ReleaseId متفاوت داشته باشد.
- **Public Update** یک ZIP و یک SHA برای Routine/Emergency است؛ فایل `src/Emergency/**` و `public/emergency.php` داخل snapshot managed جایگزین‌پذیر نباید باشد؛ هویت `src/Update/emergency-owner-v1.json` با نسخه هدف و hash owner باید بخواند.
- **Clean Install** فایل‌های نصب مستقیم و بدون `config.php`/state؛ با نسخه Update هم‌هدف و فایل‌های مشترک تطابق بایتی داشته باشد. تفاوت فایل‌های setup قابل توجیه است، تغییر منطق مشترک نه.
- **Windows Services / Maintenance / Print Agent** مستقل نسخه‌بندی و در صورت تغییر همان component منتشر می‌شوند. `KEEP` یعنی بدون توقف/بازنویسی سرویس دیگر. این شاخه هیچ builder و installer ویندوزی را دستکاری نمی‌کند.

## ۶. محدودیت صریح اجرای کنونی
`release-ops/audit.py` و Workflow مرتبط **گیت قبل از انتشار** هستند؛ ساخت بستهٔ نصبیِ واقعی، امضای لازم در موتور فعلی، نصب Windows، Public host، دیتابیس، چاپ یا پذیرش کاربر را جعل نمی‌کنند. Workflow فقط artifact موجود در همان repository و GitHub Actions را بررسی می‌کند، نتیجه JSON می‌دهد و **هرگز انتشار یا نصب انجام نمی‌دهد**.

برای تولید خودکار نسخه‌های نهایی در مرحلهٔ بعد، باید منبع کامل و صحیحِ نسخهٔ مورد قبول از Library/Workspace به GitHub با pin تغییرناپذیر در دسترس باشد، سازندهٔ واقعی همان نوع بسته شناسایی و آزموده شده باشد و گیت‌های Native دقیقاً همان ZIP را اجرا کنند. تا این پیش‌شرط‌ها برقرار نشده‌اند، نتیجهٔ این کار «زیرساخت کنترل و کشف مسیر آماده؛ بستهٔ نصب تجاری NOT QUALIFIED» است، نه «کارخانه انتشار عملیاتی کامل».

**اصل محافظت:** به‌جای عبور با پرچم‌های `production_pass=true` یا بازنویسی Manifest، در اولین FAIL فرایند را متوقف و علت را ثبت کن.

## ۷. اتصال ساده به GitHub Actions
Workflow ثابت: `.github/workflows/sokna-release-assurance.yml`.

- **برای ایجنت سازنده:** پس از ساخت ZIP در GitHub Actions، آن را با `actions/upload-artifact@v4` نگه‌دار. Workflow بالا را با `workflow_call` صدا بزن و نام Artifact و SHA256 **همان بایت‌ها** را ارسال کن. هیچ نسخهٔ دیگری نساز و هیچ فایل را پس از این آزمون بازنویسی نکن.
- **برای بررسی یک بسته قبلی:** از `workflow_dispatch` و `artifact_run_id`، `artifact_name`، `expected_sha256` استفاده کن. این شناسه‌ها را ایجنت از اجراهای GitHub استخراج می‌کند، نه کاربر کافه.
- **فرمت مدارک اختیاری کنار تنها فایل ZIP:** `baseline-catalog.json` برای همهٔ هویت‌های proven با `entries[].identity`، `origin` (full/delta/adoption) و `source_archive_sha256`؛ `accepted-fixes.json` شامل `required_files[].id/path/sha256`؛ `route-evidence.json` شامل `format=sokna-public-two-route-evidence-v1`، `package_sha256` و `baselines[].identity/routine/emergency`. در هر route، `status=PASS`، `package_sha256` یکسان و آدرس شاهد مستقل لازم است. ابزار فقط سازگاری این گزارش را کنترل می‌کند؛ معتبر بودن آزمایش میزبان از روی یک JSON قابل اثبات نیست.
- **شناسه‌های قبلی:** `release-ops/version-registry.json` عمداً `historical_complete=false` دارد و تا ورود داده‌های آرشیو حقیقی نباید کامل معرفی شود. هر نسخه تأییدشده آینده باید با SHA ثابت به دفتر افزوده شود. 35gg فعلاً قرنطینه است.

**توقف اجباری:** این Workflow صرفاً Gate ایستای قبل از تحویل است، نه workflow ساخت موتور و نه انتشار عمومی. اگر پرونده صلاحیت واقعی Windows/Public/DB یا سورس معتبر ناقص بود، ایجنت باید همان را `NOT_RUN / BLOCKED` اعلام کند.


## ۸. مرحله «ورود سورس» قبل از تولید بسته

کنترل قطعی بر مبنای سورس معتبر:

```bash
python3 release-ops/source_gate.py --source /path/to/EXACT-SOURCE.zip --component local --checkpoint R233-20261115 --report source-audit.json
```

آرشیو `SOURCE-ONLY` را مستقیم به `audit.py` به‌عنوان بستهٔ نصب نده. در سورس R233، `VERSION.txt` هنوز متعلق به R204 است؛ SHA و `source_tree_sha256` مرجع، ملاک هویت **سورس** هستند. هویت **بستهٔ مقصد** تنها پس از مرحله بسته‌سازی بررسی می‌شود. `source-register.json` فقط چک‌پوینت‌های هش‌دار و معتبر را ثبت می‌کند، نه نسخهٔ «جدیدتر» را به‌صورت خودکار انتخاب کند. هر نوبت ایجنت باید آخرین `LATEST-WORKING` و تصمیم‌های کاربر را بخواند.

برای اثبات حفظ بایت فایل‌های مشترک، همان source ZIP و بسته مقصد را با `--candidate`, `--route`, `--require-output-ready` مقایسه کن؛ در صورت نبود مدارک نصب واقعی هیچ وضعیت عملیاتی صادر نکن. GitHub workflow با ورودی `source_artifact_name` این کنترل را اجرا می‌کند.

**یافته R233:** `resources/local-managed-files-v1.json` فقط ۵۹۰ از ۵۹۳ فایل را دارد، و مالک Emergency در سورس Public هنوز `35hk` را ذکر می‌کند؛ هر دو در مرحله تولید Metadata بسته مقصد باید بررسی/ترمیم شوند، بدون دستکاری کد عملکردی. جزئیات در [R233_SOURCE_INTAKE_FINDINGS_FA.md](R233_SOURCE_INTAKE_FINDINGS_FA.md).


## ۹. بازپخش واقعی منبع R233 در GitHub (SOURCE-ONLY)

- Workflow: [`sokna-release-source-replay-r233.yml`](../.github/workflows/sokna-release-source-replay-r233.yml).
- سازنده read-only: `release-ops/replay_r233_source.py` از exact R204 parent Actions 43513196061 و checkout قفل‌شده روی commit `120d1fb5107699d6b62eaba74f04fd962338f6a3` هفت Patch لوکال و فایل‌های Public R220+R233 را بازپخش می‌کند.
- نتیجهٔ واقعی: [Actions 43829020152](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43829020152): Local ۵۹۳ و Public ۲۳۳ فایل، SHA هر دو ZIP با مرجع Library **بایت‌به‌بایت یکسان**.
- گام آزمایشی بسته‌سازی: [Actions 43829800080](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43829800080) با `release-ops/qa_public_root_clean_rehearsal.py`: فقط Public Flat Root Clean، فقط تغییر metadata نسخه/owner روی نسخهٔ موقت، کد مشترک ۰ فایل تغییر، Static PASS، `release_authorized=false`. خود ZIP آزمایشی آپلود نمی‌شود؛ فقط گزارش.
- نام artifact سورس: `SOKNA-R233-FROZEN-SOURCE-ONLY-NOT-INSTALLABLE`؛ **هرگز به‌عنوان Update یا Clean Install کاربر ارائه نشود**.
- این Workflow به R233 پین است. برای نسخه تازه، سورس جامع پذیرفته‌شده و ورودی SHA باید جدا ثبت شود. هرگز R233 را «آخرین» معرفی نکن.
- **ریسک پایایی:** مادر R204 فعلاً در GitHub Actions artifact ذخیره شده که retention محدود دارد. تا وقتی منبع durable و تأییدشده برای آن ایجاد نشده، نباید دسترسی همیشگی به زنجیرهٔ بازپخش ادعا شود. گم‌شدن آن = BLOCKED، نه رجوع خودکار به نسخه قدیمی یا استفاده از فایل نامعلوم.


## ۱۰. آرشیو پایدار منبع و آزمون واقعی فرایند (بدون انتشار نصب)

- مرجع پایدار سورس SOURCE-ONLY: Workflow `.github/workflows/sokna-source-evidence-vault-r233.yml` فقط روی `main` پس از کنترل SHA، یک **Draft Release غیرعمومی** با نام `sokna-source-evidence-r233-20261116` می‌سازد؛ هیچ ZIP نصب‌پذیر یا Release عمومی ایجاد نمی‌کند. با GitHub token می‌توان آن را بازیابی کرد؛ Source ZIP به‌هیچ‌وجه بسته آپدیت نیست.
- آزمون فرایند: `.github/workflows/sokna-r233-release-qa-rehearsal.yml` سورس دقیق R233، parent واقعی R201 و مبناهای 35HK/35HT را بازیابی و در CI به‌شکل موقت کنترل می‌کند. **فرمت Local Maintenance** با همان validator موجود و Windows PowerShell 5.1 بررسی می‌شود؛ **یک ZIP پابلیک** با همان SHA در Routine/Emergency از هر دو مبنا روی PHP واقعی تست می‌شود. خود ZIP آزمایشی منتشر نمی‌شود؛ فقط گزارش‌های JSON.
- گزارش جزئی شواهد و مرزها: [R233_PACKAGING_QA_EVIDENCE_20261116_FA.md](R233_PACKAGING_QA_EVIDENCE_20261116_FA.md).
- تا زمانی که **منبع approved جدید**، سازگاری چندمبدأ روی native Windows و پذیرش واقعی هاست/دیتابیس تأیید نشده است، خروجی همچنان QA/NOT INSTALL است. هیچ فرایند انتشار مجاز به تنظیم دلخواه `for_install=true` یا `production_pass=true` نیست.


### دسترسی پایدار به SOURCE-ONLY R233، بدون عمومی‌سازی سورس

- انتشار Draft با tag `sokna-source-evidence-r233-20261116` در اجرای مورداعتماد [43833215488](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/43833215488) ساخته شد. هر دو SHA روی آرشیوهای بازخوانی‌شده **PASS** شد.
- Pull Request با مجوز `contents:read` نمی‌تواند Draft را دانلود کند؛ این مجوز را برای PR افزایش نده و Draft را برای رفع این محدودیت Publish نکن.
- برای تهیه ورودی موقت از منبع پایدار، از Workflow `.github/workflows/sokna-source-evidence-vault-r233.yml` **روی شاخه main** استفاده کن (workflow_dispatch). این Workflow در اجرای مورداعتماد، اول از Draft می‌خواند، SHA دو سورس را تطبیق می‌دهد و Artifact با نام `SOKNA-R233-RESTORED-SOURCE-ONLY-NOT-INSTALLABLE` برای آزمون‌های بعدی می‌سازد. آرشیو قدیمیِ Actions فقط در bootstrap اولیه و وقتی Draft موجود نیست استفاده می‌شود.
- عمر Artifact موقت محدود است؛ منبع پایدار همان Draft با هش ثابت است. هیچ‌کدام نصب‌پذیر یا به‌معنای تأیید Production نیستند.


## ۱۱. نقطه ورود عمومی انتشار + آزمون دیتابیس

- `release-ops/active-source.json` آخرین **مرجع بررسی‌شده** Workspace در زمان ثبت را نگه می‌دارد، نه اینکه آخرین شاخه GitHub را خودکار معتبر بداند. برای هر درخواست جدید ابتدا مرجع `LATEST-WORKING` در Library باید دوباره خوانده شود؛ سپس فقط با تأیید SHA و Source Tree، سورس موردنظر در GitHub ثبت شود.
- `.github/workflows/sokna-release-source-intake.yml` نقطه ورود واحد `workflow_dispatch` / `workflow_call` برای Local/Public است. ابزار `release-ops/intake.py` نام و SHA ZIP را از مرجع ثبت‌شده می‌سنجد، مالک واقعی نوع بسته را جدا می‌کند و اثرانگشت SQL را کنترل می‌کند. این مسیر **عمداً بسته تولید یا منتشر نمی‌کند**؛ ابتدا مانع بسته‌سازی از سورس اشتباه می‌شود.
- `release-ops/db_gate.py` Migrationهای مقصد را با آرشیو پایه دقیق و SHA واقعی مقایسه می‌کند؛ SQL اجراشدهٔ حذف/تغییرشده، ترتیب نامعتبر و دستور تخریبی تازه را رد می‌کند. صرف عدد migration کافی نیست.
- `.github/workflows/sokna-release-db-gate.yml` با MariaDB 11.4 و دیتابیس‌های موقت، نصب واقعی Source، ۱۹ سناریوی کسب‌وکار R233، اجرای دوباره Migrationهای Local، بکاپ consistent و بازیابی تمام داده‌ها را بررسی می‌کند. در Workflow بسته‌سازی QA هم به‌عنوان گیت اجباری فراخوانی می‌شود.
- گزارش مستند: [DB_RELEASE_GATES_FA.md](DB_RELEASE_GATES_FA.md). گیت Database هنوز **اثبات نصب روی دیتابیس قدیمی عملیاتی، پیشرفت DB-forward در Maintenance Windows یا migration مجدد مالک Public** نیست؛ آنها وضعیت `NOT_RUN` دارند و تولید بسته نهایی تا اثبات نیازمندی‌های همان Release مسدود می‌ماند.
- همه ابزارها فقط در `release-ops/` و `.github/workflows/` هستند؛ هیچ سورس Local/Public و Windows/Maintenance/PrintAgent تغییر نکند.


## ۱۲. مرز مهم میان آزمون R262 و خروجی Clean اصلاح‌شده R263

- ممیزی بایتی: [R262_R263_EXACT_ARCHIVE_GATES_FA.md](R262_R263_EXACT_ARCHIVE_GATES_FA.md). فرایند `.github/workflows/sokna-r262-r263-archive-crossgate.yml` با SHA دقیق روی آرشیوهای واقعی عمل می‌کند.
- **Clean قدیمی R262 (۲۴۴فایلی) مردود است** چون `bootstrap.php` پنج کلاس لازم را در ZIP نمی‌یابد؛ آزمون قبلی با کپی فایل کمکی این نقص را پنهان می‌کرد.
- **R263 Self-contained Clean (۲۵۱فایلی) فقط QA** است؛ تمام ۲۴۴ فایل مشترک با Public Update برابر و ۷ فایل ثابت Emergency/Trust اضافی دارد. `audit.py` اکنون حضور همه وابستگی‌های صریح bootstrap را کنترل می‌کند.
- فراداده `for_install=true/production_pass=true` در Local R262 برای Windows CI است، نه اعلام انتشار. با وجود تأیید Stage/Activate روی Windows آزمایشی، پذیرش هاست/سیستم مشتری همچنان لازم است.
- R262/R263 شواهد QA جدیدترند، ولی بدون ثبت سورس کامل approved و تصمیم کاربر نباید به‌طور خودکار جانشین checkpoint R233 در `active-source.json` شوند.


## ۱۳. ممیزی مستقل ۷۲ کنترل قبل از هر انتشار

- سند اصلی: [INDEPENDENT_SYSTEM_COVERAGE_AUDIT_20261116_FA.md](INDEPENDENT_SYSTEM_COVERAGE_AUDIT_20261116_FA.md) و [دفتر قابل پردازش ۷۲ ردیفی](INDEPENDENT_COVERAGE_REGISTER_20261116.json).
- پوشش حوزه‌ها: **سورس و REG-003؛ مالک بسته Local/Public؛ دیتابیس، تراکنش و Backup؛ Runtime/Print/Maintenance ویندوز؛ ارتباط و ۱۱ projection؛ چاپ تا کاغذ؛ مالی/انبار؛ امنیت/موبایل؛ پذیرش ۵۹ پرونده و ۲۷ HOLD**.
- تا زمان این ممیزی، **۰ پرونده در دفتر ۵۹تایی دارای امضای پذیرش نصب واقعی** بوده؛ این **مساوی ۵۹ باگ باز نیست**. خروجی R262 Clean ناقص مردود و R263 اصلاح‌شده فقط QA است.
- در هر Release باید ابتدا کنترل‌های **قابل اعمال به محدوده همان تغییر** انتخاب و برای هرکدام مدرک دقیق artifact+SHA+مبدأ+مقصد+سناریو ثبت شود. صرف شمارنده آزمون‌ها یا حضور کلاس در سورس کافی نیست. P0 قابل اعمال و فاقد اثبات، BLOCKED باقی می‌ماند؛ هیچ وضعیت NOT_RUN به PASS تبدیل نشود.
- خودِ این سند یک ممیزی بدون تغییر محصول است و **مجوز خودکار انتشار نیست**؛ برای دوره‌های بعدی دوباره از سورس و شواهد همان Release بازبینی شود.

- تکمیل ممیزی دامنه‌های سورس: همهٔ **۲۴ پوشهٔ کسب‌وکار Local** با نماینده مشخص در دفتر ۷۲کنترلی تطبیق داده شد؛ ده دامنهٔ جامانده در مرور اول نیز اضافه شدند. این آزمونِ ساختاری به‌تنهایی پذیرش رفتار نیست.


- **کنترل ضد جاافتادگی معماری:** قبل از بسته‌سازی، `module_coverage_gate.py` پوشه‌های واقعی Local/Public را با `module-coverage-map.json` تطبیق می‌دهد. ثبت مالک برای ۲۴ دامنهٔ کسب‌وکار Local، ۹ ماژول زیرساخت Local و ۲۰ ماژول Public الزامی است. این بررسی `PASS_INVENTORY_ONLY` است، نه آزمون عملکرد. ماژول تازه/حذف‌شده تا بازبینی خطر اجازه عبور ندارد.


## ۱۴. اجرای واحد کارخانه QA انتشار — بدون انتشار عملیاتی

برای کنترل منسجم هر دو مؤلفه، [Workflow مشترک](../.github/workflows/sokna-release-factory.yml) را اجرا کن. مرحله اول Local/Public Source Intake و تطبیق SHA با Workspace است؛ مرحله دوم ساخت بسته‌های موقت QA با ابزارهای موجود و Windows/MariaDB/Public dual-route است؛ مرحله سوم `factory_decision.py` شواهد **همان اجرای واحد** را تطبیق می‌دهد و فقط گزارش ماشین‌خوان `SOKNA-UNIFIED-RELEASE-READINESS-REPORT-ONLY` را می‌فرستد.

- [راهنمای عملیاتی](UNIFIED_FACTORY_RUNBOOK_20261117_FA.md). اجرای موجود فقط روی سورس تاریخی **R233** صلاحیت QA دارد؛ سورس جدیدتر باید از ابتدا با SHA و سازنده و آزمونِ همان خروجی تأیید شود.
- نتیجه `QA_PASS_EXTERNAL_HOST_ACCEPTANCE_BLOCKED` **اجازه نصب نیست**. حتی با سبزبودن همه CI، آخرین Workspace و REG-003 و داده واقعی/بکاپ و Windows Maintenance و Public Host و Print Agent نیازمند شاهد مستقل‌اند.
- هیچ Workflow اجازه تغییر host/DB/service یا انتشار GitHub Release عملیاتی ندارد. برای نسخه‌های آینده این کنترل‌ها را استفاده مجدد کن؛ سازنده موردی جدید یا دستکاری ناشناس سورس ممنوع.


## ۱۵. هر خروجی باید به‌وضوح بگوید از کارخانه استفاده کرده یا نه

- دستورالعمل [PUBLIC_R265_R266_FACTORY_ATTRIBUTION_FA.md](PUBLIC_R265_R266_FACTORY_ATTRIBUTION_FA.md) را برای تجربهٔ جدید پابلیک R265/35if و R266/35ig بخوان. این دو بسته از R267/R268 با [اجرای مستقل GitHub 44025587468](https://github.com/mobaraki20/SoknaCafeV3/actions/runs/44025587468) ساخته شده‌اند، نه از کارخانه یکپارچه R233.
- **همراه هر بسته تحویلی اجباری است:** `Factory used: YES/NO/UNKNOWN`، Workflow/Run ID واقعی، SHA دقیق همان ZIP، نسخه/هویت مبدأ، میزان Migration، شواهد QA و شواهد میزبان واقعی، وضعیت مجوز `READY/BLOCKED`. اگر گزارش همان ZIP از کارخانه وجود ندارد، گفتن YES ممنوع است.
- برای پروندهٔ R268، `trace_external_public_candidate.py` و `.github/workflows/sokna-r268-candidate-attribution.yml` گزارش «خارج از کارخانه» را به‌صورت مستقل و فقط‌خواندنی تهیه می‌کنند.
- **قاعده جدید:** پیش از ساخت آپدیت Public باید قابلیت Emergency در **نسخه نصب‌شده** برای DDL/forward migration سنجیده شود؛ R249/35ic مسیر مستقیم R262/35id را مسدود می‌کند. دو مرحله R265 Bridge بدون SQL و R266 با ۳ Migration، فقط استثنای آن نسل است؛ استاندارد هر نسخه همچنان یک ZIP مشترک Routine/Emergency است.
- تکیه بر CI مستقل کافی نیست؛ انتقال/اتصال Public و Local، backup/restore واقعی، محافظت از کلیدها، سلامت پس از مرحله و سفارش مهمان فعال/معلق باید جداگانه احراز شوند. هیچ بخشی از این ثبت منشأ انتشار عملیاتی ایجاد نمی‌کند.


## ۱۶. یک ورودی برای R233 و کاندیدای جدید Local/Public

- در `.github/workflows/sokna-release-factory.yml` اکنون انتخاب مسیر `historical-r233`، `legacy-public-35ic` یا `registered-candidate` وجود دارد. انتخاب مسیر جدید، **از خود بایت‌های ZIP اصلی موجود در Artifact سازنده** و قرارداد پین‌شده بررسی می‌کند، نه بسته بازسازی‌شده.
- سازنده‌های بعدی باید گیت `.github/workflows/sokna-release-candidate-gate.yml` را فراخوانی کنند و در رسید، `built_inside_factory` و `factory_validated_exact_bytes` را جدا گزارش کنند. ابزار `release-ops/candidate_admission.py` اتصال فراداده سازنده، Artifact، SHA و ساختار را fail-closed بررسی می‌کند.
- آزمون ساختاری این گیت، سورس واقعیِ تأییدشده، Migration/DB-forward، نصب Windows/host و چاپ فیزیکی را **خودبه‌خود تأیید نمی‌کند**؛ تا زمان پیوست‌شدن آزمون مالک هر مؤلفه و پذیرش میدانی همان SHA، `operator release BLOCKED` است.
- دستور استفاده و مثال قرارداد: [UNIFIED_CANDIDATE_INTAKE_RUNBOOK_FA.md](UNIFIED_CANDIDATE_INTAKE_RUNBOOK_FA.md). مسیر ویژهٔ R265/R266 فقط استثنای سازگاری Emergency مبدأ 35ic است.
