# SOKNA Cafe V3 — Architecture Contract

This document defines the active V3 architecture. It is normative for humans and agents. When legacy implementation conflicts with this contract, migration must adapt the legacy implementation rather than reintroduce the legacy coupling.

## 1. Product model

SOKNA is **one product with independently releasable components**, maintained in one V3 monorepo.

Target structure:

```text
SoknaCafeV3/
├── apps/
│   ├── local-web/
│   └── public/
├── windows/
│   ├── runtime/
│   └── print-agent/
├── platform/
├── contracts/
├── packaging/
├── tests/
└── docs/
```

Independent ownership does not mean a fragmented microservice system. Prefer a small number of clear lifecycle owners over many background services.

## 2. Authority and ownership

### Local Web

`apps/local-web` is the sole primary Business Authority.

It owns:
- orders and order validation;
- settlement/accounting/business rules;
- inventory business rules;
- table/order draft business semantics;
- deferred/reconciliation business decisions;
- canonical local business data and migrations;
- Local user-facing workflows.

It does **not** own Winspool, Registry, Windows Service Control, machine ACLs, elevated OS changes or hardware-driver lifecycle.

### Public

`apps/public` is an independently deployable edge application.

It may own:
- guest/public surfaces;
- remote staff gateway surfaces;
- durable relay state;
- safe projections/snapshots;
- Public-owned database/migrations;
- limited emergency/recovery surface for Public itself.

It must never become a full clone or alternate authority for Local business data.

### Windows Runtime

`windows/runtime` owns OS integration and supervision:
- installed Windows service lifecycle;
- scheduler/triggering;
- watchdog and health;
- diagnostics;
- approved OS adapters;
- maintenance integration;
- machine-bound runtime API.

It must not decide business validity, mutate Local business tables directly, or duplicate Local business rules.

### Print Agent

`windows/print-agent` preserves the mature printing lifecycle as a separate deployable owner when required. Runtime may supervise it, but printing state/driver/spooler integration must not be reimplemented in Local Web.

### Platform

`platform` owns approved infrastructure and prerequisite lifecycle, such as supported Apache/PHP/MariaDB/runtime dependencies and machine provisioning.

Infrastructure is independently versioned, but end users should not be forced to install every prerequisite manually. A SOKNA-managed Platform lifecycle/bootstrapper may compose the installation.

### Contracts

`contracts` owns versioned interfaces between releasable components. Contract changes must declare compatibility requirements and tests.

## 3. Business-logic boundary

The hard rule is:

```text
Windows Runtime -> trigger/supervise -> Local business worker/API -> business logic -> Local DB
```

Never:

```text
Windows Runtime -> business decision -> Local business DB
```

The same principle applies to Public: Public may queue, project and relay, but Local revalidates canonical business mutations.

## 4. Data ownership

- Local business DB: owned by Local Web.
- Public DB: owned by Public.
- Runtime machine/service state: owned by Windows Runtime.
- Print state: owned by Print Agent.
- Platform installation state: owned by Platform/Maintenance lifecycle.

Cross-owner writes are prohibited. Components communicate through explicit contracts/APIs/events.

## 5. Local Runtime communication

Initial V3 preference:
- loopback-only endpoint (`127.0.0.1`);
- machine-bound authentication secret/token;
- OS ACL protection for secrets/configuration;
- versioned Runtime API;
- no Internet-exposed Windows service endpoint.

Named pipes may be introduced only by an ADR showing a concrete benefit over the simpler loopback contract.

## 6. Public connectivity rule

Internet/Public must not directly control Windows services. The conceptual path is:

```text
Public <-> Local Web <-> versioned Runtime contract <-> Windows/OS
```

Public-to-Local business traffic must use the authenticated relay/gateway model defined by the Local/Public contracts. No direct Public-to-Windows path is allowed.

## 7. Release and packaging ownership

Canonical releases for Local and Public are **full, immutable packages**. Delta packages are optional transport optimizations and never the only repair source.

Independent versions include at minimum:
- PlatformVersion;
- RuntimeVersion;
- PrintAgentVersion;
- LocalAppVersion;
- LocalSchemaVersion;
- PublicAppVersion;
- PublicSchemaVersion;
- LocalPublicContractVersion;
- RuntimeContractVersion.

A package declares compatible dependency ranges before installation/update.

## 8. Deployment and rollback

Prefer staged/A-B style deployment for Local/Public where practical:

```text
Download -> Verify -> Stage -> Migrate -> Health Check -> Switch Active
```

Failure before activation must leave the current version untouched. Failed activation must have a deterministic rollback path.

Database rollback is not assumed to be symmetric with code rollback. Schema evolution should prefer **Expand -> Migrate -> Contract** so a previous compatible application can survive the migration window.

## 9. Update / Repair / Recovery

These are separate lifecycle operations:
- **Update** moves to another compatible version.
- **Repair** restores the currently intended version/components without changing business data unexpectedly.
- **Recovery** restores data/system state from an explicit recovery source/point.

A central SOKNA Update Center may provide UI and orchestration, but it must not turn Local Web into the lifecycle owner of every component. Platform/Windows maintenance must remain operable when Local Web is unhealthy.

## 10. Failure isolation acceptance

The architecture is considered correctly separated only if these expectations hold:

| Failure | Required behavior |
|---|---|
| Public down | Local remains operational |
| Internet down | Local remains operational; Public behavior degrades safely |
| Runtime down | Local business UI remains available where OS integration is not required; background/OS features degrade explicitly |
| Print Agent down | Orders/accounting remain usable; printing reports unavailable/degraded |
| Local Web down | Windows/platform diagnostics and maintenance remain reachable independently |
| Web server down | Maintenance/diagnostics can still identify/repair the failure |
| Database down | Business operations stop safely; Windows/platform diagnostics remain available |
| Platform update failure | Application/data remain unchanged or recoverable |
| Public update failure | Local is unaffected |
| Local update failure | Runtime/Platform/Public are not implicitly replaced |

## 11. UI / Design System boundary

Architecture migration is not permission to redesign the product. `UI_DESIGN_SYSTEM.md` is a mandatory product contract.

Every migrated UI scope must converge on one canonical component/pattern owner. Legacy CSS, markup or JavaScript owners that duplicate the canonical owner must be removed from the migrated scope rather than carried forward as parallel implementations.

## 12. CI model

CI is component/path aware.

Examples:
- Local-only change -> Local tests/build/package plus impacted contract tests.
- Public-only change -> Public tests/build/package plus impacted contract tests.
- Runtime change -> Runtime unit/integration and Windows-specific gates.
- Platform/installer/packaging change -> clean-install/repair/uninstall acceptance as required.
- Shared contract change -> all declared consumers are tested.

Full Windows clean-install is reserved for affected infrastructure/packaging paths and release-candidate gates; it is not automatically required for every application-only commit.

## 13. No accidental microservice expansion

Do not create separate Windows services for relay, backup, notification, reporting, synchronization, update, or similar concerns merely to achieve separation. Separation of ownership is required; distributed-process complexity is not.

A new long-running process/service requires an ADR proving why an existing owner cannot safely host the responsibility.

## 14. Migration rule

Legacy code is never bulk-copied simply because it exists.

For each migrated capability:
1. identify the current legacy owner and observable behavior;
2. classify the V3 target owner;
3. identify data and contract boundaries;
4. preserve approved behavior/UI;
5. refactor incompatible coupling before or during migration;
6. remove duplicate owners from the migrated scope;
7. add/update tests and rollback evidence;
8. record material architectural decisions in an ADR.

The migration goal is continuity of product behavior and history **without continuity of accidental coupling**.
