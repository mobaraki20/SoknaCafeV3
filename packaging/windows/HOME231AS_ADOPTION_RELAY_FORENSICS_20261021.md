# Home 231as / Public 35ek / Windows 1.1.19 — release-blocking evidence

**Status:** Source-only qualification. NOT a ready-to-install release. No service, Local or Public deployment from this branch.

## Local adoption — verified source and guard

Home Windows 1.1.19 / Maintenance 0.3.9 support at 03:05:39 UTC records `LOCAL_BASELINE_NOT_ALLOWLISTED` for exact build:
`3.0.0-g6.2-workspace.231as-connectivity-relay-ack-home-uat`.

The previously published `local-slot-bootstrap-contract-v1.json` had only `231ai` (497 files), so 231as never reached any physical-R8 or signed-identity check. The actual 231as executable inside `SOKNA-Home-Connectivity-Local231as-Public35ek-Canary-20261021.zip` embeds a Local ZIP with 509 managed files. Recomputed digest using path-ascending ordinal, `path + NUL + lowercase(SHA256(file)) + LF`, and SHA256 of the concatenation:

- **231as managed_files:** 509
- **231as managed_tree_sha256:** `d67561b5cc72d0e8003c2497c2af26dcdb3f7c5a893aa4eccaa462924c49f77f`
- `resources/local-managed-files-v1.json` format verified; 509 paths listed and all 509 present in embedded ZIP.

This branch adds **only** the exact known tuple, not wildcard build acceptance. The queue helper re-hashes the independently installed managed files before acting; mismatch stays blocked. If stable Web is already an R8 junction, it **does not** migrate, rewrite installed identity, alter DB/Public or replace a healthy slot. The next **genuine publisher-signed** Local-only Release Set must advertise and prove the exact 231as baseline; `SupervisorSignedLocalIdentityRebind` owns any identity correction during signed preflight.

**Limit:** We verified the archived source, NOT the deployed home's byte-for-byte live Web. An exact source hash is not an issuer signature and does not authorize activation alone. Real-host proof must validate the files and existing Maintenance identity/junction before a signed Stage/Activate.

## Stable recovery — separate real blocker

Local's `SystemDiagnosticsService` `stable_recovery` check failed. The embedded 231as managed ZIP has **no** `public/local-recovery.php`. The Local updater intentionally excludes that file and `public/local-update-runner.php` from managed releases; they are machine-owned stable overlays. A missing entrypoint must NOT be deemed healthy merely because Windows Recovery Console exists.

Before home panel activation, restore or deploy the genuine authorized stable Local entrypoint through the trusted machine-owned persistent overlay, verify its exact bytes/access controls and probe its safe recovery read-only behavior. Do not generate an unsigned PHP recovery endpoint or copy arbitrary source into live Apache. Coordinate this with the separate Local P1 stream; do not change the release tree in this Windows branch.

## Runtime 1.0.6 / business command

Support `SOKNA-Support-v2-20261021-063856.zip` shows Runtime 1.0.6 Scheduler continued ticking, all five triggers scheduled, and status `degraded` at 03:09 UTC. On 2026-10-21 UTC, runtime.log contains **258** `public.relay_sync trigger_deadline_exceeded` events, **33** notifications, **21** inventory, **9** Maintenance health and **1** projection. Other triggers also hit deadlines, suggesting shared Local PHP/Apache worker contention or slow handler/WAN work, rather than a stopped global scheduler. Existing fast relay deadline is 4500ms and failed execution invokes backoff; effective 1s cadence is not guaranteed under failures.

The Public report at 03:08 UTC retained `table-draft-finalize-atomic-mvhhizn7-b878729d` in `queued` state, created 02:20:33 UTC, expires 02:23:26 UTC, never claimed; last claimed diagnostics at 02:19:28 UTC. Local WAN exchange logs show successful `realtime/exchange` calls last at **02:19:23 UTC** in the captured window, with none in the subsequent log tail. Heartbeat is independently fresh; that does not prove relay command consumption. Earlier realtime calls were ~270–381ms, so the cause of the subsequent absence cannot be assumed to be WAN transport latency alone.

**P1 verification focus:** Trace `Runtime POST /internal/runtime/v1/trigger -> PHP worker -> PublicEdgeRelayService::syncRealtimeOnly -> outbound /realtime/exchange -> claim -> durable ACK`. Measure each segment with a common correlation ID. Ensure business commands are claimed/acknowledged ahead of projection/deferred work, that the PHP handler obeys its 4500ms relay budget, that backoff cannot permanently starve the relay queue, and that retries retain the same business idempotency identity. Test WAN loss/recovery and simultaneous 15min trigger execution. Do not change Runtime version without isolating a reproducible Runtime defect.

## Required acceptance before the next release

1. On the actual Home system, verify 231as live managed inventory (509 files + exact hash), signed baseline acceptance, and valid stable recovery capability.
2. Install a genuine *next-version* signed Local release **from the Local web panel**, then verify active version via live HTTP, installed identity, readback, and a successful safe rollback/recovery drill. Merely showing `panel_update_ready=true` is insufficient.
3. Run relay/heartbeat/inventory/projection simultaneously >=15min; command `queued -> claimed -> ACK/terminal` should complete in allowed window, with WAN temporary failure/recovery, without duplicate business operations.
4. Until these on-host checks pass: **no new Windows Services release and no Runtime patch**. The P1 Local/Public work may proceed independently.
