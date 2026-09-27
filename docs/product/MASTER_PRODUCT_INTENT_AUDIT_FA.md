# SOKNA Cafe V3 — Master Product / Architecture Audit

تاریخ: 2026-09-27  
وضعیت: **CANONICAL CONTINUATION BASELINE — PRODUCT GAP CLOSURE REQUIRED**  
V3 source checked: `architecture/v3-foundation @ 911d9700755508d23e30ff94fa7464eba6cfaa43`  
post-UI authority checked: `mobaraki20/SoknaCafe @ a46435cca57df5bd5b9770efd0bb95390528aa05`  
historical business source checked: `Sokna-CLEAN-INSTALL-1.36.4-dev.26`  
initial handoff checked: `SOKNA_ARCHITECTURE_HANDOFF_STANDALONE_FINAL_R2_2026-09-18` (all SHA256 entries verified OK).

## نتیجه قطعی

هنداور اولیه در شروع مسیر نادیده گرفته نشده بود. در خط `dev.26 -> post-UI/dev39` واقعاً Source Audit، Gap Analysis، Migration Matrix، Risk Register، API contracts و checkpointهای فازها ساخته و بخش بزرگی از target architecture پیاده شدند. **شکاف اصلی هنگام استخراج/بازسازی محصول در repo تمیز V3 رخ داد**: بسیاری از ownerهای backend و contractها منتقل شدند، اما user-facing product surfaces، بعضی domainها، updater/recovery surfaces و deployment composition کامل منتقل نشدند. سپس completion چند slice مهندسی به اشتباه به completion کل محصول تعمیم داده شد.

بنابراین V3 فعلی restart نمی‌شود؛ core سالم حفظ می‌شود، اما وضعیت محصول از `Manual UAT next` به **Product Gap Closure** برگردانده شده است.

## چرا Final Integration قبلی زود بود

- هنداور اولیه قبل از implementation الزام کرده بود Source Audit + Migration Matrix + Gap Analysis + Risk Register وجود داشته باشد و «copying files is never completion». این مرحله در legacy R2 انجام شد، اما در V3 وضعیت‌ها دوباره با معنای narrow slice ثبت شدند.
- `M6` در V3 فقط shared Design System foundation بود، نه migration تمام UI؛ با این حال handoff نهایی Local را CLOSED اعلام کرد.
- `M9/M10` installer/release engineering را qualify کردند، ولی Product Parity Gate که همه capabilityهای initial handoff/post-UI را بررسی کند وجود نداشت.
- current V3 `apps/local-web/public` فقط login/logout/index/SCDS/internal Runtime/Print endpoints دارد؛ در حالی که post-UI baseline صدها فایل product surface دارد.
- V3 realtime/deferred contracts بعضی operationها را تعریف کرده‌اند که Local adapter نهایی آن‌ها موجود نیست (مثلاً guest order/waiter/settlement و subscriber payment deferred).

## Source authority hierarchy

این ترتیب در `FINAL_ARCHITECTURE_DECISIONS_FA.md` canonical شده است: تصمیم‌های نهایی جدید > Frozen intent هنداور اولیه > post-UI UI/behavior baseline > dev.26 historical behavior > V3 implementation evidence.

## چیزهایی که حفظ می‌شوند و دوباره ساخته نمی‌شوند

- Local business authority و بخش عمده Orders/Table Draft/Preparation/Inventory/Supply/Expenses/Tax/Financial Period/Settlement services
- Public auth/realtime/deferred/guest projection backend primitives
- Runtime core و Print Agent durable core
- Versioned Realtime/Deferred/Runtime/Print contracts
- SCDS foundation و product language
- Business Backup/Restore core
- generic package lifecycle primitives verify/stage/activate/rollback/repair
- CI component impact classification و بسیاری از automated gates

## Gapهای Blocker / P0

