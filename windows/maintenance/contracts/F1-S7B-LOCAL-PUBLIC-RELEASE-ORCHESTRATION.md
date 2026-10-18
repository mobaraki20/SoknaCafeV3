# F1-S7B — Local/Public release transaction boundary

Status: engineering contract only; Public implementation not changed by this Windows PR.

## One release identity, independent execution owners

- Release Set V2.1 is signed and identity-bound. `release_type` may be `local_only`, `public_only`, `paired`, `windows_components_only`, `updater_only` or `recovery_forward`.
- Local runs under Windows Maintenance Supervisor and delegated Update Engine. Local Web is a UI client, not owner of lifecycle.
- Public runs under the **Public Maintenance Kernel** on the web host. It has its OWN release journal, active release identity, schema/migration engine, ZIP/chunk attestation, health checks and authorization. The Public kernel cannot be overwritten by a routine Public application release.
- A single panel may initiate/observe the shared release transaction but cannot replace either owner. Browser closure does not cancel or commit the transaction.
- Public target is A/B slots with stable hosting router/pointer, **not a Windows junction** or symlink requirement. If a real hosting environment cannot support it, explicitly qualify an alternative capability; never silently use in-place replacement.
- `public_only` must remain possible without a Windows Local activation. `local_only` must not claim to deploy Public.
- `paired` requires independent Public staging + Local staging, an explicit compatibility pair sequence, bounded `max_stagger_seconds`, client/PWA protocol window, read/write mutation policy and durable acknowledge/health from BOTH owners. An unverified Public cache/version string is NEVER such an ACK.

## Transaction steps and failure handling

1. Coordinator authenticates signed Release Set, queries each independent active release identity, checks exact supported baselines, protocol windows and updater kernel versions.
2. Each owner stages and verifies its own artifacts. Waiting on WAN is `WAITING_PUBLIC`, not implicit failure or idle. Queues/outbox remain durable.
3. The declared intermediate pair must be compatible BEFORE either half changes active pointers. If incompatible, neither half activates.
4. If a backend DB migration passes its forward-only boundary, no automatic old-code/schema restore is permitted. Use forward recovery and keep the target updater/kernel operational.
5. After independently verified commit ACKs, coordinator records Release Set completion. A partial ACK must remain `WAITING_PUBLIC`/`WAITING_LOCAL` or recovery-required, never COMPLETE.
6. UI merges states but never invents local/public versions from browser cache, passive health probes or support bundle historical values.
7. PWA/Staff and Guest remain reachable according to explicit `allow`/`queue`/`read_only`/`block` policy, preserve request IDs, offline/outbox semantics and cross-origin handoff when Wi-Fi is lost.

## Scope of current F1-S7B

Only signed **Local** Stage has code support today; this PR adds a write-once non-executable Local activation dry-run plan. Its `public_execution_owner=public-maintenance-kernel`, `public_release_included=false`, and `paired_compatibility_qualified=false` are explicit.
This is NOT Public Kernel implementation and NOT a paired-deployment orchestrator.

Pending distinct workstreams:
- Public Kernel capability + real hosting A/B router and release identity/journal
- Public migration/backup safety and rollback/forward recovery
- Signed coordinator protocol + idempotent ACK/WAN outage/failover
- Real-host Public/Guest/PWA/client compatibility and 80-case failure matrix qualification.

`panel_update_ready=false`, `activation_allowed=false` until all binding gates pass. No Home/Cafe/hosting deployment authorized.
