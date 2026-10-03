# Contracts

`contracts` owns versioned interfaces between independently releasable SOKNA V3 components.

## Rule

No component may bypass a declared boundary because another component happens to be installed on the same machine or stored in the same monorepo.

At minimum V3 has separate contracts for:

1. `local-public-realtime` — Local-required realtime relay/mutations.
2. `local-public-deferred` — deferred-safe pending/reconciliation state machine; never merged with realtime.
3. `runtime-api` — Local Web to Windows Runtime supervision/OS integration; Runtime contains no business authority.
4. `print-agent-api` — Local print intent/submission to the separate Windows Print Agent; Runtime may supervise Agent lifecycle but does not absorb its spooler state machine.

Contract inventory and lifecycle state are stored in `contracts/manifest.json`.

## Versioning

Draft migration contracts use explicit pre-release versions. A component package must declare the contract range it requires before activation.

Breaking semantics require a major contract version. Additive compatible changes require a minor version. Clarifications/fixes that do not alter wire semantics use patch versions.

Rolling upgrades must not rely on undocumented coupling. When compatibility across old/new component versions is required, the contract and tests must state the supported range explicitly.

## Required contract evidence

A contract is not `stable` until it has:
- canonical producer and consumer owners;
- schema/request/event definitions;
- authentication/trust boundary;
- timeout/retry/idempotency semantics where relevant;
- error/degraded-state semantics;
- backward-compatibility tests;
- migration/rollback rules.

The current skeletons deliberately do **not** claim stable APIs. They establish boundaries before implementation migration.