- **A05 — Realtime Relay**: Public realtime transport exists; Local adapters only TableDraft + Preparation  →  Implement Local adapters/dispatch for guest order, waiter call, committed order edit/cancel, settlement and full result flow
- **A06 — Deferred-safe work**: Public deferred transport + Supply/Inventory/Expense adapters exist; subscriber.payment adapter absent  →  Implement subscriber payment deferred adapter and end-to-end review/reconciliation UI
- **A15 — Settlement / invoices**: V3 SettlementService/schema/tests exist; realtime settlement adapter absent  →  Add realtime adapter + complete settlement/invoice/operator UI + receipt parity
- **A16 — Subscribers / customer payments**: V3 SubscriberService/schema exists; UI absent; deferred payment adapter absent  →  Implement subscriber.payment deferred adapter; migrate subscriber/account/payment UI
- **A17 — Guest menu / order**: V3 Guest renderer/runtime/compatibility services and JS/CSS exist, but deployable front controller/routes are incomplete  →  Create actual Public deployable entrypoints/routes and end-to-end Local adapters
- **A18 — Waiter calls**: V3 Public compatibility emits waiter kinds; no canonical Local waiter domain/adapter/table  →  Migrate waiter_calls Local owner + realtime adapter + staff/guest status/cancel UI
- **A19 — Remote staff gateway**: V3 Public auth/read-model backend exists; no staff web surface and Local publisher/worker is incomplete  →  Build Public staff UI + Local outbound projection publisher/heartbeat + routing/session UX
- **A20 — Guest publish / availability**: V3 Public GuestPublishService/availability storage exists; Local publish owner/UI missing  →  Implement Local snapshot builder/preview/publish UI/worker and availability sync
- **A35 — Observability / diagnostics**: V3 Local Observability core and Public health exist; no unified component dashboard/support UI  →  Build unified Component Health/Diagnostics view and support bundle aggregation
- **A36 — Local Update Center**: V3 lifecycle.py primitives only; no Local update center/UI  →  Build component inventory/status API + update UI; update Public normally from Local; component owners execute own lifecycle
- **A37 — Public Emergency Console**: V3 no emergency/updater path  →  Build restricted authenticated console, audited actions, limited logs, updater/recovery
- **A38 — Application updater**: V3 generic packaging lifecycle primitives exist but no product-facing Local/Public updater engines  →  Create Local and Public update engines/UI on common signed package semantics; stable recovery entrypoint
- **A39 — Local initial install**: V3 setup-machine tools + Windows installer assumption  →  Build browser preflight, DB test/config, migrations, admin bootstrap, installation identity/pairing, lock/re-entry recovery
- **A40 — Infrastructure**: V3 platform/windows owns Apache/PHP/MariaDB provisioning and prerequisite bundle policy  →  Define compatibility matrix/checker only; prerequisite acquisition optional verified online/manual
- **A41 — Windows Services installer**: Current installer source compiles but composition conflicts with final architecture  →  Rewrite SetupHost/UI/Inno; prerequisite check/download/manual fallback; no Local/Apache/PHP/MariaDB payload ownership
- **A42 — Prerequisite acquisition**: No final UX for online acquisition  →  Implement signed/hash-locked providers, progress UI, resumable/fail-safe download policy
- **A43 — Public deploy package**: V3 apps/public artifact contains code but lacks complete deployable document-root/control/update surfaces  →  Create hosting-ready package, config/browser/bootstrap or documented setup flow, health, emergency/update entrypoints
- **A44 — Independent component versions/releases**: V3 ownership split exists but release candidate used one 3.0.0-rc.1 line  →  Add component versions/manifests/release compatibility and independent artifact pipelines
- **A45 — Agent component workflow — CLOSED IN G0**: root `COMPONENTS.json` schema v2, registry gate and Library checkpoint/continuation policy now define exact agent scope and durable continuation.
- **A46 — UI / Design System**: V3 SCDS foundation exists and gate passes; most product surfaces were not migrated  →  Migrate every protected product surface using Audit→Correct→Standardize→Migrate→Enforce and UI DoD
- **A50 — Release qualification**: M10 validated engineering subset but not product parity  →  Rebuild M9/M10 only after all required matrix rows closed; then physical/manual UAT

## Gapهای مهم بعد از Blocker

