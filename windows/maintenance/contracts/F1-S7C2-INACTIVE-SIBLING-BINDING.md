# F1-S7C2 — Real-host inactive Local Web sibling preparation (binding)

**Scope:** Windows Supervisor only. Production release switch and Public deployment remain DISABLED. PR #25 remains DRAFT.

## Inputs and identities
- POST `/v1/local-updates/prepare-inactive-slot` requires signed `web-adapter` request + Idempotency-Key, `stage_operation_id`, `operator_id` and the existing private candidate's idempotency key.
- C1 re-verifies the signed Release Set, staged artifact hashes, installed baseline and the private extracted Web tree. Only `local_only` signed releases with the exact `web-tree-zip-v1` payload/target contract qualify for this path. `paired` and `public_only` require their own separate deployment owner and pairing gates.
- Actual `SoknaApache` service defines authoritative Web/Package layout; no user-supplied filesystem destination is accepted.

## Preconditions for physical sibling writing
1. Real Local `SoknaApache` service is installed; `Web` is a resolvable R8 production junction to a physical `Web.release.*` directory. Its active build and health endpoint are readable and healthy.
2. Signed installed Local version and managed-tree attestation match the actual active Web; migration/recovery ledger is idle.
3. Local lifecycle is IDLE and no rollback evidence/recovery marker exists. `install.lock`, stable active `.sokna-installation.json` and machine persistent binding share the exact installation ID/version.
4. Maintenance Supervisor owns shared OS machine mutation lease. The caller cannot request an arbitrary `PackageRoot`, target path, active pointer, or overlay.
5. Destination uses `ProductionLocalSlotMigrationBridge.BuildProductionSlotPath` in a new sibling under the authoritative package root; prior incomplete incoming or existing target without a committed matching receipt requires inspection, not overwrite.

## Copy and validation
- Source: verified C1 private Web tree. Copy listed managed files only, directly into a unique `Web.release.*.__incoming.<stage-id>` sibling.
- Overlay: copy approved machine-local PHP files from the existing persistent snapshot. Rebind `.sokna-installation.json` **only in the target sibling** using `RoutineLocalBinding.PrepareTarget`. Do not write to persistent, active Web or legacy release.
- Verify managed-tree SHA-256, version, binding/installation ID, overlay hashes, unchanged SQL migration catalog, target Browser Setup CLI read-only status, current lifecycle and Apache health again.
- Atomic directory rename from completed incoming sibling to previously ABSENT `Web.release.*`; commit receipt and SHA marker in Maintenance ProgramData; a crash/receipt mismatch is quarantined.
- Replay with exactly the same idempotency key re-verifies the slot and the actual active pointer; changed key or evidence is blocked.
- Response always `promotion_qualified=false`, `activation_allowed=false`, `activation_performed=false`, `database_mutation_performed=false`, `public_mutation_performed=false`.

## Tests and important limitation
- Windows CI compiles the actual production coordinator and tests an isolated junction, target binding, copy/hash, no active/persistent mutation, rejection of overwritten/tampered files.
- CI runner intentionally has NO installed `SoknaApache`. Signed HTTP flow must fail CLOSED there (503); it cannot demonstrate real target browser setup or real MariaDB compatibility.
- This is an **engineering candidate**, not a real-host UAT. Before any machine install, require backup integrity and actual Windows host evaluation plus M9/M10/P6/V3/G8 release qualification; all must independently pass.
- Local/Public pairing stays explicit and future-owned by Public Maintenance Kernel; this stage cannot alter Public.

## Next
- S7C3: UAT matrix for real existing R8 host, same/changed build, overlay, active health, backup and migration compatibility; confirm no downtime.
- S7D: independent Activate transaction/journal, atomic pointer switch and pre-DB rollback, only after qualified backups.
- S7E: DB forward boundary with forward-only recovery, no unsafe automatic database restoration.

No deployment, service stop, MariaDB schema mutation, active junction switch, or Public host operations performed by this PR.
