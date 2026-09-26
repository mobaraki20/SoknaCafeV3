# START HERE — SOKNA Cafe V3

This is the operational entrypoint for every human or agent.

## Current truth

SOKNA V3 has strong validated backend/component foundations, but the product migration is **not yet complete**. Do not start from the historical `3.0.0-rc.1 / Manual UAT next` assumption.

Read in this order:

1. `docs/migration/CURRENT_CONTINUATION_FA.md`
2. `docs/product/MASTER_PRODUCT_INTENT_AUDIT_FA.md`
3. `COMPONENTS.json`
4. `ARCHITECTURE.md`
5. `PROJECT_LINEAGE.md`
6. UI/Design System authority for any user-facing work
7. target component README/handoff and relevant contracts/ADRs

Historical migration/M10 documents remain evidence, not current product-completion authority.

## Source hierarchy

1. Latest explicitly approved V3 decisions/ADRs win implementation-method conflicts.
2. Initial architecture handoff R2 remains product-intent authority unless explicitly superseded.
3. Legacy final post-UI baseline `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05` is observable UI/behavior evidence.
4. dev.26 is business provenance/historical behavior evidence.
5. Current V3 source proves what exists, not what may silently be removed from intended product scope.

## Component rule

One monorepo, independently releasable components, explicit contracts. Use `COMPONENTS.json` and work on one component at a time. Do not re-audit closed cores without an evidence defect or explicit contract impact.

## Final deployment decision

`docs/adr/0004-deployment-and-component-lifecycle-v2.md` supersedes the old unified Windows composition where relevant:

- Local Web: ZIP + Browser Setup Wizard; no PS1/EXE installer.
- Windows Services installer: Runtime + Print Agent only.
- Infrastructure prerequisites: detect/acquire/manual fallback; not bundled.
- Public Edge: independent deploy package.
- Local Update Center + Public Emergency Console are required product surfaces.

## Mandatory preservation rules

Local remains final Business Authority. Public is not a second full business DB/admin clone. Runtime never writes business tables or decides business validity. Realtime and Deferred-safe remain separate. SCDS is canonical and UI migration is not redesign permission. Important committed history uses correction/reversal/audit, not hard deletion. Recovery must survive broken application releases.

Manual UAT resumes only after product-gap closure, new M9/M10 qualification, and a new RC.
