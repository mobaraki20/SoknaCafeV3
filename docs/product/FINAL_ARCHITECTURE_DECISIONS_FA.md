# SOKNA Cafe V3 — Final Architecture Decisions / Supersession Log

تاریخ: 2026-09-27
وضعیت: **CANONICAL FOR CONTINUATION**

## ترتیب مرجع‌ها
در صورت تعارض، ترتیب تصمیم‌گیری از بالا به پایین است:

1. **تصمیم‌های صریح نهایی کاربر در گفت‌وگوی 2026-09-27** که در این سند ثبت شده‌اند.
2. `SOKNA_ARCHITECTURE_HANDOFF_STANDALONE_FINAL_R2_2026-09-18` برای Product Intent و Frozen business semantics که صریحاً supersede نشده‌اند.
3. post-UI baseline `mobaraki20/SoknaCafe @ a46435cca57df5bd5b9770efd0bb95390528aa05` برای UI/Design System و رفتار واقعی پس از UI Update.
4. `1.36.4-dev.26` برای provenance و business behavior تاریخی.
5. V3 current source برای evidence اینکه الان چه چیزی واقعاً پیاده شده است؛ پیاده‌سازی ناقص حق تغییر intent را ندارد.

## D1 — Deployment separation — FINAL
تصمیم قبلی «یک Setup واحد برای Platform + Runtime + Print + Local» و ADR-0002 از نظر composition **superseded** است.

مدل نهایی:
- **Infrastructure**: external/independent dependency layer؛ مالک SOKNA نیست.
- **Windows Services**: یک Native Windows Installer فقط برای `Windows Runtime + Print Agent + service lifecycle`.
- **Local Web**: full ZIP/web package مستقل؛ copy/extract در web root و سپس **Browser Setup Wizard**. هیچ `PS1`/`EXE` installer برای Local Web.
- **Public Edge**: deploy package مستقل برای hosting/server Public.

## D2 — Infrastructure prerequisites — FINAL
زیرساخت Local Web از Windows Services و خود Local Web جدا است.

- **Windows Services Setup** فقط پیش‌نیازهای Runtime/Print Agent خودش را بررسی می‌کند و Apache/PHP/MariaDB را نمایش، نصب یا مدیریت نمی‌کند.
- **SOKNA Prerequisites Setup** ابزار مستقل آماده‌سازی زیرساخت است و فقط Apache + PHP + MariaDB را از artifactهای رسمی و release-locked دریافت/verify و نصب/تعمیر/بازیابی می‌کند.
- **Local Web** همچنان فقط ZIP مستقل است؛ Prerequisites Setup هیچ فایل Local Web را کپی، نصب یا migrate نمی‌کند.
- مسیر نصب زیرساخت قابل انتخاب است و می‌تواند روی درایوی غیر از درایو ویندوز باشد؛ layout مرجع `<drive>:\\SOKNA\\Infrastructure`، `<drive>:\\SOKNA\\Data\\MariaDB` و `<drive>:\\SOKNA\\Web` است.
- Binary و Data Directory ماریا‌دی‌بی جدا هستند. در Repair/Recover وجود دیتای قبلی به معنی **ممنوعیت initialize مجدد** است.
- بعد از تعویض ویندوز، حالت Recover فایل‌های باقی‌مانده را validate می‌کند، dependencyهای وابسته به OS را دوباره برقرار و سرویس‌های `SoknaApache` و `SoknaMariaDB` را re-register می‌کند؛ Web/Data موجود حذف یا overwrite نمی‌شوند.
- ساخت دیتابیس کاربردی SOKNA، user محدود برنامه و migrationها مالکیت **Browser Setup Wizard خود Local Web** است، نه Prerequisites Setup.
- نصب باید log پایدار، state machine-readable، diagnostics و Support Bundle بدون secret داشته باشد.
- مسیر دستی فقط fallback اضطراری است؛ مسیر اصلی کاربر نصب/Repair/Recover خودکار با hash verification و fail-closed است.

