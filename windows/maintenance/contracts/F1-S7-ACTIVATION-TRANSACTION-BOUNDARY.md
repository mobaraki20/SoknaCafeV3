# F1-S7 — Local Activate / Rollback boundary (engineering contract)

Status: **S7A read-only activation eligibility implemented; mutation disabled**.
Windows source: `mobaraki20/SoknaCafeV3`, Draft PR #25.
This contract does **not** authorize a release or host update.

## Rules

1. Supervisor is sole updater authority. It must own a live machine-wide lease for the entire Activate operation; delegated Update Engine is launched suspended, bound to KILL_ON_JOB_CLOSE before resume, and receives a short-lived one-use signed permit.
2. Stage completion ≠ installation authorization. Independently reverify signed Release Set, exact installed baseline, sequence, every staged file, current slot identity, updater versions and any changed input immediately before each irreversible step.
3. A signed **for_install** release requires production qualification (real Windows/MariaDB/Public/Canary, Recovery Console, real backup and vault, clean restore, failure matrix). A test fixture or read-only success is never installable.
4. Durable journal must record an operation state and fsync **before** each side effect. State transitions are never inferred from a lone receipt. Crash recovery inspects physical pointer, Apache state, current schema and backup evidence; uncertain states are quarantined for Recovery Console.
5. Mandatory before-mutation evidence: healthy Local/Apache, versioned managed-tree snapshot, exact current stable Web junction, no conflicting ledger operations, authenticated operator, verified local backup (freshness threshold), independently verified remote-vault copy, successful recovery-drill binding and local lifecycle snapshot. Failure rejects without mutation.
6. The Stage Release Set artifact is a **package container**, not an already executable Local Web release slot. Explicit mapping from signed local-web payload to a verified sibling slot must be qualified. Do not point Apache at an arbitrary staging folder.
7. Cutover happens with service stop proof -> durable pointer switch intent -> atomic junction swap -> Apache start and verified target health/build/tree -> post-switch journal. Old release remains retained for code rollback.
8. **Before the DB forward boundary**, rollback can restore the previous code slot and compatible local lifecycle state; must verify old build HTTP health and pointer/tree, and preserve evidence if rollback fails. No silent state overwrite.
9. **After a MariaDB forward migration is committed**, in-place schema or code rollback is forbidden unless an independently proven compatible forward recovery explicitly exists. Retain the target updater and use forward-only recovery. Never restore a live business DB over existing data as an automated rollback.
10. Public/Guest and dual-origin PWA reconciliation require a separate qualified contract. Local activation cannot silently change Public or Guest data.
11. Installation from Local Panel must keep `panel_update_ready=false` and `activation_enabled=false` until full M9/M10/P6/V3/G8 and real Home/Canary UAT pass. Engineering CI is NOT an operational PASS.

## Durable transaction phases (future S7B+)

| Phase | Required evidence | On failure |
|---|---|---|
| ELIGIBILITY_READ_ONLY | Exact Stage/Release Set evidence, signed installable status, all blockers cleared | No mutation |
| QUIESCE_AND_BACKUP | No in-flight writes, service/database consistency, local+remote verified backup, recovery drill | Reject before mutation |
| PREPARE_SIBLING | Signed payload-to-slot mapping, target boot, migrations compatibility, persistent overlay | Discard target, retain active |
| BEFORE_SWITCH | fsynced intent, healthy prior slot, parent lease+Job+permit | Reject/retain active |
| CODE_SWITCH | Apache stopped, atomic junction swap, verified target boot and HTTP health | Restore prior slot/lifecycle if no DB forward |
| DB_FORWARD_BOUNDARY | Independent migration head, rollback prohibition, durable boundary evidence | Forward-only recovery |
| COMMIT | Active slot/schema/health/identity attestation + durable operation receipt | Quarantine unproven state |
| CRASH_RECONCILE | Read physical slot, service, migration head and receipts without guessing | Recovery Console/manual decision |

## F1-S7A shipped behavior

Signed **GET** `/v1/local-updates/activation-readiness/{stageOperationId}` is read-only.
It calls the same independent package+staged-artifact verification used for Stage Recovery.
It describes blockers, including lack of runtime/DB/backup evidence and the unqualified atomic switch journal.
It ALWAYS returns `activation_allowed=false` and `panel_update_ready=false`; it does not create an activation operation or mutate Apache, schema or Public.
An incomplete/corrupt/quarantined Stage is rejected, not reported ready.

## Remaining gates

- S7B: dry-run immutable activation plan and durable intent/rollback journal, including crash points.
- S7C: qualified sibling slot preparation with exact content mapping, overlay, health.
- S7D: real supervised cutover + pre-DB compensation with explicit probes and journaling.
- S7E: MariaDB forward-boundary activation and forward-only recovery, with real backup/restore drills and failure injection.
- Host-specific UAT and release-gate reconciliation; never assume Home or Cafe is installed with this source.
