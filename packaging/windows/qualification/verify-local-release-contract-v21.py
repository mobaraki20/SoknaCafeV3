#!/usr/bin/env python3
"""Read-only early contract gate for Maintenance V4 Local Release Set v2.1.

Never signs, claims real-host qualification or bypasses Supervisor checks.
Run before publisher signing to catch payload/target mismatches early.
"""
import argparse
import hashlib
import io
import json
import sys
import zipfile
from pathlib import Path

def sha(data):
    return hashlib.sha256(data).hexdigest()

def inspect(package_path, preparation=False):
    defects = []
    def require(ok, code):
        if not ok:
            defects.append(code)
    try:
        with zipfile.ZipFile(package_path) as package:
            names = [i.filename for i in package.infolist() if not i.is_dir()]
            candidate_names = (["release-set-template-UNQUALIFIED.json", "release-set.json"]
                               if preparation else ["release-set.json"])
            present = [name for name in candidate_names if names.count(name) == 1]
            if len(present) != 1:
                return ["MANIFEST_MISSING_OR_DUPLICATE"]
            name = present[0]
            raw = package.read(name)
            require(len(raw) <= 2 * 1024 * 1024, "MANIFEST_TOO_LARGE")
            manifest = json.loads(raw)
            require(manifest.get("format") == "sokna-maintenance-release-set-v2.1"
                    and str(manifest.get("schema_version")) == "2.1", "FORMAT_INVALID")
            require(manifest.get("release_type") == "local_only", "NOT_LOCAL_ONLY")
            target = (manifest.get("targets") or {}).get("local") or {}
            bases = manifest.get("supported_baselines") or []
            require(isinstance(bases, list) and 1 <= len(bases) <= 64 and all(
                isinstance(b, dict) and isinstance(b.get("local"), dict)
                and b.get("public") is None for b in bases
            ), "EXACT_LOCAL_BASELINES_1_TO_64_REQUIRED")
            identities = [target] + [b.get("local", {}) for b in bases if isinstance(b, dict)]
            unique_baselines = set()
            for i, identity in enumerate(identities):
                require(isinstance(identity, dict), "BASELINE_IDENTITY_NOT_OBJECT")
                if not isinstance(identity, dict):
                    continue
                for key in ("release_id", "version", "managed_tree_sha256",
                            "schema_fingerprint", "migration_head", "authority_epoch_policy"):
                    value = identity.get(key)
                    require(isinstance(value, str) and bool(value.strip())
                            and "__READ_TRUSTED" not in value,
                            "UNRESOLVED_IDENTITY_" + key.upper())
                if i:
                    key = tuple(identity.get(k) for k in ("release_id", "version", "managed_tree_sha256",
                        "schema_fingerprint", "migration_head", "authority_epoch_policy"))
                    require(key not in unique_baselines, "DUPLICATE_BASELINE_FORBIDDEN")
                    unique_baselines.add(key)
            artifacts = [a for a in (manifest.get("artifacts") or {}).values()
                         if a.get("component") == "local-web"
                         and a.get("kind") == "web-tree-zip-v1"]
            if len(artifacts) != 1:
                return defects + ["EXACT_LOCAL_WEB_ARTIFACT_REQUIRED"]
            a = artifacts[0]
            path = a.get("path", "")
            if names.count(path) != 1:
                return defects + ["ARTIFACT_MISSING_OR_DUPLICATE"]
            if not preparation:
                require(set(names) == {name, path}, "UNDECLARED_ZIP_ENTRIES")
            packed = package.read(path)
            require(len(packed) == a.get("size_bytes") and
                    sha(packed) == a.get("sha256"), "ARTIFACT_HASH_OR_SIZE_INVALID")
            with zipfile.ZipFile(io.BytesIO(packed)) as inner:
                files = {}
                for entry in inner.infolist():
                    if entry.is_dir():
                        continue
                    p = entry.filename
                    if p in files or p.startswith(("/", "\\")) or "\\" in p or any(
                        part in ("", ".", "..") for part in p.split("/")
                    ):
                        return defects + ["UNSAFE_OR_DUPLICATE_MANAGED_PATH"]
                    files[p] = inner.read(entry)
            count = len(files)
            require(0 < count <= 20000, "MANAGED_FILE_COUNT_INVALID")
            tree = sha(b"".join((p + "\0" + sha(files[p]) + "\n").encode()
                                for p in sorted(files)))
            require(target.get("managed_files") == count,
                    "SIGNED_TARGET_MANAGED_FILES_MISSING_OR_WRONG")
            require(target.get("managed_tree_sha256") == tree,
                    "SIGNED_TARGET_TREE_MISMATCH")
            require(files.get("VERSION.txt", b"").decode("utf-8-sig").strip()
                    == target.get("version"), "SIGNED_TARGET_VERSION_MISMATCH")
            migrations = {p[len("database/migrations/"):-4]: sha(v)
                          for p, v in files.items()
                          if p.startswith("database/migrations/") and p.endswith(".sql")}
            if migrations and isinstance(target.get("schema_fingerprint"), str) and (
                    target["schema_fingerprint"].startswith("migration-ledger-sha256-v1:")):
                def ledger(head):
                    relevant = sorted((k,v) for k,v in migrations.items() if k <= head)
                    if not relevant or relevant[-1][0] != head:
                        return None
                    return "migration-ledger-sha256-v1:" + sha("".join(
                        k + "\\0" + v + "\\n" for k,v in relevant).encode())
                require(target.get("schema_fingerprint") == ledger(target.get("migration_head","")),
                        "TARGET_SCHEMA_LEDGER_MISMATCH")
                for baseline in bases:
                    if not isinstance(baseline, dict) or not isinstance(baseline.get("local"), dict):
                        continue
                    source = baseline["local"]
                    require(source.get("schema_fingerprint") == ledger(source.get("migration_head","")),
                            "BASELINE_SCHEMA_LEDGER_MISMATCH")
                if any(isinstance(b, dict) and isinstance(b.get("local"), dict)
                       and b["local"].get("migration_head") != target.get("migration_head") for b in bases):
                    dag = ((manifest.get("database") or {}).get("local") or {}).get("migration_dag")
                    require(isinstance(dag, list) and bool(dag),
                            "MIGRATION_DAG_REQUIRED_FOR_SCHEMA_CHANGE")
            if not preparation:
                require(manifest.get("for_install") is True
                        and manifest.get("production_pass") is True,
                        "NOT_INSTALL_QUALIFIED")
                require(bool((manifest.get("signature") or {}).get("value")),
                        "SIGNATURE_MISSING")
                # These are only declared claims. Supervisor must independently
                # verify signer, exact installed baseline, anti-rollback,
                # qualification and machine recovery/backup evidence.
    except (OSError, ValueError, KeyError, TypeError, UnicodeError, zipfile.BadZipFile) as ex:
        defects.append("ARCHIVE_UNREADABLE_" + type(ex).__name__)
    return sorted(set(defects))

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("zip", type=Path)
    parser.add_argument("--preparation", action="store_true")
    args = parser.parse_args()
    defects = inspect(args.zip, preparation=args.preparation)
    print(json.dumps({"source_contract_pass": not defects,
                      "installation_qualified_by_this_tool": False,
                      "defects": defects}, indent=2))
    return 1 if defects else 0

if __name__ == "__main__":
    sys.exit(main())
