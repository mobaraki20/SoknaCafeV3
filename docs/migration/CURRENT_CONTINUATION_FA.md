# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.1 Explicit Sellables checkpoint:

- Head: `dc096b5d57decf8bf16bfb1ca457491d3c516476`
- implementation commit: `b5fb10fd932b391978835b540721d535e4dd4564`
- `M5 Sellables Gate`: run `36244864710` — **SUCCESS**
- `V3 Component Gates`: run `36244864724` — **SUCCESS**
- M4 regression gates on the same head also remain **SUCCESS**.

Canonical evidence:

- `docs/migration/M5_SELLABLES_AUDIT_FA.md`
- `docs/migration/M4_CLOSURE_EVIDENCE_FA.md`

## Slice status

- F0: COMPLETE at foundation level.
- M1: COMPLETE at contract-extraction / executable-boundary level.
- M2: COMPLETE at Local Core slice level.
- M3: COMPLETE at Public Edge transport/auth/projection slice level.
- M4: COMPLETE — Guest Publish/Runtime/Remote Read Models exit gate satisfied.
- M5.1: **COMPLETE — Explicit Sellables/catalog authority exit gate satisfied.**
- M5.2: **NEXT — canonical Orders services.**
- M6: continuous SCDS cross-cutting track.
- M7..M10: planned / not complete.

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

## Immediate continuation — M5.2 canonical Orders

Do not bulk-copy the historical order stack. Audit/freeze authority first, then implement the smallest canonical transaction owner.

### Historical owners to audit first

- `includes/guest_order_service.php`
- `includes/guest_order_manage_service.php`
- `includes/staff_order_service.php`
- `staff/api_quick_order.php`
- `operator/api_bill.php`
- `orders`, `order_items`, `order_business_sequences`
- table/session ownership only insofar as canonical order commit requires it
- business-date/shift snapshot helpers used by order numbering/commit

### Frozen M5.2 requirements already known from M5.1

1. New committed order lines persist explicit `sellable_kind_snapshot` from the locked canonical item row.
2. Historical committed rows are not backfilled merely to populate sellable classification.
3. Category/station/name/recipe/takeaway must never infer sellable kind.
4. Orders remain Local canonical authority; Public compatibility routes only reach them through already-migrated M3 Realtime.
5. No preparation task, inventory movement, settlement/finance side effect or receipt should be invented outside the historical canonical commit boundary.
6. Idempotency/client-token behavior, business-order numbering and business-date/shift snapshots must be audited before implementation.
7. M5.2 must not silently pull Table Draft/Staff Quick Order ownership forward; those are the following sub-slice unless a shared primitive is strictly required for canonical Orders.
8. Any user-facing Orders UI touched by the migration must follow SCDS in the same sub-slice.

### M5 remaining dependency order

After canonical Orders:

1. Staff Quick Order + Table Draft;
2. Preparation permission/action owner;
3. Inventory;
4. Supply/Purchase;
5. Expenses;
6. Financial periods/Settlement/Reconciliation;
7. Tax/cross-domain finance integration;
8. Accommodation/Center adapters.

Do not start M7/M8/M9 implementation as a substitute for M5 domain migration.

## Failure-isolation note carried forward

M4 proved boundary-level Local-down/Public-down/Internet-or-sync-loss behavior. It did not claim packet-level NIC/DNS/proxy/browser chaos qualification; that remains explicit M10 release-qualification work.