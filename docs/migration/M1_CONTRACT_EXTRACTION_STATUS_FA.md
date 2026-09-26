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
- M1: **exit candidate**. چهار خانواده قرارداد اکنون evidence ماشین‌خوان و gate اجرایی دارند؛ بسته‌شدن رسمی M1 منوط به سبز شدن CI روی head شامل Runtime v1 و Print v4/loopback v1 است.
- M1.1 Realtime: wire + operation schema + compatibility vectors استخراج و در CI قفل شده‌اند. producer/consumer implementation هنوز migrate نشده و contract همچنان Draft است.
- M1.2 Deferred-safe: wire + operation schema + compatibility vectors استخراج و در CI قفل شده‌اند. جزئیات domain result/review هنگام مهاجرت ownerهای واقعی تکمیل می‌شوند؛ API stability نهایی هنوز ادعا نمی‌شود.
- M1.3 Runtime: historical audit + ADR + V3 `contract-v1.json` + compatibility vectors ایجاد شده‌اند. Runtime HTTP تاریخی وجود نداشت؛ V3 contract جدید و versioned است و legacy HTTP compatibility ادعا نمی‌کند.
- M1.3 Print: historical audit + retained Print API v4 + loopback v1 + compatibility vectors ایجاد شده‌اند. ownership به `windows/print-agent` منتقل/تصحیح شده ولی safety semantics بالغ حفظ می‌شوند.

## شواهد ثبت‌شده
### Realtime
- `contracts/local-public-realtime/wire-v1.json`
- `contracts/local-public-realtime/operation-schemas-v1.json`
- `contracts/local-public-realtime/compatibility-vectors-v1.json`
- `tests/relay-wire-contract.py`
- `tests/relay-operation-contract.py`

رفتارهای قفل‌شده شامل:
- claim lease: default 20، clamp به 5..60؛
- lease token: 24 random bytes / 48 hex، فقط SHA256 در persistence؛
- ACK states: `committed|rejected|expired|cancelled|unknown_review`؛
- terminal ACK تاریخی: state ذخیره‌شده را dedupe می‌کند و result را بازنویسی نمی‌کند؛
- result shape، auth/replay و Realtime/Deferred separation.

### Deferred-safe
- `contracts/local-public-deferred/wire-v1.json`
- `contracts/local-public-deferred/operation-schemas-v1.json`
- `contracts/local-public-deferred/compatibility-vectors-v1.json`
- همان relay contract gates بالا.

رفتارهای قفل‌شده شامل:
- claim lease: default 30، clamp به 10..120؛
- lease expiry بدون تغییر state از `pending_sync`؛
- claim باعث افزایش `attempt_count` می‌شود؛
- ACK terminal mismatch => `terminal_state_conflict`؛
- reconcile فقط `needs_review -> committed|rejected` و target فعلی idempotent است؛
- period blocking = `pending_sync + needs_review`؛
- result response shape تاریخی.

### Runtime
- `contracts/runtime-api/historical-audit-v1.json`
- `docs/adr/0001-runtime-local-contract-direction.md`
- `contracts/runtime-api/contract-v1.json`
- `contracts/runtime-api/compatibility-vectors-v1.json`
- `tests/runtime-print-contract-audit.py`
- `tests/runtime-print-v1-contract.py`

تصمیم‌های قفل‌شده M1:
- dev39 Runtime HTTP API پایدار نداشت؛ evidence تاریخی CLI + `sokna-local-runtime-v1` state + Windows SCM است.
- Local -> Runtime v1 فقط boundary مشاهده/health/supervision ماشین‌محور و loopback است.
- Runtime -> Local فقط idempotent allowlisted trigger intent است؛ Business work/DB داخل Local می‌ماند.
- هیچ generic execute/shell/PowerShell/SQL/business payload در contract وجود ندارد.
- Public/Internet مستقیماً Runtime را کنترل نمی‌کند.
- historical `printing` trigger مالکیت چاپ را به Runtime منتقل نمی‌کند.

### Print Agent
- `contracts/print-agent-api/historical-audit-v1.json`
- `contracts/print-agent-api/server-wire-v4.json`
- `contracts/print-agent-api/loopback-v1.json`
- `contracts/print-agent-api/compatibility-vectors-v4.json`
- `tests/runtime-print-contract-audit.py`
- `tests/runtime-print-v1-contract.py`

رفتارهای قفل‌شده M1:
- retained Print server actions: `probe`, `heartbeat`, `claim`, `claim_reconcile`, `attempt_status`, `renew`, `accept`, `start`, `report`؛
- `request_id`/request-body fingerprint idempotency؛
- immutable claimed destination evidence؛
- durable `local_receipt_id + content_sha256` fence قبل از physical execution؛
- `accept -> start -> physical execution -> report`؛
- `unknown` و `recovery_hold` safety stateهای durable هستند و blind auto-reprint ممنوع است؛
- loopback `/v1/wake` فقط nudge است و `/v1/preview` فقط render preview؛ هیچ‌کدام durable submission path نیستند؛
- Runtime فقط lifecycle سرویس Print Agent را supervise می‌کند.

## CI
Contracts gate اکنون چهار gate اجرایی را اجرا می‌کند:
- `tests/relay-wire-contract.py`
- `tests/relay-operation-contract.py`
- `tests/runtime-print-contract-audit.py`
- `tests/runtime-print-v1-contract.py`

آخرین CI کاملاً تأییدشده قبل از اضافه‌شدن Runtime v1/Print v4 final M1 contract files:
- head: `5af39e53a37b016fab9fc7f4d0841201329dee6a`
- workflow: `V3 Component Gates`
- run: `36209559220`
- نتیجه: همه jobها SUCCESS.

CI روی head جدید شامل `runtime-print-v1-contract.py` باید جداگانه سبز شود؛ pending/skipped به‌عنوان PASS حساب نمی‌شود.

## Exit gate M1
M1 فقط وقتی بسته است که:
1. چهار خانواده Realtime / Deferred / Runtime / Print contract evidence اجرایی داشته باشند؛
2. Contracts + Foundation gates روی head شامل همه فایل‌های بالا SUCCESS باشند؛
3. هیچ producer/consumer implementation قبل از contract به‌صورت موازی/حدسی migrate نشده باشد؛
4. manifest و migration docs همین boundaryها را منعکس کنند.

بعد از تحقق این gate، قدم بعدی **M2 — Local Core** است: bootstrap/config، Local DB/schema owner، users/capabilities/preparation-area authority، observability/health primitives و business-service bootstrapping؛ بدون Windows/Winspool/SCM ownership و بدون redesign UI.

## نکته handoff
اگر چت یا Agent عوض شد، از PR #1، این فایل، `docs/migration/MIGRATION_SLICES.md`، `contracts/manifest.json` و ADR-0001 ادامه بده؛ حافظه گفتگو مرجع پروژه نیست.
