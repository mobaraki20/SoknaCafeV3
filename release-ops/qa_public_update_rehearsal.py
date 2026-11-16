#!/usr/bin/env python3
"""One byte-identical Public ZIP for Routine and independent Emergency QA only.

Derived from immutable R233 full source, using documented R249 two verified
historical baseline IDs and the R233 managed-owner exclusion contract.
No product source code, installed server, engine, database or Windows is changed.
The generated ZIP is ephemeral and DO-NOT-INSTALL; CI uploads JSON proof only.
"""
from __future__ import annotations

import argparse
import io
import json
import pathlib
import zipfile

from audit import audit, sha, tree
from source_gate import inspect as inspect_source
from qa_public_root_clean_rehearsal import build_qa_clean

VERSION = "3.0.0-g3.6-workspace.35zy-r233-qa-do-not-install"
BASELINES = (
    {"version": "3.0.0-g3.6-workspace.35hk-r153-identity-reconciled-qa",
     "managed_tree_sha256": "0fd94f725670f521c60557137aab38e518e72c1c4cd9a0b0b6861e65f9fa7537"},
    {"version": "3.0.0-g3.6-workspace.35ht-r171-cumulative-staff-expenses-uat",
     "managed_tree_sha256": "be2ebe23091472480beadd6a06cad621b43da365d3677f54fadaab6ba56242b6"},
)
SKIP = {"config.php", "install.lock", "README.md", "manifest.json",
        "emergency.php", "resources/update-trust-v1.json"}
PREFIXES = ("src/Emergency/", "storage/", "tools/", "Q3_P1_")
METADATA = {"VERSION.txt", "src/Update/emergency-owner-v1.json"}


def j(x):
    return (json.dumps(x, sort_keys=True, ensure_ascii=False, separators=(",", ":")) + "\n").encode()


def zbytes(d):
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for n, b in sorted(d.items()):
            info = zipfile.ZipInfo(n, (2026, 11, 16, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, b, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return buffer.getvalue()


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", type=pathlib.Path, required=True)
    p.add_argument("--update", type=pathlib.Path, required=True)
    p.add_argument("--clean", type=pathlib.Path, required=True)
    p.add_argument("--report", type=pathlib.Path, required=True)
    a = p.parse_args()
    register = pathlib.Path(__file__).with_name("source-register.json")
    pinned = inspect_source(a.source, "R233-20261115", "public", register)
    if pinned["source_integrity"] != "PASS":
        raise RuntimeError("R233_SOURCE_NOT_PINNED:" + str(pinned["errors"]))
    with zipfile.ZipFile(a.source) as source_zip:
        if source_zip.testzip() is not None:
            raise RuntimeError("CORRUPT_SOURCE")
        full = {n: source_zip.read(n) for n in source_zip.namelist()}
    managed = {n: b for n, b in full.items()
               if n not in SKIP and not n.startswith(PREFIXES)}
    if len(full) != 233 or len(managed) != 228:
        raise RuntimeError("R233_MANAGED_FILE_COUNT_MISMATCH")
    original_managed = dict(managed)
    managed["VERSION.txt"] = (VERSION + "\n").encode()
    owner = "src/Update/emergency-owner-v1.json"
    code = "src/Update/EmergencyOwnerCandidate.php"
    if owner not in managed or code not in managed:
        raise RuntimeError("PUBLIC_OWNER_REQUIRED_FILES_MISSING")
    metadata = json.loads(managed[owner])
    if metadata.get("format") != "sokna-emergency-owner-v1":
        raise RuntimeError("PUBLIC_OWNER_FORMAT_UNKNOWN")
    metadata["release_version"] = VERSION
    metadata["sha256"] = sha(managed[code])
    managed[owner] = j(metadata)
    changed = {n for n in managed if original_managed[n] != managed[n]}
    if not changed.issubset(METADATA):
        raise RuntimeError("UNAPPROVED_PRODUCT_CODE_CHANGE")
    manifest = {
        "format": "sokna-component-package-v1",
        "schema_version": 1,
        "component": "public-edge",
        "version": VERSION,
        "compatibility": {"supported_baselines": list(BASELINES)},
        "contracts": {"local_public_contract": 1},
        "target_managed_tree_sha256": tree({n: (sha(b), len(b)) for n, b in managed.items()}),
        "provenance": {
            "source_archive_sha256": pinned["sha256"],
            "build": "R233 SHA-pinned Public Routine+Emergency SAME-ZIP QA rehearsal",
            "strict_multibaseline_v1": True,
            "stable_emergency_owner": "not_in_payload",
            "production_host_qualified": False,
            "release_qualification": "QA_DO_NOT_INSTALL",
            "host_operator_note": "Do NOT install. Only disposable PHP harness can test this.",
        },
        "files": [{"path": n, "sha256": sha(b), "size": len(b)}
                  for n, b in sorted(managed.items())],
    }
    a.update.parent.mkdir(parents=True, exist_ok=True)
    a.update.write_bytes(zbytes({"manifest.json": j(manifest),
                                 **{"payload/" + n: b for n, b in managed.items()}}))
    clean_proof = build_qa_clean(a.source, a.clean, VERSION)
    source_proof = inspect_source(a.source, "R233-20261115", "public", register,
                                  candidate=a.update, route="public-update")
    update_audit = audit(a.update, expected_kind="public-update",
                         expected_sha=sha(a.update.read_bytes()),
                         paired=a.clean,
                         registry=pathlib.Path(__file__).with_name("version-registry.json"))
    clean_audit = audit(a.clean, expected_kind="public-clean",
                        expected_sha=sha(a.clean.read_bytes()))
    if (source_proof["source_integrity"] != "PASS" or source_proof["errors"] or
            source_proof["release_blockers"] or
            update_audit["static"] != "PASS" or clean_audit["static"] != "PASS"):
        raise RuntimeError("PUBLIC_QA_PAIR_INVALID:" + json.dumps(
            {"source": source_proof, "update": update_audit, "clean": clean_audit}, ensure_ascii=False))
    report = {
        "format": "sokna-r233-public-one-zip-two-route-qa-v1",
        "source_sha256": pinned["sha256"],
        "qa_version": VERSION,
        "public_update_sha256": sha(a.update.read_bytes()),
        "public_clean_sha256": sha(a.clean.read_bytes()),
        "update_managed_files": len(managed),
        "clean_full_files": len(full),
        "supported_historical_baselines_declared": len(BASELINES),
        "native_install_baselines_proven": 0,
        "changed_source_paths": sorted(changed),
        "functional_byte_drift": source_proof["candidate_comparison"]["functional_drift_count"],
        "update_clean_common_bytes": "PASS",
        "update_static": update_audit["static"],
        "clean_static": clean_audit["static"],
        "one_sha_for_routine_and_emergency": sha(a.update.read_bytes()),
        "both_routes_native_php": "NOT_RUN",
        "release_authorized": False,
        "production_installable": False,
        "source_only_qa": True,
        "clean_evidence": clean_proof,
    }
    a.report.parent.mkdir(parents=True, exist_ok=True)
    a.report.write_text(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n")
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
