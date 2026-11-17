#!/usr/bin/env python3
"""One read-only candidate contract for all builders; fail closed, never deploy.

The caller's GitHub run must download the precise Actions artifact selected by a
version-controlled producer contract. Passing is only EXACT-BYTES QA, never an
operational release authorization or proof of installed-host acceptance.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import re
import sys

from audit import audit
from trace_external_public_candidate import trace

HEX = re.compile(r"^[a-f0-9]{64}$")
NAME = re.compile(r"^[A-Za-z0-9_.-]{1,200}$")
RUN_ID = re.compile(r"^[1-9][0-9]{0,18}$")
KINDS = {
    "public": {"public-update", "public-clean"},
    "local": {"local-update", "local-legacy-update", "local-clean"},
}


def inspect(contract: pathlib.Path, artifacts: pathlib.Path, run_id: str,
            artifact_name: str, factory_run_id: str) -> dict:
    result = {
        "schema": "sokna-unified-factory-candidate-receipt-v1",
        "profile": None,
        "component": None,
        "producer_run_id": run_id,
        "producer_artifact": artifact_name,
        "factory_gate_run_id": factory_run_id,
        "factory_gate_used": bool(factory_run_id),
        "built_inside_factory": bool(run_id and run_id == factory_run_id),
        "factory_validated_exact_bytes": False,
        "candidate_zip_sha256": [],
        "database_migration_path": "NOT_PROVEN",
        "source_approved": False,
        "field_host_backup_restore": "NOT_RUN",
        "field_host_activation": "NOT_RUN",
        "operator_release_authorized": False,
        "production_authorized": False,
        "status": "BLOCKED_INCOMPLETE_OR_INVALID_PROOF",
        "errors": [],
        "not_proven": [],
    }
    errors = result["errors"]
    try:
        spec = json.loads(contract.read_text(encoding="utf-8"))
        if not isinstance(spec, dict):
            raise ValueError("CONTRACT_MUST_BE_OBJECT")
        if not RUN_ID.fullmatch(run_id) or not RUN_ID.fullmatch(factory_run_id):
            raise ValueError("REAL_GITHUB_RUN_IDS_REQUIRED")
        if not NAME.fullmatch(artifact_name):
            raise ValueError("ARTIFACT_NAME_INVALID")
        claimed_run_id = (spec.get("producer") or {}).get("run_id")
        if (claimed_run_id != int(run_id) and not (
                claimed_run_id == "same_run" and run_id == factory_run_id)):
            raise ValueError("PRODUCER_RUN_ID_NOT_PINNED_BY_CONTRACT")
        if spec["producer"].get("artifact") != artifact_name:
            raise ValueError("ARTIFACT_NAME_NOT_PINNED_BY_CONTRACT")
        if spec.get("production_authorized") is not False:
            raise ValueError("UNSUPPORTED_PRODUCTION_AUTHORIZATION")
        if not artifacts.is_dir():
            raise ValueError("ACTUAL_ACTIONS_ARTIFACT_DIRECTORY_REQUIRED")
        if spec.get("schema") == "sokna-candidate-provenance-v1":
            if spec.get("family") != "SOKNA-R268-PUBLIC-EMERGENCY-HOST-CANDIDATE":
                raise ValueError("UNREGISTERED_LEGACY_EMERGENCY_EXCEPTION")
            result["profile"] = "legacy-public-r265-r266"
            result["component"] = "public"
            checked = trace(contract, artifacts)
            if checked["errors"] or not checked["report_status"].startswith("EXTERNAL_QA_BYTES_VERIFIED"):
                raise ValueError("LEGACY_PACKAGE_BYTES_OR_SCHEMA_INVALID:" +
                                 ",".join(checked["errors"])[:180])
            if (len(checked["steps"]) != 2 or
                [x["migrations"] for x in checked["steps"]] != [8, 11]):
                raise ValueError("LEGACY_8_TO_11_MIGRATION_SEQUENCE_INVALID")
            result["candidate_zip_sha256"] = [x["sha256"] for x in checked["steps"]]
            result["database_migration_path"] = "ISOLATED_R268_EMERGENCY_8_TO_11_QA_ONLY"
            result["source_approved"] = False
            result["not_proven"].extend([
                "CURRENT_APPROVED_WORKSPACE_AND_REG003_SOURCE",
                "REAL_HOST_BACKUP_RESTORE",
                "ACTUAL_INSTALLED_LEGACY_EMERGENCY_CAPABILITY_READBACK",
                "POST_ACTIVATION_PUBLIC_HEALTH_AND_SYNC",
            ])
        elif spec.get("schema") == "sokna-candidate-contract-v1":
            result["profile"] = "generic-static-v1"
            component = spec.get("component")
            result["component"] = component
            if component not in KINDS:
                raise ValueError("COMPONENT_NOT_SUPPORTED")
            packs = spec.get("packages")
            if not isinstance(packs, list) or not (1 <= len(packs) <= 8):
                raise ValueError("ONE_TO_EIGHT_CANDIDATE_PACKAGES_REQUIRED")
            if (spec.get("source_authority") or {}).get("approved_checkpoint") in (None, ""):
                raise ValueError("SOURCE_APPROVAL_CHECKPOINT_REQUIRED")
            source_sha = (spec.get("source_authority") or {}).get("source_sha256", "")
            if not isinstance(source_sha, str) or not HEX.fullmatch(source_sha):
                raise ValueError("SOURCE_SHA256_REQUIRED")
            if any((x.get("component") != component or
                    x.get("kind") not in KINDS[component] or
                    not NAME.fullmatch(x.get("file", "")) or
                    not HEX.fullmatch(x.get("sha256", "")) or
                    not x.get("version")) for x in packs if isinstance(x, dict)) or \
               any(not isinstance(x, dict) for x in packs):
                raise ValueError("INVALID_PACKAGE_LINE")
            # For same-run producers, the generated SHA contract may travel
            # inside the exact Actions artifact AFTER the ZIP was built. It
            # is a producer assertion, not a signed source-approval proof.
            # The only extra file allowed is the exact one being validated.
            contract_inside = contract.resolve().is_relative_to(artifacts.resolve())
            expected_contract = contract.name if contract_inside else None
            if contract_inside and (contract.name != "candidate-contract.json"
                                    or run_id != factory_run_id
                                    or claimed_run_id != "same_run"):
                raise ValueError("UNTRUSTED_EMBEDDED_CONTRACT")
            files = sorted(p.name for p in artifacts.rglob("*") if p.is_file())
            if expected_contract:
                files.remove(expected_contract)
            if sorted(x["file"] for x in packs) != files:
                raise ValueError("ARTIFACT_CONTAINS_MISSING_OR_UNDECLARED_FILES")
            if len(set(files)) != len(files):
                raise ValueError("DUPLICATE_PACKAGE_FILENAMES")
            for row in packs:
                p = artifacts / row["file"]
                actual = hashlib.sha256(p.read_bytes()).hexdigest()
                if actual != row["sha256"]:
                    raise ValueError("EXACT_ZIP_SHA_MISMATCH:" + row["file"])
                inspection = audit(p, expected_kind=row["kind"],
                                   expected_sha=row["sha256"],
                                   registry=pathlib.Path(__file__).with_name("version-registry.json"))
                if inspection["static"] != "PASS" or inspection.get("version") != row["version"]:
                    raise ValueError("GENERIC_COMPONENT_STATIC_AUDIT_FAILED:" +
                                     ",".join(inspection["errors"])[:100])
                result["candidate_zip_sha256"].append(actual)
            result["not_proven"].extend([
                "SOURCE_ZIP_BYTES_AND_ACCEPTED_FIX_MATRIX_NEED_SEPARATE_INTAKE",
                "ACTUAL_BASELINE_COMPATIBILITY_AND_DB_FORWARD_NEED_OWNER_QA",
                "WINDOWS_OR_PUBLIC_HOST_INSTALL_AND_RESTORE_NEED_FIELD_EVIDENCE",
            ])
            result["database_migration_path"] = "NOT_PROVEN_BY_STATIC_AUDIT"
        else:
            raise ValueError("UNSUPPORTED_CONTRACT_SCHEMA")
        result["factory_validated_exact_bytes"] = True
        result["status"] = "QA_EXACT_BYTES_PASS_FIELD_AND_SOURCE_APPROVAL_BLOCKED"
    except (OSError, TypeError, KeyError, ValueError, json.JSONDecodeError) as exc:
        errors.append(type(exc).__name__ + ":" + str(exc)[:240])
    return result


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--contract", required=True, type=pathlib.Path)
    ap.add_argument("--artifact-dir", required=True, type=pathlib.Path)
    ap.add_argument("--producer-run-id", required=True)
    ap.add_argument("--artifact-name", required=True)
    ap.add_argument("--factory-run-id", required=True)
    ap.add_argument("--report", required=True, type=pathlib.Path)
    args = ap.parse_args()
    report = inspect(args.contract, args.artifact_dir, args.producer_run_id,
                     args.artifact_name, args.factory_run_id)
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, indent=2, ensure_ascii=False, sort_keys=True) + "\n")
    print(json.dumps(report, indent=2, ensure_ascii=False, sort_keys=True))
    return 0 if report["factory_validated_exact_bytes"] and not report["errors"] else 2


if __name__ == "__main__":
    sys.exit(main())
