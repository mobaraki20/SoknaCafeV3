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
- M1: در حال انجام، با extraction evidence اجرایی برای Realtime/Deferred و historical surface audit برای Runtime/Print.
- M1.1 Realtime: wire + operation schema + compatibility vectors استخراج و در CI قفل شده‌اند. مسیر نهایی V3 و producer/consumer implementation هنوز Draft است.
- M1.2 Deferred-safe: wire + operation schema + compatibility vectors استخراج و در CI قفل شده‌اند. جزئیات domain result/review هنگام مهاجرت ownerهای واقعی باید تکمیل شوند؛ final API stability هنوز ادعا نمی‌شود.
- M1.3 Runtime/Print: historical surface/ownership audit انجام و در CI قفل شده است. Runtime تاریخی API HTTP پایدار نداشته؛ Print API v4 و loopback bridge اثبات‌شده audit شده‌اند. قرارداد نهایی V3 برای subset موردنیاز Runtime/Print هنوز باید طراحی/استخراج و با compatibility vectors تثبیت شود.

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

### Runtime
- `contracts/runtime-api/historical-audit-v1.json`

نتیجه audit:
- در dev39 یک Runtime HTTP API پایدار وجود ندارد.
- interface اثبات‌شده شامل CLI، فایل state با format `sokna-local-runtime-v1` و Windows SCM service host است.
- هر HTTP/IPC جدید در V3 باید به‌عنوان قرارداد جدید versioned طراحی شود؛ نباید به‌اشتباه legacy-compatible معرفی شود.
- Runtime فقط supervision/OS integration است و Business Authority یا owner چاپ نیست.

### Print Agent
- `contracts/print-agent-api/historical-audit-v1.json`

نتیجه audit:
- Print API v4 تاریخی و loopback bridge audit شده‌اند.
- رفتارهای بالغ مثل durable claim/accept/start/report، submission fence، request/body idempotency، server-scope binding، unknown/recovery_hold و device/spooler execution باید حفظ شوند.
- loopback bridge تاریخی `/v1/wake` و `/v1/preview`، pairing/origin guard و resource limits مشخص دارد.
- عبارت تاریخی dev39 مبنی بر internal Local component بودن Print Worker، authority معماری V3 نیست؛ owner V3 برابر `windows/print-agent` است و Runtime فقط آن را supervise می‌کند.

## CI
Contracts gate اکنون این سه gate اجرایی را اجرا می‌کند:
- `tests/relay-wire-contract.py`
- `tests/relay-operation-contract.py`
- `tests/runtime-print-contract-audit.py`

آخرین CI تأییدشده برای checkpoint قبل از این به‌روزرسانی سند:
- head: `5af39e53a37b016fab9fc7f4d0841201329dee6a`
- workflow: `V3 Component Gates`
- run: `36209559220`
- نتیجه: همه jobها SUCCESS، شامل Foundation، Contracts، Local، Public، Runtime، Print Agent، Platform، Packaging، Migration و SCDS.

این موفقیت فقط architecture/contract-foundation و extraction/audit evidence را اثبات می‌کند؛ application migration، clean-machine installer acceptance و physical-printer UAT را اثبات نمی‌کند.

## نقطه دقیق ادامه
1. final V3 Runtime contract را از روی نیاز مصرف‌کننده و historical CLI/state/SCM semantics تعریف کن؛ هیچ business mutation یا direct Local business-table write وارد آن نشود.
2. برای Print Agent، subset نهایی V3 از Print API v4/loopback behavior را مشخص کن و exact request/response/state schemas + compatibility vectors را بساز.
3. semantics بالغ `accept -> start -> report`, durable receipt/content hash, unknown/recovery_hold و submission fence ضعیف نشوند.
4. بعد از executable شدن Runtime/Print contracts، M1 exit gate را ارزیابی کن؛ قبل از آن producer/consumer implementation را بین componentها جابه‌جا نکن.
5. بعد از بسته‌شدن M1، طبق `MIGRATION_SLICES.md` وارد M2 Local Core شو.

## نکته handoff
اگر چت یا Agent عوض شد، از PR #1، این فایل، `docs/migration/MIGRATION_SLICES.md` و `contracts/manifest.json` ادامه بده؛ حافظه گفتگو مرجع پروژه نیست.
