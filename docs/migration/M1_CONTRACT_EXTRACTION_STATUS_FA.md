# وضعیت M1 — استخراج قراردادهای بین‌کامپوننتی

به‌روزرسانی: 2026-09-26

## مرجع
- ریپو مقصد: `mobaraki20/SoknaCafeV3`
- شاخه فعال: `architecture/v3-foundation`
- PR: `#1`
- سورس تاریخی فقط برای استخراج رفتار: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05` (`work/reconcile-dev39`)
- هیچ تغییر جدیدی نباید روی ریپوی تاریخی به‌عنوان بخشی از مهاجرت V3 انجام شود.

## وضعیت کلی
- F0 Foundation: کامل در سطح معماری/قرارداد.
- M1: در حال انجام.
- M1.1 Realtime: استخراج wire و operation schema/compatibility vectors انجام شده؛ مسیر نهایی V3 و producer/consumer implementation هنوز Draft است.
- M1.2 Deferred-safe: استخراج wire و operation schema/compatibility vectors انجام شده؛ review taxonomy/domain result details و producer/consumer implementation هنوز باید هنگام مهاجرت ownerها تکمیل شوند.
- M1.3 Runtime/Print: هنوز شروع اجرایی نشده؛ ابتدا audit تاریخی و سپس استخراج قرارداد، بدون اختراع schema جدید قبل از audit.

## شواهد ثبت‌شده
### Realtime
- `contracts/local-public-realtime/wire-v1.json`
- `contracts/local-public-realtime/operation-schemas-v1.json`
- `contracts/local-public-realtime/compatibility-vectors-v1.json`

رفتارهای قفل‌شده شامل:
- claim lease: default 20، clamp به 5..60؛
- lease token: 24 random bytes / 48 hex، فقط SHA256 در persistence؛
- ACK states: `committed|rejected|expired|cancelled|unknown_review`؛
- terminal ACK تاریخی: state ذخیره‌شده را dedupe می‌کند و result را بازنویسی نمی‌کند؛
- result shape و access rule تاریخی.

### Deferred-safe
- `contracts/local-public-deferred/wire-v1.json`
- `contracts/local-public-deferred/operation-schemas-v1.json`
- `contracts/local-public-deferred/compatibility-vectors-v1.json`

رفتارهای قفل‌شده شامل:
- claim lease: default 30، clamp به 10..120؛
- lease expiry بدون تغییر state از `pending_sync`؛
- claim باعث افزایش `attempt_count` می‌شود؛
- ACK terminal mismatch => `terminal_state_conflict`؛
- reconcile فقط `needs_review -> committed|rejected` و target فعلی idempotent است؛
- period blocking = `pending_sync + needs_review`؛
- result response shape تاریخی.

## CI
- `tests/relay-wire-contract.py`: wire-level invariants، HMAC، state/kind separation و route evidence.
- `tests/relay-operation-contract.py`: operation schema و compatibility vectors.
- `.github/workflows/v3-component-gates.yml`: Contracts gate هر دو تست را اجرا می‌کند.

## قاعده ادامه
1. نتیجه CI این head باید بررسی شود؛ pending/skipped به‌عنوان PASS حساب نمی‌شود.
2. اگر Contracts/Foundation سبز بود، M1.1 و M1.2 در سطح extraction evidence قابل بسته‌شدن هستند، نه در سطح final API stability.
3. قدم بعدی M1.3 است: audit دقیق Runtime API و Print Agent API از سورس تاریخی/Pagent lineage.
4. Runtime فقط supervision/OS integration؛ هیچ business mutation یا direct Local business-table write وارد قرارداد Runtime نشود.
5. Print Agent owner مستقل durable print/device/spooler state باقی بماند؛ Runtime فقط supervise کند.
6. قبل از حرکت producer/consumer code بین componentها، contract executable باید وجود داشته باشد.

## نکته handoff
اگر چت یا Agent عوض شد، از PR #1، این فایل، `docs/migration/MIGRATION_SLICES.md` و `contracts/manifest.json` ادامه بده؛ حافظه گفتگو مرجع پروژه نیست.
