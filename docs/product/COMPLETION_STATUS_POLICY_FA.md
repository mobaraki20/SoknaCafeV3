# SOKNA V3 — Completion Status Policy

Status: **CANONICAL / REQUIRED**
Date: 2026-09-27

این سند جلوی تکرار false-completion را می‌گیرد. هیچ عبارت مبهم `closed` یا `migrated` به‌تنهایی حق ادعای کامل‌شدن محصول را ندارد.

## سطوح مجاز

### `INVENTORY_ONLY`
مالک/منبع/ریسک شناسایی شده، اما implementation claim وجود ندارد.

### `CORE_COMPLETE`
schema/domain/service/contract اصلی و تست‌های core مربوطه کامل‌اند، ولی ممکن است UI، workflow کامل، integration end-to-end، updater/recovery یا packaging هنوز باز باشد.

### `PRODUCT_OPEN`
قابلیت موردنیاز محصول هنوز gap دارد. ممکن است core کامل یا ناقص باشد؛ جزئیات باید در capability matrix/evidence مشخص شود.

### `PRODUCT_COMPLETE`
برای همان capability تمام موارد لازم متناسب با scope کامل‌اند:
- behavior/business semantics؛
- data/domain/API owner؛
- user-facing UI/workflow اگر capability user-facing است؛
- cross-component provider/consumer integration اگر لازم است؛
- failure/degraded/pending states؛
- update/recovery implication؛
- automated evidence؛
- SCDS/UI DoD برای surfaceهای کاربر.

### `RELEASE_BLOCKED`
خود capability ممکن است Product Complete باشد، اما release evidence لازم هنوز تکمیل نشده یا blocker خارجی دارد.

### `RELEASE_COMPLETE`
Product Complete + artifact/package/version/compatibility + update/repair/recovery qualification + همه UATهای لازم.

### `SUPERSEDED`
نیاز/روش قبلی با تصمیم صریح جدید جایگزین شده و replacement/evidence مشخص دارد.

### `FUTURE_ONLY`
فقط با تصمیم صریح محصول؛ به معنی silently dropped نیست.

## قوانین

1. `CORE_COMPLETE != PRODUCT_COMPLETE`.
2. `PRODUCT_COMPLETE != RELEASE_COMPLETE`.
3. واژه‌های قدیمی `migrated`, `closed`, `done` بدون completion level ممنوع‌اند.
4. هر capability موردنیاز handoff اولیه/post-UI باید در Master Capability Matrix ردیف داشته باشد.
5. یک row فقط با evidence قابل ارتقاست؛ CI سبز یک slice محدود، خودبه‌خود Product Complete نمی‌سازد.
6. UI migration به معنی redesign آزاد نیست؛ SCDS و post-UI observable behavior مرجع‌اند مگر تصمیم explicit جدید.
7. قابلیت cross-component وقتی Product Complete است که provider و consumer واقعی و end-to-end gate داشته باشد، نه فقط schema/contract file.
