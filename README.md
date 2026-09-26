# SOKNA Cafe V3

SOKNA Cafe V3 is the clean architectural continuation of `mobaraki20/SoknaCafe`.

## Canonical starting point

- Historical repository: `mobaraki20/SoknaCafe`
- Historical engineering baseline: branch `work/reconcile-dev39`
- Baseline commit: `a46435cca57df5bd5b9770efd0bb95390528aa05`
- V3 repository: `mobaraki20/SoknaCafeV3`

The historical repository remains the authoritative archive for old commits, pull requests, reviews, CI runs, handoffs, and phase evidence. V3 does **not** copy legacy complexity by default. Code is migrated only when its ownership and target V3 component are explicit.

## V3 model

One product, independently releasable components:

1. `apps/local-web` — Local business authority and web application.
2. `apps/public` — Public Edge application; never the primary business authority.
3. `windows/runtime` — Windows/OS integration, supervision, diagnostics and hardware adapters; no business rules.
4. `platform` — approved infrastructure/runtime dependencies and platform lifecycle.
5. `contracts` — versioned contracts between independently releasable components.

## Start here

Every human or agent must read, in this order, before changing code:

1. `START_HERE.md`
2. `ARCHITECTURE.md`
3. `PROJECT_LINEAGE.md`
4. the README of the component being changed
5. relevant ADRs under `docs/adr/`

Do not revive a legacy owner, workflow, installer path, or duplicate business implementation unless an ADR explicitly authorizes it.
