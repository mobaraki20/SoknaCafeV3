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

- Active migration branch: `architecture/v3-foundation`
- Active draft PR: `#1`
- F0 architecture/contract foundation: complete.
- M1 cross-component contract extraction: in progress.
  - Realtime and Deferred-safe wire semantics, exact operation schemas and compatibility vectors are executable in CI.
  - Runtime historical audit confirms dev39 exposed CLI/state-file/SCM semantics rather than a stable Runtime HTTP API.
  - Print Agent historical audit covers Print API v4 and the loopback wake/preview bridge while preserving V3 separate-deployable ownership.
  - Final V3 Runtime/Print contract subsets remain Draft and must become executable before producer/consumer implementation moves across component boundaries.

For the exact continuation state, read:
1. `docs/migration/M1_CONTRACT_EXTRACTION_STATUS_FA.md`
2. `docs/migration/MIGRATION_SLICES.md`
3. `contracts/manifest.json`

## Start here

Every human or agent must read, in this order, before changing code:

1. `START_HERE.md`
2. `ARCHITECTURE.md`
3. `PROJECT_LINEAGE.md`
4. the current migration-status file under `docs/migration/`
5. the README of the component being changed
6. relevant ADRs under `docs/adr/`

Do not revive a legacy owner, workflow, installer path, or duplicate business implementation unless an ADR explicitly authorizes it. Conversation history is not a project source of truth; decisions, evidence, CI state and the exact continuation point must be recorded in-repo.
