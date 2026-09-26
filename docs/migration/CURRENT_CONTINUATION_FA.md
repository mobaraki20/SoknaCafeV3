# نقطه ادامه واحد مهاجرت V3

وضعیت این فایل: **CURRENT / canonical continuation authority**

تاریخ: 2026-09-26

## مبنا

- Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
- Active V3 branch: `architecture/v3-foundation`
- Active PR: `#1`

## آخرین checkpoint اجرایی تأییدشده

M5.6 Supply/Purchase checkpoint:

- Head: `2a18c0e3540f90b219b1be0b61dbdd03c96dd400`
- `M5 Supply Gate`: PR run `36259004015` — **SUCCESS**
- `M5 Inventory Gate`: PR run `36259004004` — **SUCCESS**
- `M5 Orders Gate`: PR run `36259004003` — **SUCCESS**
- `M5 Table Draft Gate`: PR run `36259003998` — **SUCCESS**
- `M5 Preparation Gate`: PR run `36259003997` — **SUCCESS**
- `M5 Sellables Gate`: PR run `36259004010` — **SUCCESS**
- `V3 Component Gates`: PR run `36259004019` — **SUCCESS**
- M4 regression gates — **SUCCESS**

Canonical evidence:

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
- M5.7: **NEXT — Tax.**
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

## Immediate continuation — M5.7 Tax

Historical authority to audit/freeze before implementation:

- `includes/tax.php`
- `admin/tax.php`
- `docs/architecture-migration-r2/PHASE6F_LOCAL_MIGRATION.sql`
- `docs/architecture-migration-r2/PHASE6F_TAX_COMPLETION_FA.md`
- `docs/handoffs/HOUSE_TAX_SNAPSHOT_HANDOFF_FA.md`
- `tests/r2-phase6f-tax-contract.php`
- `tests/r2-phase6f-tax-integration-contract.py`
- `tests/accommodation-tax-snapshot-contract.php`
- existing order/settlement/receipt snapshot columns and renderers

Known invariants:

1. Tax is additive/optional; enabling it must not rewrite historical business rows.
2. Tax configuration is effective-dated and future changes must not change already-committed line semantics.
3. Applicability is explicit; it is not inferred from sellable kind, category, station, recipe or service behavior.
4. Order lines snapshot the applicable tax policy/rate at commit.
5. Settlement/receipt calculation consumes immutable snapshots rather than current configuration.
6. Rounding/calculation rules must be deterministic and covered by golden tests.
7. Business receipt may show Tax; preparation ticket must not.
8. Historical lines without Tax snapshots retain legacy/no-tax semantics unless an explicit migration contract says otherwise.
9. Tax UI/config ownership must not become a second Order/Settlement authority.
10. Finance dependencies are integrated through snapshots/calculation contracts, not by rewriting historical settlements.

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