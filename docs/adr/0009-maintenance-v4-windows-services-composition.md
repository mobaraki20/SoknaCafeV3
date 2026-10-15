# ADR 0009 — Maintenance V4 joins the unified SOKNA Windows Services lifecycle

Status: **Accepted**  
Date: 2026-10-15

## Context

ADR-0004 correctly separated Local Web and third-party infrastructure from the native Windows installer. At that time, the native Windows service lifecycle was enumerated as Runtime + Print Agent only.

Maintenance V4 now introduces a new Windows-owned control-plane component with responsibilities that cannot safely belong to Local Web/PHP or Public Edge:

- privileged Windows service lifecycle and ACL ownership;
- machine-local maintenance control plane;
- update staging/activation orchestration as later phases are enabled;
- Windows-owned backup/recovery engines as later phases are enabled;
- independent Recovery Console / break-glass access when Local Web is unavailable;
- durable Windows-side journal/diagnostics.

A temporary separate Maintenance installer was considered during UAT preparation, but keeping two independent Windows installers/lifecycles would create duplicated ownership, inconsistent repair/uninstall behavior, version ambiguity, and higher support cost.

## Decision

### Unified installer remains the single Windows lifecycle surface

`SOKNA Windows Services Setup` is the single user-facing lifecycle owner for SOKNA-owned Windows components.

Primary logical components are now:

1. Runtime — persistent service `SoknaRuntime`
2. Print Agent / Worker — persistent service `SoknaPrintWorker`
3. Maintenance Supervisor — persistent service `SoknaMaintenanceSupervisor`

Maintenance subordinate components include:

- Update Engine — versioned child executable, not a permanent service by default
- Recovery Console — versioned independent executable/UI that remains usable without Local Web / Apache / PHP
- future Windows-owned Maintenance backup/recovery engines when their implementation phases become active

### Per-component lifecycle, not package-wide blind replacement

The unified installer does not imply all components are always mutated together.

Before mutation it computes and displays a per-component plan such as:

- `KEEP`
- `INSTALL`
- `UPDATE`
- `REPAIR`
- `BLOCKED`
- `PENDING_RESTART`
- `NO_ACTION_OPTIONAL`

A component in `KEEP` must not be stopped, copied, re-registered, or restarted unless an explicit compatibility/transaction-group contract requires coordinated mutation.

Package version and component versions remain separate identities.

### Preserved ADR-0004 boundaries

This ADR supersedes ADR-0004 **only** where ADR-0004 enumerated the Windows Services installer as owning Runtime + Print Agent only.

The following ADR-0004 decisions remain unchanged:

- Local Web is not bundled into the Windows Services installer.
- Local Web remains an independent immutable web package/browser-driven installation surface.
- Apache/PHP/MariaDB and similar infrastructure remain external prerequisites, not SOKNA-owned payloads in the normal Windows Services installer.
- Public Edge remains an independent deploy/lifecycle surface.
- Local Update Center remains the normal operator visibility/orchestration surface; component lifecycle ownership remains with each component owner.

### Diagnostics and security are part of lifecycle ownership

The unified Windows lifecycle must preserve:

- pre-UAC + elevated installer transaction logs;
- shared transaction/correlation evidence;
- fail-closed service ownership and ACL checks;
- no secret leakage into logs/support bundles;
- independent Windows-owned Support Bundle/Recovery access when Local Web is down;
- exact artifact/source provenance.

The mandatory engineering guardrails are recorded in:

`docs/windows/WINDOWS_ENGINEERING_REQUIRED_READING.md`

## Alternatives considered

### Separate Maintenance installer

Rejected as the final product path because it would create a second Windows lifecycle owner, duplicate repair/uninstall logic, complicate version visibility, and increase the probability of incompatible service combinations.

A separate UAT-only installer/harness may exist temporarily for isolated engineering experiments, but it is not the product architecture.

### Put privileged Maintenance lifecycle in Local Web

Rejected. Local Web must not own Windows SCM, privileged ACL mutation, Windows secrets, or break-glass recovery primitives.

### Treat Maintenance as part of Runtime

Rejected. Runtime and Maintenance have different responsibilities, versions, failure domains, lifecycle policies, and security boundaries. They may share the unified installer while remaining independent logical components.

## Compatibility / migration impact

- Existing Runtime and Print installations may remain `KEEP` when installing Maintenance.
- Installer package updates do not imply Runtime/Print updates.
- Existing hosts must be inventoried before mutation; version equality alone is insufficient if binary/service/ACL drift exists.
- Downgrade remains fail-closed unless an explicit approved recovery path says otherwise.

## Tests / UAT

Engineering qualification must include at least:

- Windows Server CI build of Runtime/Print/Maintenance/Setup UI/Setup Host;
- per-component planner tests;
- Maintenance-only selective lifecycle proving Runtime/Print `KEEP` without mutation/restart;
- service ownership/path validation;
- Windows PowerShell 5.1 compatibility for lifecycle scripts;
- installer log evidence from bootstrap through commit/failure;
- screenshot qualification from the real Setup UI entrypoint plus visual review;
- secret/redaction checks;
- separate Home Test Host and Cafe Test Host UAT evidence.

Home PASS does not close Cafe UAT.

## Rollback implications

Rollback is transaction/component aware. A failure while adding/updating Maintenance must not silently roll unrelated `KEEP` components through unnecessary replacement.

Recovery tooling and evidence must remain available after a failed transaction.

## Historical lineage

- ADR-0004 remains authoritative for separation of Local Web and external infrastructure from the Windows installer.
- ADR-0005 remains authoritative for operator control-plane visibility and break-glass recovery principles.
- ADR-0009 is the newer decision for the composition of SOKNA-owned Windows components and supersedes only the Runtime+Print-only enumeration in ADR-0004.
