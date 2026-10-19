# F1-S7D1 — Code cutover write-ahead journal foundation

Status: ENGINEERING-ONLY, DRAFT PR #25; no production cutover or UI activation. Not installed on Home, Cafe or Public.

## Canonical ownership

- Supervisor is the sole Local mutation authority. A valid live OS machine mutation lease (`supervisor-code-cutover`) must cover all mutation-intent journal writes.
- The eventual Update Engine receives a short-lived operation-bound delegation and executes under Windows Job Object before any host mutation. The current S7D1 journal has **no Update Engine invocation**.
- Public deploy is owned independently by Public Maintenance Kernel. Paired Release Set requires independently verified Public ACK, protocol compatibility and queue/outbox policies; these are absent from this milestone.

## What the implementation actually provides

- New Core `LocalCodeCutoverWriteAheadJournal` writes an append-only `events.jsonl` under private ProgramData `update/code-cutover-journal/{transactionId}`; every new event flushes with `FileOptions.WriteThrough` + `Flush(flushToDisk:true)`.
- Event hashes form a sequential SHA-256 chain to detect accidental corruption or unsanctioned rewriting (not a cryptographic protection against an attacker with full write access). Storage ACL and independent signed intent binding remain mandatory before real use.
- The write-ahead phases model Apache stop intent, evidence of stop, junction switch intent, observed switch, target HTTP, DB migration intent, DB committed and explicit pre-DB rollback.
- Phase transitions cannot skip barriers; the presence of a stale or incomplete directory is **quarantined**, never overwritten or auto-cleaned.
- On every replay, the reader validates event ordering, paths, immutable install hash, evidence hashes and chain. The crash classifier observes reported pointer position but never treats it as Apache health proof.
- Starting with `db_forward_intent`, the outcome is **forward-only/manual recovery** because MariaDB may already have changed even without a `db_forward_committed` receipt. No automatic schema/old-code rollback.
- ALL records and classifier responses explicitly set `dry_run_only=true`/`execution_authorized=false`, `activation_allowed=false`, `panel_update_ready=false`, `automatic_rollback_allowed=false`.
- No HTTP activation API, service stop/start, actual junction swap, SQL execution, queue freeze, web install or Public mutation exists in this class.

## Independent Windows CI acceptance

- Compile Core and Windows Probe with warnings as errors, and run synthetic sibling path test with a retained live machine lease.
- Reject journal begin without correct issuer lease; reject duplicate begin and illegal jumps.
- Simulate process restart by constructing a fresh journal reader for the same on-disk evidence.
- Simulate crash before stop, mid-pointer and mid-rollback: classify as unproven and do not offer automatic rollback.
- Simulate `db_forward_intent` without DB commit receipt: forbid pre-DB rollback immediately.
- Simulate post-boundary restart: retain forward-only result.
- Corrupt an on-disk record and retain an incomplete transaction directory: fail closed and never overwrite.
- Compare old/new synthetic slot bytes before/after: WAL itself does not mutate files.
- Existing six CI gates all must pass on **one exact SHA**; do not reuse previous-head successes.

## F1-S7D2+ execution blockers

1. Bind transaction to a fresh signed Release Set, immutable S7C2 slot receipt, real installed identity and machine ACL; qualify authorization and strong evidence provenance.
2. Verify MariaDB and business backup consistency, independently restorable local/remote copies and migration graph compatibility before any mutation.
3. Add a separately audited cutover executor with `stop Apache -> durable before-pointer checkpoint -> atomic qualified junction swap -> restart Apache -> exact target/version/health probes`, retaining the old physical slot.
4. After a pre-DB failure, explicitly prove previous Web pointer, Apache health/build and unchanged DB schema before declaring recovery. Never infer rollback from merely seeing the old pointer.
5. For any DB-forward-intent crash, block old-code rollback until an independent MariaDB proof resolves the mutation boundary. Automatic business DB overwrite is forbidden.
6. Add independent Public Kernel + signed paired transaction and failure simulations for WAN loss, PWA transitions and outbox durability.
7. Complete 80-case fault matrix, package/upgrade/backout and real-host UAT only at the final approval gate. Home/Cafe remain untouched during engineering.

Keep PR Draft, `panel_update_ready=false`, no installer publication and no new direct Engine CLI bypass.
