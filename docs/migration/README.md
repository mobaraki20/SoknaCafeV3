# V3 Migration Inventory

This directory is the operational bridge from historical `mobaraki20/SoknaCafe` to the explicit V3 owners.

## Read order

1. `MIGRATION_SLICES.md` — ordered execution plan and exit gates.
2. `MIGRATION_MATRIX.csv` — capability-by-capability owner/treatment/status inventory.
3. relevant contract/component/ADR documents before implementation movement.

## Baseline

- historical repository: `mobaraki20/SoknaCafe`
- branch: `work/reconcile-dev39`
- baseline commit: `a46435cca57df5bd5b9770efd0bb95390528aa05`
- continuation handoff: `docs/handoffs/DEV39_RECONCILIATION_2026-09-25_FA.md`
- historical R2 inventory seed: `docs/architecture-migration-r2/MIGRATION_MATRIX.csv`
- V3 Design Authority: `SCDS-CANONICAL-2026-R1`

## Rule

The matrix is an ownership/migration plan, not a bulk-copy manifest.

For every capability:
1. locate the real historical owner and tests;
2. determine the target V3 owner;
3. classify treatment before copying code;
4. preserve valid business behavior and data semantics;
5. correct legacy coupling/UI debt that conflicts with V3 contracts;
6. define contract/data/release boundaries;
7. migrate in a small testable slice;
8. delete/retire duplicate owners after consumers move;
9. update this matrix with evidence/status.

## Treatment vocabulary

- `migrate`: ownership already matches V3; move with minimal structural refactor.
- `refactor_then_migrate`: valuable implementation exists but current coupling/ownership conflicts with V3.
- `split`: current scope contains responsibilities that belong to multiple V3 owners.
- `preserve_external`: preserve a mature independent boundary rather than absorb it into another owner.
- `replace`: preserve contract/behavior but replace implementation/lifecycle owner.
- `retire`: do not carry implementation forward after migration.
- `reference_only`: keep as historical evidence, not runtime/product code.

## Slice status vs product completion

`status` در `MIGRATION_MATRIX.csv` فقط وضعیت تاریخی **حرکت همان migration slice** را نگه می‌دارد و دیگر authority برای ادعای Product Complete نیست:

- `inventory`: slice فقط inventory شده؛
- `ready`: slice برای حرکت آماده است؛
- `in_progress`: migration/refactor همان slice فعال است؛
- `migrated`: owner/contract/tests/cleanup همان **scoped slice** انجام شده؛
- `blocked`: همان slice blocker دارد.

ستون مستقل `completion_level` authority فعلی completion است و از `docs/product/COMPLETION_STATUS_POLICY_FA.md` پیروی می‌کند:

- `INVENTORY_ONLY`
- `CORE_COMPLETE`
- `PRODUCT_OPEN`
- `PRODUCT_COMPLETE`
- `RELEASE_BLOCKED`
- `RELEASE_COMPLETE`
- `SUPERSEDED`
- `FUTURE_ONLY`

قاعدهٔ قطعی: `status=migrated` **هیچ‌وقت به‌تنهایی** به معنی `PRODUCT_COMPLETE` نیست. Backend/core slice می‌تواند migrated باشد و در عین حال `completion_level=CORE_COMPLETE` باقی بماند تا UI/workflow/integration/update/recovery لازم بسته شود.

## Authority rule

When the old default branch conflicts with `work/reconcile-dev39`, the selected dev.39 continuation authority wins for historical facts. V3 architecture/ADRs win for intentional new ownership boundaries.

Do not mark a row `migrated` because files were copied. Migration means ownership, contracts, tests and cleanup are complete for that slice.