## D3 — Local Browser Setup — FINAL
Local Web نصب اولیه شبیه WordPress است:
1. package در web root قرار می‌گیرد؛
2. URL باز می‌شود؛
3. preflight محیط؛
4. DB host/port/name/user/password + Test Connection؛
5. schema/migrations؛
6. admin/initial cafe config/installation identity؛
7. optional pairing/status؛
8. setup lock + safe re-entry/recovery.

## D4 — Componentized monorepo — FINAL
یک repository باقی می‌ماند، اما هر component مستقل قابل کار/تست/commit/release است. Root machine-readable registry باید برای هر component ثبت کند:
- owned paths؛
- read-only shared contracts؛
- dependencies/compatibility؛
- tests/gates؛
- package/artifact؛
- version owner؛
- updater/recovery owner؛
- health endpoint/evidence؛
- allowed cross-component writes.

ایجنت با درخواست `public-edge` یا `local-web` یا `windows-services` نباید کل پروژه را دوباره audit کند.

## D5 — Versions and releases — FINAL
Local Web, Public Edge, Runtime, Print Agent/Windows Services می‌توانند نسخه و release مستقل داشته باشند. Compatibility manifest قرارداد نسخه‌های قابل‌همزیستی را مشخص می‌کند. Contractها نیز versioned هستند.

## D6 — Local Update Center — FINAL
Local Web routine control plane است و باید در یک سطح مدیریتی مرکزی نشان دهد:
- component name/version/compatibility؛
- health/connectivity/I/O status؛
- sync/publish/backlog/error state؛
- update available/current/previous/LKG state؛
- update actionهای مجاز، بدون تصاحب lifecycle owner component.

Public Edge در حالت عادی از Local Update Center قابل مشاهده و update است.

## D7 — Public Emergency Console — FINAL
اگر Local یا مسیر Local↔Public unavailable باشد، Public یک break-glass console مستقل دارد برای:
- health/connectivity/version؛
- limited logs/diagnostics؛
- update/verify/stage/activate؛
- rollback/LKG/recovery؛
- remote-access/order-intake kill switch و recovery/takeover controls متناسب با قرارداد اولیه.

Routine admin دوم ممنوع است؛ actions حساس audit/reason/auth قوی می‌خواهند.

## D8 — Updater preservation — FINAL
Updater legacy حذف‌شده تلقی نمی‌شود. اصول بالغ آن باید در V3 حفظ شوند:
- stable recovery entrypoint؛
- versioned/upgradable engine یا معادل مقاوم؛
- package validation/hash/signature/compatibility؛
- staging؛
- recovery point؛
- health check؛
- atomic activation where possible؛
- rollback/LKG؛
- repair/recovery اگر app release خراب است.

`packaging/tools/lifecycle.py` فقط primitive است؛ product updater وقتی کامل است که Local/Public surfaces و component lifecycle integration موجود باشند.

## D9 — UI / Design System — FINAL
`SCDS-CANONICAL-2026-R1` همچنان Design Authority است. post-UI baseline `a46435...` مرجع UI behavior/visual source است؛ dev.26 فقط provenance/DNA و business behavior است.

هیچ domain/backend slice بدون user-facing surface لازم، UI stateهای failure/pending، responsive/RTL/Persian/A11y evidence و canonical component ownership «Product Complete» نیست.

## D10 — Completion vocabulary — FINAL
از این به بعد:
- `CORE_COMPLETE` = business/domain/service/schema/contract complete؛
- `PRODUCT_COMPLETE` = Core + required UI/API/workflow + integration + update/recovery + tests complete؛
- `RELEASE_COMPLETE` = Product complete + packaging/update/recovery + automated qualification + required manual UAT.

واژه `migrated/closed` بدون سطح بالا ممنوع است.

## D11 — Current RC status — FINAL
`3.0.0-rc.1` و CI سبز آن engineering evidence معتبرند، ولی **Final Product RC نیستند**. Manual UAT نهایی تا بستن Product Gaps و ساخت RC جدید متوقف است.
