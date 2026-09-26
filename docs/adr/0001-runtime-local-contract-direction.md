# ADR-0001 — Runtime ↔ Local contract direction and minimum M1 surface

Status: Accepted for M1 contract foundation  
Date: 2026-09-26

## Context

V3 freezes `apps/local-web` as the sole primary Business Authority and `windows/runtime` as the owner of Windows/OS integration, supervision, scheduling/triggers, watchdog/health, diagnostics and approved OS adapters.

The selected dev39 baseline does not provide a stable Runtime HTTP API to preserve. Its proven Runtime surfaces are CLI execution, `sokna-local-runtime-v1` state JSON and the Windows SCM service host. At the same time, V3 architecture requires an explicit versioned machine-bound contract and states the business path as:

`Windows Runtime -> trigger/supervise -> Local business worker/API -> business logic -> Local DB`

A contract is therefore required before Runtime/Local implementation is migrated, but calling a new V3 HTTP surface "legacy-compatible" would be false.

## Decision

The M1 Runtime contract family has two explicit directions and no generic command execution surface.

### A. Local -> Runtime: observation / OS-supervision boundary

The initial versioned surface is loopback-only and machine-authenticated. It exposes:
- Runtime health/status and component supervision state;
- bounded diagnostics metadata suitable for Local UI/support;
- declared contract/runtime versions and capabilities.

M1 does **not** standardize arbitrary service-control or shell-command execution. A future mutating OS operation requires a named, typed capability and contract revision/ADR.

### B. Runtime -> Local: allowlisted trigger intent

Runtime may request execution of an allowlisted Local worker trigger through a versioned Local-owned endpoint. The request contains scheduling/trace identity only; business validation and all Local DB access remain inside Local.

The contract forbids:
- SQL, table names or direct DB mutation instructions;
- arbitrary executable paths/arguments/PowerShell/shell commands;
- business entity payloads whose validation would move business authority into Runtime;
- using Runtime as a second queue/state machine for Realtime or Deferred business mutations.

The historical worker registry is migration evidence, not automatic V3 ownership. `printing` is specifically excluded from Runtime business-worker ownership because V3 assigns durable print/device/spooler execution to `windows/print-agent`.

### C. Failure semantics

- Runtime unavailable must not make ordinary Local business UI unavailable where OS integration is unnecessary.
- Local unavailable may leave Runtime health/diagnostics reachable, but Runtime must not execute business work directly as a fallback.
- Trigger delivery is idempotent by `request_id`; acceptance means Local accepted the trigger intent, not that business work committed successfully.
- Runtime health is observational evidence, not business truth.

## Alternatives considered

### Copy the dev39 Runtime CLI/state surface only
Rejected as the final cross-component design because V3 explicitly requires a versioned machine-bound Runtime contract and Local/Public must not depend on ad-hoc files/CLI coupling forever. The historical surface remains compatibility/evidence input.

### Give Runtime direct DB access to simplify background workers
Rejected. It violates Local Business Authority and creates cross-owner writes/recovery coupling.

### Generic `/execute` or command runner API
Rejected. It would turn Runtime into an escalation/ownership bypass and make compatibility/security auditing unbounded.

### Put printing back inside Runtime
Rejected. V3 has a separate `windows/print-agent` owner; Runtime may supervise its lifecycle but must not absorb the print state machine.

## Compatibility / migration impact

- No historical HTTP route is claimed as compatible because none was stable in dev39.
- Proven `sokna-local-runtime-v1` health/state semantics inform the new health schema.
- Historical scheduler cadence/worker names are inventory evidence only; each trigger is admitted only when its V3 owner is explicit.
- Local/Public/Runtime implementations must negotiate the declared contract version/capabilities before depending on new features.

## Tests / gates

M1 must provide executable schema/vector tests proving at minimum:
- loopback + machine-authenticated boundary declaration;
- health schema retains required status/staleness/worker evidence without business fields;
- trigger request has no command/SQL/business-payload escape hatch;
- printing is excluded from Runtime worker ownership;
- trigger dedupe semantics are explicit;
- Public/Internet cannot directly invoke Runtime.

Windows service lifecycle, ACL/secret rotation and actual loopback integration remain later Windows/UAT gates.

## Rollback

This ADR introduces no deployed implementation. Before M7, rollback is removal/revision of the draft contract plus its tests. After implementation begins, compatible version negotiation and staged component rollback rules apply.

## Historical lineage

Evidence baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05` (`work/reconcile-dev39`), especially:
- `runtime/sokna-runtime.php`
- `includes/runtime.php`
- `runtime/windows/SoknaRuntimeService.cs`
- `runtime/README_FA.md`

V3 normative sources:
- `ARCHITECTURE.md`
- `windows/runtime/README.md`
- `contracts/runtime-api/historical-audit-v1.json`
