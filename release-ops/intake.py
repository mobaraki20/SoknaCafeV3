#!/usr/bin/env python3
"""One simple, fail-closed entrypoint for the CURRENT explicitly registered source.

Human/agent says "build an update" -> read active-source.json; verify full ZIP
bytes and applicable route. No guessed main/latest, no staging, no deploy.
Future approved source snapshots use the SAME procedure after the authoritative
Workspace pointer and source register are updated with real immutable hashes.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import re
import sys

from audit import short_version_code
from db_gate import analyze as audit_db
from source_gate import inspect as audit_source, load_register


KINDS = {
    "local": {"local-update", "local-legacy-update", "local-clean"},
    "public": {"public-update", "public-clean"},
}


def inspect_active(
    source: pathlib.Path,
    component: str,
    kind: str,
    active_file: pathlib.Path,
    source_register: pathlib.Path,
    version_registry: pathlib.Path,
    target_version: str = "",
    desired_checkpoint: str = "",
) -> dict:
    report = {
        "format": "sokna-release-source-intake-v1",
        "component": component, "kind": kind,
        "checkpoint": None,
        "source_sha256": None,
        "source_status": "FAIL",
        "database_status": "NOT_RUN",
        "target_version": target_version or None,
        "production_authorized": False,
        "package_constructed": False,
        "source_verified": False,
        "errors": [], "not_proven": [],
    }
    try:
        active = json.loads(active_file.read_text(encoding="utf-8"))
        if active.get("format") != "sokna-release-active-source-v1":
            raise ValueError("ACTIVE_SOURCE_REGISTER_INVALID")
        checkpoint = active.get("active_checkpoint")
        report["checkpoint"] = checkpoint
        if desired_checkpoint and checkpoint != desired_checkpoint:
            raise ValueError("ACTIVE_POINTER_CHANGED_FROM_REQUEST")
        if component not in KINDS or kind not in KINDS[component]:
            raise ValueError("UNSUPPORTED_COMPONENT_PACKAGE_OWNER_COMBINATION")
        pin = load_register(source_register, checkpoint, component)
        entry = (active.get("components") or {}).get(component) or {}
        if (entry.get("sha256") != pin["zip_sha256"]
            or entry.get("files") != pin["file_count"]
            or entry.get("migrations") != pin["migration_count"]
            or entry.get("file") != pin["file"]):
            # source-register uses file optional: name must be checked only when present
            if (entry.get("sha256") != pin["zip_sha256"]
                or entry.get("files") != pin["file_count"]
                or entry.get("migrations") != pin["migration_count"]):
                raise ValueError("ACTIVE_POINTER_CONFLICTS_WITH_PINNED_SOURCE_REGISTER")
        if source.name != entry.get("file"):
            raise ValueError("SOURCE_FILENAME_NOT_ACTIVE_CHECKPOINT")
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        report["source_sha256"] = digest
        if digest != entry["sha256"]:
            raise ValueError("ACTIVE_SOURCE_SHA256_MISMATCH")
        actual = audit_source(source, checkpoint, component, source_register)
        if actual["source_integrity"] != "PASS":
            raise ValueError("PINNED_SOURCE_TREE_OR_FILE_SET_MISMATCH")
        report["source_verified"] = True
        report["source_status"] = "PASS"
        report["source_release_blockers"] = actual["release_blockers"]
        db = audit_db(source, component, checkpoint, source_register)
        report["migration_count"] = db["migration_count"]
        report["migration_manifest_sha256"] = db["migration_manifest_sha256"]
        report["database_status"] = db["static"]
        if db["static"] != "PASS":
            report["errors"].extend(db["errors"])
        report["not_proven"].extend(db["not_proven"])
        if target_version:
            if (not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9._-]{3,120}", target_version)
                or target_version == pin["original_version"]):
                raise ValueError("TARGET_VERSION_INVALID_OR_SAME_AS_SOURCE")
            known = json.loads(version_registry.read_text(encoding="utf-8"))
            code = short_version_code(target_version)
            if not code:
                raise ValueError("TARGET_COMPONENT_SHORT_CODE_REQUIRED")
            if code in known.get("quarantined_short_versions", []):
                raise ValueError("TARGET_VERSION_CODE_QUARANTINED")
            for row in known.get("observed", []) + known.get("released", []):
                if row.get("component") != component:
                    continue
                if row.get("version") == target_version or row.get("short_code") == code:
                    raise ValueError("TARGET_VERSION_ALREADY_RESERVED")
        else:
            report["not_proven"].append("TARGET_VERSION_HAS_NOT_BEEN_SELECTED")
        report["not_proven"] += [
            "LATEST_WORKSPACE_POINTER_MUST_BE_RECHECKED_BEFORE_EACH_RELEASE",
            "EXACT_PREVIOUS_INSTALLED_BASELINE_AND_DB_UPGRADE_NOT_YET_CHECKED_IN_INTAKE",
            "SOURCE_ONLY_ARCHIVE_NOT_AN_INSTALLER",
            "SOURCE_TO_FINAL_PACKAGE_BYTE_QA_NOT_RUN_BY_INTAKE",
            "ACCEPTED_FIX_PRESERVATION_MATRIX_NOT_COMPLETE",
            "NATIVE_WINDOWS_OR_PUBLIC_HOST_INSTALL_QUALIFICATION_NOT_RUN",
        ]
        if kind == "local-update":
            report["builder_route"] = "Existing Maintenance Release Set v2.1 owner; DO NOT use legacy Local component ZIP"
        elif kind == "local-legacy-update":
            report["builder_route"] = "Existing LocalUpdateService legacy route; verify installed guard allows it"
        elif kind == "public-update":
            report["builder_route"] = "Existing Public routine component ZIP; same exact SHA MUST work through Emergency"
        elif kind == "public-clean":
            report["builder_route"] = "Existing host-root Public Clean builder; do not assume deploy-v1 manifest layout"
        else:
            report["builder_route"] = "Existing Local Clean installer owner; requires direct install acceptance"
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError) as e:
        report["errors"].append(str(e)[:160])
    report["errors"] = sorted(set(report["errors"]))
    report["not_proven"] = sorted(set(report["not_proven"]))
    report["source_status"] = "PASS" if report["source_verified"] and not report["errors"] else "FAIL"
    report["production_authorized"] = False
    report["package_constructed"] = False
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=pathlib.Path)
    parser.add_argument("--component", required=True, choices=sorted(KINDS))
    parser.add_argument("--kind", required=True, choices=sorted(set.union(*map(set,KINDS.values()))))
    parser.add_argument("--checkpoint", default="")
    parser.add_argument("--target-version", default="")
    parser.add_argument("--active", type=pathlib.Path,
                        default=pathlib.Path(__file__).with_name("active-source.json"))
    parser.add_argument("--source-register", type=pathlib.Path,
                        default=pathlib.Path(__file__).with_name("source-register.json"))
    parser.add_argument("--version-registry", type=pathlib.Path,
                        default=pathlib.Path(__file__).with_name("version-registry.json"))
    parser.add_argument("--report", type=pathlib.Path)
    args = parser.parse_args()
    result = inspect_active(args.source,args.component,args.kind,args.active,
                            args.source_register,args.version_registry,
                            args.target_version,args.checkpoint)
    text = json.dumps(result,ensure_ascii=False,indent=2,sort_keys=True)
    print(text)
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(text+"\n")
    return 0 if result["source_status"] == "PASS" and result["database_status"] == "PASS" else 2


if __name__ == "__main__":
    sys.exit(main())
