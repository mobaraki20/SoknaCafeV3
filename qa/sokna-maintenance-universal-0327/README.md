# Maintenance 0.3.27 universal bridge — QA design (2026-11-11)

**Scope:** Windows Maintenance Supervisor + Update Engine only. Do not change Local, Public, DB, RecoveryConsole, Print or R125 history. No change to normal Local/Public user-facing update flow.

## Root cause addressed
The 0.3.27 independent installer only accepts an exact 0.3.26 predecessor. Running it on an otherwise healthy 0.3.22 home host produces \`MAINTENANCE_BASELINE_UNSUPPORTED_OR_REINSTALL\` in \`BASELINE_VERSION\` before recording observed versions.

## New one-file orchestrator
An outer Inno EXE bundles the *unchanged official QA 0.3.26.5 independent EXE* (SHA256 93981643923860e9d78bb7280be7d6d9f8e960bdbba4ffb9dea166bd59197874) and the *unchanged QA 0.3.27 independent EXE* (SHA256 cd55273e84cba349cc59fc260a7457f720f3b2752876277ad4f404868e598dd5).

- 0.3.22/23/24/25 => 0.3.26 => 0.3.27.
- 0.3.26 => 0.3.27.
- 0.3.27 => exact replay verification through 0.3.27 installer.
- Unknown/mismatched binary versions => safe failure, **never** guess or reinstall.
- The two existing, fully qualified inner installers remain solely responsible for mutation, service identity, signed health, snapshot, rollback and journal checks.
- The wrapper verifies the physical Supervisor/Engine versions, preserved Recovery hash, and both immutable installer hashes.
- If stage 0.3.26 commits and the 0.3.27 stage fails, the host stays at a verified 0.3.26 intermediate; this is a **failed overall upgrade**, not falsely labelled as a complete 0.3.27 or a rollback. No DB mutation is expected.
- The original 0.3.27 app files, version proof and R125 operation evidence remain unchanged.

## Hard release gates
- Compile and test the *actual outer EXE* in Windows.
- Test one-call physical host upgrades from 0.3.22 with preserved Recovery 0.1.1, and from 0.3.26, plus repeat .27 and injected failure.
- Reject unknown/mixed versions and tampered payload, with versions present in safe report.
- Verify exact EXE SHA256 in final artifact, not only source files.
- Do not claim Production ready or provide it for normal installation while tests fail, binaries are QA only or original host proof is missing.

**Status:** Source candidate. No installed computer touched. No Production release authorised.
