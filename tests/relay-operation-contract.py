#!/usr/bin/env python3
"""Executable gate for extracted dev39 relay operation schemas and compatibility vectors."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RT_SCHEMA = ROOT / "contracts/local-public-realtime/operation-schemas-v1.json"
RT_VECTORS = ROOT / "contracts/local-public-realtime/compatibility-vectors-v1.json"
DF_SCHEMA = ROOT / "contracts/local-public-deferred/operation-schemas-v1.json"
DF_VECTORS = ROOT / "contracts/local-public-deferred/compatibility-vectors-v1.json"
SOURCE_COMMIT = "a46435cca57df5bd5b9770efd0bb95390528aa05"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def check(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"RELAY OPERATION CONTRACT FAILED: {message}")


def by_id(vectors: dict) -> dict[str, dict]:
    items = vectors["vectors"]
    ids = [item["id"] for item in items]
    check(len(ids) == len(set(ids)), "compatibility vector IDs must be unique")
    return {item["id"]: item for item in items}


def main() -> None:
    rt = load(RT_SCHEMA)
    rv = load(RT_VECTORS)
    df = load(DF_SCHEMA)
    dv = load(DF_VECTORS)

    check(rt["source"]["commit"] == SOURCE_COMMIT, "realtime schema source commit drifted")
    check(df["source"]["commit"] == SOURCE_COMMIT, "deferred schema source commit drifted")
    check(rv["source_commit"] == SOURCE_COMMIT, "realtime vector source commit drifted")
    check(dv["source_commit"] == SOURCE_COMMIT, "deferred vector source commit drifted")

    check(set(rt["operations"]) == {"local_claim", "local_ack", "remote_result"}, "realtime operation registry drifted")
    check(set(df["operations"]) == {"local_claim", "local_ack", "local_reconcile", "local_period_status", "remote_result"}, "deferred operation registry drifted")

    rt_claim = rt["operations"]["local_claim"]
    check(rt_claim["historical_path"] == "/api/v1/local/claim.php", "realtime claim path drifted")
    rt_lease = rt_claim["request"]["properties"]["lease_seconds"]
    check((rt_lease["default"], rt_lease["minimum_after_normalization"], rt_lease["maximum_after_normalization"]) == (20, 5, 60), "realtime lease normalization drifted")
    rt_lease_rule = rt["operations"]["local_ack"]["lease_rule"]
    check("SHA256" in rt_lease_rule and "lease_token" in rt_lease_rule, "realtime ACK lease hashing rule missing")
    check(rt["operations"]["local_ack"]["request"]["properties"]["state"]["enum"] == ["committed", "rejected", "expired", "cancelled", "unknown_review"], "realtime ACK terminal state schema drifted")
    check(rt["operations"]["remote_result"]["success"]["required_fields"] == ["ok", "state", "terminal", "result", "error_code", "updated_at"], "realtime result shape drifted")

    df_claim = df["operations"]["local_claim"]
    df_lease = df_claim["request"]["properties"]["lease_seconds"]
    check((df_lease["default"], df_lease["minimum_after_normalization"], df_lease["maximum_after_normalization"]) == (30, 10, 120), "deferred lease normalization drifted")
    check("attempt_count increments" in df_claim["state_effect"], "deferred claim attempt_count rule missing")
    check(df["operations"]["local_ack"]["request"]["properties"]["state"]["enum"] == ["committed", "needs_review", "rejected"], "deferred ACK state schema drifted")
    check("terminal_state_conflict" in df["operations"]["local_ack"]["errors"]["409"], "deferred terminal conflict schema missing")
    check(df["operations"]["local_reconcile"]["request"]["properties"]["state"]["enum"] == ["committed", "rejected"], "deferred reconcile target schema drifted")
    check("needs_review -> committed|rejected" in df["operations"]["local_reconcile"]["success"]["transition_rule"], "deferred reconcile transition drifted")
    check(df["operations"]["local_period_status"]["success"]["blocking_formula"] == "counts.pending_sync + counts.needs_review", "deferred period blocking formula drifted")
    check(df["operations"]["remote_result"]["success"]["required_fields"] == ["ok", "request_id", "kind", "actor_projection_id", "state", "occurred_at", "result", "error_code", "created_at", "updated_at"], "deferred result shape drifted")

    r = by_id(rv)
    d = by_id(dv)
    check(r["rt-claim-default-lease"]["expected"]["normalized_lease_seconds"] == 20, "realtime default lease vector drifted")
    check(r["rt-claim-low-clamp"]["expected"]["normalized_lease_seconds"] == 5, "realtime low clamp vector drifted")
    check(r["rt-claim-high-clamp"]["expected"]["normalized_lease_seconds"] == 60, "realtime high clamp vector drifted")
    rt_terminal_dedupe = r["rt-ack-terminal-dedupe-preserves-stored-state"]["expected"]
    check(rt_terminal_dedupe == {"http": 200, "ok": True, "state": "rejected", "deduplicated": True, "stored_result_rewritten": False}, "realtime terminal dedupe compatibility changed")
    check(r["rt-result-terminal-shape"]["expected"]["terminal"] is True, "realtime result terminal vector drifted")

    check(d["df-claim-default-lease"]["expected"]["normalized_lease_seconds"] == 30, "deferred default lease vector drifted")
    check(d["df-claim-low-clamp"]["expected"]["normalized_lease_seconds"] == 10, "deferred low clamp vector drifted")
    check(d["df-claim-high-clamp"]["expected"]["normalized_lease_seconds"] == 120, "deferred high clamp vector drifted")
    check(d["df-ack-terminal-state-conflict"]["expected"] == {"http": 409, "error": "terminal_state_conflict", "state": "rejected"}, "deferred terminal conflict compatibility changed")
    check(d["df-reconcile-needs-review-to-commit"]["expected"]["deduplicated"] is False, "deferred review resolution vector drifted")
    counts = d["df-period-blocking-formula"]["precondition"]["counts"]
    check(counts["pending_sync"] + counts["needs_review"] == d["df-period-blocking-formula"]["expected"]["blocking"] == 5, "deferred period blocking vector failed")

    check(rt["operations"]["local_ack"]["success"]["dedupe_rule"] != df["operations"]["local_ack"]["success"]["dedupe_rule"], "realtime/deferred ACK dedupe semantics were accidentally merged")
    print("Relay operation schemas and compatibility vectors passed.")


if __name__ == "__main__":
    main()
