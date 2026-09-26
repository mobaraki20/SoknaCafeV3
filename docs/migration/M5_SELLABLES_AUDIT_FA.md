# M5.1 — Explicit Sellables Audit

Status: **IMPLEMENTATION STARTED — historical semantics frozen before code movement**

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

## Historical evidence

Canonical Phase 6B evidence:

- `includes/sellable.php`
- `database/schema.sql`
- `docs/architecture-migration-r2/PHASE6B_LOCAL_MIGRATION.sql`
- `docs/handoffs/PHASE6B_HANDOFF_FA.md`
- `tests/phase6b-sellable-kind-contract.php`
- `admin/item_form.php`
- `admin/items.php`
- menu/catalog and order-write consumers listed by the Phase 6B handoff.

## Frozen semantics

1. Sellable classification is explicit and closed to exactly:
   - `menu_item`
   - `service_item`
2. Missing/legacy read classification normalizes to `menu_item` for backward-compatible reads.
3. Write-time classification is strict; an invalid explicit value is rejected rather than guessed.
4. Service classification must never be inferred from category, preparation station, item name, `staff_only`, recipe, or takeaway mode.
5. `service_item` is classification only; it does not silently mutate independent properties such as preparation station, staff visibility, takeaway permission, availability or price.
6. Historical documented service codes `SERVICE-TAKEAWAY` and `SERVICE-CAKE` were explicitly classified by the old migration; that migration did not classify arbitrary rows by heuristics.
7. Takeaway mode does not imply a service item and must not auto-insert `SERVICE-TAKEAWAY` into an order.
8. Historical committed order rows are never rewritten merely to populate classification.
9. New canonical order lines must snapshot the selected sellable classification when Orders migrate in M5.2; M5.1 does not create Orders tables.

## M5.1 V3 ownership boundary

### Local owns now

- base catalog schema: menus, categories, items and menu membership;
- explicit sellable-kind validation/normalization;
- canonical item classification and read projection.

### Not owned by M5.1

- Orders/order-line business effects or snapshots — M5.2;
- Table Draft / Staff Quick Order — later M5 sub-slice;
- Preparation permission/action semantics — later M5 sub-slice;
- Inventory/recipe ownership — later M5 sub-slice;
- Finance/tax/settlement effects — later M5 sub-slices.

Fields such as `preparation_station`, `staff_only` and `takeaway_allowed` may exist as independent item data because the historical catalog carries them, but M5.1 must not infer `sellable_kind` from those fields or claim their downstream domain behavior.

## Initial V3 implementation

Planned/implemented files for the first executable slice:

- `apps/local-web/database/migrations/0002_m5_sellables.sql`
- `apps/local-web/src/Domain/Sellables/SellableKind.php`
- `apps/local-web/src/Domain/Sellables/SellableRepository.php`
- Local Bootstrap registration
- `tests/local-m5-sellables-selftest.php`

Database-level guard:

`items.sellable_kind` is constrained to `menu_item | service_item` so invalid classifications cannot bypass service-layer validation through direct SQL.

## Exit gate for M5.1

M5.1 is not complete until all are true:

1. Local migration stack is MariaDB-green and replay/idempotency-safe;
2. explicit kind service/repository tests pass;
3. no Orders/Preparation/Inventory/Finance table owner leaks into the M5.1 migration;
4. no heuristic service inference exists;
5. catalog read projection exposes explicit kind + label;
6. historical order compatibility requirement is frozen for M5.2 without rewriting old committed rows;
7. migration matrix status is changed only when executable evidence justifies it.

After this gate, continuation moves to **M5.2 canonical Orders services**, which must persist `sellable_kind_snapshot` for newly committed lines without backfilling historical committed order rows.
