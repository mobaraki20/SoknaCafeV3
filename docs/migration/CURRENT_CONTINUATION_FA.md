# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.7 Tax checkpoint:

- Head: `23473d0a04484190ec194bb667f93d6d0fa8f859`
- `M5 Tax Gate`: PR run `36259999682` — **SUCCESS**
- `V3 Component Gates`: push run `36259996859` — **SUCCESS**
- M5 Orders / Inventory / Supply / Preparation / Sellables regressions — **SUCCESS**
- M4 regressions — **SUCCESS**

Canonical evidence:

- `docs/migration/M5_TAX_AUDIT_FA.md`
- `docs/migration/M5_SUPPLY_AUDIT_FA.md`
- `docs/migration/M5_INVENTORY_AUDIT_FA.md`
- `docs/migration/M5_PREPARATION_AUDIT_FA.md`
- `docs/migration/M5_TABLE_DRAFT_AUDIT_FA.md`
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
- M5.3: **COMPLETE — Staff Quick Order + server-persistent Table Draft exit gate satisfied.**
- M5.4: **COMPLETE — Preparation permission/action owner exit gate satisfied.**
- M5.5: **COMPLETE — Inventory exit gate satisfied.**
- M5.6: **COMPLETE — Supply/Purchase exit gate satisfied.**
- M5.7: **COMPLETE — Tax exit gate satisfied.**
- M5.8: **NEXT — Expenses.**
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

## Immediate continuation — M5.8 Expenses

Audit/freeze before implementation:

- `includes/expenses.php`
- `admin/expenses.php`
- Expense schema and category seeds
- Financial Period identity/lookup helper required at create time
- Deferred `expense.create` source-request idempotency
- append-only reversal/correction behavior

Known invariants:

1. Expenses are general café expenses only; Inventory/Supply purchase cost is not duplicated here.
2. Expense rows are immutable after commit.
3. Reversal is a new append-only row; original expense is never edited/deleted.
4. Correction is one transaction: reversal of old row + replacement committed row.
5. `source_request_id` owns exactly-once semantics.
6. Expense category must be active at create time.
7. Every expense belongs to the Financial Period covering its occurred date.
8. Normal mutation of a closed period is forbidden.
9. Because Expenses require period identity, M5.8 may introduce only minimal Financial Period identity/lookup as a prerequisite; period closing and Settlement-aware summaries remain M5.9.
10. Deferred expense mutation must use the same Local receipt/review owner migrated in M5.6.

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