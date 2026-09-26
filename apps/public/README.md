# Public

`apps/public` is the independently deployable Public Edge component.

It may own guest/public surfaces, remote staff gateway surfaces, durable relay state, safe projections/snapshots, Public-owned storage/migrations and limited emergency/recovery tooling for Public itself.

It is never the primary Business Authority and must not become a full clone of Local business data. Canonical business mutations must be revalidated/committed by Local through versioned contracts.

## M3 status

**M3 is complete at the Public Edge persistence/auth/transport boundary.**

Verified M3 checkpoint:

- head `07757f037ece5c71097cafb0f3dcc1d21ae4dcdf`;
- `V3 Component Gates` run `36241108219` — SUCCESS.

M3 Public ownership now includes:

- Public database connection and migration lifecycle;
- installation/binding state;
- minimal auth projection and Public sessions per ADR 0003;
- login throttling and security-audit metadata;
- durable installation-bound request nonce/replay protection;
- signed Local HMAC verification;
- heartbeat/connectivity metadata;
- durable Realtime relay queue and its enqueue/result/claim/ack owner;
- durable Deferred-safe pending/review queue and its enqueue/list/result/claim/ack/reconcile/period-status owner;
- Public health and safe-error boundary with correlation IDs.

Realtime and Deferred persistence/state machines remain separate. Public capability checks are edge filters only; Local remains the final canonical authorization and business-mutation authority.

The Public M3 CI gate executes migration, auth projection, login/session/throttling/audit, HMAC/replay, HTTP adapter, connectivity, Realtime, Deferred and health/safe-error tests against MariaDB.

## M4 boundary

Guest publish revisions, guest active-revision state, guest availability and remote read models remain **M4** scope and are intentionally excluded from the M3 core. Their migration must preserve immutable/atomic publish semantics, degraded read-only behavior and projected scope filtering without turning Public into a business authority or copying rejected legacy UI/design debt.

Canonical M3 evidence: `docs/migration/M3_PUBLIC_EDGE_AUDIT_FA.md`.
Canonical continuation: `docs/migration/CURRENT_CONTINUATION_FA.md`.
