#!/usr/bin/env python3
"""Prepare (never sign or install) a portable, exact-multibaseline Sokna Local release.

This is deliberately a **publisher preparation** step. It does not claim host
qualification, enroll a key, rewrite installed identities, or authorize install.
Local payload stays a complete web snapshot until Maintenance safely supports
reconstruction/atomic activation of cumulative delta payloads.
"""
from __future__ import annotations
import argparse
import copy
import hashlib
import io
import json
import re
import sys
import zipfile
from pathlib import Path

FINGERPRINT_PREFIX = "migration-ledger-sha256-v1:"
REQUIRED_ID = ("release_id", "version", "managed_tree_sha256", "schema_fingerprint", "migration_head", "authority_epoch_policy")
HEX64 = re.compile(r"^[0-9a-fA-F]{64}$")
MIGRATION = re.compile(r"^database/migrations/(\d{4}_[a-z0-9_]+)\.sql$")
MAX_BASELINES = 64
VERSION_MARKER = re.compile(r"\.231([a-z]{1,4})(?=[^a-z]|$)")

def version_ordinal(version: str):
    match = VERSION_MARKER.search(version)
    if match is None:
        return None
    value = 0
    for ch in match.group(1):
        value = value * 26 + ord(ch) - 96
    return value

class UnsafeRelease(ValueError):
    pass


def sha(b: bytes) -> str:
    return hashlib.sha256(b).hexdigest()


def safe_zip_entries(raw: bytes, max_entries=20_000) -> dict[str, bytes]:
    result = {}
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        info = [x for x in z.infolist() if not x.is_dir()]
        if not info or len(info) > max_entries:
            raise UnsafeRelease("ARCHIVE_ENTRY_COUNT_INVALID")
        for entry in info:
            n = entry.filename
            if (n in result or n.startswith(("/", "\\")) or "\\" in n or
                    any(p in ("", ".", "..") for p in n.split("/")) or
                    entry.file_size > 80 * 1024 * 1024):
                raise UnsafeRelease("UNSAFE_DUPLICATE_OR_OVERSIZE_ENTRY:" + n)
            result[n] = z.read(entry)
    return result


def canonical_tree(files: dict[str, bytes]) -> str:
    return sha("".join(p + "\0" + sha(files[p]) + "\n" for p in sorted(files)).encode("utf-8"))


def migration_catalog(files: dict[str, bytes]) -> dict[str, str]:
    return {match.group(1): sha(data) for path, data in files.items()
            if (match := MIGRATION.fullmatch(path))}


def ledger(catalog: dict[str, str], head: str) -> str:
    subset = sorted((name, digest) for name, digest in catalog.items() if name <= head)
    if not subset or subset[-1][0] != head:
        raise UnsafeRelease("UNKNOWN_MIGRATION_HEAD:" + head)
    return FINGERPRINT_PREFIX + sha("".join(name + "\0" + digest + "\n" for name, digest in subset).encode())


def validated_identity(value: dict, catalog: dict[str, str]) -> dict:
    if not isinstance(value, dict):
        raise UnsafeRelease("BASELINE_IDENTITY_INVALID")
    identity = {key: value.get(key) for key in REQUIRED_ID}
    if not all(isinstance(x, str) and x.strip() and not x.startswith("__READ") for x in identity.values()):
        raise UnsafeRelease("BASELINE_IDENTITY_INCOMPLETE")
    if not HEX64.fullmatch(identity["managed_tree_sha256"]):
        raise UnsafeRelease("BASELINE_TREE_DIGEST_INVALID")
    # Every previous SQL file must be byte-for-byte identical to the migration
    # catalog of the destination. Silent migration edits are never approved.
    if identity["schema_fingerprint"] != ledger(catalog, identity["migration_head"]):
        raise UnsafeRelease("BASELINE_MIGRATION_LEDGER_MISMATCH:" + identity["version"])
    return identity


