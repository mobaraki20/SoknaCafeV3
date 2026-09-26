# M4 — Guest Publish, Guest Runtime and Remote Read Models Audit

Status: **IN PROGRESS — historical ownership/invariant audit complete enough to begin implementation**

Historical baseline: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
M4 starts only after verified M3 closure (`84ecd1d3544c8390c8ae89a3d414c5fca227fe56`, workflow `36241360998`, SUCCESS).

## Why M4 is a distinct slice

The dev.39 Public schema co-located relay/auth and guest/read-model tables. V3 intentionally separated them:

- M3 owns Public edge persistence/auth/connectivity/transport;
- M4 owns published guest artifacts/runtime projections and remote read models;
- Local remains the source of canonical catalog/business data and the publish decision;
- Public remains a projection/runtime owner, never canonical business mutation authority.

## Historical owners audited

### Guest publish/media ingress from Local

- `public_edge/api/v1/local/guest/media.php`
- `public_edge/api/v1/local/guest/publish.php`
- `public_edge/api/v1/local/guest/availability.php`

All three are signed Local-to-Public boundaries.

### Guest/Public persistence

Historical schema tables:

- `guest_publish_revisions`
- `guest_active_revisions`
- `guest_availability_state`
- `remote_read_models`

These were deliberately excluded from M3.

### Guest compatibility/runtime surface

- `public_edge/api/v1/guest/enqueue.php`
- `public_edge/api/v1/guest/result.php`
- `public_edge/api/v1/guest/compat/*`
- historical `public_guest_bundle`, table-token/ref helpers, action-state/degraded-state helpers and media URL helpers in `public_edge/bootstrap.php`.

Compatibility endpoints that create/query orders or waiter calls are adapters over Realtime; they must not create a second queue/business owner in M4.

### Remote read-model ingress/consumption

- signed Local sync: `public_edge/api/v1/local/read_model_sync.php`
- remote consumption: `public_edge/api/v1/remote/read.php`
- session/projection scope filters in historical `public_edge/bootstrap.php`.

## Frozen Guest Publish invariants

1. Revision IDs are content-addressed: `guest-` + first 32 hex chars of the canonical content hash.
2. Content hash is SHA-256 over canonical `{format,snapshot,media_manifest}` and snapshot format is `sokna-guest-snapshot-v1`.
3. Reusing a revision ID with a different content hash is a conflict; replaying the same revision is idempotent.
4. Media is content-addressed by SHA-256, constrained to approved image MIME/extension pairs, with an 8 MiB historical item limit.
5. Existing media with the same hash is deduplicated; an existing path whose bytes do not match is a collision/error.
6. A revision cannot be activated until every media manifest entry exists and verifies against its SHA-256.
7. Revision insert and active-revision pointer switch occur in one DB transaction.
8. Published revisions are immutable; activation moves a pointer rather than rewriting snapshot content.

## Frozen Availability/degraded-state invariants

1. Availability is a distinct projection from the immutable publish snapshot.
2. Payload version is SHA-256 over canonical payload excluding the `version` field.
3. Payload contains at least item availability and order-acceptance state and has its own generated/sync timestamps.
4. Guest read-only content may remain available when Local is stale/unreachable.
5. Mutating guest actions are enabled only when installation remote/order-intake flags permit them **and** Local heartbeat and availability projection are fresh.
6. Historical guest action-state freshness was a short operational window (15 seconds); V3 may parameterize the value but must preserve explicit fresh/stale semantics rather than silently allowing writes.

## Frozen Remote Read Model invariants

Historical model registry:

- `operations` -> `operations.read`
- `preparation` -> `preparation.read`
- `inventory` -> `inventory.read`
- `inventory_cost` -> `inventory.cost.read`
- `reports` -> `reports.read`
- `deferred_context` -> `deferred.context`

Sync rules:

1. format is `sokna-remote-read-v1`;
2. `source_version` is SHA-256 of canonical payload;
3. allowed model keys are closed/allowlisted;
4. same source version updates sync freshness without rewriting payload;
5. different source version replaces only that installation/model projection.

Read rules:

1. a valid Public session is required;
2. model-specific capability is required;
3. `preparation.monitor` implies preparation read and wildcard retains full access;
4. non-monitor preparation readers see only their assigned preparation areas;
5. `deferred_context` is pruned field-by-field based on deferred capabilities;
6. responses expose generated/sync timestamps plus connectivity-derived stale state;
7. read models never accept business mutations.

## V3 M4 owner plan

### `apps/public` owns

- M4 Public migration for the four projection/publish tables;
- content-addressed Guest media storage;
- immutable Guest revision storage and atomic active pointer;
- availability projection storage;
- guest bundle/degraded action-state projection;
- remote read-model storage and capability/scope-filtered reads;
- thin HTTP adapters for signed Local sync and Public/session reads.

### Local owns

- canonical source catalog/tables/menu/business data;
- generation/publish decision and source versions;
- authoritative availability/business acceptance decision;
- canonical order/waiter/business mutation after Realtime dispatch.

### M3 owners reused, not duplicated

- HMAC/replay verification;
- Public session/auth projection;
- heartbeat/connectivity;
- Realtime enqueue/result for guest mutation adapters.

## SCDS/UI rule

Historical Guest HTML/CSS/JS is **evidence**, not automatic V3 authority. Before migrating user-facing Guest markup/styles, apply:

`Audit -> Correct -> Standardize -> Migrate -> Enforce`

M4 must not create a second guest renderer or preserve rejected style debt solely for pixel parity.

## Planned implementation order

1. M4 schema migration + migration gate for the four M4 tables.
2. Guest media/revision/active-pointer/availability services and signed Local adapters.
3. Guest bundle/action-state read service with degraded behavior tests.
4. Remote read-model sync/read/filter service and executable capability-scope tests.
5. Guest compatibility adapters bound to M3 Realtime without queue duplication.
6. SCDS-audited Guest runtime/rendering migration.
7. Local-down/Public-down/Internet-down exit scenarios and M4 closure evidence.

## M4 non-goals

- No migration of canonical Orders/Inventory/Finance/Supply owners from Local (M5).
- No new Public business SQL/decision owner.
- No second Realtime or Deferred queue.
- No blind copy of legacy guest UI.
- No Runtime/Print/Packaging implementation movement as a substitute for M4 completion.
