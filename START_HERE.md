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
4. the target component README
5. relevant ADRs

Then identify:

- owner component,
- contract impact,
- data owner,
- release artifact impacted,
- UI/behavior preservation impact,
- tests required,
- rollback behavior.

If ownership is unclear, stop implementation and add or update an ADR first. If a user-visible behavior would change without explicit approval, treat it as a regression rather than an incidental cleanup.
