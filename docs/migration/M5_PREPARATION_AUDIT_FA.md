# M5.4 — Preparation Permission/Action Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation head: `8d24779cd059d854614e11d6ba800c963986a6c1`
- `M5 Preparation Gate` run `36254559171` — **SUCCESS**
- `M5 Table Draft Gate` run `36254559182` — **SUCCESS**
- `M5 Orders Gate` run `36254559189` — **SUCCESS**
- `M5 Sellables Gate` run `36254559163` — **SUCCESS**
- `V3 Component Gates` push run `36254558003` — **SUCCESS**
- M4 Guest Renderer run `36254559183` — **SUCCESS**
- M4 Failure Isolation run `36254559168` — **SUCCESS**

## Historical authority audited

- `docs/handoffs/PHASE6A_HANDOFF_FA.md`
- `docs/architecture-migration-r2/PHASE6A_CHECKPOINT_FA.md`
- `includes/preparation_permissions.php`
- `waiter/api_feed.php`
- `waiter/api_action.php`
- `includes/relay_projection.php`
- `user_preparation_areas`
- `order_preparation_claims`
- Preparation Realtime/Deferred boundaries from M1/M3

## Frozen permission semantics preserved

- Preparation only → assigned areas visible/actionable.
- Shift supervision only → all areas visible; none actionable.
- Shift supervision + Preparation → all areas visible; only assigned areas actionable.
- Admin role alone → all areas visible; none actionable.
- Invalid/unassigned areas are rejected; they are never normalized into authorization.
- Local re-resolves the active user/capability/area scope before mutation.
- Public capability/scope is edge filtering only; Local remains final authorization authority.

## Migrated action/read owner

M5.4 migrates the currently dependency-safe Preparation owner:

- side-effect-free Preparation feed over confirmed order lines;
- `claim_order_area` with locked order/line re-read;
- stable item-signature ownership;
- same-user retry idempotency;
- conflict when another user owns the unchanged signature;
- changed-signature upsert behavior compatible with the historical owner;
- audit record for successful claim;
- Realtime `preparation.mutate` Local adapter with active-actor revalidation.

`preparation.mutate` remains Realtime/Local-required and does not enter Deferred-safe transport.

## Deliberately deferred dependency

`preparation_adjustments` is **not** created in M5.4. Its historical producer is the canonical order-item adjustment flow (`operator/api_bill.php` / `order_item_adjustments`), which has not yet been migrated as an independent owner. Pulling only the consumer table/action forward would create an orphaned state machine.

When the Order-item adjustment producer is migrated, the existing historical adjustment delivery/apply semantics must be attached to this Preparation permission boundary rather than creating a second permission owner.

## V3 owners

- `apps/local-web/database/migrations/0005_m5_preparation.sql`
- `apps/local-web/src/Domain/Preparation/PreparationAccessService.php`
- `apps/local-web/src/Domain/Preparation/PreparationService.php`
- `apps/local-web/src/Domain/Preparation/PreparationException.php`
- `apps/local-web/src/Relay/PreparationRealtimeAdapter.php`
- Bootstrap registrations
- `tests/local-m5-preparation-selftest.php`
- `.github/workflows/m5-preparation-gate.yml`

## Exit decision

**M5.4 exit gate: SATISFIED at Preparation permission/action-owner scope.**

Continuation moves to **M5.5 — Inventory**. Preserve the mature movement/idempotency authority; stock-count draft and finalize remain distinct, and remote/deferred ingress must not turn Public into inventory authority.
