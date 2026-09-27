# SOKNA Cafe V3 — Ordered Migration Slices

This is the execution order for moving the historical product into V3. It is intentionally capability/owner based rather than a directory copy order.

Historical baseline: `mobaraki20/SoknaCafe@work/reconcile-dev39` / `a46435cca57df5bd5b9770efd0bb95390528aa05`.

## Global rule for every slice

A capability slice is complete only when all applicable implementation requirements are true:

1. historical owner and behavior are audited;
2. V3 owner/data owner/contract are explicit;
3. implementation is migrated/refactored behind that owner;
4. relevant business and SCDS behavior has executable regression coverage;
5. duplicate legacy owner for that scoped capability is removed/retired;
6. rollback/compatibility behavior is documented and tested at the applicable level;
7. historical slice `status` is updated only with scoped implementation evidence; the independent `completion_level` is updated separately under `docs/product/COMPLETION_STATUS_POLICY_FA.md`.

Preparatory slices such as M1 may complete their explicit contract-extraction exit gate without falsely upgrading downstream capability completion. A slice-level `migrated` value is not a Product Complete claim.

Copying files is never completion.

---

## F0 — V3 Foundation

**Status:** complete at foundation-contract level.

Scope:
- architecture and component ownership;
- project lineage and dev39 continuation authority;
- SCDS `SCDS-CANONICAL-2026-R1` authority, component registry and product language;
- migration inventory/matrix;
- component-aware CI;
- draft contract families for Realtime, Deferred-safe, Runtime and Print Agent.

Evidence:
- PR #1 foundation branch;
- `V3 Component Gates` green on current foundation revisions.

This status does not claim application, Windows, physical printer or installer acceptance.

---

## M1 — Extract Proven Cross-Component Contracts Before Code Movement

**Status:** complete at contract-extraction / executable-boundary level.

