#!/usr/bin/env python3
"""QA-only Public root-clean packaging rehearsal from an exact pinned source.

This does NOT make an installable release. It mirrors R233's existing flat-root
builder: preserve every functional file exactly and stamp only VERSION.txt and
src/Update/emergency-owner-v1.json in an ephemeral candidate.
The candidate ZIP is not uploaded, deployed, or promoted by our workflow.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import pathlib
import sys
import zipfile

from audit import audit as audit_package
from source_gate import inspect as inspect_source

CHECKPOINT = "R233-20261115"
COMPONENT = "public"
ALLOWED_META = {"VERSION.txt", "src/Update/emergency-owner-v1.json"}
REQUIRED = {".htaccess", "bootstrap.php", "index.php", "setup/index.php", "setup/api.php",
            "src/Emergency/PublicUpdateService.php", "src/Update/EmergencyOwnerCandidate.php"}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def canonical_json(obj: dict) -> bytes:
    return (json.dumps(obj, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()


def build_qa_clean(source_zip: pathlib.Path, output: pathlib.Path, version: str) -> dict:
    approved_qa_versions = (
        version.startswith("QA-R233-DO-NOT-INSTALL-") or
        version == "3.0.0-g3.6-workspace.35zy-r233-qa-do-not-install"
    )
    if not approved_qa_versions or len(version) > 90:
        raise RuntimeError("INVALID_NONINSTALLABLE_QA_VERSION")
    with zipfile.ZipFile(source_zip) as z:
        paths = z.namelist()
        if len(paths) != len(set(paths)) or any(n.startswith("/") or ".." in n.split("/") for n in paths):
            raise RuntimeError("INVALID_SOURCE_MEMBER_NAMES")
        if z.testzip() is not None:
            raise RuntimeError("INVALID_SOURCE_ARCHIVE_CRC")
        files = {name: z.read(name) for name in paths}
    if not REQUIRED.issubset(files):
        raise RuntimeError("PUBLIC_ROOT_SOURCE_INCOMPLETE")
    if "config.php" in files or "install.lock" in files:
        raise RuntimeError("UNSAFE_MUTABLE_STATE_IN_SOURCE")
    target = dict(files)
    target["VERSION.txt"] = (version + "\n").encode("utf-8")
    owner = "src/Update/emergency-owner-v1.json"
    code = "src/Update/EmergencyOwnerCandidate.php"
    previous = json.loads(target[owner])
    if previous.get("format") != "sokna-emergency-owner-v1" or not previous.get("sha256"):
        raise RuntimeError("UNKNOWN_PUBLIC_OWNER_FORMAT")
    previous["release_version"] = version
    previous["sha256"] = sha(target[code])
    target[owner] = canonical_json(previous)
    changed = {name for name in files if target[name] != files[name]}
    if not changed.issubset(ALLOWED_META):
        raise RuntimeError("FUNCTIONAL_APPLICATION_BYTE_CHANGE_FORBIDDEN")
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name in sorted(target):
            info = zipfile.ZipInfo(name, (2026, 11, 16, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, target[name], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return {"candidate_file_count": len(target), "metadata_only_paths": sorted(changed),
            "candidate_sha256": sha(output.read_bytes()), "candidate_version": version,
            "production_installable": False}


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", type=pathlib.Path, required=True)
    p.add_argument("--output", type=pathlib.Path, required=True)
    p.add_argument("--version", required=True)
    p.add_argument("--report", type=pathlib.Path, required=True)
    p.add_argument("--register", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("source-register.json"))
    p.add_argument("--version-registry", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("version-registry.json"))
    args = p.parse_args()
    prelim = inspect_source(args.source, CHECKPOINT, COMPONENT, args.register)
    if prelim["source_integrity"] != "PASS":
        raise RuntimeError("SOURCE_PIN_FAILED:" + str(prelim["errors"]))
    build = build_qa_clean(args.source, args.output, args.version)
    src = inspect_source(args.source, CHECKPOINT, COMPONENT, args.register,
                         candidate=args.output, route="public-root-clean")
    final = audit_package(args.output, expected_kind="public-clean",
                          expected_sha=build["candidate_sha256"], registry=args.version_registry)
    if src["source_integrity"] != "PASS" or src["errors"] or src["release_blockers"]:
        raise RuntimeError("SOURCE_TO_PACKAGE_BYTES_NOT_PRESERVED:" + str(src))
    if final["static"] != "PASS":
        raise RuntimeError("QA_CLEAN_PACKAGE_STATIC_AUDIT_FAILED:" + str(final["errors"]))
    report = {
        "format": "sokna-public-root-clean-qa-rehearsal-v1",
        "source_zip_sha256": prelim["sha256"],
        "candidate": build,
        "source_preservation": {
            "status": "PASS",
            "functional_byte_changes": src["candidate_comparison"]["functional_drift_count"],
            "original_source_unchanged": True,
        },
        "static_audit": final["static"],
        "unproven": final["not_proven"],
        "release_authorized": False,
        "installed_or_emergency_qualifed": False,
        "warnings": ["TEST_ONLY_NONINSTALLABLE_QA_VERSION", "NO_PUBLIC_HOST_INSTALL_OR_DEPLOY",
                     "NO_LOCAL_OR_WINDOWS_PACKAGE_GENERATED"],
    }
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
