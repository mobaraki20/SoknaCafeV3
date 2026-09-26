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
- **M1: COMPLETE در سطح contract extraction / executable boundary evidence.**
- M1.1 Realtime: wire + operation schema + compatibility vectors استخراج و در CI قفل شده‌اند. producer/consumer implementation هنوز migrate نشده و contract همچنان Draft است.
- M1.2 Deferred-safe: wire + operation schema + compatibility vectors استخراج و در CI قفل شده‌اند. جزئیات domain result/review هنگام مهاجرت ownerهای واقعی تکمیل می‌شوند؛ API stability نهایی هنوز ادعا نمی‌شود.
- M1.3 Runtime: historical audit + ADR + V3 `contract-v1.json` + compatibility vectors ایجاد و در CI قفل شده‌اند. Runtime HTTP تاریخی وجود نداشت؛ V3 contract جدید و versioned است و legacy HTTP compatibility ادعا نمی‌کند.
- M1.3 Print: historical audit + retained Print API v4 + loopback v1 + compatibility vectors ایجاد و در CI قفل شده‌اند. ownership به `windows/print-agent` تصحیح شده ولی safety semantics بالغ حفظ شده‌اند.

**معنای COMPLETE:** قراردادهای لازم برای شروع حرکت implementation بین componentها وجود دارند و executable regression gate دارند. این وضعیت به معنی migrate شدن application code، Windows integration، installer، physical printer UAT یا release readiness نیست.

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

## CI evidence برای بستن M1
Checkpoint تأییدشده:
- head: `6fd46e4d8196d350884539067d446bd83db6f46a`
- workflow: `V3 Component Gates`
- PR run: `36210340655` (#109)
- نتیجه کلی: **SUCCESS**

Jobهای تأییدشده روی همان PR diff:
- Foundation: SUCCESS
- Contracts: SUCCESS
- Local: SUCCESS
- Public: SUCCESS
- Windows Runtime: SUCCESS
- Print Agent: SUCCESS
- Platform: SUCCESS
- Packaging: SUCCESS
- Migration: SUCCESS
- SCDS: SUCCESS

داخل Contracts gate هر چهار تست زیر واقعاً اجرا و SUCCESS شدند:
1. `tests/relay-wire-contract.py`
2. `tests/relay-operation-contract.py`
3. `tests/runtime-print-contract-audit.py`
4. `tests/runtime-print-v1-contract.py`

Push-runهایی که به‌دلیل path impact بعضی jobها را skipped کرده‌اند، evidence بستن M1 محسوب نشده‌اند؛ مبنا PR-run کامل بالا است.

## چرا MIGRATION_MATRIX هنوز implementationها را migrated نشان نمی‌دهد
M1 قرارداد و boundary را کامل کرده، نه migration implementation هر capability را. بنابراین ردیف‌هایی مثل Realtime relay، Deferred-safe، Windows Runtime و Printing - OS execution فقط وقتی `migrated` می‌شوند که owner implementation مربوطه طبق sliceهای بعدی منتقل، تست و legacy duplicate همان scope retire شود. M1 completion نباید این واقعیت را جعل کند.

## نقطه دقیق ادامه — M2
قدم بعدی **M2 — Local Core: Bootstrap, Data Ownership, Auth and Observability** است.

ترتیب پیشنهادی M2:
1. audit دقیق bootstrap/config/database/auth/observability در baseline تاریخی؛
2. ایجاد حداقل skeleton اجرایی `apps/local-web` بدون Windows ownership؛
3. Local DB connection/schema/migration owner؛
4. users/capabilities/preparation-area authority بدون permission system دوم؛
5. correlation ID / safe error / redaction / Local health primitives؛
6. shared business-service bootstrap که domain sliceهای بعدی روی آن سوار شوند؛
7. تست init/unit/integration طوری که ordinary Local core برای تست به Public/Runtime/Print نیاز نداشته باشد.

قیود M2:
- هیچ Runtime/Winspool/Registry/SCM access داخل Local core؛
- هیچ UI redesign صرفاً به‌خاطر تغییر ساختار فایل؛ هر UI touched scope باید تحت SCDS canonical rule بماند؛
- schema evolution ترجیحاً Expand -> Migrate -> Contract؛
- code bulk-copy completion محسوب نمی‌شود؛ owner و regression evidence باید روشن باشد.

## نکته handoff
اگر چت یا Agent عوض شد، از PR #1، این فایل، `docs/migration/MIGRATION_SLICES.md`، `contracts/manifest.json` و ADR-0001 ادامه بده؛ حافظه گفتگو مرجع پروژه نیست.