Verified M1 checkpoint:
- head `6fd46e4d8196d350884539067d446bd83db6f46a`;
- PR workflow run `36210340655` (#109);
- Foundation and Contracts gates SUCCESS;
- Contracts gate executed all four M1 contract tests successfully.

M1 completion does **not** mean producer/consumer application implementations are migrated or release-stable. All manifest contracts remain explicitly Draft until implementation/integration gates mature them.

### M1.1 Realtime relay wire contract

Source audit:
- `includes/relay_protocol.php`;
- Public realtime enqueue/result routes;
- Local claim/ack routes;
- relay client/dispatch/actor/worker and tests.

V3 target:
- `contracts/local-public-realtime/`.

Executable evidence:
- exact `sokna-relay-v1` HMAC signature construction;
- auth header names and durable nonce/replay semantics;
- envelope validation rules;
- realtime state/terminal-state registry;
- realtime kind/capability registry;
- historical route map;
- exact claim/ACK/result operation schema;
- compatibility vectors for lease normalization, terminal ACK dedupe, lease conflict and result shape;
- regression HMAC test vector;
- `tests/relay-wire-contract.py` and `tests/relay-operation-contract.py`.

Implementation work remains for later slices:
- decide which historical paths are compatibility aliases vs final V3 canonical paths as producer/consumer code migrates;
- bind migrated Local/Public implementations to the executable contract without weakening idempotency/expiry/ambiguity semantics.

### M1.2 Deferred-safe wire contract

Source audit:
- `includes/relay_protocol.php`;
- `includes/deferred.php`;
- Public deferred enqueue/list/result;
- Local deferred claim/ack/reconcile/period-status;
- deferred worker/tests/financial-close hooks.

V3 target:
- `contracts/local-public-deferred/`.

Executable evidence:
- state and allowed-kind registries;
- `occurred_at` validation and no-expiry semantics;
- route map and strict separation from realtime;
- exact claim/ACK/reconcile/period-status/result operation schemas;
- compatibility vectors for lease normalization, terminal-state conflict, `needs_review` resolution and financial-period blocking;
- idempotency/storage evidence and financial integrity invariants;
- `tests/relay-wire-contract.py` and `tests/relay-operation-contract.py`.

Implementation work remains for later slices:
- preserve domain-specific `needs_review` reason/result details as each canonical Local owner is migrated;
- bind migrated producer/consumer implementations to the executable contract;
- keep admin review resolution explicit/audited and never silently reopen/back-post financial periods.

### M1.3 Runtime and Print contracts

Historical audit:
- Runtime audit complete: dev39 has no stable Runtime HTTP API; proven external surfaces are CLI, `sokna-local-runtime-v1` state file and Windows SCM service host.
- Print audit complete: Print API v4 and loopback `/v1/wake` + `/v1/preview` audited, including durable submission/attempt semantics, request-body idempotency, server-scope binding and device/spooler ownership.

V3 ownership correction remains frozen:
- Runtime = supervision/OS integration only;
- Print Agent = separate deployable owner of durable print/device/spooler execution.

Runtime executable evidence:
- `contracts/runtime-api/historical-audit-v1.json`;
- `docs/adr/0001-runtime-local-contract-direction.md`;
- `contracts/runtime-api/contract-v1.json`;
- `contracts/runtime-api/compatibility-vectors-v1.json`.

Runtime v1 freezes:
- machine-bound loopback observation/health boundary from Local to Runtime;
- idempotent allowlisted trigger intent from Runtime to a Local-owned endpoint;
- no arbitrary command/shell/PowerShell/SQL/business payload escape hatch;
- no direct business-table writes;
- no Public/Internet direct Runtime control;
- no migration of historical `printing` ownership into Runtime.

Print executable evidence:
- `contracts/print-agent-api/historical-audit-v1.json`;
- `contracts/print-agent-api/server-wire-v4.json`;
- `contracts/print-agent-api/loopback-v1.json`;
- `contracts/print-agent-api/compatibility-vectors-v4.json`.

Retained Print semantics:
- server actions `probe`, `heartbeat`, `claim`, `claim_reconcile`, `attempt_status`, `renew`, `accept`, `start`, `report`;
- request/body idempotency and claim reconciliation;
- immutable claimed destination evidence;
- durable receipt/content-hash fence;
- `accept -> start -> physical execution -> report` ordering;
- durable `unknown` / `recovery_hold` ambiguity handling and no blind auto-reprint;
- paired loopback wake/preview remains separate from durable submission.

M1 Runtime/Print gates:
- `tests/runtime-print-contract-audit.py`;
- `tests/runtime-print-v1-contract.py`.

**M1 exit gate: SATISFIED.** Executable cross-component contracts now exist for Realtime, Deferred-safe, Runtime and Print Agent before producer/consumer implementation movement.

---

## M2 — Local Core: Bootstrap, Data Ownership, Auth and Observability

**Status:** complete at Local Core slice level.

Target owner: `apps/local-web`.

Migrated/refactored foundation:
- configuration/bootstrap without Windows-specific ownership;
- Local DB connection/schema/migration owner;
- users/capabilities/preparation-area authority;
- correlation IDs, safe errors, redaction and Local health primitives;
- canonical shared business-service bootstrapping.

Preserved constraints:
- no Runtime/Winspool/Registry/SCM ownership inside Local core;
- no second permission system;
- schema migrations prefer Expand -> Migrate -> Contract;
- M2 completion does not claim Orders/Preparation/Inventory/Finance domain migration.

Executable evidence includes Local ownership/bootstrap gates and MariaDB migration self-tests in `V3 Component Gates`.

**M2 exit gate: SATISFIED.** Local core initializes and its foundation tests execute without requiring Public/Runtime/Print as ordinary business-domain dependencies.

---

## M3 — Public Edge Persistence, Auth Projection and Relay Transport

**Status:** complete at Public Edge slice level.

Target owner: `apps/public` plus M1 contracts.

Migrated/refactored:
- Public DB/migrations;
- Local/Public binding state;
- minimal user/capability verifier projection and Public sessions;
- login throttling/security audit;
- signed Local HMAC and durable replay protection;
- realtime durable relay transport;
- deferred-safe durable pending/review transport;
- heartbeat/connectivity metadata;
- Public safe error/health primitives.

Preserved constraints:
- no Local full business DB clone;
- no business SQL/decision owner on Public;
- Local remains final mutation authority;
- Realtime and Deferred stores/routes/state machines remain distinct;
- M4 Guest publish/read-model tables were deliberately excluded.

Verified M3 checkpoint:
- head `07757f037ece5c71097cafb0f3dcc1d21ae4dcdf`;
- `V3 Component Gates` run `36241108219`;
- result `SUCCESS`.

Executable evidence:
- `tests/public-m3-contract.py`;
- `tests/public-m3-transport-contract.py`;
- `tests/public-health-contract.py`;
- Public MariaDB migration/auth/HMAC/connectivity/realtime/deferred/health self-tests.

**M3 exit gate: SATISFIED.** Public-owned transport/projection/health boundaries execute against M1 semantics while canonical business authority remains outside Public.

Canonical evidence: `docs/migration/M3_PUBLIC_EDGE_AUDIT_FA.md`.

---

## M4 — Guest Publish, Guest Runtime and Remote Read Models

**Status:** next slice / audit in progress; no M4 implementation completion claimed yet.

Move/refactor:
- immutable guest snapshot/media publish;
- atomic active revision switch;
- shared guest rendering/runtime contract without renderer fork;
- degraded read-only behavior;
- remote read models with capability/preparation-area filtering;
- stale/last-sync/connectivity semantics.

SCDS requirement:
- audit/correct/standardize before migrating legacy guest CSS/markup;
- rejected Design System patterns are not preserved as requirements.

Ownership boundary:
- Local owns source business/catalog data and publish decision;
- Public owns published immutable artifacts/projections/runtime storage;
- Public/Guest remains non-authoritative for canonical business mutation.

**Exit gate:** Local-down/Public-down/Internet-down scenarios have explicit tested behavior and no Public business authority emerges.

---

## M5 — Local Business Domains in Dependency Order

**Status:** planned, executed as multiple sub-slices rather than one bulk move.

Suggested dependency order:
1. explicit Sellables;
2. canonical Orders services;
3. Staff Quick Order + Table Draft;
4. Preparation permission/action owner;
5. Inventory;
6. Supply/Purchase;
7. Expenses;
8. Financial periods/Settlement/Reconciliation;
9. Tax and cross-domain finance integration;
10. Accommodation/Center adapters.

Each sub-slice must migrate its UI through SCDS in the same slice when user-facing surfaces are touched. UI migration is not deferred to a final cosmetic phase.

**Exit gate:** business-domain regression coverage plus Local/Public contract integration; no legacy parallel owner remains for migrated scope.

---

## M6 — SCDS Shared UI Foundation and Progressive Consumer Migration

**Status:** continuous cross-cutting track; not a late redesign project.

Build canonical tokens/components/pattern owners just-in-time from the registry as consumers are migrated.

Required approach:
`Audit -> Correct -> Standardize -> Migrate -> Enforce`.

Debt policy:
- historical UI debt baseline is ratchet-down-only;
- V3-native code does not inherit legacy debt allowances;
- component owner is registered before broad reuse;
- page-local generic owner/style island is a regression.

Physical/device-sensitive interactions stay `UAT_REQUIRED` until verified on target devices.

---

## M7 — Windows Runtime and Background Supervision

**Status:** planned after Local worker/business ownership is explicit. M1 Runtime contract foundation is complete.

Move/refactor:
- Windows service host;
- scheduler/watchdog;
- approved worker triggering;
- health/diagnostics/support integration;
- machine-authenticated loopback Runtime API;
- notification queue processing supervision.

Do not migrate:
- business decision logic;
- direct Local business DB mutation;
- Print Agent spooler state machine.

**Exit gate:** Windows service lifecycle/restart/recovery/ACL/secret-redaction tests with Local business service as an external contract consumer.

---

## M8 — Separate Print Agent

**Status:** planned implementation slice; M1 Print contract foundation is complete.

Preserve mature semantics from historical print implementations/Pagent lineage while changing deployment ownership to V3:
- separate Windows deployable;
- durable submission fence/state;
- local device/spooler/driver integration;
- diagnostics and retry/recovery;
- Runtime supervision only.

Do not carry forward obsolete standalone product-control/install UI just because it existed historically.

**Exit gate:** automated state-machine/restart/failure tests plus explicit physical-printer UAT. Product business workflows remain usable when Print Agent is unavailable.

---

## M9 — Platform, Immutable Packaging, Update/Repair/Recovery and Installer

**Status:** planned after components own their payload/lifecycle.

Order:
1. approved platform/prerequisite manifest and provisioning;
2. full immutable Local/Public/component packages;
3. compatibility/version manifest;
4. staged activation/rollback;
5. same-version Repair using canonical full package source;
6. Recovery set semantics distinct from Repair/Update;
7. final user-facing `SOKNA Setup.exe` composition for New/Recover/Repair/Uninstall.

Constraints:
- user never needs to manually execute PowerShell;
- PowerShell/.NET may be internal implementation details behind Setup/lifecycle engine;
- installer does not own active Local app files forever;
- clean-machine prerequisites and data preservation are acceptance requirements.

---

## M10 — Full Product Integration, Recovery and Release Qualification

**Status:** planned.

Required scenarios include:
- clean install;
- upgrade from supported historical baseline;
- Local-only update;
- Public-only update;
- Runtime update;
- Print Agent update/failure;
- Platform repair/failure;
- same-version application repair;
- backup/restore and machine takeover;
- Public offline / Internet offline / Local offline;
- printer unavailable;
- DB unavailable;
- contract-version incompatibility rejection before activation;
- SCDS responsive/A11y/device UAT;
- Windows and physical-printer UAT where required.

Only after these gates can V3 move from migration branch semantics to a release-candidate product claim.
