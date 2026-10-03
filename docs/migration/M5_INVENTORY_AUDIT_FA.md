# M5.5 — Inventory Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation head: `a8cc7dbf5e9f0861aa6c255c8382d431e12e1bbb`
- `M5 Inventory Gate` PR run `36258340099` — **SUCCESS**
- `M5 Orders Gate` PR run `36258340117` — **SUCCESS**
- `M5 Table Draft Gate` PR run `36258340111` — **SUCCESS**
- `M5 Preparation Gate` PR run `36258340148` — **SUCCESS**
- `M5 Sellables Gate` PR run `36258340092` — **SUCCESS**
- `V3 Component Gates` push run `36258337148` — **SUCCESS**
- M4 Failure Isolation PR run `36258340173` — **SUCCESS**
- M4 Guest Renderer PR run `36258340152` — **SUCCESS**

## Historical authority audited

- `includes/inventory.php`
- `database/schema.sql`
- `includes/deferred.php`
- Inventory admin/count/receive/waste routes
- `includes/modules.php` Inventory ownership registry
- historical Phase 5 Deferred-safe tests
- historical Inventory regression/defect-class tests

## Frozen Inventory semantics preserved

1. The immutable movement ledger is canonical; `inventory_balances` is a projection, not an independent mutation authority.
2. Movement writes are transactional and support a unique idempotency key.
3. Historical/backdated quantity or cost corrections rebuild the projection in `occurred_at,id` order.
4. Moving-average cost semantics preserve known/partial/estimated/unknown cost state rather than relabelling incomplete historical cost as known.
5. Exactly one count draft may be open; count start is serialized by a Local mutex row.
6. Periodic counts snapshot current quantity/cost when an item is first counted, not when the whole session starts.
7. Count draft edit and count Finalize are separate authorities; Finalize requires `inventory_finalize`.
8. Deferred-safe count edits may change periodic draft lines only; Opening inventory remains Local-only.
9. Deferred `inventory.waste` revalidates Local actor/capability and expected balance version, but lost-ACK retry deduplicates by request-derived movement key before conflict checking.
10. Deferred stale waste/count mutation returns a review outcome rather than silently overwriting newer Local state.
11. `inventory.count_finalize` is not Deferred-safe.
12. Recipe versions are immutable; order-accounted consumption captures the exact recipe and cost basis at accounting time.
13. Accounted-order Inventory work is a durable local event and an optional secondary effect: Inventory failure does not make the protected Order transaction fail.
14. Recipe-consumption movement keys make retried Order/accounted processing idempotent.
15. Supply/Purchase and Finance tables remain outside Inventory ownership.

## V3 owners

- `apps/local-web/database/migrations/0006_m5_inventory.sql`
- `apps/local-web/src/Domain/Inventory/InventoryException.php`
- `apps/local-web/src/Domain/Inventory/InventoryService.php`
- `apps/local-web/src/Domain/Inventory/InventoryCountService.php`
- `apps/local-web/src/Domain/Inventory/InventoryOrderService.php`
- `apps/local-web/src/Relay/InventoryDeferredAdapter.php`
- Order `accounted` integration through the canonical `OrderCommitService`
- Bootstrap registrations
- `tests/local-m5-inventory-selftest.php`
- `.github/workflows/m5-inventory-gate.yml`

## Defects closed during migration

- Deferred waste desired-state retry is deduplicated before expected-version conflict evaluation.
- Balance/count optimistic timestamp versions use microsecond precision; explicit balance projection updates use `NOW(6)`, preventing same-second stale-write collisions.
- Recipe version allocation is serialized by locking the canonical menu-item row rather than relying on aggregate `MAX(...) FOR UPDATE`.

## Deliberately deferred dependencies

The historical Inventory event stream also contains `quantity_adjusted`, `cancelled`, and `reaccounted` events. Their **producers** belong to Order edit/cancel/reaccount flows that have not yet been migrated as canonical V3 mutation owners. M5.5 keeps their schema vocabulary for compatibility but does not invent or duplicate those upstream owners.

Likewise Supply receipt remains a Supply/Purchase owner that calls the Inventory movement contract; M5.5 does not migrate Supply state or receipt tables.

## Exit decision

**M5.5 exit gate: SATISFIED.**

Continuation moves to **M5.6 — Supply/Purchase**. Preserve Supply Need → Preparing → physical Receive semantics, keep batch receive atomic, and route every stock increase through the M5.5 Inventory movement owner.
