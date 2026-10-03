# M3 — Public Edge Persistence, Auth Projection and Relay Transport

Status: **COMPLETE — M3 exit gate satisfied**

Historical source: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
Target owner: `apps/public` plus the executable M1 Local/Public contracts.

Verified M3 checkpoint:

- Head: `07757f037ece5c71097cafb0f3dcc1d21ae4dcdf`
- GitHub Actions workflow: `36241108219`
- Workflow: `V3 Component Gates`
- Result: `SUCCESS`

## Ownership migrated in M3

M3 now has executable Public-owned implementations for:

- Public database connection and migration lifecycle;
- `installations` binding/enablement state;
- `auth_projections` and `public_sessions` per ADR 0003;
- login throttling and security-audit metadata without plaintext credentials/session material;
- signed Local HMAC verification and durable installation-bound replay protection;
- heartbeat/connectivity metadata and service/HTTP adapter;
- Realtime durable relay transport: enqueue/result/claim/ack;
- Deferred-safe transport: enqueue/list/result/claim/ack/reconcile/period-status;
- Public health and safe-error boundary with correlation IDs and no exception-detail leakage.

## Deliberately excluded from M3

The following historical Public tables/behaviors belong to M4 and were intentionally not absorbed into the M3 migration merely because they were co-located in the old schema/bootstrap:

- `guest_publish_revisions`;
- `guest_active_revisions`;
- `guest_availability_state`;
- `remote_read_models`;
- guest snapshot/media runtime and remote read-model rendering/filtering beyond the M3 auth/connectivity edge.

This preserves slice/data ownership instead of copying the historical directory structure.

## Preserved invariants

- Local remains final Business Authority and canonical business-mutation owner.
- Public auth projection is compatibility/security edge state, not canonical user ownership.
- Public does not clone the Local business database.
- Realtime and Deferred persistence/state machines remain separate.
- Realtime idempotency remains installation/request scoped and conflicting reuse of a request ID is rejected.
- Realtime lease material is stored only as a one-way SHA-256 hash.
- Deferred work remains independently durable, retains `needs_review`, reconciliation and financial-period blocking semantics, and does not inherit Realtime expiry semantics.
- request nonce/replay uniqueness remains installation-bound.
- Public session persistence stores only a one-way token hash.
- HMAC failure taxonomy remains `unknown_installation`, `bad_signature`, `replay_detected`.
- health/error responses do not expose exception class/message/trace, DB connection detail, credentials or Local business-table data.

## Executable evidence

Static/ownership gates:

- `tests/public-m3-contract.py`
- `tests/public-m3-transport-contract.py`
- `tests/public-health-contract.py`

MariaDB-backed executable gates:

- `tests/public-mysql-migration-selftest.php`
- `tests/public-auth-projection-selftest.php`
- `tests/public-login-selftest.php`
- `tests/public-signed-local-request-selftest.php`
- `tests/public-auth-http-adapter-selftest.php`
- `tests/public-connectivity-selftest.php`
- `tests/public-realtime-selftest.php`
- `tests/public-deferred-selftest.php`
- `tests/public-health-selftest.php`

The Public Edge M3 job runs these against MariaDB in `.github/workflows/v3-component-gates.yml` and was green at the verified checkpoint above.

## M3 exit-gate decision

**SATISFIED.** Public-owned persistence, projection/authentication edge, signed Local boundary, heartbeat/connectivity, Realtime transport, Deferred-safe transport and Public health/safe-error primitives are executable behind their V3 owners and tested against the M1 contract semantics without moving canonical business authority to Public.

This M3 completion does **not** claim that Local Orders/Inventory/Supply/Finance business owners have been migrated; those remain later Local business slices. Likewise Guest Publish/Guest Runtime/Remote Read Models remain M4.

## Next canonical slice

Proceed to **M4 — Guest Publish, Guest Runtime and Remote Read Models**.
