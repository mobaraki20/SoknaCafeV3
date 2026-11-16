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
3. **استفاده از سازندهٔ معتبر موجود:** فقط builder مخصوص قالب **واقعی نصب‌کننده**، نه هر فایل به نام `*-update.zip`. Local پنلی فعلی `sokna-maintenance-release-set-v2.1` با ریشهٔ `release-set.json` + `artifacts/local/web.zip` است؛ `apps/local-web/tools/build-update-package.py` در شاخه‌های آزمایشگاهی صرفاً `sokna-component-package-v1` می‌سازد و جانشین Release Set نیست. Public مقصد `sokna-component-package-v1` با `manifest.json` و `payload/` است. نصب تمیز Local مستقیم `sokna-install-manifest.json` و Public مستقیم `manifest.json` با `sokna-public-deploy-v1` دارد.
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
