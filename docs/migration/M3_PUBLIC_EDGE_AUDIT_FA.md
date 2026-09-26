# M3 — Public Edge Persistence / Initial Executable Slice

Status: **IN PROGRESS**

Historical source: `mobaraki20/SoknaCafe@a46435cca57df5bd5b9770efd0bb95390528aa05`
Target owner: `apps/public`

## Scope of this checkpoint

This first M3 checkpoint migrates the Public-owned persistence foundation required by Auth Projection and relay transport. It does not claim the whole M3 slice complete.

Migrated ownership:

- Public DB bootstrap/connection and migration lifecycle;
- `installations`;
- `auth_projections` and `public_sessions` per ADR 0003;
- `realtime_requests`;
- `request_nonces`;
- `installation_heartbeats`;
- `deferred_work`;
- V3 additive `auth_login_throttle` and `auth_security_audit` storage required by ADR 0003 abuse-control hardening.

## Deliberately excluded

The historical `public_edge/database/schema.sql` also contains Guest Publish and Remote Read Model tables. They are not copied into this migration because their V3 owner/slice is M4:

- `guest_publish_revisions`;
- `guest_active_revisions`;
- `guest_availability_state`;
- `remote_read_models`.

This prevents historical file co-location from becoming accidental V3 ownership.

## Preserved invariants

- Local remains final Business Authority.
- Public auth projection is compatibility/security edge state, not canonical user ownership.
- Realtime and Deferred persistence/state machines remain separate.
- Realtime request idempotency remains unique by `(installation_id, request_id)`.
- Deferred work remains independently durable by `(installation_id, request_id)`.
- durable request nonce uniqueness remains installation-bound.
- Public session token persistence stores only a one-way hash.
- auth throttle/audit storage must contain no plaintext password, projected verifier or bearer/session token.

## Executable evidence

- `tests/public-m3-contract.py` checks ownership/scope/schema invariants and PHP lint.
- `tests/public-mysql-migration-selftest.php` exercises migration/rerun, storage separation, nonce replay uniqueness and secret-minimizing auth control tables on real MariaDB.
- `.github/workflows/v3-component-gates.yml` Public job runs these gates against MariaDB.

## Remaining M3 work after this checkpoint

1. signed Local installation/binding and auth projection sync;
2. Public login/session/throttling/audit implementation per ADR 0003;
3. signed Local HMAC/replay verification adapter bound to reconciled M1 taxonomy;
4. Realtime enqueue/result/claim/ack transport implementation;
5. Deferred enqueue/list/result/claim/ack/reconcile/period-status transport implementation;
6. heartbeat/connectivity route/service implementation;
7. producer/consumer operation tests against M1 contracts;
8. migration matrix/status evidence updates and M3 exit-gate review.
