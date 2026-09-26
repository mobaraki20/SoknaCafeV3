#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "apps" / "public"
CORE = PUBLIC / "src" / "Core"
REALTIME = PUBLIC / "src" / "Realtime"
DEFERRED = PUBLIC / "src" / "Deferred"
HTTP = PUBLIC / "src" / "Http"

required = [
    REALTIME / "RealtimeService.php",
    DEFERRED / "DeferredService.php",
    HTTP / "RealtimeHttpAdapter.php",
    HTTP / "DeferredHttpAdapter.php",
    ROOT / "tests" / "public-realtime-selftest.php",
    ROOT / "tests" / "public-deferred-selftest.php",
]
missing = [str(path.relative_to(ROOT)) for path in required if not path.is_file()]
if missing:
    raise SystemExit("Missing M3 transport files: " + ", ".join(missing))

realtime = (REALTIME / "RealtimeService.php").read_text(encoding="utf-8")
for token in (
    "guest.order.submit", "guest.waiter_call.create", "orders.mutate", "finance.settle",
    "preparation.mutate", "orders.table_draft", "request_id_conflict", "lease_token_hash",
    "hash('sha256', $leaseToken)", "state='expired'", "local_unavailable", "order_intake_disabled",
):
    if token not in realtime:
        raise SystemExit(f"Realtime service missing frozen contract invariant: {token}")
if "deferred_work" in realtime:
    raise SystemExit("Realtime service absorbed Deferred storage/state")

realtime_adapter = (HTTP / "RealtimeHttpAdapter.php").read_text(encoding="utf-8")
for forbidden in ("INSERT INTO realtime_requests", "UPDATE realtime_requests", "DELETE FROM realtime_requests"):
    if forbidden in realtime_adapter:
        raise SystemExit(f"Realtime adapter duplicated service persistence: {forbidden}")
for delegated in (
    "publicSessions()->resolve", "signedLocalRequests()->verify", "realtime()->enqueue",
    "realtime()->result", "realtime()->claim", "realtime()->ack",
):
    if delegated not in realtime_adapter:
        raise SystemExit(f"Realtime adapter lost canonical delegation: {delegated}")

deferred = (DEFERRED / "DeferredService.php").read_text(encoding="utf-8")
for token in (
    "supply.need.defer", "supply.manage.defer", "inventory.waste.defer", "inventory.count_draft.defer",
    "subscriber.payment.defer", "expense.create.defer", "request_id_conflict", "pending_sync",
    "terminal_state_conflict", "needs_review", "attempt_count=attempt_count+1", "blocking",
):
    if token not in deferred:
        raise SystemExit(f"Deferred service missing frozen contract invariant: {token}")
if "realtime_requests" in deferred:
    raise SystemExit("Deferred service absorbed Realtime storage/state")
if "array_key_exists('expires_at', $envelope)" not in deferred:
    raise SystemExit("Deferred service no longer rejects realtime-style expires_at semantics")

deferred_adapter = (HTTP / "DeferredHttpAdapter.php").read_text(encoding="utf-8")
for forbidden in ("INSERT INTO deferred_work", "UPDATE deferred_work", "DELETE FROM deferred_work"):
    if forbidden in deferred_adapter:
        raise SystemExit(f"Deferred adapter duplicated service persistence: {forbidden}")
for delegated in (
    "publicSessions()->resolve", "signedLocalRequests()->verify", "deferred()->enqueue", "deferred()->list",
    "deferred()->result", "deferred()->claim", "deferred()->ack", "deferred()->reconcile", "deferred()->periodStatus",
):
    if delegated not in deferred_adapter:
        raise SystemExit(f"Deferred adapter lost canonical delegation: {delegated}")

bootstrap = (CORE / "Bootstrap.php").read_text(encoding="utf-8")
for signature in ("function realtime(): RealtimeService", "function deferred(): DeferredService"):
    if signature not in bootstrap:
        raise SystemExit(f"Public Bootstrap missing transport owner: {signature}")

php = shutil.which("php")
if php is None:
    raise SystemExit("PHP CLI is required for the M3 transport contract gate")
for path in required:
    if path.suffix == ".php":
        subprocess.run([php, "-l", str(path)], cwd=ROOT, check=True)

print("Public M3 realtime/deferred transport contract: OK")
