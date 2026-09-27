# ADR 0005 — Component update control plane and break-glass recovery

Status: **Accepted**
Date: 2026-09-27

## Context

SOKNA components are independently versioned and independently serviceable. Routine administration must remain simple from Local Web, while a Public failure or Local↔Public connectivity loss must not remove the ability to inspect and recover Public Edge.

Legacy SOKNA proved the value of a stable updater entrypoint, staged activation, previous-engine/LKG fallback and recovery outside the normal application bootstrap. V3 already has generic lifecycle primitives, but product-facing ownership must be explicit.

## Decision

### Local Update Center

Local Web is the normal operator control plane for component visibility and orchestration. It must show, per registered component:
- installed/current/previous/LKG version;
- compatibility state;
- health/connectivity/I-O/sync/backlog state;
- update availability and validated package metadata;
- allowed update/repair/recovery actions;
- last action/result/correlation evidence.

Local Update Center **does not become the lifecycle owner of every component**. It requests/coordinates an action through the declared owner contract. Each component remains responsible for its own package activation/rollback rules.

### Public Emergency Console

Public Edge owns a deliberately limited, strongly authenticated break-glass console that remains usable when Local is unavailable or the Local↔Public path is broken. It may provide only:
- Public health/connectivity/version;
- bounded/redacted logs and diagnostics;
- verify/stage/activate update;
- rollback/LKG/recovery;
- approved kill-switch/takeover controls required by recovery.

It must not become a second general admin panel or Business Authority.

### Recovery independence

A broken current application release must not destroy the recovery entrypoint. The implementation must preserve the mature semantics of stable recovery entrypoint, package verification, compatibility validation, staging, recovery point, health check, activation, rollback/LKG and repair/recovery.

### Package trust

All update packages are immutable and validated fail-closed using declared identity/version/compatibility plus SHA-256 and, where required by the release policy, signature verification. Same-version repair is explicit and must never be confused with normal update.

## Consequences

- `packaging/tools/lifecycle.py` is a primitive, not itself a complete product updater.
- Local Web and Public Edge require product-facing updater/recovery surfaces before they can be `PRODUCT_COMPLETE`.
- component health/update ownership must be declared in `COMPONENTS.json`.
- Release Qualification must exercise update, repair and recovery separately.