def load_manifest(path: Path) -> dict:
    z = safe_zip_entries(path.read_bytes())
    if "release-set.json" not in z:
        raise UnsafeRelease("BASELINE_RELEASE_SET_MISSING:" + path.name)
    manifest = json.loads(z["release-set.json"])
    if manifest.get("format") != "sokna-maintenance-release-set-v2.1":
        raise UnsafeRelease("BASELINE_RELEASE_SET_UNEXPECTED_FORMAT")
    return manifest


def prepare(source_release: Path, baseline_releases: list[Path], output: Path):
    if output.exists():
        raise UnsafeRelease("OUTPUT_ALREADY_EXISTS")
    source = safe_zip_entries(source_release.read_bytes())
    manifest = json.loads(source.get("release-set.json", b"{}"))
    if manifest.get("release_type") != "local_only":
        raise UnsafeRelease("LOCAL_ONLY_SOURCE_REQUIRED")
    a = (manifest.get("artifacts") or {}).get("local-web", {})
    path = a.get("path")
    if path not in source or set(source) != {"release-set.json", path}:
        raise UnsafeRelease("LOCAL_PAYLOAD_MISSING_OR_UNDECLARED")
    payload = source[path]
    if a.get("sha256") != sha(payload) or a.get("size_bytes") != len(payload):
        raise UnsafeRelease("SOURCE_PAYLOAD_HASH_OR_LENGTH_MISMATCH")
    files = safe_zip_entries(payload)
    target = (manifest.get("targets") or {}).get("local", {})
    tree = canonical_tree(files)
    if target.get("managed_tree_sha256") != tree or target.get("managed_files") != len(files):
        raise UnsafeRelease("TARGET_MANAGED_TREE_MISMATCH")
    if files.get("VERSION.txt", b"").decode("utf-8-sig").strip() != target.get("version"):
        raise UnsafeRelease("TARGET_VERSION_MISMATCH")
    catalog = migration_catalog(files)
    if not catalog:
        raise UnsafeRelease("TARGET_MIGRATION_CATALOG_MISSING")
    head = max(catalog)
    if head != target.get("migration_head"):
        raise UnsafeRelease("TARGET_MIGRATION_HEAD_MISMATCH")
    target = dict(target, schema_fingerprint=ledger(catalog, head))
    identity = validated_identity(target, catalog)
    for required in ("database", "recovery", "requirements", "install_plan", "signature"):
        if required not in manifest and required != "signature":
            raise UnsafeRelease("SOURCE_SECTION_MISSING:" + required)
    baselines = []
    seen = set()
    for release in baseline_releases:
        m = load_manifest(release)
        if m.get("release_type") != "local_only":
            raise UnsafeRelease("NON_LOCAL_BASELINE_ARCHIVE")
        # Archive contains proven *identifiers* only; qualification of each
        # candidate on a real host is a distinct mandatory release gate.
        candidates = [((m.get("targets") or {}).get("local"))]
        candidates.extend(x.get("local") for x in m.get("supported_baselines", []))
        for candidate in candidates:
            if not isinstance(candidate, dict):
                continue
            candidate = validated_identity(candidate, catalog)
            if candidate["managed_tree_sha256"] == identity["managed_tree_sha256"]:
                continue
            if candidate["migration_head"] > head:
                raise UnsafeRelease("DOWNGRADE_DATABASE_FORBIDDEN")
            source_ordinal = version_ordinal(candidate["version"])
            target_ordinal = version_ordinal(identity["version"])
            if source_ordinal is not None and target_ordinal is not None and source_ordinal >= target_ordinal:
                raise UnsafeRelease("DOWNGRADE_OR_SAME_APP_VERSION_FORBIDDEN:" + candidate["version"])
            key = tuple(candidate[k] for k in REQUIRED_ID)
            if key in seen:
                continue
            seen.add(key)
            baselines.append({"local": candidate, "public": None})
    if not 1 <= len(baselines) <= MAX_BASELINES:
        raise UnsafeRelease("BASELINE_COUNT_UNSUPPORTED:" + str(len(baselines)))
    baselines.sort(key=lambda v: (v["local"]["migration_head"], v["local"]["version"],v["local"]["release_id"]))
    result = copy.deepcopy(manifest)
    result["supported_baselines"] = baselines
    result["targets"]["local"] = target
    dag=[]
    for version, digest in sorted(catalog.items()):
        if any(b["local"]["migration_head"] < version for b in baselines):
            dag.append({"version":version,"sha256":digest,
                        "applies_when_installed_head_precedes":version})
    result["database"]["local"]["migration_dag"] = dag
    # Fail closed: signing, trust provisioning, qualification, and host-specific
    # preflight are separate steps. Never make unsigned publisher prep installable.
    result["for_install"] = False
    result["production_pass"] = False
    result["status"] = "UNIVERSAL_MULTI_BASELINE_SOURCE_PREPARATION_NOT_INSTALLABLE"
    result["signature"] = {"algorithm":"ecdsa-p256-sha256","key_id":"PUBLISHER_UNSET", "value":""}
    result["qualification"] = {"status":"NOT_QUALIFIED", "panel_install":"NOT_RUN", "real_windows":False,
                               "real_mariadb":False,"real_public_host":False,"canary":False,
                               "failure_matrix_id":"NOT_RUN",
                               "scope":"Reusable exact-baseline contract, not a release authorization"}
    report = {"installable":False,"source_release":source_release.name,"target_version":identity["version"],
              "target_tree":tree,"target_schema_fingerprint":target["schema_fingerprint"],
              "target_migration_head":head,"baseline_count":len(baselines),
              "baselines":[{"release_id":b["local"]["release_id"],"version":b["local"]["version"],
                            "schema_fingerprint":b["local"]["schema_fingerprint"]} for b in baselines],
              "migrations_union":[x["version"] for x in dag],
              "missing_requirements":["exact live-host baseline qualification", "publisher signing and installed trust",
                                      "Windows Maintenance preflight/stage/activate/recovery", "Public relay UAT"]}
    with zipfile.ZipFile(output,"x",compression=zipfile.ZIP_DEFLATED,compresslevel=7) as z:
        z.writestr("release-set.json",json.dumps(result,ensure_ascii=False,sort_keys=True,separators=(",",":")))
        z.writestr(path,payload,compress_type=zipfile.ZIP_STORED)
    proof = safe_zip_entries(output.read_bytes())
    if proof[path] != payload or json.loads(proof["release-set.json"]).get("supported_baselines") != baselines:
        output.unlink(missing_ok=True)
        raise UnsafeRelease("WRITE_READBACK_MISMATCH")
    return report


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--candidate",required=True,type=Path)
    ap.add_argument("--baseline",action="append",type=Path,default=[])
    ap.add_argument("--baseline-dir",action="append",type=Path,default=[],
                    help="Trusted catalog directory of historical Local release-set ZIPs")
    ap.add_argument("--output",required=True,type=Path)
    ap.add_argument("--report",type=Path)
    args = ap.parse_args()
    try:
        catalogs=[]
        for directory in args.baseline_dir:
            if not directory.is_dir():
                raise UnsafeRelease("BASELINE_CATALOG_DIRECTORY_MISSING:" + str(directory))
            catalogs.extend(sorted(directory.glob("*.zip")))
        report=prepare(args.candidate,args.baseline+catalogs,args.output)
    except (UnsafeRelease,ValueError,OSError,zipfile.BadZipFile) as e:
        print(json.dumps({"ok":False,"code":str(e)},ensure_ascii=False),file=sys.stderr)
        return 2
    if args.report:
        args.report.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding="utf-8")
    print(json.dumps(report,ensure_ascii=False,indent=2))
    return 0

if __name__=="__main__":
    sys.exit(main())