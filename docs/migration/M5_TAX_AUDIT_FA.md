# M5.7 — Tax Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation head: `23473d0a04484190ec194bb667f93d6d0fa8f859`
- `M5 Tax Gate` PR run `36259999682` — **SUCCESS**
- `M5 Tax Gate` push run `36259996908` — **SUCCESS**
- `V3 Component Gates` push run `36259996859` — **SUCCESS**
- M5 Orders / Inventory / Supply / Preparation / Sellables regression gates — **SUCCESS**
- M4 Guest Renderer / Failure Isolation regression gates — **SUCCESS**

## Historical authority audited

- `includes/tax.php`
- `admin/tax.php`
- `docs/architecture-migration-r2/PHASE6F_LOCAL_MIGRATION.sql`
- `docs/architecture-migration-r2/PHASE6F_TAX_COMPLETION_FA.md`
- `docs/handoffs/HOUSE_TAX_SNAPSHOT_HANDOFF_FA.md`
- `tests/r2-phase6f-tax-contract.php`
- `tests/r2-phase6f-tax-integration-contract.py`
- accommodation Tax snapshot contract

## Frozen Tax semantics preserved

1. Tax is optional and defaults disabled.
2. Catalog and `orders.total_amount` remain pre-tax; historical meaning is not rewritten.
3. Default rates and per-item policies are append-only/effective-dated.
4. Item policy vocabulary remains `inherit_default` / `exempt` / `custom_rate`.
5. Each new order line snapshots policy, basis-point rate and owning version IDs.
6. Later rate/policy changes never rewrite an existing order-line snapshot.
7. Historical/pre-Tax lines remain `disabled` with rate zero.
8. Tax is calculated after proportional invoice-discount allocation.
9. Monetary Tax rounding uses deterministic integer half-up arithmetic.
10. Module enable/disable is blocked while live account rows exist, preventing one open account from spanning two Tax regimes.
11. The first effective rate may not be scheduled only in the future and may not be introduced while live account rows exist.
12. Tax ownership does not create Settlement/Printing side effects inside canonical Order commit.

## V3 owners

- `apps/local-web/database/migrations/0009_m5_tax.sql`
- `apps/local-web/src/Domain/Tax/TaxException.php`
- `apps/local-web/src/Domain/Tax/TaxService.php`
- canonical Order line integration in `OrderCommitService`
- Bootstrap registration
- `tests/local-m5-tax-selftest.php`
- `.github/workflows/m5-tax-gate.yml`

## Deliberately deferred Tax consumers

The historical Phase 6F migration also adds Tax allocation fields to Settlement records/lines and wires customer receipts, reporting and accommodation invoice snapshots. Those consumers are **not pulled forward here** because their canonical V3 owners have not yet been migrated.

M5.7 only adds the Tax owner, pure calculator, effective history and immutable Order snapshots. Settlement will consume these snapshots later; it must never recompute historical lines from the then-current rate.

## Exit decision

**M5.7 exit gate: SATISFIED.**

Continuation moves to **M5.8 — Expenses**. Historical Expense semantics require a Financial Period identity at create time; M5.8 may therefore introduce only the minimal period identity/lookup prerequisite required by Expenses, while period closing, close summaries and Settlement-dependent closure remain M5.9.
