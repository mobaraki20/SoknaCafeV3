# SOKNA Cafe V3 — Ordered Migration Slices

This is the execution order for moving the historical product into V3. It is intentionally capability/owner based rather than a directory copy order.

Historical baseline: `mobaraki20/SoknaCafe@work/reconcile-dev39` / `a46435cca57df5bd5b9770efd0bb95390528aa05`.

## Global rule for every slice

A slice is complete only when all of the following are true:

1. historical owner and behavior are audited;
2. V3 owner/data owner/contract are explicit;
3. implementation is migrated/refactored behind that owner;
4. relevant business and SCDS behavior has executable regression coverage;
5. duplicate legacy owner for that scoped capability is removed/retired;
6. rollback/compatibility behavior is documented and tested at the applicable level;
7. `MIGRATION_MATRIX.csv` is updated from `ready/in_progress` to `migrated` only with evidence.

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

**Status:** in progress.

### M1.1 Realtime relay wire contract

Source audit:
- `includes/relay_protocol.php`;
- Public realtime enqueue/result routes;
- Local claim/ack routes;
- relay client/dispatch/actor/worker and tests.

V3 target:
- `contracts/local-public-realtime/`.

Current evidence already migrated:
- exact `sokna-relay-v1` HMAC signature construction;
- auth header names;
- envelope validation rules;
- realtime state/terminal-state registry;
- realtime kind registry;
- historical route map;
- regression HMAC test vector.

Remaining before M1.1 completion:
- extract claim lease/ACK/result payload schemas from executable source;
- extract error/result code taxonomy;
- extract replay/nonce durable semantics and idempotency storage rules;
- extract compatibility test vectors from historical tests;
- define which endpoint paths remain compatibility aliases vs new V3 canonical paths.

### M1.2 Deferred-safe wire contract

Source audit:
- `includes/relay_protocol.php`;
- `includes/deferred.php`;
- Public deferred enqueue/list/result;
- Local deferred claim/ack/reconcile/period-status;
- deferred worker/tests/financial-close hooks.

V3 target:
- `contracts/local-public-deferred/`.

Current evidence already migrated:
- state model;
- allowed kind registry;
- `occurred_at` validation and no-expiry semantics;
- route map;
- separation from realtime states/kinds;
- financial integrity invariants.

Remaining before M1.2 completion:
- exact claim/ACK/reconcile payload/result schemas;
- needs-review reason taxonomy;
- period-status schema;
- idempotency/receipt key rules;
- admin review resolution compatibility tests.

### M1.3 Runtime and Print contracts

Do not invent final schemas yet. First audit historical runtime/print APIs, then extract proven semantics while applying the V3 ownership correction:
- Runtime = supervision/OS integration only;
- Print Agent = separate deployable owner of durable print/device/spooler execution.

**Exit gate for M1:** executable contract schemas/tests exist before producer/consumer code is moved across V3 component boundaries.

---

## M2 — Local Core: Bootstrap, Data Ownership, Auth and Observability

**Status:** planned after M1 contract extraction.

Target owner: `apps/local-web`.

Move/refactor the minimum Local foundation needed by all business slices:
- configuration/bootstrap without Windows-specific ownership;
- Local DB connection/schema/migration owner;
- users/capabilities/preparation-area authority;
- correlation IDs, safe errors, redaction and Local health primitives;
- canonical shared business-service bootstrapping.

Constraints:
- no Runtime/Winspool/Registry/SCM access inside Local core;
- no second permission system;
- schema migrations prefer Expand -> Migrate -> Contract;
- no UI rewrite merely because file structure changes.

**Exit gate:** Local core can run/tests can initialize without Public/Runtime/Print being required for ordinary business-domain unit/integration tests.

---

## M3 — Public Edge Persistence, Auth Projection and Relay Transport

**Status:** planned.

Target owner: `apps/public` plus M1 contracts.

Move/refactor:
- Public DB/migrations;
- Local/Public binding/authentication material;
- minimal user/capability projection;
- realtime durable relay transport;
- deferred-safe durable pending/review transport;
- heartbeat/connectivity metadata;
- Public safe error/health primitives.

Constraints:
- no Local full business DB clone;
- no business SQL/decision owner on Public;
- Local remains final mutation authority;
- Realtime and Deferred stores/routes remain distinct.

**Exit gate:** transport/projection tests pass against executable M1 contracts with Local business owners stubbed/mocked, proving Public can fail independently.

---

## M4 — Guest Publish, Guest Runtime and Remote Read Models

**Status:** planned.

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

**Status:** planned after Local worker/business ownership is explicit.

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

**Status:** planned after M1.3 contract audit.

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