- **A03 — Auth / permissions** [P1 / PARTIAL_PRODUCT]: Add complete Local Users/roles/permission UI and remote staff surface; keep server-side revalidation
- **A04 — Preparation authorization** [P1 / PARTIAL_PRODUCT]: Restore operator/waiter Preparation product UI/feed/actions on canonical service
- **A07 — Financial period reconciliation** [P1 / CORE_COMPLETE_UI_MISSING]: Build financial-period UI showing pending/unknown/override/review evidence
- **A08 — Orders / committed workflow** [P1 / CORE_COMPLETE_UI_MISSING]: Migrate operator/order/table user-facing workflows to V3 SCDS
- **A09 — Table Draft** [P1 / CORE_COMPLETE_UI_MISSING]: Rebuild staff/table draft UI and route surface on canonical owner
- **A10 — Sellables / Service Item** [P1 / CORE_COMPLETE_UI_MISSING]: Migrate item/catalog/category admin UI and service-item field
- **A11 — Tax** [P1 / CORE_COMPLETE_UI_MISSING]: Add Tax settings/item override/checkout/receipt UI and integration acceptance
- **A12 — Inventory** [P1 / CORE_COMPLETE_UI_MISSING]: Migrate complete inventory UI/reports/count/review/receive/adjustment flows
- **A13 — Supply / purchase** [P1 / CORE_COMPLETE_UI_MISSING]: Migrate needs/purchases/batch/receive UI and remote deferred UI
- **A14 — Expenses** [P1 / CORE_COMPLETE_UI_MISSING]: Add expenses UI/category/reversal/reporting integration
- **A21 — Theme engine / packages** [P2 / MISSING_REQUIRED]: Implement safe theme package contract/manager after core guest publish; first official theme from post-UI visual source
- **A22 — Media Library** [P2 / PARTIAL_REQUIRED]: Implement Local media library, references/GC/derived images and publish integration
- **A23 — Central editable guest copy** [P2 / MISSING_REQUIRED]: Create constrained copy registry and UI using SCDS/product language rules
- **A24 — Marketing / campaigns / events** [P2 / MISSING_REQUIRED]: Migrate business behavior and SCDS UI; classify Public exposure if any
- **A25 — Reporting / analytics** [P2 / MISSING_REQUIRED]: Migrate Local reports/analytics and bounded remote read model producer
- **A26 — Notifications / Push** [P2 / MISSING_REQUIRED]: Migrate outbox/preferences/client UX and Runtime trigger worker; real-device UAT
- **A27 — Printing business UI** [P1 / PARTIAL_REQUIRED]: Build Local printers/destinations/history/test/health UI
- **A28 — Print templates / package** [P2 / MISSING_REQUIRED]: Migrate template manager and .soknaprint lifecycle on canonical renderer
- **A31 — Accommodation integration** [P2 / PARTIAL_REQUIRED]: Migrate Local integration settings/status/log/UI and shared finance acceptance
- **A32 — SOKNA Center/Core — RETIRED / SUPERSEDED**: cancelled by final product decision before operational baseline; hard-removed across Local/Runtime/packaging/tests. Personnel remains an independent Local identity and is not tied to Center.
- **A33 — Backup / business recovery** [P1 / CORE_COMPLETE_UI_MISSING]: Build recovery UI/workflow and support final componentized recovery set
- **A34 — Machine takeover** [P1 / PARTIAL_CRITICAL]: Implement operational takeover/re-enrollment UI/API with Public Emergency path
- **A47 — Module enable/disable** [P2 / PARTIAL_REQUIRED]: Implement module registry/settings lifecycle; history preserved; dependencies explicit
- **A48 — Users / settings / admin control** [P1 / MISSING_REQUIRED]: Migrate users/settings/modules/tables/QR/configuration surfaces to SCDS
- **A49 — Tables / hall / active sessions** [P1 / PARTIAL_REQUIRED]: Migrate table management/session operational UI; classify table_session_clients behavior before retirement

## UI / Design System conclusion

post-UI commit `a46435...` دارای `SCDS-CANONICAL-2026-R1` است و Definition of Done صریح دارد: Persian/RTL، Vazirmatn، 320px، keyboard/focus، state/error/loading، حداقل touch target 44px، debt ratchet، و منع parallel component/style owner. V3 این foundation را منتقل کرده، اما اکثر surfaces مصرف‌کننده را منتقل نکرده است. بنابراین هر workstream UI باید از post-UI source به‌عنوان behavior/visual authority و SCDS به‌عنوان canonical implementation authority استفاده کند.

