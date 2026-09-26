# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.2 Canonical Orders checkpoint:

- Head: `b1628c44c113372516bf094b140241a53993bc53`
- `M5 Orders Gate`: run `36253424745` — **SUCCESS**
- `M5 Sellables Gate`: run `36253424706` — **SUCCESS**
- `V3 Component Gates`: run `36253424830` — **SUCCESS**
- M4 regression gates on the same head — **SUCCESS**

Canonical evidence:

- `docs/migration/M5_ORDERS_AUDIT_FA.md`
- `docs/migration/M5_SELLABLES_AUDIT_FA.md`

## Slice status

- F0: COMPLETE at foundation level.
- M1: COMPLETE at contract-extraction / executable-boundary level.
- M2: COMPLETE at Local Core slice level.
- M3: COMPLETE at Public Edge transport/auth/projection slice level.
- M4: COMPLETE — Guest Publish/Runtime/Remote Read Models exit gate satisfied.
- M5.1: **COMPLETE — Explicit Sellables/catalog authority exit gate satisfied.**
- M5.2: **COMPLETE — canonical Orders authority exit gate satisfied.**
- M5.3: **NEXT — Staff Quick Order + server-persistent Table Draft.**
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

## Immediate continuation — M5.3 Staff Quick Order + Table Draft

Historical authority to audit/preserve:

- `docs/handoffs/PHASE6C_HANDOFF_FA.md`
- `docs/architecture-migration-r2/PHASE6C_DESIGN_NOTES_FA.md`
- `includes/table_draft.php`
- `includes/staff_order_service.php`
- `staff/api_quick_order.php`
- historical Realtime `table_draft.*` adapter/actor behavior

Frozen requirements:

1. exactly one active draft per table;
2. server/Local persistence is authoritative; browser-only draft state is not;
3. optimistic `version` prevents stale writers overwriting newer state;
4. Save/Edit Draft creates no `orders` row, no business order number and no downstream business side effect;
5. no auto-expiry — only explicit Finalize/Cancel closes the lifecycle;
6. Finalize revalidates current table/session/catalog/price/availability/fulfillment and current actor permission;
7. Finalize delegates to M5.2 canonical `OrderCommitService`; no second order-commit SQL owner;
8. retried Finalize resolves idempotently to the already-finalized order;
9. remote draft mutation remains Realtime/Local-required and never Deferred-safe;
10. no Preparation/Inventory/Finance/Print ownership is pulled forward.

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