# Contract: Local ↔ Public Realtime

Version: `0.1.0-draft`

This boundary carries Local-required realtime operations between Public and the sole canonical Local Business Authority.

## Frozen semantics

- Local revalidates actor, capability, expected state/version and business rules before commit.
- Success is reported only after a valid Local commit.
- Lost ACK/retry must not create a duplicate business effect.
- Expired/ambiguous requests must not surprise-commit.
- Public owns durable relay transport state; it does not become business authority.
- This state machine is separate from `local-public-deferred` and must never share state values merely for implementation convenience.

## Migration source

Historical owners include `includes/relay_protocol.php`, `relay_client.php`, `relay_dispatch.php`, `relay_projection.php`, `relay_actor.php`, `tools/relay-worker.php` and Public relay endpoints on `work/reconcile-dev39`.

## Draft work still required

Before stability: extract exact envelope/schema, authentication/signature format, claim/lease rules, TTL, idempotency key semantics, ACK/result states, error taxonomy and cross-version tests from the audited source.
