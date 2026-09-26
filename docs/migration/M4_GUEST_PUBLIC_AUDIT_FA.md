# M4 — Guest Publish, Guest Runtime and Remote Read Models Audit

Status: **COMPLETE — exit gate satisfied**

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`

Verified M4 implementation checkpoint:

- head: `08119caa741916badac997350aa71e8c4ee72822`
- `V3 Component Gates` run `36244318018`: **SUCCESS**
- `M4 Guest Renderer Gate` run `36244318015`: **SUCCESS**
- `M4 Failure Isolation Gate` run `36244318053`: **SUCCESS**

Canonical closure evidence: `docs/migration/M4_CLOSURE_EVIDENCE_FA.md`.

## Why M4 is a distinct slice

The historical Public schema co-located relay/auth and guest/read-model tables. V3 intentionally separates them:

- M3 owns Public persistence/auth/connectivity/transport;
- M4 owns published Guest artifacts/runtime projections and remote read models;
- Local remains canonical catalog/business-data and publish-decision owner;
- Public remains projection/runtime owner, never canonical business mutation authority.

## Historical owners audited

### Guest publish/media ingress from Local

- `public_edge/api/v1/local/guest/media.php`
- `public_edge/api/v1/local/guest/publish.php`
- `public_edge/api/v1/local/guest/availability.php`

All are signed Local-to-Public boundaries.

### Guest/Public persistence

M4 owns the Public-side migration/runtime for:

- `guest_publish_revisions`
- `guest_active_revisions`
- `guest_availability_state`
- `remote_read_models`

These tables were deliberately excluded from M3.

### Guest compatibility/runtime surface

Historical evidence:

- `public_edge/api/v1/guest/enqueue.php`
- `public_edge/api/v1/guest/result.php`
- `public_edge/api/v1/guest/compat/*`
- `public_edge/guest/index.php`
- `includes/guest_menu_view.php`
- historical Guest bundle/action-state/media helpers.

V3 correction: compatibility actions are adapters over the existing M3 Realtime transport. No second queue/business owner is created.

### Remote read-model ingress/consumption

Historical evidence:

- signed Local sync: `public_edge/api/v1/local/read_model_sync.php`
- remote consumption: `public_edge/api/v1/remote/read.php`
- historical Public session/projection scope filters.

## Frozen Guest Publish invariants

1. revision IDs are content-addressed as `guest-` + the first 32 hex chars of canonical content hash;
2. content hash is SHA-256 over canonical `{format,snapshot,media_manifest}` and format is `sokna-guest-snapshot-v1`;
3. same revision/same content is idempotent; same revision/different content is conflict;
4. media is content-addressed by SHA-256 and constrained to approved image MIME/extension pairs;
5. media hash deduplicates identical bytes; path/hash mismatch is an error;
6. activation requires all manifest media to exist and verify;
7. revision insertion and active-pointer switch are transactional;
8. revisions are immutable; activation moves a pointer rather than rewriting content.

## Frozen Availability/degraded-state invariants

1. availability is distinct from the immutable publish snapshot;
2. availability version is SHA-256 over canonical payload excluding its `version` field;
3. availability has generated/sync timestamps;
4. read-only Guest content may remain available while Local/sync is stale;
5. mutating Guest actions require installation flags plus fresh Local heartbeat and fresh availability projection;
6. freshness is explicit and fail-closed for writes.

## Frozen Remote Read Model invariants

Model/capability registry:

- `operations` -> `operations.read`
- `preparation` -> `preparation.read`
- `inventory` -> `inventory.read`
- `inventory_cost` -> `inventory.cost.read`
- `reports` -> `reports.read`
- `deferred_context` -> `deferred.context`

Sync/read rules preserved:

1. format is `sokna-remote-read-v1`;
2. `source_version` is SHA-256 of canonical payload;
3. model keys are closed/allowlisted;
4. same version may refresh sync age without rewriting payload;
5. different version replaces only that installation/model projection;
6. Public session + model capability are required for reads;
7. preparation scope is filtered by projected preparation areas unless monitor/wildcard scope applies;
8. `deferred_context` is field-filtered by deferred capabilities;
9. response exposes generated/sync/connectivity stale state;
10. read models never accept business mutations.

## Implemented V3 owners

### `apps/public`

- M4 migrations for Guest/public projection tables;
- content-addressed media store;
- immutable publish revision + atomic active pointer;
- availability projection;
- Guest runtime bundle/action-state;
- Remote Read Model storage/read/filtering;
- thin signed Local adapters and session-aware remote adapters;
- Guest compatibility service bound to M3 Realtime;
- SCDS Guest page renderer and domain-scoped assets.

### Local remains owner of

- canonical catalog/table/menu/business source data;
- publish decision/source versions;
- authoritative availability/business acceptance decision;
- canonical order/waiter/business effects after Realtime dispatch.

### M3 owners reused, not duplicated

- HMAC/replay verification;
- Public session/auth projection;
- heartbeat/connectivity;
- Realtime enqueue/claim/ack/result.

## Implementation evidence by planned step

1. **Schema migration** — `tests/public-m4-schema-contract.py`, Public MariaDB migration self-test.
2. **Guest media/publish/availability** — `tests/public-m4-guest-publish-selftest.php`.
3. **Guest runtime/degraded state** — `tests/public-m4-guest-runtime-selftest.php`.
4. **Remote Read Models** — `tests/public-m4-remote-read-model-selftest.php`.
5. **Compatibility on M3 Realtime** — commit `c32ef35ce2234cdf3a9f155f40c9c72a66198cef`, `tests/public-m4-guest-compat-selftest.php`.
6. **SCDS Guest renderer** — commit `6d528a3f1b05f01f69bbc14590c82046b110be91`, workflow `M4 Guest Renderer Gate`.
7. **Failure isolation / closure** — commit `08119caa741916badac997350aa71e8c4ee72822`, workflow `M4 Failure Isolation Gate`.

## SCDS decision

Historical Guest HTML/CSS/JS remained evidence rather than V3 authority. The migrated renderer follows:

`Audit -> Correct -> Standardize -> Migrate -> Enforce`

Registered V3 Guest owners are in `docs/ui-design-system/COMPONENT_REGISTRY.json`. The implementation uses `sg-*` domain selectors and does not create generic parallel `.btn`/card/input ownership.

## Exit scenarios

### Local-down

Tested: immutable published menu remains readable; action state degrades; order/waiter mutation UI is removed; compatibility mutation fails closed with `local_unavailable`.

### Public-down

Tested: Local bootstrap/ownership contract and real MariaDB migration execute in an isolated CI job with no Public service/configuration.

### Internet-down / sync-loss

Tested at the boundary/freshness level: Local remains independently bootable; Public with stale availability sync retains immutable read content but disables writes.

This is not represented as a packet-level network-chaos test. NIC/DNS/proxy/browser end-to-end failure qualification remains M10 scope.

## M4 non-goals preserved

- no migration of canonical Orders/Inventory/Finance/Supply owners from Local;
- no new Public business SQL/decision owner;
- no second Realtime or Deferred queue;
- no blind copy of legacy Guest UI;
- no Runtime/Print/Packaging movement as a substitute for M4 completion.

## Exit decision

**M4 exit gate: SATISFIED.**

The next canonical slice is **M5 — Local Business Domains in dependency order**, starting with explicit Sellables before canonical Orders.
