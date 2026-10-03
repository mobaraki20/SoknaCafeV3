# M5.3 — Staff Quick Order + Server-persistent Table Draft Audit

Status: **COMPLETE — exit gate satisfied**

Date: 2026-09-26

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified V3 checkpoint:

- implementation head: `b7c021ff7cd318bd40ee6212c2385c540aad3632`
- `M5 Table Draft Gate` run `36254200135` — **SUCCESS**
- `M5 Orders Gate` run `36254200164` — **SUCCESS**
- `M5 Sellables Gate` run `36254200133` — **SUCCESS**
- `V3 Component Gates` push run `36254196611` — **SUCCESS**
- M4 Guest Renderer run `36254200147` — **SUCCESS**
- M4 Failure Isolation run `36254200127` — **SUCCESS**

## Historical authority audited

- `docs/handoffs/PHASE6C_HANDOFF_FA.md`
- `docs/architecture-migration-r2/PHASE6C_CHECKPOINT_FA.md`
- `docs/architecture-migration-r2/PHASE6C_DESIGN_NOTES_FA.md`
- `docs/architecture-migration-r2/PHASE6C_LOCAL_MIGRATION.sql`
- `includes/table_draft.php`
- `includes/staff_order_service.php`
- `staff/api_quick_order.php`
- `includes/relay_dispatch.php`
- `includes/relay_actor.php`
- M3 Public Realtime/Deferred transport owners in V3

## Frozen semantics preserved

1. Exactly one active draft may exist per table.
2. Draft state is Local/server persistent and shared across authorized contexts; browser-only state is not authoritative.
3. Optimistic `version` rejects stale writers.
4. Save/Edit Draft creates no canonical order, business number, preparation work, inventory/finance/print side effect or receipt.
5. Drafts have no auto-expiry; only explicit Finalize/Cancel closes the active lifecycle.
6. Finalize revalidates current Local actor, capability, table/session and current catalog price/orderability.
7. Finalize delegates to M5.2 canonical `OrderCommitService`; Table Draft owns no second `INSERT INTO orders` path and no business-number allocator.
8. Retried Finalize resolves to the already-finalized order without creating a second order or advancing the sequence.
9. Staff Quick Order and Draft Finalize share the same canonical order/catalog authority.
10. Remote Table Draft is Realtime/Local-required and never Deferred-safe.
11. Remote `actor_projection_id=user:<id>` is re-resolved against active Local identity/capability before mutation.
12. Realtime `table_draft.create` forces `expected_version=0` as in the historical adapter instead of trusting caller input.

## V3 owners

- `apps/local-web/database/migrations/0004_m5_table_drafts.sql`
- `apps/local-web/src/Domain/Orders/OrderCatalogService.php`
- `apps/local-web/src/Domain/Orders/StaffQuickOrderService.php`
- `apps/local-web/src/Domain/Orders/TableDraftService.php`
- `apps/local-web/src/Relay/TableDraftRealtimeAdapter.php`
- Bootstrap registrations for order catalog / staff quick order / table draft / realtime adapter
- `tests/local-m5-table-draft-selftest.php`
- `.github/workflows/m5-table-draft-gate.yml`

## Failure/regression evidence closed during implementation

- migration regression gate was advanced to include `0004_m5_table_drafts`;
- M5.2 Orders regression gate was corrected so a now-owned M5.3 table is not falsely classified as leakage;
- realtime Create adapter was corrected to force version zero;
- transport contract test was made literal-safe without weakening the Local-required assertion.

## Exit decision

**M5.3 exit gate: SATISFIED.**

Continuation moves to **M5.4 — Preparation permission/action owner**.

M5.4 must preserve Phase 6A's strict split between visibility and actionability, keep Admin/shift-supervision read-only unless explicit Preparation authority exists, keep mutation scoped to assigned areas, and keep `preparation.mutate` Realtime/Local-required rather than Deferred-safe.
