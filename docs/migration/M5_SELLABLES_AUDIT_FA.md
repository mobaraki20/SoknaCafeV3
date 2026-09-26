# M5.1 — Explicit Sellables Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation commit: `b5fb10fd932b391978835b540721d535e4dd4564` — `feat: start M5 explicit Sellables authority`
- contract-alignment commit: `dc096b5d57decf8bf16bfb1ca457491d3c516476` — `test: accept closed M4 ownership wording`
- `M5 Sellables Gate`: run `36244864710` — **SUCCESS**
- `V3 Component Gates`: run `36244864724` — **SUCCESS**
- `M4 Guest Renderer Gate`: run `36244864742` — **SUCCESS**
- `M4 Failure Isolation Gate`: run `36244864744` — **SUCCESS**

## Historical evidence

Canonical Phase 6B evidence:

- `includes/sellable.php`
- `database/schema.sql`
- `docs/architecture-migration-r2/PHASE6B_LOCAL_MIGRATION.sql`
- `docs/handoffs/PHASE6B_HANDOFF_FA.md`
- `tests/phase6b-sellable-kind-contract.php`
- `tests/phase6b-sellable-kind-local.php`
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
9. New canonical order lines must snapshot the selected sellable classification when Orders migrate in M5.2; M5.1 intentionally does not create Orders tables.

## V3 ownership boundary

### Local owner established in M5.1

- base catalog schema: `menus`, `categories`, `menu_categories`, `items`, `menu_items`;
- explicit sellable-kind validation/normalization;
- canonical item classification and catalog read projection.

### Deliberately not owned by M5.1

- Orders/order-line business effects or snapshots — M5.2;
- Table Draft / Staff Quick Order — later M5 sub-slice;
- Preparation permission/action semantics — later M5 sub-slice;
- Inventory/recipe ownership — later M5 sub-slice;
- Finance/tax/settlement effects — later M5 sub-slices.

Fields such as `preparation_station`, `staff_only` and `takeaway_allowed` remain independent catalog attributes. M5.1 neither infers `sellable_kind` from them nor claims their downstream domain behavior.

## Implemented V3 owners

- `apps/local-web/database/migrations/0002_m5_sellables.sql`
- `apps/local-web/src/Domain/Sellables/SellableKind.php`
- `apps/local-web/src/Domain/Sellables/SellableRepository.php`
- Local Bootstrap `sellables()` registration
- `tests/local-m5-sellables-selftest.php`
- updated ordered Local MariaDB migration self-test
- `.github/workflows/m5-sellables-gate.yml`

Database-level guard:

`items.sellable_kind` is constrained to `menu_item | service_item`, so direct SQL cannot introduce a third inferred classification.

## Exit evidence

The dedicated M5 MariaDB gate proves:

- ordered Local migration stack applies on real MariaDB and replays idempotently;
- catalog tables exist while `orders`, `order_items`, `table_drafts`, inventory and finance owners do not leak into M5.1;
- invalid write-time kind is rejected;
- a menu item with service-like name/station/staff flags remains `menu_item` when explicitly classified so;
- an explicit service item remains `service_item` without rewriting independent properties;
- changing kind does not change preparation station or staff visibility;
- direct SQL with a third kind is rejected by the database constraint;
- catalog projection exposes explicit kind plus label;
- migration contains no historical `order_items` rewrite.

The full `V3 Component Gates` run on the same head also passed, including the Public M3/M4 contract suite. A brittle M4 documentation-string assertion found on the prior run was corrected without weakening schema/authority invariants; the rerun is green.

## Exit decision

**M5.1 exit gate: SATISFIED.**

Continuation moves to **M5.2 — canonical Orders services**.

M5.2 must preserve the frozen rule that newly committed order lines snapshot `sellable_kind`, while historical committed order rows are not backfilled merely to satisfy the new classification field.