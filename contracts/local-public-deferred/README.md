# Contract: Local ↔ Public Deferred-safe

Version: `0.1.0-draft`

This boundary carries explicitly deferred-safe work while Local is unavailable or synchronization is delayed.

## Frozen semantics

- Deferred-safe is a distinct state machine from realtime relay.
- Public may own durable pending transport/review state; Local owns canonical validation and commit.
- Expected states/versions are revalidated by Local.
- Late work for a closed financial period must not silently back-post or reopen the period.
- Reconciliation may result in committed, rejected or needs-review outcomes according to the Local business owner.
- Normal financial close is blocked by pending/review work and by required Public connectivity uncertainty under the frozen financial rules; audited override remains explicit.

## Historical scope

Historical owners include `includes/deferred.php`, deferred Public endpoints and `tools/deferred-worker.php` on `work/reconcile-dev39`.

## Draft work still required

Before stability: extract the exact eligible operation list, envelope/schema, state transitions, conflict/review reasons, idempotency keys, connectivity semantics, period-close hooks and compatibility tests from audited source.
