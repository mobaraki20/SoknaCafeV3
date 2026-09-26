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
- M3 Public Edge transport/auth/projection: complete at slice level.
- M4 Guest Publish/Runtime/Remote Read Models: complete at slice level.
- M5.1 Explicit Sellables: complete.
- M5.2 Canonical Orders: complete.
- M5.3 Staff Quick Order + server-persistent Table Draft: complete.
- M5.4 Preparation permission/action owner: complete.
- M5.5 Inventory: complete.
- M5.6 Supply/Purchase: complete.
- M5.7 Tax: complete; verified by M5 Tax Gate `36259999682` and V3 Component Gates push `36259996859`.
- Next ordered slice: **M5.8 — Expenses**.

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
