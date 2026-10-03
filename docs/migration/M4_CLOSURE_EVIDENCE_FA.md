# M4 — شواهد بستن Guest/Public

وضعیت: **COMPLETE — exit gate satisfied**

تاریخ: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified M4 checkpoint:

- branch: `architecture/v3-foundation`
- head: `08119caa741916badac997350aa71e8c4ee72822`
- `V3 Component Gates` PR run: `36244318018` — **SUCCESS**
- `M4 Guest Renderer Gate` PR run: `36244318015` — **SUCCESS**
- `M4 Failure Isolation Gate` PR run: `36244318053` — **SUCCESS**

## آنچه در M4 بسته شد

M4 اکنون Public-owned Guest publish/runtime/read-model boundary را بدون انتقال business authority به Public اجرا می‌کند:

- چهار persistence owner این slice برای Guest publish/active revision/availability/remote read models؛
- content-addressed Guest media و immutable revision؛
- atomic active-revision switch؛
- signed Local-to-Public publish/availability/read-model ingress با reuse کردن M3 HMAC/replay boundary؛
- Guest runtime bundle و fresh/stale action state؛
- Remote Read Models با capability و preparation-area filtering؛
- Guest compatibility روی همان M3 `realtime_requests`، بدون queue دوم؛
- retry/dedupe سازگار برای Guest و پشتیبانی `guest_order.quote`؛
- SCDS-owned Persian/RTL Guest renderer با degraded read-only state؛
- failure-isolation gate صریح برای Local-down / Public-down / Internet-or-sync-loss.

## شواهد اجرایی اصلی

### Persistence / publish / availability

- `tests/public-mysql-migration-selftest.php`
- `tests/public-m4-schema-contract.py`
- `tests/public-m4-guest-publish-selftest.php`
- `tests/public-m4-guest-runtime-selftest.php`

این تست‌ها migration واقعی MariaDB، revision/media ownership، atomic activation و fresh/stale action state را پوشش می‌دهند.

### Remote Read Models

- `tests/public-m4-remote-read-model-selftest.php`

این gate allowlist، capability scope، preparation-area filtering و read-only بودن projection را اجرا می‌کند.

### Guest compatibility / Realtime reuse

- commit `c32ef35ce2234cdf3a9f155f40c9c72a66198cef` — `feat: bind M4 guest compatibility to realtime`
- `tests/public-m4-guest-compat-selftest.php`

شواهد:

- Guest از همان M3 Realtime queue استفاده می‌کند؛
- `guest_order.quote` داخل contract مجاز است؛
- retry منطقی Guest با تغییر timestamp به `request_id_conflict` کاذب تبدیل نمی‌شود؛
- Guest نمی‌تواند kind خارج از scope خودش مثل `settlement.commit` را enqueue کند؛
- stale Local به read-only degradation ختم می‌شود.

### SCDS Guest renderer

- commit `6d528a3f1b05f01f69bbc14590c82046b110be91` — `feat: migrate M4 guest renderer to SCDS`
- gate commit `e0bef83daef78211a0d402ea8eff5179f365521f`
- workflow `M4 Guest Renderer Gate`
- `tests/public-m4-guest-renderer-selftest.php`

شواهد:

- `<html lang="fa" dir="rtl">` از owner سرور؛
- `sg-*` domain namespace و owner ثبت‌شده در SCDS registry؛
- بدون inline style island یا generic `.btn` owner موازی؛
- focus-visible، 44px touch targets، reduced-motion و 320px safety؛
- snapshot text escaping و عدم `innerHTML` injection؛
- stale Local: منو می‌ماند ولی order/waiter mutation UI حذف می‌شود؛
- invalid QR و unpublished revision از system-state pattern استفاده می‌کنند.

## Exit scenarioهای M4

### 1. Local-down

Executable evidence: `tests/public-m4-failure-isolation-selftest.php` در job `Public degrades safely when Local or sync is unavailable`.

رفتار ثابت‌شده:

- heartbeat قدیمی می‌شود؛
- immutable published Guest snapshot همچنان HTTP 200 و قابل‌خواندن می‌ماند؛
- UI به degraded state می‌رود؛
- mutation control حذف می‌شود؛
- Guest compatibility mutation با `503 local_unavailable` رد می‌شود؛
- Public هیچ business decision جدیدی از stale data نمی‌سازد.

### 2. Public-down

Executable evidence: job `Local remains operational without Public` در workflow `M4 Failure Isolation Gate`.

این job Local bootstrap/ownership contract و migration واقعی MariaDB را بدون هیچ Public service یا Public configuration اجرا می‌کند. نتیجه: **SUCCESS**.

بنابراین نبود Public یک dependency لازم برای bootstrap/data/auth foundation محلی نیست.

### 3. Internet-down / sync-loss

Executable evidence از دو جهت است:

1. job مستقل Local بالا آمدن Local را بدون Public ثابت می‌کند؛
2. Public self-test با heartbeat تازه ولی `guest_availability_state.last_sync_at` stale، آخرین snapshot را read-only نگه می‌دارد و mutation را می‌بندد.

این سناریو boundary/freshness semantics قطع ارتباط را ثابت می‌کند؛ **ادعای packet-level/network-chaos end-to-end test نمی‌کند**. تست واقعی قطع NIC/DNS/proxy در qualification کامل M10 جای می‌گیرد.

## Authority check

بعد از M4:

- Local همچنان canonical source/publish-decision/business mutation owner است؛
- Public فقط published immutable artifact/projection/runtime/transport owner است؛
- Guest compatibility business mutation را از M3 Realtime به Local می‌فرستد؛
- Remote Read Models mutation API ندارند؛
- queue دوم یا business SQL owner جدید روی Public ایجاد نشده است.

## چیزهایی که M4 ادعا نمی‌کند

- canonical Orders/Preparation/Inventory/Supply/Finance هنوز migrate نشده‌اند؛ این‌ها M5 هستند؛
- Runtime/Print/Packaging هنوز implementation-complete نیستند؛
- packet-level browser/network chaos و release qualification کامل مربوط به M10 است؛
- باقی ماندن historical source repository به‌عنوان provenance به معنی active duplicate V3 owner نیست.

## تصمیم خروج

**M4 exit gate: SATISFIED.**

نقطه ادامه بعدی باید M5 باشد و از dependency order رسمی شروع شود: ابتدا explicit Sellables، سپس canonical Orders و ادامه‌ی domainهای Local. هیچ M7/M8/M9 کاری نباید جایگزین ترتیب M5 شود.
