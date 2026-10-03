#!/usr/bin/env python3
"""Executable M1 gate for the V3 Runtime v1 and retained Print Agent v4/loopback contracts."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "contracts/runtime-api/contract-v1.json"
RUNTIME_VECTORS = ROOT / "contracts/runtime-api/compatibility-vectors-v1.json"
PRINT_SERVER = ROOT / "contracts/print-agent-api/server-wire-v4.json"
PRINT_LOOPBACK = ROOT / "contracts/print-agent-api/loopback-v1.json"
PRINT_VECTORS = ROOT / "contracts/print-agent-api/compatibility-vectors-v4.json"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def check(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"RUNTIME/PRINT V1 CONTRACT FAILED: {message}")


def vector_map(doc: dict) -> dict[str, dict]:
    ids = [v["id"] for v in doc["vectors"]]
    check(len(ids) == len(set(ids)), "compatibility vector IDs must be unique")
    return {v["id"]: v for v in doc["vectors"]}


def main() -> None:
    runtime = load(RUNTIME)
    rv = load(RUNTIME_VECTORS)
    server = load(PRINT_SERVER)
    loopback = load(PRINT_LOOPBACK)
    pv = load(PRINT_VECTORS)

    # Runtime boundary/direction.
    check(runtime["contract_id"] == "sokna-runtime-local-v1", "Runtime contract identity drifted")
    check(runtime["transport"]["network_scope"] == "loopback-only", "Runtime must remain loopback-only")
    check(runtime["transport"]["internet_exposure"] is False, "Runtime must not become Internet-exposed")
    interfaces = runtime["interfaces"]
    check(set(interfaces) == {"local_to_runtime_health", "runtime_to_local_trigger"}, "Runtime v1 surface expanded without contract review")
    health = interfaces["local_to_runtime_health"]
    check(health["mutation"] is False and health["method"] == "GET", "Runtime health must remain observational")
    trigger = interfaces["runtime_to_local_trigger"]
    check(trigger["owner_of_endpoint"] == "apps/local-web", "Runtime trigger endpoint must be Local-owned")
    forbidden = set(trigger["request"]["explicitly_forbidden_fields"])
    check({"command", "executable", "args", "powershell", "shell", "sql", "business_payload", "payload"}.issubset(forbidden), "Runtime trigger arbitrary-command/business escape hatch detected")
    check(trigger["request"]["additional_properties"] is False, "Runtime trigger must reject uncontracted fields")
    check(any("printing is not" in s.lower() for s in trigger["semantics"]), "Print ownership separation missing from Runtime contract")

    rvec = vector_map(rv)
    check(rvec["runtime-trigger-retry-deduplicates"]["expected_response"]["deduplicated"] is True, "Runtime trigger retry idempotency drifted")
    check(rvec["runtime-trigger-arbitrary-command-rejected"]["expected"]["valid"] is False, "Runtime arbitrary command vector must reject")
    check(rvec["runtime-trigger-business-payload-rejected"]["expected"]["valid"] is False, "Runtime business payload vector must reject")
    check(rvec["runtime-trigger-printing-owner-rejected"]["expected"]["error"] == "unsupported_trigger", "Runtime must not own historical printing trigger")
    check(rvec["runtime-public-direct-control-rejected"]["expected"]["allowed"] is False, "Public must not directly control Runtime")

    # Print server protocol preservation.
    check(server["contract_id"] == "sokna-print-server-v4" and server["protocol_version"] == 4, "Print server protocol identity drifted")
    check(server["direction"] == "windows/print-agent -> apps/local-web print server API", "Print server direction drifted")
    check(server["constants"]["lease_seconds"] == 45, "Print lease duration drifted")
    check(server["constants"]["max_attempts"] == 5, "Print max attempts drifted")
    actions = server["actions"]
    expected_actions = {"probe", "heartbeat", "claim", "claim_reconcile", "attempt_status", "renew", "accept", "start", "report"}
    check(set(actions) == expected_actions, "Print action registry drifted")
    check(actions["report"]["request"]["status_enum"] == ["submitted", "failed", "unknown", "recovery_hold"], "Print report safety-state registry drifted")
    check(set(server["attempt_terminal_states"]) == {"submitted", "failed", "unknown", "recovery_hold", "cancelled", "expired"}, "Print terminal attempt states drifted")
    check("request_body_conflict" in server["request_idempotency"]["conflict_codes"], "Print request body fingerprint guard missing")
    check(any("accept precedes physical execution" in x for x in server["safety_invariants"]), "Print submission fence order missing")
    check(any("unknown/recovery_hold" in x for x in server["safety_invariants"]), "Print ambiguity safety invariant missing")

    # Loopback bridge is explicitly not durable submission.
    check(loopback["contract_id"] == "sokna-print-agent-loopback-v1" and loopback["protocol_version"] == 1, "Print loopback protocol identity drifted")
    check(loopback["network"]["bind"] == "127.0.0.1 only", "Print loopback must bind loopback only")
    check(loopback["network"]["max_connections"] == 24, "Print loopback connection bound drifted")
    check(loopback["network"]["default_max_body_bytes"] == 8192, "Print loopback default body bound drifted")
    check(loopback["network"]["preview_max_body_bytes"] == 262144, "Print preview body bound drifted")
    check(set(loopback["routes"]) == {"/v1/wake", "/v1/preview"}, "Print loopback route surface drifted")
    wake = loopback["routes"]["/v1/wake"]
    check(wake["request"]["properties"]["job_ids"]["max_items"] == 50, "Print wake job bound drifted")
    check(any("nudge only" in x for x in wake["semantics"]), "Print wake must remain non-authoritative")
    preview = loopback["routes"]["/v1/preview"]
    check(preview["request"]["properties"]["dpi"]["default"] == 203, "Print preview DPI default drifted")
    check(any("does not create" in x for x in preview["semantics"]), "Print preview must not create durable attempt")

    pvec = vector_map(pv)
    check(pvec["print-accept-binds-fence"]["expected"]["physical_execution_before_accept_allowed"] is False, "Print accept fence vector weakened")
    check(pvec["print-accept-request-body-conflict"]["expected"]["code"] == "request_body_conflict", "Print body conflict vector drifted")
    check(pvec["print-start-terminal-state-does-not-restart"]["expected"]["automatic_restart_allowed"] is False, "Print terminal restart safety weakened")
    check(pvec["print-report-ambiguous-unknown-is-durable"]["expected"]["automatic_reprint_allowed"] is False, "Unknown print outcome must not auto-reprint")
    check(pvec["print-report-recovery-hold-is-durable"]["expected"]["requires_explicit_resolution"] is True, "Recovery hold must remain explicit")
    check(pvec["print-loopback-preview-not-submission"]["expected"]["creates_durable_attempt"] is False, "Preview must remain separate from durable submission")

    print("Runtime v1 and Print Agent v4/loopback contract gates passed.")


if __name__ == "__main__":
    main()
