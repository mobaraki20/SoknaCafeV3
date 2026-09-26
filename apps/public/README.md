# Public

`apps/public` is the independently deployable Public Edge component.

It may own guest/public surfaces, remote staff gateway surfaces, durable relay state, safe projections/snapshots, Public-owned storage/migrations and limited emergency/recovery tooling for Public itself.

It is never the primary Business Authority and must not become a full clone of Local business data. Canonical business mutations must be revalidated/committed by Local through versioned contracts.

## M3 status

M3 is in progress. The first executable Public foundation owns only:

- Public database connection and migration lifecycle;
- installation/binding state;
- minimal auth projection and Public sessions per ADR 0003;
- durable Realtime relay queue state;
- durable Deferred-safe queue state;
- request nonce/replay state;
- heartbeat/connectivity metadata;
- Public-owned login throttling/security-audit metadata with no password/verifier/token storage.

Guest publish revisions, guest availability and remote read models remain M4 scope and are intentionally excluded from the M3 core migration.

Realtime and Deferred persistence/state machines remain separate. Public capability checks are edge filters only; Local remains the final canonical authorization and business-mutation authority.
