# F1-S6 machine mutation authority — binding engineering contract

Status: **F1-S6A working / F1-S6B authorization pending**, 2026-10-18.

## Confirmed operator decision

1. Maintenance Supervisor is the **sole authority** for panel-initiated updates.
2. Update Engine is an executor, not an independent source of authorization.
3. Initial Windows Services installation may run standalone before a Maintenance authority exists.
4. Maintenance-bound Windows Installer/Setup Host must coordinate with the same machine mutation lease as Supervisor and Update Engine.
5. Any unknown authority, unsafe disk evidence, busy lease, or crash recovery remains **fail-closed**.
6. No panel activation, DB mutation, or host installation is authorized by this contract alone.

## F1-S6A implemented and tested

- Single canonical lease path: `%ProgramData%\SOKNA\Maintenance\update\machine-mutation.lock`.
- A Windows OS **file-handle lease** (`FileShare.Read`, single read/write owner), held for the full operation and released by OS process death. The file is **never deleted** when a holder exits.
- Supervisor's canonical signed Local Stage takes the lease before mutable state changes, returns 409 `MACHINE_MUTATION_LEASE_BUSY` or 503 `MACHINE_MUTATION_LEASE_UNAVAILABLE` when acquisition fails.
- Direct mutating legacy Update Engine commands take the same OS lease; signed read-only `verify-only` and Local inspection do not.
- Windows Services Setup Host obtains the same lock when an existing Maintenance installation is present. Absent a Maintenance root, clean installation remains independent.
- Windows CI tests: two separate processes, competing direct Engine mutation, Stage HTTP contention, crash/restart lease recovery, Verify-only, durable operation evidence, and physical junction checks.

## NOT YET IMPLEMENTED — F1-S6B mandatory release blockers

- A **Supervisor-issued, command/arguments/installation/operation-bound, short-lived, one-use authorization** for delegated Update Engine execution. An independently launched Engine currently obtains the OS lease but is not thereby proven authorized; mere possession of a lease is never sufficient to authorize production mutation.
- An owner-to-child **delegation protocol** that holds the same OS lease across Supervisor-to-Engine execution, with replay/expiry/crash rejection. Parent/child must not try to acquire incompatible exclusive handles.
- Global coordination with every legacy Supervisor slot migration/recovery/home-cycle owner for the *entire* externally launched child lifetime. In-process locking already exists, but it is not a substitute for the cross-process delegation proof.
- Lifecycle qualification of Windows Services Setup Host under a live conflicting lease and a real clean-install baseline.
- Any implementation may not weaken HMAC, anti-rollback, exact Release Set baseline verification, restore/backup boundaries, or Windows services' existing clean-install guarantees.

## F1-S6B acceptance matrix

1. Authorized Supervisor child executes **only** the exact signed command/arguments for its operation.
2. Missing, forged, replayed, expired or cross-install permits fail before any mutation.
3. Supervisor death invalidates delegation; child may not extend or silently restart work.
4. Installer, Stage and legacy Engine operations are mutually exclusive on the same machine, including service/account boundaries and a mixed-versions installation.
5. Recovery Console may reconcile durable evidence but never fabricates a successful activation.
6. Clean install with no Maintenance instance passes; a conflicting installed Maintenance host fails closed without stopping services.
7. Real Windows runner integration passes, then host-specific UAT; M9, M10, P6, V3 and G8 release gates all must PASS independently.

**Release status:** Draft PR only. `panel_update_ready=false`; do not install on Home or Cafe.