## Updater / Recovery conclusion

legacy updater یک capability واقعی و بالغ بوده و post-UI baseline همچنان `admin/update` و `includes/updater_engine` را دارد. V3 فقط lifecycle primitives را منتقل کرده است. پس Local/Public product updater و Public Emergency Console missing هستند و باید با حفظ semantics بالغ stage/verify/recovery-point/health/activation/rollback/LKG ساخته شوند.

## Deployment architecture — final approved

1. Infrastructure = external/independent dependency layer.
2. Windows Services installer = Runtime + Print Agent/service lifecycle only; prerequisite checker/acquisition, no infrastructure payload and no Local Web payload.
3. Local Web = full ZIP/web package + Browser Setup Wizard; no PS1/EXE installer.
4. Public Edge = independent deploy package.
5. Local Update Center = central version/health/I-O/sync/update visibility/orchestration.
6. Public Emergency Console = independent break-glass health/update/rollback/recovery.
7. components independently versioned/releasable with compatibility manifest.
8. monorepo stays one repo but agent work is component-scoped by machine-readable registry.

## Corrected execution order — do not re-audit from zero

1. **Governance Repair — COMPLETE**: root Component Registry, superseding ADRs, completion vocabulary, migration semantics, Product Parity Gate and Library checkpoint policy are now in place.
2. **Local Web Product Parity**: migrate protected Local UI/workflows on existing services; fill missing Local owners/adapters (waiter/realtime/subscriber deferred).
3. **Local Install + Control Plane**: Browser Setup Wizard; unified health/diagnostics; Local Update Center; Local updater/recovery UI.
4. **Public Edge Productization**: deployable routes/document root; remote staff surface; Local projection/publish producers; Emergency Console/updater.
5. **Remaining Product Capabilities**: Marketing/Campaign/Event, Reporting/Analytics, Notifications/Push, Theme/Media, Print templates, modules/users/settings/tables, integration UIs.
6. **Windows Packaging Revision**: external infrastructure prerequisite model; Runtime+Print Agent installer only; verified downloader/manual fallback.
7. **New M9/M10**: rewrite qualification against final architecture and independent component packages/versions.
8. **New RC + Manual UAT**: only after parity gate has no required OPEN rows; then clean install/deploy/update/recovery, printer soak, touch/Persian/IME and fault/chaos matrix.

## Product Parity Gate — mandatory before next Final Integration

- Every required row in `MASTER_CAPABILITY_MATRIX` is PRODUCT_COMPLETE or explicitly FUTURE_ONLY/superseded with documented user decision.
- Every legacy protected route/workflow is mapped to Preserve/Refactor/Replace/Retire with evidence; no unexplained disappearance.
- Every legacy/post-UI table is classified owner-wise; no unclassified loss of business history/capability.
- Every cross-component contract kind has a real provider + consumer implementation and end-to-end gate, not schema-only coverage.
- Every required UI surface satisfies SCDS UI Definition of Done and browser evidence appropriate to risk.
- Every component has version/package/health/update/recovery ownership recorded in root Component Registry.
- Update/Repair/Recovery are exercised independently; broken app release recovery remains possible.
- Only after automated product parity and packaging gates are green may Manual UAT become the next stage.

## No-reaudit rule for future agents

A new agent MUST NOT restart architecture/source audit unless: (a) the user supplies a newer authoritative handoff/source, (b) the matrix explicitly contains an UNKNOWN requiring source inspection, or (c) implementation evidence contradicts this audit. Otherwise it reads `00_READ_ME_FIRST_FA.md`, this master audit, final decisions, capability matrix and continuation JSON, then executes only the current workstream.

## Machine-readable companion

`MASTER_CAPABILITY_MATRIX.json/csv` is the detailed canonical row-by-row status. `CONTINUATION_STATE.json` gives the exact next workstream and source refs.


## G0 closure update

G0 Governance Repair is complete in the local workspace and durably checkpointed in Library. A45 is now `PRODUCT_COMPLETE`. The active workstream is G1 Local Web Product Parity. Product parity inventory currently reports 4 `PRODUCT_COMPLETE`, 45 `PRODUCT_OPEN`, and 1 `RELEASE_BLOCKED`; therefore Manual UAT remains on hold.
