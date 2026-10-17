# ADR 0010 — Update Transaction Ownership and Durable Local Adoption

**Status:** Accepted for Maintenance V4 R7 qualification

## Context

Maintenance V4 R6 exposed a structural reliability defect even though its source and packaging CI passed.

The Windows Services installer correctly committed the Windows-owned component transaction, but then synchronously invoked a second mutation domain — Local Web layout adoption — through the newly restarted Maintenance Supervisor loopback API. The bootstrap treated persisted `control-plane.json` existence and SCM `RUNNING` as sufficient readiness evidence. Those are not functional-readiness contracts. A previous descriptor can already exist while a new Supervisor process is still starting, reconciling ACLs, or has not yet made its mutation endpoint usable.

The result was an avoidable coupling:

1. Windows Services component update completed.
2. Local layout adoption was attempted synchronously as post-commit installer work.
3. Failure in the second mutation domain caused the installer UX to report the whole installation as incomplete even though the first transaction had committed successfully.
4. Re-running the user-facing installer became an implicit diagnostic/retry mechanism.

This is not an acceptable production updater contract.

## Decision

### 1. One mutation domain, one transaction owner

The Windows Services installer owns only the Windows Services component transaction.

Local Web adoption/update is owned by Maintenance Supervisor and its versioned Update Engine. A failure in Local adoption MUST NOT roll back, relabel, or invalidate an already committed Windows Services component update.

The same principle applies to future Public and database mutation domains: each domain has one explicit transaction owner and one durable state machine.

### 2. Installer queues intent; it does not execute Local migration

After Windows component commit, the installer may persist a validated, allowlisted Local adoption request. It MUST NOT synchronously invoke the Local migration endpoint and MUST NOT depend on loopback listener readiness, HMAC transport availability, descriptor freshness, browser state, Local PHP/Apache availability, or UI lifetime to complete the migration.

Queue persistence is idempotent. The same request is a no-op; a conflicting request fails closed and is not overwritten.

### 3. Supervisor owns durable execution and resume

Maintenance Supervisor consumes the durable adoption request after startup. State is persisted outside Local Web and survives:

- installer exit;
- UI/browser closure;
- Supervisor restart;
- process crash;
- Local Web outage;
- retryable pre-mutation failures.

The durable adoption states are:

`pending -> running -> retry_wait -> running -> committed`

or terminal:

`recovery_required | failed_terminal`

### 4. Retry is bounded and mutation-aware

Automatic retry is permitted only when durable evidence proves the failed attempt did not perform activation.

If `activation_performed=true`, unattended automatic retry stops. This prevents repeated pointer switches or activation loops after a partially completed attempt.

`recovery_required` is terminal for the automatic worker and must be surfaced through independent Maintenance recovery controls.

### 5. SCM state is not product readiness

`RUNNING` or `Stopped` are process/service states only.

- `RUNNING` MUST NOT be treated as proof that a loopback API, ACL, HMAC endpoint, update engine, or product function is ready.
- `Stopped` MUST NOT be treated as proof that owned processes exited and files are safe to replace.

Where synchronous readiness is genuinely required, a fresh readiness contract tied to the new process generation must be probed. Durable asynchronous work should prefer eliminating unnecessary readiness coupling entirely.

### 6. Local adoption remains isolated from unrelated Windows components

Local layout adoption may control only its owned Local service boundary (`SoknaApache`) and Local release-slot filesystem state. It MUST NOT stop, restart, replace, repair, or re-register Runtime or Print Agent merely because a Local adoption operation occurs.

### 7. No user canary is a qualification substitute

A source build or simulated happy-path CI PASS is insufficient for update/recovery work.

Before a new updater architecture is offered to Home or Cafe as a user-visible candidate, qualification MUST include a repeated Windows reliability matrix with at least 20 completed cycles covering:

- healthy activation;
- post-switch failure and automatic rollback;
- abrupt process termination at durable-state boundaries;
- recovery with Local Web unavailable;
- unhealthy baseline with zero activation;
- idempotent repeated execution;
- preserved physical-backup evidence;
- no unrelated-service mutation;
- no database/Public mutation unless explicitly in that domain's transaction;
- no Windows reboot unless explicitly required.

A real host is validation after engineering qualification, not the primary mechanism for discovering deterministic lifecycle bugs.

## User-facing semantics

Installer UX must distinguish these outcomes:

- Windows Services update: committed / failed / repair required.
- Local adoption: not required / queued / running / committed / deferred / recovery required.

A committed Windows Services update with deferred Local adoption MUST NOT be shown as a generic failed installation.

Internal packaging terms such as `shell` must not appear as the user-facing component name.

## Packaging and size

Future necessary Windows Services releases should avoid shipping or replacing unchanged component payloads where the delivery architecture can safely reuse a trusted cached/canonical component. `KEEP` continues to mean zero lifecycle mutation. Package-size optimization must not weaken provenance, hash verification, repairability, or offline recovery.

## Consequences

- Initial Local layout adoption may finish shortly after the Windows installer exits rather than inside the installer transaction.
- Update state becomes observable and resumable without repeating the installer.
- Recovery tooling remains available even if Local Web is down.
- Failure evidence is attached to the owning mutation domain instead of a generic installation error.
- Engineering qualification takes longer before release, but real-host troubleshooting becomes exceptional rather than the normal development loop.
