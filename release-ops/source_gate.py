#!/usr/bin/env python3
"""Pin and examine a full SOURCE snapshot before packaging (no edits or deployment).

This is deliberately separate from audit.py: accepted full SOURCE ZIPs may carry
old VERSION.txt (release stamping happens later), and are NEVER installer ZIPs.
"""
from __future__ import annotations

import argparse
import io
import json
import pathlib
import sys
import zipfile

from audit import hashes, issue, sha

HEX = set("0123456789abcdef")


def load_register(path: pathlib.Path, checkpoint: str, component: str) -> dict:
    reg = json.loads(path.read_text(encoding="utf-8"))
    if reg.get("format") != "sokna-release-source-register-v1":
        raise ValueError("SOURCE_REGISTER_FORMAT_INVALID")
    item = reg.get("checkpoints", {}).get(checkpoint)
    if not isinstance(item, dict) or item.get("status") != "SOURCE_ONLY_NOT_INSTALLABLE":
        raise ValueError("SOURCE_CHECKPOINT_NOT_VERIFIED_SOURCE_ONLY")
    source = item.get("sources", {}).get(component)
    if not isinstance(source, dict):
        raise ValueError("SOURCE_COMPONENT_NOT_REGISTERED")
    for field in ("zip_sha256", "source_tree_sha256"):
        v = source.get(field, "")
        if len(str(v)) != 64 or any(c not in HEX for c in str(v)):
            raise ValueError("SOURCE_REGISTER_HASH_INVALID")
    if not isinstance(source.get("file_count"), int) or source["file_count"] <= 0:
        raise ValueError("SOURCE_REGISTER_COUNT_INVALID")
    return source


def source_tree(file_hashes: dict[str, tuple[str, int]]) -> str:
    # This is the already-used R233 source_tree() contract, distinct from the
    # installer's managed-tree SHA (which uses pathname + NUL).
    return sha("".join(path + ":" + file_hash + "\n"
                       for path, (file_hash, _size) in sorted(file_hashes.items())).encode())


def inspect(path: pathlib.Path, checkpoint: str, component: str, register: pathlib.Path,
            candidate: pathlib.Path | None = None, route: str | None = None) -> dict:
    result = {
        "format": "sokna-source-admission-result-v1",
        "checkpoint": checkpoint, "component": component,
        "file": path.name, "sha256": "", "source_integrity": "FAIL",
        "source_only": True, "release_authorized": False,
        "errors": [], "release_blockers": [], "not_proven": [],
    }
    try:
        pin = load_register(register, checkpoint, component)
        data = path.read_bytes()
        result["sha256"] = sha(data)
        if result["sha256"] != pin["zip_sha256"]:
            issue(result, "SOURCE_ZIP_SHA256_NOT_PINNED")
        with zipfile.ZipFile(io.BytesIO(data)) as z:
            files = hashes(z, result)
            result["file_count"] = len(files)
            result["source_tree_sha256"] = source_tree(files)
            if len(files) != pin["file_count"]:
                issue(result, "SOURCE_FILE_COUNT_DRIFT")
            if result["source_tree_sha256"] != pin["source_tree_sha256"]:
                issue(result, "SOURCE_TREE_SHA256_DRIFT")
            old_version = z.read("VERSION.txt").decode("utf-8").strip() if "VERSION.txt" in files else ""
            result["original_version"] = old_version
            if old_version != pin.get("original_version"):
                issue(result, "SOURCE_ORIGINAL_VERSION_CHANGED")
            migration_count = len([n for n in files if n.startswith("database/migrations/") and n.endswith(".sql")])
            result["migration_count"] = migration_count
            if migration_count != pin.get("migration_count"):
                issue(result, "SOURCE_MIGRATION_COUNT_DRIFT")
            if any(n in files for n in ("config.php", "install.lock")):
                issue(result, "SOURCE_CONTAINS_MUTABLE_INSTALL_STATE")
            if component == "local":
                name = "resources/local-managed-files-v1.json"
                if name not in files:
                    result["release_blockers"].append("LOCAL_MANAGED_FILE_INVENTORY_MISSING")
                else:
                    inventory = json.loads(z.read(name)).get("files", [])
                    missing = sorted(set(files).difference(inventory))
                    extras = sorted(set(inventory).difference(files))
                    result["inventory_missing"] = missing
                    result["inventory_extras"] = extras
                    if (len(inventory) != len(set(inventory)) or missing or extras):
                        result["release_blockers"].append("LOCAL_MAINTENANCE_TARGET_INVENTORY_INCOMPLETE")
            elif component == "public":
                name = "src/Update/emergency-owner-v1.json"
                if name in files:
                    meta = json.loads(z.read(name))
                    if meta.get("release_version") != old_version:
                        result["release_blockers"].append("PUBLIC_RELEASE_OWNER_VERSION_STAMP_REQUIRED")
                else:
                    result["release_blockers"].append("PUBLIC_RELEASE_OWNER_METADATA_ABSENT")
            else:
                issue(result, "SOURCE_COMPONENT_UNKNOWN")
            if candidate is not None:
                if not route:
                    issue(result, "CANDIDATE_ROUTE_REQUIRED")
                else:
                    result["candidate_comparison"] = compare_candidate(z, files, candidate, component, route, result)
        result["source_integrity"] = "PASS" if not result["errors"] else "FAIL"
    except (OSError, ValueError, KeyError, TypeError, AttributeError, RuntimeError, zipfile.BadZipFile, json.JSONDecodeError) as exc:
        issue(result, "SOURCE_INSPECTION_ERROR_" + type(exc).__name__)
    result["errors"].sort()
    result["release_blockers"].sort()
    result["not_proven"] = [
        "SOURCE_SNAPSHOT_IS_NOT_INSTALLABLE",
        "RELEASE_VERSION_AND_PACKAGE_QA_REQUIRED",
        "INSTALLED_HOST_OR_WINDOWS_QUALIFICATION_NOT_RUN",
    ]
    result["release_authorized"] = False
    return result


