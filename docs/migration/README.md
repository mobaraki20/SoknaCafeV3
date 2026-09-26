# V3 Migration Inventory

This directory is the operational bridge from historical `mobaraki20/SoknaCafe` to the explicit V3 owners.

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

## Status vocabulary

- `inventory`: classified, no implementation migration claimed.
- `ready`: enough source/contract/test evidence exists to start a migration slice.
- `in_progress`: implementation movement/refactor is active.
- `migrated`: target owner exists and legacy duplicate is removed/retired for the scoped slice.
- `blocked`: requires an explicit dependency/decision/evidence before migration.

## Authority rule

When the old default branch conflicts with `work/reconcile-dev39`, the selected dev.39 continuation authority wins for historical facts. V3 architecture/ADRs win for intentional new ownership boundaries.

Do not mark a row `migrated` because files were copied. Migration means ownership, contracts, tests and cleanup are complete for that slice.
