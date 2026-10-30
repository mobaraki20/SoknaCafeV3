#!/usr/bin/env python3
"""Read-only early contract gate for Maintenance V4 Local Release Set v2.1.

No signing keys, certificates or publisher enrollment are needed for operator-uploaded Local ZIPs.
Checks package structure before upload; host Supervisor retains final authority for installed baseline, backup and recovery.
"""
import argparse
import hashlib
import io
import json
import re
import sys
import zipfile
from pathlib import Path

def sha(data):
    return hashlib.sha256(data).hexdigest()

def _workspace_order(value):
    match = re.fullmatch(r"([A-Za-z0-9.+-]+-workspace\.)([0-9]{1,12})([a-z]{1,6})(?:-[A-Za-z0-9_.-]+)?", value or "")
    if not match:
        return None
    n = 0
    for letter in match.group(3):
        n = n * 26 + ord(letter) - ord('a') + 1
    return match.group(1), int(match.group(2)), n


def inspect(package_path, preparation=False, universal=False):
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
            require(isinstance(bases, list) and 1 <= len(bases) <= 64
                    and all(isinstance(b, dict) and isinstance(b.get("local"), dict)
                            for b in bases), "EXACT_LOCAL_BASELINES_REQUIRED")
            if universal:
                require(isinstance(bases, list) and 2 <= len(bases) <= 64,
                        "UUP01_TWO_DISTINCT_BASELINES_REQUIRED")
            identities = [target] + [b.get("local", {}) for b in bases if isinstance(b, dict)]
            for identity in identities:
                if not isinstance(identity, dict):
                    require(False, "UNRESOLVED_IDENTITY_OBJECT")
                    continue
                for key in ("release_id", "version", "managed_tree_sha256",
                            "schema_fingerprint", "migration_head"):
                    value = identity.get(key)
                    require(isinstance(value, str) and bool(value.strip())
                            and "__READ_TRUSTED" not in value,
                            "UNRESOLVED_IDENTITY_" + key.upper())
                authority = identity.get("authority_epoch_policy")
                require(authority is None or isinstance(authority, str)
                        and "__READ_TRUSTED" not in authority,
                        "UNRESOLVED_IDENTITY_AUTHORITY_EPOCH_POLICY")
            if universal:
                target_order = _workspace_order(target.get("version"))
                require(target_order is not None, "UUP01_TARGET_VERSION_ORDER_UNKNOWN")
                seen = set()
                for source in (b.get("local", {}) for b in bases if isinstance(b, dict)):
                    source_order = _workspace_order(source.get("version"))
                    require(source_order is not None, "UUP01_SOURCE_VERSION_ORDER_UNKNOWN")
                    require(target_order is not None and source_order is not None
                            and source_order[0] == target_order[0]
                            and source_order[1:] < target_order[1:],
                            "UUP01_DOWNGRADE_OR_INCOMPARABLE")
                    fingerprint = json.dumps(source, sort_keys=True, ensure_ascii=False)
                    require(fingerprint not in seen, "UUP01_DUPLICATE_BASELINE")
                    seen.add(fingerprint)
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
    parser.add_argument("--universal", action="store_true", help="Require >=2 distinct, comparable compatible Local source identities")
    args = parser.parse_args()
    defects = inspect(args.zip, preparation=args.preparation, universal=args.universal)
    print(json.dumps({"source_contract_pass": not defects,
                      "installation_qualified_by_this_tool": False,
                      "defects": defects}, indent=2))
    return 1 if defects else 0

if __name__ == "__main__":
    sys.exit(main())
