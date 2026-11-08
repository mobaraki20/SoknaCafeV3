#!/usr/bin/env python3
"""Read-only release gate: final Local ZIP must retain signed host blocker reasons.

This does not attest host readiness, DB backup, or installation. It prevents
a source-only diagnostics fix from being silently lost during packaging.
"""
import argparse
import io
import json
import sys
import zipfile
from pathlib import Path

CLIENT = "src/Domain/System/MaintenanceSupervisorClient.php"
SUPPORT = "src/Domain/System/MaintenanceSupportEvidence.php"
SAFE_HOST_CODES = (
    "UPDATER_MUTATION_OR_RECOVERY_PENDING",
    "MUTATION_LEDGER_EVIDENCE_UNAVAILABLE",
    "SOKNA_APACHE_DISCOVERY_OR_HEALTH_UNPROVEN",
    "ACTIVE_R8_JUNCTION_UNQUALIFIED",
    "INSTALLED_LOCAL_IDENTITY_OR_TREE_MISMATCH",
    "MACHINE_BINDING_OR_INSTALL_LOCK_MISMATCH",
    "PERSISTENT_MACHINE_LOCAL_OVERLAY_DRIFT",
    "LOCAL_LIFECYCLE_NOT_IDLE_OR_UNPROVEN",
)

def contents(args):
    if args.source_root:
        root = Path(args.source_root)
        return (root / CLIENT).read_text("utf-8"), (root / SUPPORT).read_text("utf-8")
    with zipfile.ZipFile(args.release_zip) as z:
        if CLIENT in z.namelist() and SUPPORT in z.namelist():
            return z.read(CLIENT).decode(), z.read(SUPPORT).decode()
        if "artifacts/local/web.zip" not in z.namelist():
            raise ValueError("Missing immutable artifacts/local/web.zip")
        with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as web:
            return web.read(CLIENT).decode(), web.read(SUPPORT).decode()

def checks(client, support):
    out = {
        "client carries safe reasons": "'host_blockers'=>$blockers" in client,
        "missing reasons remain unknown": "'host_blockers_available'=>is_array($data['host_blockers']??null)" in client,
        "client allowlist enforced": "in_array($v,$allowedBlockers,true)" in client,
        "bundle contains reasons": "'host_blockers'=>array_slice" in support,
        "bundle contains availability bit": "'host_blockers_available'=>" in support,
        "unknown operation count not falsely zero": "'blocking_count'=>$ok?" in support and ":null" in support,
    }
    for code in SAFE_HOST_CODES:
        out["allowlist "+code] = "'"+code+"'" in client
    return out

def main():
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--source-root")
    group.add_argument("--release-zip")
    parser.add_argument("--expect-legacy-missing", action="store_true")
    args = parser.parse_args()
    client, support = contents(args)
    failures = sorted(name for name, ok in checks(client, support).items() if not ok)
    if args.expect_legacy_missing:
        passed = "client carries safe reasons" in failures and "bundle contains reasons" in failures
    else:
        passed = not failures
    print(json.dumps({
        "gate": "SOKNA-P0-HOST-DIAGNOSTICS-RELEASE-CHECK-V1",
        "status": "PASS" if passed else "FAIL",
        "missing": failures,
        "historical_negative_control": args.expect_legacy_missing,
        "scope": "source-or-package-static-only; no real-host certification"
    }, indent=2))
    return 0 if passed else 1

if __name__ == "__main__":
    sys.exit(main())
