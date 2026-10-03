#!/usr/bin/env python3
"""Regression gate for Runtime/Print Agent historical audit and V3 ownership corrections."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / "contracts/runtime-api/historical-audit-v1.json"
PRINT = ROOT / "contracts/print-agent-api/historical-audit-v1.json"
SOURCE_COMMIT = "a46435cca57df5bd5b9770efd0bb95390528aa05"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def check(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"RUNTIME/PRINT CONTRACT AUDIT FAILED: {message}")


def main() -> None:
    runtime = load(RUNTIME)
    printing = load(PRINT)

    check(runtime["source"]["commit"] == SOURCE_COMMIT, "Runtime audit source drifted")
    check(printing["source"]["commit"] == SOURCE_COMMIT, "Print audit source drifted")

    finding = runtime["finding"]
    check(finding["stable_http_api_present"] is False, "historical Runtime must not be misrepresented as a stable HTTP API")
    check(set(finding["proven_external_surfaces"]) == {"cli", "state_file", "windows_scm_service_host"}, "Runtime proven surfaces drifted")
    check(runtime["state_file"]["format"] == "sokna-local-runtime-v1", "Runtime state format drifted")
    check(runtime["windows_service_host"]["service_name"] == "SoknaRuntime", "Runtime service identity drifted")
    check(runtime["windows_service_host"]["monitor_interval_seconds"] == 5, "Runtime monitor cadence drifted")
    check(set(runtime["cli"]["options"]) == {"--once", "--self-check", "--health-json", "--max-seconds=N"}, "Runtime CLI evidence drifted")

    correction = runtime["v3_ownership_correction"]
    check(correction["runtime_owner"] == "windows/runtime", "Runtime V3 owner drifted")
    forbidden = " ".join(correction["forbidden"]).lower()
    check("business" in forbidden and "print agent" in forbidden, "Runtime ownership guardrails incomplete")

    api = printing["server_api_v4"]
    check(api["protocol_version"] == 4, "Print API protocol drifted")
    check(api["lease_seconds"] == 45, "Print API lease duration drifted")
    check(api["max_attempts"] == 5, "Print API max attempts drifted")
    check(set(api["actions"]) == {"probe", "heartbeat", "claim_reconcile", "claim", "attempt_status", "renew", "accept", "start", "report"}, "Print API action registry drifted")
    check("submission_fence" in api["probe_capabilities"], "Print submission fence capability missing")
    check(set(api["terminal_attempt_states"]) == {"submitted", "failed", "unknown", "recovery_hold", "cancelled", "expired"}, "Print terminal attempt states drifted")
    check("request_body_conflict" in " ".join(api["important_semantics"]), "Print request body conflict protection missing")

    bridge = printing["loopback_bridge_v1"]
    check(bridge["bind"] == "127.0.0.1 only", "Print bridge must remain loopback-only evidence")
    check(set(bridge["methods"]) == {"POST", "OPTIONS"}, "Print bridge methods drifted")
    check(bridge["default_max_body_bytes"] == 8192 and bridge["preview_max_body_bytes"] == 262144, "Print bridge body limits drifted")
    check(set(bridge["routes"]) == {"/v1/wake", "/v1/preview"}, "Print bridge route evidence drifted")
    wake = bridge["routes"]["/v1/wake"]
    check(wake["protocol_version"] == 1 and wake["type"] == "print.wake", "wake protocol drifted")
    check(wake["job_ids_count"] == [1, 50], "wake job bound drifted")
    preview = bridge["routes"]["/v1/preview"]
    check(preview["protocol_version"] == 1 and preview["type"] == "print.preview", "preview protocol drifted")
    check(preview["default_dpi"] == 203, "preview default DPI drifted")

    print_correction = printing["v3_ownership_correction"]
    check(print_correction["print_owner"] == "windows/print-agent", "Print Agent V3 owner drifted")
    check("supervise" in print_correction["runtime_relationship"].lower(), "Runtime relationship must remain supervision-only")
    check(any("internal Local component" in item for item in print_correction["do_not_preserve_as_authority"]), "dev39 packaging ownership correction missing")

    print("Runtime and Print Agent historical audit contract passed.")


if __name__ == "__main__":
    main()
