# ADR 0002 — Single user-facing Windows installer with independent components

Status: Accepted
Date: 2026-09-26

## Context

SOKNA V3 separates Windows Runtime and Print Agent into independent lifecycle owners. Platform prerequisites and application payloads are also independently versioned. This separation is architectural and must not force the end user to understand or manually install several Windows packages.

Without an explicit packaging decision, independent component ownership could accidentally turn into multiple user-facing installers, duplicate repair flows, or a setup process that requires the operator to run scripts manually.

## Decision

SOKNA V3 has one canonical **user-facing Windows setup/bootstrapper**, presented as `SOKNA Setup.exe` (final filename may be branded at release time).

The setup/bootstrapper composes the installation and lifecycle of the Windows-side product components, including as applicable:

- Platform prerequisites managed by SOKNA;
- Windows Runtime;
- Print Agent;
- Local Web payload and its supported local platform binding;
- shared machine configuration required for the supported deployment.

Runtime and Print Agent remain separate deployables and separate versioned components internally. A single installer does **not** merge their ownership, binaries, state machines, services, contracts, or update versions.

The user-facing setup owns orchestration/composition only. Component packages remain the canonical lifecycle units beneath it.

## Lifecycle consequences

- Clean install: one setup flow composes all required compatible components.
- Repair: setup may repair one affected component without reinstalling unrelated healthy components.
- Update: component versions may advance independently when compatibility rules allow it; the user still uses the unified SOKNA lifecycle surface.
- Recovery: remains distinct from Repair and Update and may restore data/system state according to the recovery contract.
- Uninstall: one user-facing flow coordinates removal while respecting data-retention/recovery choices.

Internal engineering/support packages may exist for CI, development, diagnostics, staged rollout, or component-only servicing. They are not separate normal end-user installation experiences.

## Non-negotiable boundaries

- Runtime must not absorb Print Agent just to simplify setup.
- Print Agent keeps durable print/device/spooler ownership.
- Packaging/Setup does not become owner of Local business logic or active business data.
- The user must not be required to launch PowerShell scripts manually; PowerShell/.NET may be implementation details behind the setup/lifecycle engine.
- Compatibility/version checks occur before activation. A failed component installation/update must not silently replace unrelated working components.

## Deferred implementation details

The exact installer technology, bootstrapper implementation, package layout, privilege transitions, signing flow, staging directories, rollback mechanics and clean-machine acceptance are decided and implemented in M9.

This ADR freezes the product/ownership decision now so M2–M8 can assume a single user-facing Windows setup without coupling component implementations together.

## Alternatives considered

### Separate end-user installer per Windows component
Rejected. It exposes architecture to operators, increases support burden and creates inconsistent Repair/Recovery behavior.

### One monolithic Windows component/package
Rejected. It would erase independent ownership/versioning and couple Runtime and Print Agent lifecycle/state.

### Manual prerequisite/scripts plus component installers
Rejected for production UX. Manual scripts may remain engineering internals only.

## Acceptance evidence

M9 must prove at minimum:

- clean-machine New install through one setup entrypoint;
- component-aware Repair;
- independent Runtime and Print Agent update compatibility;
- deterministic failure/rollback behavior;
- uninstall/recovery semantics;
- no manual PowerShell requirement for the end user;
- Installed Apps/shortcuts/service lifecycle behavior appropriate to the final packaging design.
