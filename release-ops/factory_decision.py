#!/usr/bin/env python3
"""SOKNA one-run release factory decision. QA evidence, NEVER install authority.

Considers exact source admission, real per-component package rehearsal, native
Windows SHA check and disposable MariaDB tests together. This script CANNOT
issue an installable package or change release_authorized to true.

It is intentionally pinned to the currently verified R233 historical QA
builders; a newly approved source must be separately built/qualified on
its real lineage rather than falsely inheriting R233 CI results.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import pathlib
import sys

PROVEN_QA_CHECKPOINT = "R233-20261115"
SUPPORTED_KINDS = {"local": "local-update", "public": "public-update"}
REQUIRED_HOST_PROOFS = {
    "final_frozen_payload": "Freeze exact future operator ZIP SHA; re-check installation bytes with the original SHA, not a rebuilt copy",
    "source_approved": "Verify current Library LATEST-WORKING, REG-003 accepted fixes and 27 HOLD decisions for this release",
    "installed_old_origins": "Prove actual installed Local Full/Delta and Public origin identities from read-only host evidence",
    "local_windows_maintenance": "Native Maintenance Preflight/Stage/Activate/Readback on final Local ZIP and qualifying real Windows host versions",
    "public_https_host": "Real Public host: same final SHA Routine and Emergency, root/subpath Clean, QR compatibility and protected config",
    "data_upgrade_recovery": "Full before/after financial/order/stock data, MariaDB forward-only fault tests, encrypted backup and independent restore",
    "windows_keep_and_failure": "Runtime/Print/Maintenance KEEP and crash/reboot/resume/repair checks with no unrelated service mutation",
    "physical_print": "Printer paper vs DB/order/jobs/Agent ACK: no missing or duplicate item; 58/80mm, latency, routing",
    "business_acceptance": "Applicable P0 financial/refund, inventory, communications, security and UI behavior with signed host-role evidence",
}
EXPECTED_SHA = {
    "local": "783bdde1ecd7bad4e8043d8591b52a2e628b99bc6cd32b2247c4786c5eba7a2d",
    "public": "8d89b156b3efcd380763d907796a9c6914f9ec2c27f40c1a61d04ffb6dc4ca7b",
}


def get(path: pathlib.Path) -> dict:
    raw = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise ValueError("EVIDENCE_MUST_BE_JSON_OBJECT:" + str(path.name))
    return raw


def verify(root: pathlib.Path, active: pathlib.Path, matrix: pathlib.Path, checkpoint: str) -> dict:
    result = {
        "schema": "sokna-unified-release-factory-qa-decision-v1",
        "checkpoint": checkpoint,
        "classification": "BLOCKED_MISSING_OR_FAILED_QA",
        "package_builder": "historical_r233_qa_only",
        "source_intakes": {},
        "package_sha256": {},
        "risk_total": None,
        "applicable_p0_unapproved": [],
        "host_evidence": {k: {"status": "NOT_RUN_OR_NOT_APPROVED", "required": v}
                          for k, v in REQUIRED_HOST_PROOFS.items()},
        "errors": [],
        "warnings": [],
        "operator_package_created": False,
        "production_authorized": False,
        "github_release_published": False,
        "operational_host_mutation": False,
    }
    problems = result["errors"]
    try:
        source = get(active)
        risk = get(matrix)
        rows = risk.get("rows", [])
        if (risk.get("schema") != "sokna-independent-release-coverage-v1"
            or not isinstance(rows, list) or len(rows) < 72
            or len({x.get("id") for x in rows}) != len(rows)):
            raise ValueError("RISK_COVERAGE_72_REGISTER_MISSING_OR_INVALID")
        result["risk_total"] = len(rows)
        result["applicable_p0_unapproved"] = [
            x.get("id") for x in rows if x.get("priority") == "P0"
            and x.get("installed_accepted") is not True
        ]
        if (source.get("format") != "sokna-release-active-source-v1"
            or checkpoint != source.get("active_checkpoint")):
            raise ValueError("CHECKPOINT_NOT_EXACT_ACTIVE_LIBRARY_POINTER")
        if checkpoint != PROVEN_QA_CHECKPOINT:
            raise ValueError("HISTORICAL_R233_QA_BUILDER_CANNOT_QUALIFY_NEW_CHECKPOINT")
        for component in ("local", "public"):
            accepted_sha = source.get("components", {}).get(component, {}).get("sha256")
            if accepted_sha != EXPECTED_SHA[component]:
                raise ValueError("HISTORICAL_QA_CHECKPOINT_SHA_MISMATCH_" + component)
            report = get(root / ("SOKNA-CURRENT-SOURCE-INTAKE-QA-REPORT-ONLY-" + component)
                         / "source-intake.json")
            result["source_intakes"][component] = {
                "sha256": report.get("source_sha256"),
                "status": report.get("source_status"),
                "db_static": report.get("database_status"),
            }
            if (report.get("checkpoint") != checkpoint
                or report.get("component") != component
                or report.get("source_sha256") != accepted_sha
                or report.get("source_status") != "PASS"
                or report.get("database_status") != "PASS"
                or report.get("production_authorized") is not False
                or report.get("package_constructed") is not False):
                problems.append("SOURCE_INTAKE_NOT_EXACT_OR_UNSAFE_" + component.upper())
        local = get(root / "SOKNA-R233-LOCAL-MAINTENANCE-QA-REPORT-ONLY" / "local-static.json")
        public = get(root / "SOKNA-R233-PUBLIC-TWO-ROUTE-QA-REPORT-ONLY" / "public-static.json")
        db = get(root / "SOKNA-R233-DB-RELEASE-QA-REPORT-ONLY" / "release-boundaries.json")
        result["package_sha256"] = {
            "local_maintenance_qa_only": local.get("candidate_zip_sha256"),
            "public_update_qa_only": public.get("public_update_sha256"),
            "public_root_clean_qa_only": public.get("public_clean_sha256"),
        }
        for name, value in result["package_sha256"].items():
            if not isinstance(value, str) or len(value) != 64 or not all(c in "0123456789abcdef" for c in value):
                problems.append("QA_CANDIDATE_SHA_MISSING_" + name.upper())
        if not (local.get("source_zip_sha256") == EXPECTED_SHA["local"]
                and local.get("local_static") == "PASS"
                and local.get("functional_byte_changes") == 0
                and local.get("production_installable") is False
                and local.get("native_windows_qualification") == "NOT_RUN"):
            problems.append("LOCAL_QA_FORMAT_OR_SAFETY_FAIL")
        if not (public.get("source_sha256") == EXPECTED_SHA["public"]
                and public.get("update_static") == "PASS"
                and public.get("clean_static") == "PASS"
                and public.get("functional_byte_drift") == 0
                and public.get("same_zip_native_php_route_baseline_combinations") == 4
                and public.get("both_routes_native_php") == "PASS_DISPOSABLE_R153_35HK_AND_R171_35HT"
                and public.get("release_authorized") is False
                and public.get("production_installable") is False):
            problems.append("PUBLIC_QA_SAME_ZIP_OR_SAFETY_FAIL")
        if not (db.get("source") == checkpoint
                and db.get("db_service") == "MariaDB 11.4 disposable only"
                and db.get("live_database_modified") is False
                and db.get("release_authorized") is False
                and db.get("public_owner_migration_reapply") == "PASS_DISPOSABLE_R233_CORE_MIGRATOR_NOOP"
                and db.get("public_routine_emergency_with_real_db") == "PASS_DISPOSABLE_TWO_BASELINES_FOUR_ROUTES"):
            problems.append("ISOLATED_MARIADB_QA_INCOMPLETE_OR_UNSAFE")
        if not problems:
            result["classification"] = "QA_PASS_EXTERNAL_HOST_ACCEPTANCE_BLOCKED"
            result["warnings"].append(
                "CI confirms only scoped historical R233 QA. The QA ZIP hashes are NOT installable operator payloads."
            )
            result["warnings"].append(
                "No signed real-host acceptance is present for any in-scope P0. Never treat job success as deployment approval."
            )
    except (ValueError, KeyError, OSError, TypeError, json.JSONDecodeError) as exc:
        problems.append("FACTORY_PROOF_INCOMPLETE:" + type(exc).__name__ + ":" + str(exc)[:200])
    return result


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--reports", type=pathlib.Path, required=True)
    p.add_argument("--active", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("active-source.json"))
    p.add_argument("--coverage", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("INDEPENDENT_COVERAGE_REGISTER_20261116.json"))
    p.add_argument("--checkpoint", default=PROVEN_QA_CHECKPOINT)
    p.add_argument("--out", type=pathlib.Path, required=True)
    args = p.parse_args()
    result = verify(args.reports, args.active, args.coverage, args.checkpoint)
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    return 0 if result["classification"] == "QA_PASS_EXTERNAL_HOST_ACCEPTANCE_BLOCKED" else 2


if __name__ == "__main__":
    sys.exit(main())
