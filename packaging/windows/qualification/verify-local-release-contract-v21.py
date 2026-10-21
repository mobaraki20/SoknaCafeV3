#!/usr/bin/env python3
"""Read-only early contract gate for Maintenance V4 Local Release Set v2.1.

No signing keys, certificates or publisher enrollment are needed for operator-uploaded Local ZIPs.
Checks package structure before upload; host Supervisor retains final authority for installed baseline, backup and recovery.
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
    name = "release-set-template-UNQUALIFIED.json" if preparation else "release-set.json"
    try:
        with zipfile.ZipFile(package_path) as package:
            names = [i.filename for i in package.infolist() if not i.is_dir()]
            if names.count(name) != 1:
                return ["MANIFEST_MISSING_OR_DUPLICATE"]
            raw = package.read(name)
            require(len(raw) <= 2 * 1024 * 1024, "MANIFEST_TOO_LARGE")
            manifest = json.loads(raw)
            require(manifest.get("format") == "sokna-maintenance-release-set-v2.1"
                    and str(manifest.get("schema_version")) == "2.1", "FORMAT_INVALID")
            require(manifest.get("release_type") == "local_only", "NOT_LOCAL_ONLY")
            target = (manifest.get("targets") or {}).get("local") or {}
            bases = manifest.get("supported_baselines") or []
            require(len(bases) == 1 and isinstance(bases[0].get("local"), dict),
                    "EXACT_LOCAL_BASELINE_REQUIRED")
            for identity in [target, bases[0].get("local", {}) if bases else {}]:
                for key in ("release_id", "version", "managed_tree_sha256",
                            "schema_fingerprint", "migration_head", "authority_epoch_policy"):
                    value = identity.get(key)
                    require(isinstance(value, str) and bool(value.strip())
                            and "__READ_TRUSTED" not in value,
                            "UNRESOLVED_IDENTITY_" + key.upper())
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
                    "LOCAL_TARGET_MANAGED_FILES_MISSING_OR_WRONG")
            require(target.get("managed_tree_sha256") == tree,
                    "LOCAL_TARGET_TREE_MISMATCH")
            require(files.get("VERSION.txt", b"").decode("utf-8-sig").strip()
                    == target.get("version"), "LOCAL_TARGET_VERSION_MISMATCH")
            # Intent to install is established by an authenticated operator
            # uploading this ZIP via the Local administration panel. No issuer
            # signature/production-pass/canary metadata is required here.
            # The Supervisor independently checks package path, content digest,
            # exact installed baseline, rollback state and runtime health.
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
