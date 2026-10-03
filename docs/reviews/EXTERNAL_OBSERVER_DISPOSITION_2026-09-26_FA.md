# پاسخ و تعیین‌تکلیف گزارش ناظر بیرونی — 2026-09-26

این فایل پاسخ Agent مالک مهاجرت به `EXTERNAL_OBSERVER_REVIEW_2026-09-26_FA.md` است.

قاعده: گزارش ناظر مرجع مشاهده است، نه مرجع معماری. هر finding با درخواست/تصمیم، baseline دقیق dev.39، معماری V3 و evidence اجرایی تعیین‌تکلیف شده است.

## Snapshot مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- PR: `#1`
- Contract reconciliation checkpoint: `ebceeacf21ebdc6996634d113f6cac4a3d5a7083`
- Contract workflow: `36216627352` — SUCCESS
- Local parity gate checkpoint: `ba24a9c882e2e50bb80b9636863871ae6bce9318`
- Local parity workflow: `36217296763` — SUCCESS

## Disposition

| Finding | نتیجه | مبنا/تصمیم | تغییر و evidence |
|---|---|---|---|
| EOR-01 | `REGRESSION_FIXED` | dev.39 `public_edge/bootstrap.php` و `includes/relay_projection.php` | capability mapهای Realtime/Deferred به کلیدهای proven dev.39 برگشتند؛ fixture مستقل baseline اضافه شد؛ commit `ebceeacf...`, workflow `36216627352` SUCCESS |
| EOR-02 | `REGRESSION_FIXED` | dev.39 signed Local verification | HMAC taxonomy به `unknown_installation`, `bad_signature`, `replay_detected` reconcile شد؛ commit `ebceeacf...`, workflow `36216627352` SUCCESS |
| EOR-03 | `REGRESSION_FIXED` | نیاز به evidence مستقل از contract تحت تست | `tests/fixtures/dev39-relay-baseline-v1.json` با provenance ثابت baseline اضافه شد و relay gate از آن می‌خواند؛ workflow `36216627352` SUCCESS |
| EOR-04 | `REGRESSION_FIXED` | drift مستندات، نه تصمیم محصول | `docs/migration/CURRENT_CONTINUATION_FA.md` به‌عنوان continuation authority واحد ایجاد شد؛ `README.md` و `START_HERE.md` صریحاً به آن ارجاع می‌دهند. statusهای تاریخی در صورت تعارض authority نیستند. |
| EOR-05 | `REGRESSION_FIXED` برای پنج parity قطعی؛ transient-session behavior `PRESERVED` | dev.39 Local functions/observability/bootstrap + M2 recorded decision | capability/preparation lookup fail-closed؛ ترتیب `kitchen -> bar`؛ correlation ID ورودی/response؛ correlation ID در fallback log؛ cookie path مبتنی بر installation mount. Gate `tests/local-core-contract.py` تقویت شد؛ workflow `36217296763` SUCCESS. رفتار حفظ session هنگام transient DB failure طبق M2 عمداً حفظ شد. |
| EOR-06 | `OPEN_DECISION` / deferred acceptance item | M10 upgrade/recovery qualification | blocker طراحی اولیه M3 نیست. باید قبل از ادعای upgrade/release acceptance با fixture واقعی dev39، partial-DDL recovery و concurrent runner بسته شود. |
| EOR-07 | `PRESERVED` + explicit V3 security boundary | dev.39 `projection_sync.php` و `auth/login.php`; ADR 0003 | remote login semantics حفظ می‌شود؛ Local canonical identity/permission authority می‌ماند؛ Public فقط minimal auth projection شامل verifier لازم را نگه می‌دارد. ADR `docs/adr/0003-public-auth-projection-strategy.md` abuse controls/audit/revocation semantics و تست‌های M3 را تعریف می‌کند. |
| EOR-08 | `OPEN_DECISION` / release-governance item | merge/release governance | blocker migration domain نیست؛ پیش از merge/release پایدار باید runner/action/runtime pinning و required-check/review governance تعیین‌تکلیف شود. |
| EOR-09 | `APPROVED_CHANGE` نسبت به dev.39 deployment ownership؛ mature behavior `PRESERVED` | V3 architecture + owner clarification | Print Agent مقصد یک deployable محلی/machine-bound در `windows/print-agent` است؛ Public/Internet کنترل مستقیم ندارد؛ Runtime فقط lifecycle supervision؛ semantics بالغ تاریخی برای M8 reuse/refactor می‌شود. `server-wire-v4` historical semantic baseline است، نه الزام transport اینترنتی. |
| EOR-10 | `PRESERVED` | staged migration plan | placeholder/incomplete بودن componentهای مربوط به sliceهای بعد defect این مرحله نیست و ترتیب migration دور زده نمی‌شود. |
| EOR-11 | `OPEN_DECISION` / hardening backlog | lower-priority technical observations | موارد trust-boundary `loginUser`, config secret exposure, migration parser limits و recursive ownership scanning باید در slice مرتبط سخت‌سازی شوند؛ blocker مستقیم M3 نیستند مگر همان boundary لمس شود. |

## نکات EOR-05 که عمداً تغییر نکردند

`Auth::currentUser()` هنگام failure موقت refresh دیتابیس، session احراز هویت‌شده جاری را فوراً eject نمی‌کند. این رفتار در سند M2 به‌عنوان compatibility-sensitive behavior ثبت شده و بدون تصمیم امنیتی مستقل تغییر داده نشد. بنابراین این بخش از finding ناظر regression محسوب نشد.

## EOR-07 — نتیجه امنیتی

ADR 0003 تصمیم می‌گیرد که حذف فوری `password_hash` از Public projection در M3 انجام نشود، چون baseline remote login را می‌شکند و login را به اتصال همزمان Local وابسته می‌کند. این hash فقط verifier projection است؛ password lifecycle و user/capability authority همچنان Local-owned است.

M3 باید علاوه بر حفظ login موفق عادی، failed-login throttling و auth audit بدون secret material را پیاده و تست کند.

## Print Agent

توضیح مالکیت V3:

- Local مالک business print intent/document content است.
- `windows/print-agent` مالک durable print/device/spooler execution است.
- Runtime فقط process/service lifecycle را supervise می‌کند.
- Public/Internet نباید Print Agent را discover/submit/control کند.
- کد بالغ Pagent/Print Worker منبع reuse است و حذف کلی آن مجاز نیست.

## نقطه ادامه

پس از این disposition، اجرای ordered migration روی **M3 — Public Edge Persistence, Auth Projection and Relay Transport** ادامه پیدا می‌کند.

EOR-06 و EOR-08 به milestone پذیرش/انتشار مربوط‌اند و نباید برای رفع ظاهری گزارش ترتیب migration را متوقف یا جابه‌جا کنند.
