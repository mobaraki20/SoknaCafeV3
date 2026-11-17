#!/usr/bin/env python3
"""Verify an independently built Public candidate and explicitly state its factory lineage.

Read-only: no installer build, host mutation, publishing or promotion.
The original GitHub Actions artifact must be downloaded separately using
the exact pinned producer run/name; this tool inspects its actual bytes.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import re
import sys
import zipfile

from audit import tree


def digest(blob: bytes) -> str:
    return hashlib.sha256(blob).hexdigest()


def safe_entries(z: zipfile.ZipFile) -> list[str]:
    entries = [x.filename for x in z.infolist() if not x.is_dir()]
    if len(entries) != len(set(entries)) or len(entries) > 12000:
        raise ValueError("UNSAFE_OR_DUPLICATE_ZIP_ENTRIES")
    for name in entries:
        if (not name or name.startswith("/") or "\\" in name or
            any(bit in ("", ".", "..") for bit in name.split("/"))):
            raise ValueError("UNSAFE_ZIP_PATH")
    if z.testzip() is not None:
        raise ValueError("ZIP_CRC_FAILED")
    return entries


def trace(contract: pathlib.Path, artifact_dir: pathlib.Path) -> dict:
    out = {
        "schema": "sokna-candidate-factory-attribution-v1",
        "factory_used": False,
        "factory_run_id": None,
        "origin": "EXTERNAL_GITHUB_QA_BUILDER",
        "report_status": "FAIL",
        "producer_run_id": None,
        "producer_workflow": None,
        "steps": [],
        "errors": [],
        "release_authorized": False,
        "field_acceptance": "NOT_RUN",
        "no_live_host_read_or_write": True,
        "no_zip_modified": True,
    }
    try:
        spec = json.loads(contract.read_text(encoding="utf-8"))
        if spec.get("schema") != "sokna-candidate-provenance-v1" or \
           spec.get("origin_type") != "EXTERNAL_GITHUB_QA_BUILDER_NOT_UNIFIED_FACTORY":
            raise ValueError("INVALID_EXTERNAL_PRODUCER_CONTRACT")
        builder = spec["producer"]
        assert builder["workflow"] != spec["factory"]["workflow"]
        if (spec["factory"]["invoked_by_producer"] is not False
            or spec["factory"]["run_id"] is not None
            or spec["factory"]["exact_zip_qualified_by_factory"] is not False):
            raise ValueError("FALSE_UNIFIED_FACTORY_USAGE")
        if (spec["production_authorized"] is not False
            or spec["field_acceptance"].get("actual_host_activation") != "NOT_RUN"):
            raise ValueError("OPERATOR_AUTHORIZATION_NOT_SUPPORTED")
        out["producer_run_id"] = builder["run_id"]
        out["producer_workflow"] = builder["workflow"]
        out["candidate_family"] = spec["family"]
        out["required_field_proofs"] = spec["field_acceptance"]
        out["producer_artifact"] = builder["artifact"]
        old_sql = None
        prior_target = None
        for position, step in enumerate(spec["required_install_order"], 1):
            p = artifact_dir / step["name"]
            raw = p.read_bytes()
            if digest(raw) != step["sha256"]:
                raise ValueError("PINNED_ZIP_SHA_MISMATCH_STEP_" + str(position))
            with zipfile.ZipFile(p) as z:
                entries = safe_entries(z)
                if "manifest.json" not in entries:
                    raise ValueError("PUBLIC_COMPONENT_MANIFEST_MISSING")
                m = json.loads(z.read("manifest.json"))
                payload = {x[8:]: z.read(x) for x in entries if x.startswith("payload/")}
                if (set(entries) != {"manifest.json"} |
                        {"payload/" + x for x in payload}):
                    raise ValueError("UNEXPECTED_PUBLIC_ARCHIVE_PATHS")
                if (m.get("component") != "public-edge"
                    or m.get("format") != "sokna-component-package-v1"
                    or m.get("version") != step["version"]
                    or m.get("target_managed_tree_sha256") != step["target_tree_sha256"]):
                    raise ValueError("PUBLIC_PACKAGE_OWNER_IDENTITY_MISMATCH")
                declared = m.get("files")
                if not isinstance(declared, list) or len(declared) != len(payload):
                    raise ValueError("MANIFEST_FILE_COUNT_MISMATCH")
                indexed = {}
                for row in declared:
                    name = row["path"]
                    if name in indexed:
                        raise ValueError("DUPLICATE_MANIFEST_ENTRY")
                    indexed[name] = (row["sha256"], row["size"])
                observed = {x: (digest(b), len(b)) for x, b in payload.items()}
                if indexed != observed or tree(observed) != m["target_managed_tree_sha256"]:
                    raise ValueError("PAYLOAD_CONTENT_SHA_OR_TREE_MISMATCH")
                if len(payload) != step["managed_files"]:
                    raise ValueError("WRONG_MANAGED_FILE_COUNT")
                for name in payload:
                    if (name in {"config.php", "install.lock", "emergency.php", "recovery.php",
                                "resources/update-trust-v1.json"}
                        or name.startswith(("storage/", "src/Emergency/", "src/Recovery/"))):
                        raise ValueError("PROTECTED_HOST_FILE_IN_UPDATE_PAYLOAD")
                comp = (m.get("compatibility") or {}).get("supported_baselines") or []
                if len(comp) != 1 or comp[0].get("version") != step["baseline_version"] \
                   or comp[0].get("managed_tree_sha256") != step["baseline_tree_sha256"]:
                    raise ValueError("WRONG_EXACT_INSTALLED_BASELINE")
                if position > 1 and comp[0]["managed_tree_sha256"] != prior_target:
                    raise ValueError("INSTALL_CHAIN_IS_NOT_CONTINUOUS")
                prior_target = m["target_managed_tree_sha256"]
                sql = {x: b for x, b in payload.items()
                       if x.startswith("database/migrations/") and x.endswith(".sql")}
                if len(sql) != step["migrations"]:
                    raise ValueError("SCHEMA_MIGRATION_COUNT_MISMATCH")
                if old_sql is not None and (
                        not set(old_sql).issubset(sql) or
                        any(sql[x] != old_sql[x] for x in old_sql)):
                    raise ValueError("PREVIOUSLY_APPLIED_SQL_CHANGED_OR_DELETED")
                old_sql = sql
                provenance = m.get("provenance") or {}
                if (provenance.get("offline_qualified") is not False
                    or "QA ONLY" not in str(provenance.get("host_operator_note", ""))):
                    raise ValueError("PROVENANCE_NOT_UNAMBIGUOUS_QA_ONLY")
                out["steps"].append({
                    "position": position,
                    "file": p.name,
                    "sha256": digest(raw),
                    "version": m["version"],
                    "migrations": len(sql),
                    "managed_files": len(payload),
                    "route": "Exact one Public ZIP valid for Routine/Emergency per version",
                })
        if len(out["steps"]) != 2:
            raise ValueError("EXPECTED_TWO_STEP_LEGACY_COMPATIBILITY_EXCEPTION")
        if spec["required_install_order"][0]["migrations"] != 8 or \
           spec["required_install_order"][1]["migrations"] != 11:
            raise ValueError("LEGACY_SCHEMA_BRIDGE_SEQUENCE_INVALID")
        offline = artifact_dir / "R265-R266-OFFLINE-MANIFEST.json"
        if digest(offline.read_bytes()) != "1be6864f3159178f8307e6fb752bae1488d097f039fadce2fd8be35567d03918":
            raise ValueError("HISTORIC_OFFLINE_PROOF_MISMATCH")
        out["report_status"] = "EXTERNAL_QA_BYTES_VERIFIED_NOT_UNIFIED_FACTORY_HOST_BLOCKED"
        out["known_ci_run"] = "https://github.com/mobaraki20/SoknaCafeV3/actions/runs/" + str(builder["run_id"])
        out["important_scope"] = "Historical 35ic legacy Emergency only; future packages should use one ZIP per version through both owners."
    except (OSError, zipfile.BadZipFile, TypeError, ValueError, KeyError, AssertionError, json.JSONDecodeError) as e:
        out["errors"].append(type(e).__name__ + ":" + str(e)[:180])
    return out


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--contract", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("external-public-r268-provenance.json"))
    p.add_argument("--artifact-dir", required=True, type=pathlib.Path)
    p.add_argument("--report", required=True, type=pathlib.Path)
    args = p.parse_args()
    result = trace(args.contract, args.artifact_dir)
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True) + "\n")
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    return 0 if not result["errors"] and result["report_status"].startswith("EXTERNAL_QA_BYTES_VERIFIED") else 2


if __name__ == "__main__":
    sys.exit(main())
