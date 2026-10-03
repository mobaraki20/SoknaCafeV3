# M5.6 — Supply / Purchase Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation head: `2a18c0e3540f90b219b1be0b61dbdd03c96dd400`
- `M5 Supply Gate` PR run `36259004015` — **SUCCESS**
- `M5 Inventory Gate` PR run `36259004004` — **SUCCESS**
- `M5 Orders Gate` PR run `36259004003` — **SUCCESS**
- `M5 Table Draft Gate` PR run `36259003998` — **SUCCESS**
- `M5 Preparation Gate` PR run `36259003997` — **SUCCESS**
- `M5 Sellables Gate` PR run `36259004010` — **SUCCESS**
- `V3 Component Gates` PR run `36259004019` — **SUCCESS**
- M4 Guest Renderer run `36259004006` — **SUCCESS**
- M4 Failure Isolation run `36259003994` — **SUCCESS**

## Historical authority audited

- `modules/Supply/domain.php`
- `modules/Supply/queries.php`
- `operator/supply-needs.php`
- `admin/purchases.php`
- `admin/purchases_batch.php`
- Supply schema in `database/schema.sql`
- Supply permission helpers in `includes/functions.php`
- Deferred-safe Supply handlers in `includes/deferred.php`
- Phase 5 Deferred receipt/review ownership and tests

## Frozen Supply semantics preserved

1. Supply is an explicit lifecycle: Need → Preparing → physical Receive.
2. Low-stock state is only a signal; it does not create demand or stock movement by itself.
3. Requested, fulfilled, preparing and uncommitted quantities remain distinct.
4. Interactive need edit may change only uncommitted demand; already-Preparing quantity is frozen under buyer responsibility.
5. Deferred `supply.need.create` is additive and is not equivalent to interactive upsert.
6. Preparing moves currently-uncommitted demand into buyer responsibility without touching Inventory.
7. Return/Unavailable moves Preparing quantity back out of buyer responsibility without touching Inventory.
8. Only physical Receive increases Inventory.
9. Receive routes through the M5.5 canonical Inventory movement owner; Supply owns no second movement or balance SQL path.
10. Receipt request tokens are idempotent; lost ACK/browser retry cannot create a second movement.
11. Partial receive allocates oldest-first to underlying needs.
12. Over-receive increases stock by the actual physical quantity but never creates negative or phantom demand.
13. Receipt allocation is durably audited by per-need allocation rows.
14. Free-name needs resolve to an existing compatible Inventory item or create a `needs_review` item through the Inventory owner.
15. Batch receive validates/locks every selected group before the first stock mutation and commits atomically.
16. Partial batch replay is rejected; full replay is idempotent.
17. Need reporting and purchase/receive authority remain distinct and Local-revalidated.
18. Deferred Supply conflicts produce durable Local review state rather than silently overwriting current Local state.
19. Review approval/rejection is explicit and manager-owned.
20. Public remains transport authority only; Local owns business idempotency/result state.

## Local Deferred receipt dependency migrated here

Supply exposed a required dependency that cannot be safely postponed: `supply.need.create` is additive, so domain-level movement idempotency alone cannot protect against a lost ACK.

M5.6 therefore migrates the frozen Local Deferred business-result owners:

- `deferred_work_receipts` — unique installation/request identity, request hash and terminal Local result;
- `deferred_review_items` — one durable review candidate per receipt.

This is **not** a second Public queue. Public still owns pending Deferred transport. Local owns canonical business commit/review/result evidence.

Financial-period binding remains expand-only at this slice: `financial_period_id` is nullable but intentionally has no Financial Period FK/gate until the Finance owner is migrated. M5.6 does not invent Finance authority early.

## V3 owners

- `apps/local-web/database/migrations/0007_m5_supply.sql`
- `apps/local-web/database/migrations/0008_m5_deferred_receipts.sql`
- `apps/local-web/src/Domain/Supply/SupplyException.php`
- `apps/local-web/src/Domain/Supply/SupplyAccessService.php`
- `apps/local-web/src/Domain/Supply/SupplyService.php`
- `apps/local-web/src/Relay/DeferredReceiptService.php`
- `apps/local-web/src/Relay/SupplyDeferredAdapter.php`
- Inventory cross-domain contracts for unreviewed-item creation and purchase-unit quantity resolution
- Bootstrap registrations
- `tests/local-m5-supply-selftest.php`
- `.github/workflows/m5-supply-gate.yml`

## Exit decision

**M5.6 exit gate: SATISFIED.**

Continuation moves to **M5.7 — Tax**. Tax is an additive owner in the historical Phase 6F design: effective-dated configuration, explicit applicability, immutable order/settlement snapshots, deterministic rounding and no historical semantic rewrite.
