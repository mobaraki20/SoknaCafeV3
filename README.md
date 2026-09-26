# SOKNA Cafe V3

SOKNA Cafe V3 is the clean architectural continuation of `mobaraki20/SoknaCafe`.

## Canonical starting point

- Historical repository: `mobaraki20/SoknaCafe`
- Historical engineering baseline: branch `work/reconcile-dev39`
- Baseline commit: `a46435cca57df5bd5b9770efd0bb95390528aa05`
- V3 repository: `mobaraki20/SoknaCafeV3`

The historical repository remains the authoritative archive for old commits, pull requests, reviews, CI runs, handoffs, and phase evidence. It is read-only migration evidence for V3 work: new architecture, contracts, migration implementation and release work belong in this repository. V3 does **not** copy legacy complexity by default. Code is migrated only when its ownership and target V3 component are explicit.

## V3 model

One product, independently releasable components:

1. `apps/local-web` — Local business authority and web application.
2. `apps/public` — Public Edge application; never the primary business authority.
3. `windows/runtime` — Windows/OS integration, supervision, diagnostics and approved OS adapters; no business rules.
4. `windows/print-agent` — separate deployable owner of durable print/device/spooler execution.
5. `platform` — approved infrastructure/runtime dependencies and platform lifecycle.
6. `contracts` — versioned contracts between independently releasable components.

## Current execution point

The single canonical continuation record is:

`docs/migration/CURRENT_CONTINUATION_FA.md`

Current state:

- F0 foundation: complete.
- M1 contract extraction: complete at executable contract-extraction level.
- M2 Local Core: complete at slice level.
- Observer contract reconciliation EOR-01..03: fixed on `ebceeacf21ebdc6996634d113f6cac4a3d5a7083`; workflow `36216627352` SUCCESS.
- EOR-04/EOR-05 reconciliation: current work.
- Next ordered implementation slice after reconciliation: **M3 — Public Edge Persistence, Auth Projection and Relay Transport**.

Historical status files remain evidence, but they are not continuation authority when they disagree with `CURRENT_CONTINUATION_FA.md`.

## Start here

Every human or agent must read, in this order, before changing code:

1. `START_HERE.md`
2. `ARCHITECTURE.md`
3. `PROJECT_LINEAGE.md`
4. `docs/migration/CURRENT_CONTINUATION_FA.md`
5. the README of the component being changed
6. relevant ADRs under `docs/adr/`

Do not revive a legacy owner, workflow, installer path, or duplicate business implementation unless an ADR explicitly authorizes it. Conversation history is not a project source of truth; decisions, evidence, CI state and the exact continuation point must be recorded in-repo.