def compare_candidate(z, source_files: dict, path: pathlib.Path, component: str,
                      route: str, result: dict) -> dict:
    valid = {
        "local": ("local-legacy-update", "local-maintenance-update"),
        "public": ("public-update", "public-root-clean"),
    }
    if route not in valid[component]:
        issue(result, "CANDIDATE_ROUTE_WRONG_COMPONENT")
        return {}
    with zipfile.ZipFile(path) as candidate:
        outer = hashes(candidate, result)
        if route == "local-maintenance-update":
            if set(outer) != {"release-set.json", "artifacts/local/web.zip"}:
                issue(result, "CANDIDATE_MAINTENANCE_RELEASE_SET_REQUIRED")
                return {}
            with zipfile.ZipFile(io.BytesIO(candidate.read("artifacts/local/web.zip"))) as inner:
                actual = hashes(inner, result)
                version = inner.read("VERSION.txt").decode().strip()
                actual_data = {n: inner.read(n) for n in actual}
        elif route in ("local-legacy-update", "public-update"):
            if "manifest.json" not in outer:
                issue(result, "CANDIDATE_COMPONENT_MANIFEST_MISSING")
                return {}
            m = json.loads(candidate.read("manifest.json"))
            if m.get("component") != ("local" if component == "local" else "public-edge"):
                issue(result, "CANDIDATE_COMPONENT_IDENTITY_MISMATCH")
            actual = {n[8:]: v for n, v in outer.items() if n.startswith("payload/")}
            version = m.get("version")
            actual_data = {n: candidate.read("payload/" + n) for n in actual}
        else:
            # Canonical R233 Public root clean is intentionally flat and has no
            # generated manifest; not the same format as public deploy package v1.
            actual = outer
            version = candidate.read("VERSION.txt").decode().strip()
            actual_data = {n: candidate.read(n) for n in actual}
        if "VERSION.txt" not in actual or not version:
            issue(result, "CANDIDATE_TARGET_VERSION_MISSING")
        if actual_data.get("VERSION.txt", b"").decode("utf-8").strip() != version:
            issue(result, "CANDIDATE_TARGET_VERSION_MISMATCH")
        old_version = z.read("VERSION.txt").decode("utf-8").strip()
        if version == old_version:
            result["release_blockers"].append("CANDIDATE_OLD_SOURCE_VERSION_NOT_STAMPED")
        expected = set(source_files)
        if route == "public-update":
            excluded = {"config.php", "install.lock", "README.md", "manifest.json",
                        "emergency.php", "resources/update-trust-v1.json"}
            expected = {n for n in expected if n not in excluded and not n.startswith(
                ("src/Emergency/", "storage/", "tools/", "Q3_P1_"))}
        if route == "local-maintenance-update":
            # Different managed-file owners are possible. An explicit inventory
            # is authoritative; never expect all source-only tools in a slot.
            inv = "resources/local-managed-files-v1.json"
            if inv not in actual_data:
                issue(result, "MAINTENANCE_CANDIDATE_INVENTORY_MISSING")
            else:
                rows = json.loads(actual_data[inv]).get("files", [])
                if set(rows) != set(actual) or len(rows) != len(set(rows)):
                    issue(result, "MAINTENANCE_CANDIDATE_INVENTORY_MISMATCH")
            if not set(actual).issubset(expected):
                issue(result, "MAINTENANCE_CANDIDATE_UNKNOWN_FILES")
            if not any(k in result["errors"] for k in (
                "MAINTENANCE_CANDIDATE_INVENTORY_MISSING",
                "MAINTENANCE_CANDIDATE_INVENTORY_MISMATCH",
                "MAINTENANCE_CANDIDATE_UNKNOWN_FILES")):
                result["release_blockers"] = [x for x in result["release_blockers"]
                    if x != "LOCAL_MAINTENANCE_TARGET_INVENTORY_INCOMPLETE"]
        elif set(actual) != expected:
            issue(result, "CANDIDATE_SOURCE_FILE_SET_MISMATCH")
        # Every shared executable/asset/SQL must preserve exact source bytes.
        # Only target release metadata is allowed to change; no guessed merge.
        exceptions = {"VERSION.txt"}
        if component == "public":
            exceptions.add("src/Update/emergency-owner-v1.json")
            owner = "src/Update/emergency-owner-v1.json"
            code = "src/Update/EmergencyOwnerCandidate.php"
            if owner not in actual_data or code not in actual_data:
                issue(result, "CANDIDATE_PUBLIC_OWNER_METADATA_OR_CODE_MISSING")
            else:
                meta = json.loads(actual_data[owner])
                if (not isinstance(meta, dict) or meta.get("release_version") != version
                    or meta.get("sha256") != sha(actual_data[code])):
                    issue(result, "CANDIDATE_PUBLIC_OWNER_RELEASE_IDENTITY_STALE")
                else:
                    result["release_blockers"] = [x for x in result["release_blockers"]
                        if x != "PUBLIC_RELEASE_OWNER_VERSION_STAMP_REQUIRED"]
        if route == "local-maintenance-update":
            exceptions.add("resources/local-managed-files-v1.json")
        changed = [n for n in set(actual) & set(source_files)
                   if n not in exceptions and actual[n][0] != source_files[n][0]]
        if changed:
            issue(result, "SOURCE_TO_CANDIDATE_FUNCTIONAL_BYTE_DRIFT")
        return {
            "route": route,
            "target_version": version,
            "source_files": len(source_files),
            "candidate_files": len(actual),
            "functional_drift_count": len(changed),
            "functional_drift_sample": sorted(changed)[:15],
        }


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", type=pathlib.Path, required=True)
    p.add_argument("--checkpoint", required=True)
    p.add_argument("--component", choices=("local", "public"), required=True)
    p.add_argument("--register", type=pathlib.Path, default=pathlib.Path(__file__).with_name("source-register.json"))
    p.add_argument("--candidate", type=pathlib.Path)
    p.add_argument("--route", choices=("local-legacy-update", "local-maintenance-update",
                                        "public-update", "public-root-clean"))
    p.add_argument("--report", type=pathlib.Path)
    p.add_argument("--require-output-ready", action="store_true",
                   help="Require source + candidate format/metadata continuity; does NOT qualify real install.")
    a = p.parse_args()
    result = inspect(a.source, a.checkpoint, a.component, a.register, a.candidate, a.route)
    text = json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2)
    print(text)
    if a.report:
        a.report.parent.mkdir(parents=True, exist_ok=True)
        a.report.write_text(text + "\n", encoding="utf-8")
    if result["source_integrity"] != "PASS":
        return 2
    if a.require_output_ready and (a.candidate is None or result["release_blockers"]):
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
