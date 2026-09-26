#!/usr/bin/env python3
"""Regression gate for the proven dev39 Local/Public relay wire semantics carried into V3."""

from __future__ import annotations

import hashlib
import hmac
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REALTIME_PATH = ROOT / "contracts/local-public-realtime/wire-v1.json"
DEFERRED_PATH = ROOT / "contracts/local-public-deferred/wire-v1.json"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def check(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"RELAY WIRE CONTRACT FAILED: {message}")


def main() -> None:
    realtime = load(REALTIME_PATH)
    deferred = load(DEFERRED_PATH)

    check(realtime["source_protocol"] == "sokna-relay-v1", "realtime source protocol changed")
    check(deferred["source_protocol"] == "sokna-relay-v1/deferred", "deferred source protocol changed")

    auth = realtime["authentication"]
    check(auth["algorithm"] == "HMAC-SHA256", "unexpected HMAC algorithm")
    check(auth["headers"] == {
        "timestamp": "X-SOKNA-TIMESTAMP",
        "nonce": "X-SOKNA-NONCE",
        "signature": "X-SOKNA-SIGNATURE",
    }, "relay auth header names drifted")
    check(auth["default_clock_skew_seconds"] == 300, "clock skew default drifted")

    body = '{"limit":10}'
    base = "\n".join([
        "sokna-relay-v1",
        "POST",
        "/api/v1/local/claim.php",
        "1760000000",
        "nonce-123",
        hashlib.sha256(body.encode("utf-8")).hexdigest(),
    ])
    expected_base = (
        "sokna-relay-v1\nPOST\n/api/v1/local/claim.php\n1760000000\nnonce-123\n"
        "ca502dec04523cdc33afece69a9b600d5b9bd022d453791cc693b6b372f808ad"
    )
    check(base == expected_base, "signature base test vector drifted")
    signature = hmac.new(b"test-secret", base.encode("utf-8"), hashlib.sha256).hexdigest()
    check(signature == "6233349d136481c04836c5a860576fdfcec768696245006c62882265d6093bf5", "HMAC test vector failed")

    request_rule = realtime["request_envelope"]["request_id"]
    pattern = re.compile(request_rule["pattern"])
    check(request_rule["max_length"] == 96, "request_id max length drifted")
    for value in ["abc", "req_123", "a.b:c-9"]:
        check(pattern.fullmatch(value) is not None, f"valid request_id rejected by extracted rule: {value}")
    for value in ["", "space id", "slash/id", "x" * 97]:
        syntactic_ok = len(value) <= request_rule["max_length"] and pattern.fullmatch(value) is not None
        check(not syntactic_ok, f"invalid request_id accepted by extracted rule: {value!r}")

    realtime_states = set(realtime["states"]["workflow"])
    realtime_terminal = set(realtime["states"]["terminal"])
    check(realtime_terminal == {"committed", "rejected", "expired", "cancelled", "unknown_review"}, "realtime terminal states drifted")
    check(realtime_terminal <= realtime_states, "realtime terminal states not contained in workflow")

    deferred_states = set(deferred["states"]["workflow"])
    deferred_terminal = set(deferred["states"]["terminal"])
    check(deferred_states == {"pending_sync", "committed", "needs_review", "rejected"}, "deferred state model drifted")
    check(deferred_terminal == {"committed", "needs_review", "rejected"}, "deferred terminal states drifted")
    check("claimed" not in deferred_states and "queued" not in deferred_states, "deferred accidentally adopted realtime queue states")
    check(deferred["request_envelope"]["expires_at"] == "forbidden_by_semantics", "deferred must not gain realtime expiry semantics")

    expected_realtime_kinds = {
        "guest_order.submit", "guest_order.list", "guest_order.status", "guest_table.context",
        "waiter_call.create", "waiter_call.status", "waiter_call.cancel",
        "order.edit", "order.cancel", "settlement.commit", "preparation.mutate",
        "table_draft.get", "table_draft.create", "table_draft.edit", "table_draft.finalize", "table_draft.cancel",
    }
    check(set(realtime["kinds"]) == expected_realtime_kinds, "realtime kind registry drifted")

    expected_deferred_kinds = {
        "supply.need.create", "supply.status.prepare", "supply.status.return", "supply.receipt",
        "inventory.waste", "inventory.count_draft", "subscriber.payment", "expense.create",
    }
    check(set(deferred["kinds"]) == expected_deferred_kinds, "deferred kind registry drifted")
    check(set(realtime["kinds"]).isdisjoint(deferred["kinds"]), "realtime/deferred kind registries overlap")

    check(realtime["historical_routes"]["local_claim"] == "/api/v1/local/claim.php", "realtime claim route drifted")
    check(realtime["historical_routes"]["local_ack"] == "/api/v1/local/ack.php", "realtime ACK route drifted")
    check(deferred["historical_routes"]["local_claim"] == "/api/v1/local/deferred/claim.php", "deferred claim route drifted")
    check(deferred["historical_routes"]["local_ack"] == "/api/v1/local/deferred/ack.php", "deferred ACK route drifted")
    check(deferred["historical_routes"]["local_reconcile"] == "/api/v1/local/deferred/reconcile.php", "deferred reconcile route drifted")

    print("Relay/deferred wire-v1 extraction contract passed.")


if __name__ == "__main__":
    main()
