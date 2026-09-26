# M5.2 — Canonical Orders Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation head: `b1628c44c113372516bf094b140241a53993bc53`
- `M5 Orders Gate` run `36253424745` — **SUCCESS**
- `M5 Sellables Gate` run `36253424706` — **SUCCESS**
- `V3 Component Gates` run `36253424830` — **SUCCESS**
- M4 Guest Renderer / Failure Isolation regression gates — **SUCCESS**

## Historical owners audited

- `includes/guest_order_service.php`
- `includes/guest_order_manage_service.php`
- `includes/staff_order_service.php`
- `staff/api_quick_order.php`
- `operator/api_bill.php`
- `database/schema.sql`
- `includes/business_time.php`
- order numbering/catalog locking helpers in `includes/functions.php`
- transactional menu membership predicate in `includes/menu_catalog.php`
- `docs/handoffs/PHASE6B_HANDOFF_FA.md`

## Frozen semantics preserved

1. Orders remain Local canonical business data.
2. `client_token` is the commit idempotency key; a compatible replay returns the existing order and does not advance numbering.
3. Token reuse across another table/source, or another staff actor, is rejected as conflict.
4. Business order numbers are allocated inside the same transaction through a locked per-business-date sequence.
5. Business date / shift / cutoff are snapshotted at commit.
6. New lines re-read locked canonical catalog state and snapshot item name, current price, fulfillment mode, preparation station and explicit `sellable_kind`.
7. Sellable kind is never inferred from name/category/station/recipe/takeaway/staff visibility.
8. Historical rows are not backfilled merely to populate `sellable_kind_snapshot`.
9. M5.2 does not create preparation work, inventory movement, tax/settlement/finance effects, receipt or print work.
10. Table/session schema in this slice exists only as the minimum canonical order-reference boundary; Table Draft lifecycle is M5.3.

## V3 owners

- `apps/local-web/database/migrations/0003_m5_orders.sql`
- `apps/local-web/src/Domain/Orders/BusinessClock.php`
- `apps/local-web/src/Domain/Orders/OrderCommitException.php`
- `apps/local-web/src/Domain/Orders/OrderCommitService.php`
- Local Bootstrap `businessClock()` / `orders()`
- `tests/local-m5-orders-selftest.php`
- `.github/workflows/m5-orders-gate.yml`

## Exit decision

**M5.2 exit gate: SATISFIED.**

Continuation moves to **M5.3 — Staff Quick Order + server-persistent Table Draft**.

M5.3 must preserve Phase 6C behavior: one active draft/table, optimistic version conflicts, no canonical order or business number before Finalize, explicit Cancel/Finalize only, and Finalize delegates to this canonical M5.2 Orders owner instead of creating a second order SQL owner.
