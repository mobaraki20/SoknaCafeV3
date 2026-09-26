# START HERE — SOKNA Cafe V3

This file is the operational entrypoint for every human or agent.

## 1. Do not start from legacy assumptions

The old repository is a historical source, not the active architecture owner. Historical repository:

`mobaraki20/SoknaCafe`

Historical engineering baseline used to start V3:

`work/reconcile-dev39` @ `a46435cca57df5bd5b9770efd0bb95390528aa05`

If old code or documentation conflicts with V3 architecture, V3 documents and ADRs win unless a newer V3 ADR explicitly changes them.

## 2. Product invariant

SOKNA is one product with independently releasable components. A change in one component must not force a release of another component unless a versioned contract or compatibility boundary changes.

## 3. Component ownership

- `apps/local-web`: sole owner of local business rules and primary business data.
- `apps/public`: Public Edge, guest/remote surfaces, safe projections, relay-facing behavior and Public-owned storage only.
- `windows/runtime`: Windows integration, service supervision, hardware/OS adapters, diagnostics and scheduling; no business decisions.
- `windows/print-agent`: separate local/machine-bound owner of durable print/device/spooler execution; no Public/Internet control.
- `platform`: approved Apache/PHP/MariaDB/runtime dependencies and platform lifecycle.
- `contracts`: versioned interfaces and compatibility declarations.

## 4. Mandatory rules

1. Business logic must not be duplicated in Windows Runtime or Public.
2. Public never becomes a full clone of Local business data.
3. Windows Runtime never writes Local business tables directly.
4. Local Web never directly owns Winspool, Registry, Windows Service Control, ACL or elevated OS operations.
5. Full immutable release packages are canonical; delta packages are optional optimization only.
6. Repair, Update and Recover are separate operations.
7. Platform, Runtime, Local and Public have independent versions.
8. CI must be component/path aware. Full Windows acceptance is not required for ordinary Local/Public-only changes.
9. Shared contracts must be versioned and backward-compatible where rolling upgrades require it.
10. Legacy code is migrated by explicit ownership decision, not by bulk copy.
11. Architecture migration is not permission to redesign the UI. `UI_DESIGN_SYSTEM.md` is a mandatory product contract.
12. Refactors must preserve established UI/behavior unless an explicit `REDESIGN_APPROVED` or `BEHAVIOR_CHANGE_APPROVED` decision exists.

## 5. Before editing code

Read, in this order:

1. `ARCHITECTURE.md`
2. `PROJECT_LINEAGE.md`
3. `UI_DESIGN_SYSTEM.md` for any user-facing or interaction-affecting change
4. `docs/migration/CURRENT_CONTINUATION_FA.md` — the single current continuation authority
5. `docs/migration/AGENT_HANDOFF_FA.md` when resuming across chats/agents or from a workspace snapshot
6. `docs/reviews/EXTERNAL_OBSERVER_REVIEW_2026-09-26_FA.md` and disposition any open finding that affects the target slice
7. the target component README
8. relevant ADRs

Historical migration/status documents remain evidence. If they disagree about the current slice, `docs/migration/CURRENT_CONTINUATION_FA.md` is authoritative until intentionally superseded by a newer recorded continuation decision.

The external review is an evidence checkpoint, not an architecture authority. Do
not mechanically change behavior to satisfy it. Trace each applicable finding to
the approved handover/ADR and historical baseline, then record whether it is
preserved behavior, an approved change, a fixed regression, or an open decision.

Then identify:

- owner component,
- contract impact,
- data owner,
- release artifact impacted,
- UI/behavior preservation impact,
- tests required,
- rollback behavior.

If ownership is unclear, stop implementation and add or update an ADR first. If a user-visible behavior would change without explicit approval, treat it as a regression rather than an incidental cleanup.
