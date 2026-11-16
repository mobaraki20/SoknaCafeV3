#!/usr/bin/env python3
"""R233 frozen Local -> Maintenance v2.1 PACKAGE SHAPE rehearsal. NOT INSTALLABLE.

Reuse the historical exact R201 60-baseline Release Set manifest and source
provenance. Generate only target release metadata, correcting 590/593 inventory.
Never assert those old baselines accept a new target without native Windows QA.
No code in Local application, Windows Maintenance or its updater is edited.
"""
from __future__ import annotations

import argparse
import copy
import hashlib
import io
import json
import pathlib
import zipfile

from audit import audit, sha, tree
from source_gate import inspect as source_inspect

PARENT_SHA = "911ea673dc1863bee3ce372dbc692602aa3a32439d2fc9ccba69dc2a6c243897"
QA_VERSION = "QA-R233-LOCAL-MAINTENANCE-DO-NOT-INSTALL-20261116"
QA_RELEASE_ID = "sourceonly-r233-qa-local-maintenance-not-installable"
SOURCE_PIN = "R233-20261115"
EXCLUDE_FROM_CANONICAL = {"resources/release-identity-v1.json", "resources/build-provenance.json"}


def json_bytes(x):
    return (json.dumps(x, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode()


def zip_bytes(files):
    data = io.BytesIO()
    with zipfile.ZipFile(data, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for n, blob in sorted(files.items()):
            info = zipfile.ZipInfo(n, (2026, 11, 16, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, blob, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return data.getvalue()


def read_zip(blob):
    with zipfile.ZipFile(io.BytesIO(blob)) as z:
        if z.testzip() is not None:
            raise RuntimeError("ZIP_CRC_BAD")
        names = z.namelist()
        if len(names) != len(set(names)):
            raise RuntimeError("ZIP_DUPLICATE_PATHS")
        return {n: z.read(n) for n in names if not n.endswith("/")}


def canonical_tree(files):
    return sha("".join(n + "\0" + str(len(v)) + "\0" + sha(v) + "\n"
                       for n, v in sorted(files.items()) if n not in EXCLUDE_FROM_CANONICAL).encode())


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", type=pathlib.Path, required=True)
    p.add_argument("--parent-r201", type=pathlib.Path, required=True)
    p.add_argument("--out", type=pathlib.Path, required=True)
    p.add_argument("--report", type=pathlib.Path, required=True)
    a = p.parse_args()
    source = source_inspect(a.source, SOURCE_PIN, "local",
                            pathlib.Path(__file__).with_name("source-register.json"))
    if source["source_integrity"] != "PASS":
        raise RuntimeError("SOURCE_R233_PIN_INVALID:" + str(source["errors"]))
    parent = a.parent_r201.read_bytes()
    if sha(parent) != PARENT_SHA:
        raise RuntimeError("R201_RELEASE_SET_TEMPLATE_SHA_MISMATCH")
    outer = read_zip(parent)
    if set(outer) != {"release-set.json", "artifacts/local/web.zip"}:
        raise RuntimeError("R201_RELEASE_SET_TEMPLATE_LAYOUT_MISMATCH")
    m = json.loads(outer["release-set.json"])
    if m.get("format") != "sokna-maintenance-release-set-v2.1" or len(m.get("supported_baselines", [])) != 60:
        raise RuntimeError("R201_RELEASE_SET_TEMPLATE_ORIGINS_MISMATCH")
    payload = read_zip(a.source.read_bytes())
    if len(payload) != 593:
        raise RuntimeError("R233_LOCAL_SOURCE_NOT_593_FILES")
    original = dict(payload)
    payload["VERSION.txt"] = (QA_VERSION + "\n").encode()
    inventory_name = "resources/local-managed-files-v1.json"
    if inventory_name not in payload:
        raise RuntimeError("R233_LOCAL_INVENTORY_FILE_MISSING")
    existing = json.loads(payload[inventory_name])
    if existing.get("format") != "sokna-local-managed-files-v1":
        raise RuntimeError("UNKNOWN_LOCAL_INVENTORY_FORMAT")
    old_entries = existing.get("files", [])
    existing["files"] = sorted(payload)
    payload[inventory_name] = json_bytes(existing)
    # Keep the existing installed-source identity layout; only target identity
    # metadata may change. Fenced QA values explicitly cannot be installed.
    identity_path = "resources/release-identity-v1.json"
    if identity_path not in payload:
        raise RuntimeError("R233_RELEASE_IDENTITY_METADATA_MISSING")
    identity = json.loads(payload[identity_path])
    if not isinstance(identity, dict) or not isinstance(identity.get("source"), dict):
        raise RuntimeError("R233_RELEASE_IDENTITY_SCHEMA_UNKNOWN")
    identity["version"] = QA_VERSION
    identity["release_set_id"] = QA_RELEASE_ID
    identity["source"]["managed_file_count"] = len(payload)
    identity["source"]["canonical_file_count"] = len(payload) - len(EXCLUDE_FROM_CANONICAL)
    identity["source"]["canonical_tree_sha256"] = canonical_tree(payload)
    identity["source"]["source_ref"] = "R233 exact frozen source; SOURCE-ONLY QA Maintenance outer format rehearsal"
    payload[identity_path] = json_bytes(identity)
    provenance_path = "resources/build-provenance.json"
    if provenance_path not in payload:
        raise RuntimeError("R233_BUILD_PROVENANCE_METADATA_MISSING")
    provenance = json.loads(payload[provenance_path])
    if not isinstance(provenance, dict):
        raise RuntimeError("R233_BUILD_PROVENANCE_FORMAT_UNKNOWN")
    provenance["version"] = QA_VERSION
    provenance["release_set_id"] = QA_RELEASE_ID
    provenance["classification"] = "SOURCE_ONLY_QA_REHEARSAL_DO_NOT_INSTALL"
    provenance["source"] = "Pinned R233 Local source SHA " + source["sha256"]
    for key in ("full_business_acceptance", "installed_host_accepted", "production_host_install_verified"):
        provenance[key] = False
    payload[provenance_path] = json_bytes(provenance)
    changed = sorted(n for n in payload if payload[n] != original[n])
    expected = sorted({"VERSION.txt", inventory_name, identity_path, provenance_path})
    if changed != expected:
        raise RuntimeError(f"UNEXPECTED_APPLICATION_BYTE_CHANGES:{changed}")
    if sorted(json.loads(payload[inventory_name])["files"]) != sorted(payload):
        raise RuntimeError("TARGET_MANAGED_FILE_INVENTORY_INCOMPLETE")
    # Same contract as historical R241 builder, but **NO production claims**.
    web_zip = zip_bytes(payload)
    m = copy.deepcopy(m)
    m["release_set_id"] = QA_RELEASE_ID
    m["status"] = "SOURCE_QA_DO_NOT_INSTALL"
    m["for_install"] = False
    m["production_pass"] = False
    local = m["targets"]["local"]
    local.update(version=QA_VERSION, release_id=QA_RELEASE_ID,
                 managed_files=len(payload),
                 managed_tree_sha256=tree({n: (sha(b), len(b)) for n, b in payload.items()}))
    art = m["artifacts"]["local-web"]
    art.update(sha256=sha(web_zip), size_bytes=len(web_zip))
    q = m.get("qualification")
    if isinstance(q, dict):
        for key, value in q.items():
            if isinstance(value, bool):
                q[key] = False
    m.setdefault("qa_notes", {})["r233_qa_only"] = (
        "NO HOST QUALIFICATION. Source-only, missing installed-compatible baseline proofs and native Windows lifecycle."
    )
    if isinstance(m.get("signature"), dict):
        m["signature"]["value"] = ""
    a.out.parent.mkdir(parents=True, exist_ok=True)
    a.out.write_bytes(zip_bytes({"release-set.json": json_bytes(m), "artifacts/local/web.zip": web_zip}))
    src_to_final = source_inspect(
        a.source, SOURCE_PIN, "local", pathlib.Path(__file__).with_name("source-register.json"),
        candidate=a.out, route="local-maintenance-update",
    )
    final = audit(a.out, expected_kind="local-update", expected_sha=sha(a.out.read_bytes()),
                  registry=pathlib.Path(__file__).with_name("version-registry.json"))
    if (src_to_final["source_integrity"] != "PASS" or src_to_final["errors"] or
            src_to_final["release_blockers"] or final["static"] != "PASS"):
        raise RuntimeError("LOCAL_MAINTENANCE_QA_GATE_FAIL:" + json.dumps({
            "source": src_to_final, "static": final,
        }, ensure_ascii=False))
    report = {
        "format": "sokna-r233-local-maintenance-rehearsal-v1",
        "source_zip_sha256": source["sha256"],
        "parent_r201_exact_sha256": PARENT_SHA,
        "candidate_zip_sha256": sha(a.out.read_bytes()),
        "candidate_qa_version": QA_VERSION,
        "candidate_files": len(payload),
        "source_inventory_count": len(old_entries),
        "target_inventory_count": len(json.loads(payload[inventory_name])["files"]),
        "modified_paths": changed,
        "functional_byte_changes": src_to_final["candidate_comparison"]["functional_drift_count"],
        "inherited_unverified_r201_baselines": len(m["supported_baselines"]),
        "local_static": final["static"],
        "production_installable": False,
        "native_windows_qualification": "NOT_RUN",
        "installed_compatibility_proven": False,
        "warning": "NO INSTALL/DEPLOY. This rehearsal is not a release and no field baselines are newly proven.",
    }
    a.report.parent.mkdir(parents=True, exist_ok=True)
    a.report.write_text(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2))


if __name__ == "__main__":
    main()
