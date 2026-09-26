# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M4 implementation/exit checkpoint:

- Head: `08119caa741916badac997350aa71e8c4ee72822`
- `V3 Component Gates`: run `36244318018` — **SUCCESS**
- `M4 Guest Renderer Gate`: run `36244318015` — **SUCCESS**
- `M4 Failure Isolation Gate`: run `36244318053` — **SUCCESS**

Canonical M4 closure evidence:
`docs/migration/M4_CLOSURE_EVIDENCE_FA.md`

این checkpoint شامل Guest/Public M4 است: immutable publish/media revisions، atomic active revision، availability projection، Guest runtime/degraded state، Remote Read Models با scope filtering، Guest compatibility روی M3 Realtime، SCDS Guest renderer و failure-isolation exit scenarios.

## Slice status

- F0: COMPLETE at foundation level.
- M1: COMPLETE at contract-extraction / executable-boundary level.
- M2: COMPLETE at Local Core slice level.
- M3: COMPLETE at Public Edge transport/auth/projection slice level.
- M4: **COMPLETE — Guest Publish/Runtime/Remote Read Models exit gate satisfied.**
- M5: **NEXT — Local Business Domains in dependency order.**
- M6: continuous SCDS cross-cutting track.
- M7..M10: planned / not complete.

تکمیل M4 به معنی migrate شدن business-domain ownerهای Orders/Preparation/Inventory/Supply/Finance نیست. Local همچنان مرجع نهایی mutationهای کسب‌وکار است و همین ownerها موضوع M5 هستند.

## Observer reconciliation

- EOR-01: `REGRESSION_FIXED`
- EOR-02: `REGRESSION_FIXED`
- EOR-03: `REGRESSION_FIXED`
- EOR-04: `REGRESSION_FIXED` — this file is the single continuation authority; README/START_HERE point here.
- EOR-05: `REGRESSION_FIXED` for five proven parity items; transient authenticated-session DB failure behavior remains intentionally `PRESERVED` per M2.
- EOR-06: deferred to M10 upgrade/recovery qualification.
- EOR-07: `PRESERVED` with explicit V3 security boundary in `docs/adr/0003-public-auth-projection-strategy.md`.
- EOR-08: deferred release-governance item; not a domain-migration blocker.
- EOR-09: local/machine-bound Print Agent constraint preserved for M8; mature Pagent behavior is reuse evidence.
- EOR-10: staged-migration clarification preserved.
- EOR-11: hardening backlog; address when affected boundaries are touched.

Canonical disposition record:
`docs/reviews/EXTERNAL_OBSERVER_DISPOSITION_2026-09-26_FA.md`

## Immediate continuation — M5

M5 must be executed as dependency-ordered sub-slices, not a bulk directory move.

### M5.1 — Explicit Sellables first

1. Audit historical owner/behavior before code movement:
   - `includes/sellable.php`
   - `items` schema/usage
   - `admin/items.php`
   - menu/catalog paths that infer sellability.
2. Freeze explicit `menu_item` / `service_item` semantics and identify every legacy inference from category/station/name/recipe that must be retired rather than copied.
3. Define the canonical Local service/repository/data migration owner under `apps/local-web`; Public receives only published projection data through already-migrated M4 boundaries.
4. Add migration and regression gates for historical order/receipt compatibility before changing canonical Orders.
5. If user-facing admin/catalog surfaces are touched, migrate them through SCDS in the same sub-slice; do not defer UI correction.
6. Update `MIGRATION_MATRIX.csv` only when implementation evidence supports the status change.

### Then M5 dependency order

After Sellables exit gate:

1. canonical Orders services;
2. Staff Quick Order + Table Draft;
3. Preparation permission/action owner;
4. Inventory;
5. Supply/Purchase;
6. Expenses;
7. Financial periods/Settlement/Reconciliation;
8. Tax/cross-domain finance integration;
9. Accommodation/Center adapters.

Do not start M7/M8/M9 implementation as a substitute for M5 domain migration.

## Failure-isolation note carried forward

M4 proved boundary-level Local-down/Public-down/Internet-or-sync-loss behavior. It did **not** claim packet-level NIC/DNS/proxy/browser chaos qualification; that remains explicit M10 release-qualification work.
