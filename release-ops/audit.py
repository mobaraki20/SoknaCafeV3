#!/usr/bin/env python3
"""Read-only SOKNA release artifact admission. Does not build, install or publish."""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import pathlib
import re
import stat
import sys
import zipfile

MAX_FILES = 10000
MAX_TOTAL = 3 * 1024**3
MAX_ONE = 512 * 1024**2
MAX_JSON = 4 * 1024**2
HEX64 = re.compile(r"^[a-f0-9]{64}$")
IDENTITY = ("release_id", "version", "managed_tree_sha256",
            "schema_fingerprint", "migration_head", "authority_epoch_policy")
PROTECTED_PUBLIC = {
    "emergency.php", "public/emergency.php", "config.php", "install.lock",
    "resources/update-trust-v1.json",
}
PROTECTED_LOCAL = {"public/local-recovery.php", "config.php", "install.lock"}


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def tree(files: dict[str, tuple[str, int]]) -> str:
    return sha("".join(name + "\0" + digest + "\n"
                       for name, (digest, _) in sorted(files.items())).encode())


def issue(result: dict, code: str) -> None:
    if code not in result["errors"]:
        result["errors"].append(code)


def gap(result: dict, code: str) -> None:
    if code not in result["not_proven"]:
        result["not_proven"].append(code)


def safe_json(z: zipfile.ZipFile, name: str) -> dict:
    info = z.getinfo(name)
    if info.file_size > MAX_JSON:
        raise ValueError("JSON_TOO_LARGE")
    doc = json.loads(z.read(name).decode("utf-8"))
    if not isinstance(doc, dict):
        raise ValueError("JSON_NOT_OBJECT")
    return doc


def hashes(z: zipfile.ZipFile, result: dict) -> dict[str, tuple[str, int]]:
    seen = set()
    total = 0
    files = {}
    if len(z.infolist()) > MAX_FILES:
        raise ValueError("ZIP_TOO_MANY_ENTRIES")
    for info in z.infolist():
        name = info.filename
        norm = name.replace("\\", "/")
        if (not name or name != norm or name.startswith("/") or "\0" in name
            or any(part in ("", ".", "..") for part in name.rstrip("/").split("/"))
            or re.match(r"^[A-Za-z]:", name)):
            raise ValueError("ZIP_UNSAFE_PATH")
        if name in seen:
            raise ValueError("ZIP_DUPLICATE_NAME")
        seen.add(name)
        if stat.S_IFMT(info.external_attr >> 16) == stat.S_IFLNK:
            raise ValueError("ZIP_SYMLINK")
        if info.is_dir():
            continue
        total += info.file_size
        if total > MAX_TOTAL or info.file_size > MAX_ONE:
            raise ValueError("ZIP_SIZE_LIMIT")
        if info.file_size > 10_000_000 and info.file_size / max(1, info.compress_size) > 500:
            raise ValueError("ZIP_SUSPICIOUS_RATIO")
        h = hashlib.sha256()
        size = 0
        with z.open(info) as f:
            for chunk in iter(lambda: f.read(1024 * 1024), b""):
                size += len(chunk)
                h.update(chunk)
        if size != info.file_size:
            raise ValueError("ZIP_SIZE_MISMATCH")
        files[name] = (h.hexdigest(), size)
    if z.testzip() is not None:
        raise ValueError("ZIP_CRC_ERROR")
    return files


def manifest_files(z, declared, actual, result):
    if not isinstance(declared, list):
        issue(result, "MANIFEST_FILE_LIST_MISSING")
        return
    wanted = {}
    for row in declared:
        if not isinstance(row, dict) or not isinstance(row.get("path"), str):
            issue(result, "MANIFEST_FILE_ROW_INVALID")
            continue
        path = row["path"]
        if path in wanted:
            issue(result, "MANIFEST_DUPLICATE_PATH")
        wanted[path] = (row.get("sha256"), row.get("size"))
    if set(wanted) != set(actual):
        issue(result, "MANIFEST_FILE_SET_MISMATCH")
    for name in set(wanted) & set(actual):
        if wanted[name] != actual[name]:
            issue(result, "MANIFEST_FILE_HASH_OR_SIZE_MISMATCH")


def base_key(component: str, row: dict) -> tuple:
    if component == "local":
        obj = row.get("local", row)
        return tuple(str(obj.get(key, "")) for key in IDENTITY)
    obj = row.get("public", row)
    return (str(obj.get("version", "")), str(obj.get("managed_tree_sha256", "")))


def validate_baselines(m, component, result):
    if component == "local":
        rows = m.get("supported_baselines")
    else:
        rows = (m.get("compatibility") or {}).get("supported_baselines")
    if not isinstance(rows, list) or not rows:
        gap(result, "EXACT_COMPATIBLE_BASELINES_NOT_DECLARED")
        return []
    keys = []
    for row in rows:
        if not isinstance(row, dict):
            issue(result, "BASELINE_INVALID")
            continue
        key = base_key(component, row)
        if any(v in ("", "*", "unknown", "None") for v in key):
            issue(result, "BASELINE_INCOMPLETE_OR_WILDCARD")
        if component == "local" and not HEX64.fullmatch(key[2]):
            issue(result, "BASELINE_TREE_INVALID")
        if component == "public" and not HEX64.fullmatch(key[1]):
            issue(result, "BASELINE_TREE_INVALID")
        keys.append(key)
    if len(keys) != len(set(keys)):
        issue(result, "BASELINE_DUPLICATE_IDENTITY")
    result["baseline_count"] = len(keys)
    return keys


def check_local_release(z, outer, result):
    result["kind"] = "local-update"
    if set(outer) != {"release-set.json", "artifacts/local/web.zip"}:
        issue(result, "LOCAL_RELEASE_SET_LAYOUT_INVALID")
        return {}, {}
    m = safe_json(z, "release-set.json")
    if (m.get("format") != "sokna-maintenance-release-set-v2.1"
        or str(m.get("schema_version")) != "2.1"
        or m.get("release_type") != "local_only"):
        issue(result, "LOCAL_RELEASE_SCHEMA_INVALID")
    with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as inner:
        actual = hashes(inner, result)
        web_version = inner.read("VERSION.txt").decode("utf-8").strip() if "VERSION.txt" in actual else ""
        if "resources/local-managed-files-v1.json" in actual:
            inventory = safe_json(inner, "resources/local-managed-files-v1.json").get("files")
            if not isinstance(inventory, list) or sorted(inventory) != sorted(actual):
                issue(result, "LOCAL_MANAGED_INVENTORY_MISMATCH")
        else:
            issue(result, "LOCAL_MANAGED_INVENTORY_MISSING")
    artifact = m.get("artifacts", {}).get("local-web", {})
    if (artifact.get("path") != "artifacts/local/web.zip"
        or artifact.get("sha256") != outer["artifacts/local/web.zip"][0]
        or artifact.get("size_bytes") != outer["artifacts/local/web.zip"][1]):
        issue(result, "LOCAL_INNER_ARTIFACT_HASH_MISMATCH")
    target = m.get("targets", {}).get("local", {})
    result["version"] = target.get("version")
    if (not web_version or web_version != target.get("version")
        or target.get("managed_tree_sha256") != tree(actual)
        or target.get("managed_files") != len(actual)):
        issue(result, "LOCAL_TARGET_TREE_VERSION_OR_COUNT_MISMATCH")
    if not m.get("release_set_id") or target.get("release_id") != m.get("release_set_id"):
        issue(result, "LOCAL_TARGET_RELEASE_ID_MISMATCH")
    for key in IDENTITY:
        if target.get(key) in (None, "", "*"):
            issue(result, "LOCAL_TARGET_IDENTITY_FIELD_MISSING_" + key.upper())
    if m.get("database", {}).get("local", {}).get("rollback_after_boundary") != "forbidden":
        issue(result, "LOCAL_DB_FORWARD_ROLLBACK_GUARD_MISSING")
    plan = m.get("install_plan", [])
    if (not isinstance(plan, list) or not plan
        or plan[0].get("phase") != "preflight"
        or plan[0].get("mutation") is not False):
        issue(result, "LOCAL_PREFLIGHT_FIRST_REQUIRED")
    backup = m.get("pre_update_backup_policy", {})
    if not (backup.get("required") is True and
            backup.get("on_failure") == "reject_pre_mutation" and
            backup.get("system_recovery_snapshot_required") is True):
        issue(result, "LOCAL_REQUIRED_BACKUP_POLICY_MISSING")
    if m.get("for_install") is not True or m.get("production_pass") is not True:
        gap(result, "LOCAL_NOT_MARKED_INSTALL_QUALIFIED")
    if re.search(r"NOT.?INSTALL|SOURCE.?ONLY|QA|UAT|TEST.?ONLY", str(m.get("status", "")), re.I):
        gap(result, "LOCAL_QA_OR_SOURCE_ONLY_STATUS")
    keys = validate_baselines(m, "local", result)
    return m, actual, keys


def check_public_update(z, outer, result):
    result["kind"] = "public-update"
    m = safe_json(z, "manifest.json")
    if m.get("format") != "sokna-component-package-v1" or m.get("component") != "public-edge":
        issue(result, "PUBLIC_UPDATE_SCHEMA_INVALID")
    payload = {n[8:]: v for n, v in outer.items() if n.startswith("payload/")}
    if any(n not in {"manifest.json"} and not n.startswith("payload/") for n in outer):
        issue(result, "PUBLIC_UPDATE_LAYOUT_INVALID")
    manifest_files(z, m.get("files"), payload, result)
    for p in payload:
        if (p in PROTECTED_PUBLIC or p.startswith(("src/Emergency/", "storage/", "tools/"))
            or p.startswith("var/")):
            issue(result, "PROTECTED_PUBLIC_FILE_IN_UPDATE")
    result["version"] = m.get("version")
    if "VERSION.txt" not in payload or z.read("payload/VERSION.txt").decode("utf-8").strip() != m.get("version"):
        issue(result, "PUBLIC_VERSION_MISMATCH")
    if m.get("target_managed_tree_sha256") != tree(payload):
        issue(result, "PUBLIC_MANAGED_TREE_MISMATCH")
    owner = "src/Update/emergency-owner-v1.json"
    code = "src/Update/EmergencyOwnerCandidate.php"
    if owner in payload:
        meta = json.loads(z.read("payload/" + owner))
        if (not isinstance(meta, dict) or meta.get("release_version") != m.get("version")
            or code not in payload or meta.get("sha256") != payload.get(code, ("", 0))[0]):
            issue(result, "PUBLIC_EMERGENCY_OWNER_VERSION_OR_HASH_MISMATCH")
    else:
        gap(result, "PUBLIC_EMERGENCY_OWNER_NOT_PROVEN")
    comp = m.get("compatibility") or {}
    if any(k.startswith("from_") for k in comp):
        issue(result, "PUBLIC_ONE_SOURCE_ONLY_COMPATIBILITY")
    keys = validate_baselines(m, "public", result)
    return m, payload, keys




def check_local_legacy_update(z, outer, result):
    # Distinct old Local Web ownership. This is NOT the Maintenance slot
    # Release Set and MUST NOT be promoted as a current universal Local ZIP.
    result["kind"] = "local-legacy-update"
    m = safe_json(z, "manifest.json")
    if (m.get("format") != "sokna-component-package-v1"
        or m.get("component") != "local"
        or m.get("updater_contract") != 2
        or (m.get("upgrade") or {}).get("mode") != "legacy-full-bridge"):
        issue(result, "LEGACY_LOCAL_COMPONENT_SCHEMA_INVALID")
    payload = {n[8:]: v for n, v in outer.items() if n.startswith("payload/")}
    if set(outer) != {"manifest.json"} | {"payload/" + p for p in payload}:
        issue(result, "LEGACY_LOCAL_COMPONENT_LAYOUT_INVALID")
    manifest_files(z, m.get("files"), payload, result)
    if not payload or "VERSION.txt" not in payload:
        issue(result, "LEGACY_LOCAL_VERSION_MISSING")
    elif z.read("payload/VERSION.txt").decode("utf-8").strip() != m.get("version"):
        issue(result, "LEGACY_LOCAL_VERSION_MISMATCH")
    result["version"] = m.get("version")
    gap(result, "LEGACY_LOCAL_NOT_A_MAINTENANCE_RELEASE_SET")
    gap(result, "LEGACY_LAYOUT_ELIGIBILITY_NOT_PROVEN_FOR_INSTALLED_TARGET")
    return m, payload, []


def check_public_root_clean(z, outer, result):
    # R233 Public clean installs are deliberately flat to domain root and have
    # no artificial deploy manifest. Require explicit --kind and pinned source
    # for delivery; generic ZIPs must not be misidentified as valid installers.
    result["kind"] = "public-clean"
    required = {"VERSION.txt", ".htaccess", "bootstrap.php", "index.php",
                "setup/index.php", "setup/api.php",
                "src/Emergency/PublicUpdateService.php"}
    if not required.issubset(outer):
        issue(result, "PUBLIC_ROOT_CLEAN_REQUIRED_FILES_MISSING")
    if any(n in outer for n in ("manifest.json", "config.php", "install.lock")):
        issue(result, "PUBLIC_ROOT_CLEAN_CONTAINS_MANIFEST_OR_PRIVATE_STATE")
    if any(n.startswith(("storage/", "var/", "data/", ".git/")) for n in outer):
        issue(result, "PUBLIC_ROOT_CLEAN_MUTABLE_STATE_INCLUDED")
    # On a genuinely EMPTY host no test harness may inject missing PHP
    # dependencies. This catches the historical R262 244-file Clean ZIP:
    # it passed setup QA after helper-copy, but bootstrap was incomplete.
    if "bootstrap.php" in outer:
        bootstrap = z.read("bootstrap.php").decode("utf-8", errors="replace")
        imports = re.findall(r"""require_once\s+__DIR__\s*\.\s*['"]/([^'"]+)['"]""", bootstrap)
        missing = sorted({name for name in imports if name not in outer})
        if missing:
            issue(result, "PUBLIC_CLEAN_BOOTSTRAP_DEPENDENCIES_MISSING")
            result["missing_bootstrap_files"] = missing
    if "resources/update-trust-v1.json" in outer:
        trust = safe_json(z, "resources/update-trust-v1.json")
        if (trust.get("format") != "sokna-update-trust-v1"
            or trust.get("ed25519_public_keys") != {}):
            issue(result, "PUBLIC_CLEAN_TRUST_TEMPLATE_INVALID_OR_PRECONFIGURED")
    if "VERSION.txt" in outer:
        result["version"] = z.read("VERSION.txt").decode("utf-8").strip()
    else:
        issue(result, "PUBLIC_ROOT_CLEAN_VERSION_MISSING")
    if "src/Update/emergency-owner-v1.json" in outer:
        m = safe_json(z, "src/Update/emergency-owner-v1.json")
        code = "src/Update/EmergencyOwnerCandidate.php"
        if (m.get("release_version") != result["version"]
            or code not in outer or m.get("sha256") != outer.get(code, ("", 0))[0]):
            issue(result, "PUBLIC_CLEAN_EMERGENCY_OWNER_VERSION_OR_HASH_MISMATCH")
    else:
        issue(result, "PUBLIC_CLEAN_EMERGENCY_OWNER_METADATA_MISSING")
    gap(result, "PUBLIC_FLAT_ROOT_CLEAN_HAS_NO_EMBEDDED_SOURCE_MANIFEST")
    return {}, outer, []


def check_clean(z, outer, component, result):
    result["kind"] = component + "-clean"
    name = "sokna-install-manifest.json" if component == "local" else "manifest.json"
    m = safe_json(z, name)
    expected_format = ("sokna-local-web-clean-install-v1" if component == "local"
                       else "sokna-public-deploy-v1")
    if m.get("format") != expected_format or m.get("component") != ("local-web" if component == "local" else "public-edge"):
        issue(result, "CLEAN_INSTALL_SCHEMA_INVALID")
    actual = {k: v for k, v in outer.items() if k != name}
    manifest_files(z, m.get("files"), actual, result)
    required = {"VERSION.txt", "public/index.php"}
    if component == "local":
        required.update({"bootstrap.php", "public/setup/index.php", "public/setup/api.php"})
    if not required.issubset(actual):
        issue(result, "CLEAN_INSTALL_REQUIRED_FILE_MISSING")
    for p in actual:
        if (p in {"config.php", "install.lock", "public/config.php"}
            or p.startswith(("storage/", "data/", "var/", ".git/"))):
            issue(result, "CLEAN_INSTALL_MUTABLE_STATE_INCLUDED")
    result["version"] = m.get("version")
    if "VERSION.txt" not in actual or z.read("VERSION.txt").decode("utf-8").strip() != m.get("version"):
        issue(result, "CLEAN_INSTALL_VERSION_MISMATCH")
    return m, actual, []



def short_version_code(version: str) -> str:
    m = re.search(r"\.(35[a-z0-9]+|231[a-z0-9]+)(?:-|$)", version, re.I)
    return m.group(1).lower() if m else ""


def check_version_registry(path: pathlib.Path | None, result: dict) -> None:
    if path is None:
        gap(result, "VERSION_REGISTRY_NOT_CHECKED")
        return
    registry = json.loads(path.read_text(encoding="utf-8"))
    if registry.get("format") != "sokna-release-version-ledger-v1":
        issue(result, "VERSION_REGISTRY_FORMAT_INVALID")
        return
    code = short_version_code(str(result.get("version") or ""))
    if code in registry.get("quarantined_short_versions", []):
        issue(result, "KNOWN_SHORT_VERSION_QUARANTINED")
    kind = str(result.get("kind", "unknown"))
    component = kind.split("-")[0]
    for row in registry.get("rejected", []):
        if (row.get("component") == component and row.get("kind") == kind
            and row.get("sha256") == result.get("sha256")):
            issue(result, "KNOWN_REJECTED_ARCHIVE_SHA")
    for row in (registry.get("released", []) + registry.get("observed", [])):
        if row.get("component") != component or row.get("kind") != kind:
            continue
        same_id = row.get("version") == result.get("version") or (code and row.get("short_code") == code)
        if same_id and row.get("sha256") != result.get("sha256"):
            issue(result, "RELEASE_VERSION_IDENTITY_COLLISION")
    if registry.get("historical_complete") is not True:
        gap(result, "HISTORICAL_RELEASE_VERSION_LEDGER_INCOMPLETE")


def check_route_evidence(path: pathlib.Path | None, digest: str, baselines: list[tuple], result: dict) -> None:
    if result["kind"] != "public-update":
        return
    if path is None:
        gap(result, "PUBLIC_TWO_ROUTE_SAME_SHA_NOT_PROVEN")
        return
    evidence = json.loads(path.read_text(encoding="utf-8"))
    if (evidence.get("format") != "sokna-public-two-route-evidence-v1"
        or evidence.get("package_sha256") != digest):
        issue(result, "PUBLIC_ROUTE_EVIDENCE_PACKAGE_SHA_MISMATCH")
        return
    witnessed = set()
    for case in evidence.get("baselines", []):
        identity = case.get("identity", {})
        key = base_key("public", identity)
        if key not in baselines or key in witnessed:
            issue(result, "PUBLIC_ROUTE_EVIDENCE_BASELINE_INVALID")
        witnessed.add(key)
        for route in ("routine", "emergency"):
            r = case.get(route, {})
            if (r.get("package_sha256") != digest
                or r.get("status") != "PASS"
                or not r.get("evidence_url")):
                issue(result, "PUBLIC_TWO_ROUTE_EVIDENCE_MISSING_OR_DIVERGENT")
    if not baselines or witnessed != set(baselines):
        issue(result, "PUBLIC_TWO_ROUTE_BASELINE_COVERAGE_INCOMPLETE")
    gap(result, "PUBLIC_ROUTE_DECLARATION_NOT_INDEPENDENT_HOST_PROOF")

def audit(path: pathlib.Path, expected_kind="auto", expected_sha=None, baseline_catalog=None,
          paired=None, source_dir=None, accepted=None,
          registry=None, routes=None) -> dict:
    result = {
        "package": path.name, "kind": "unknown", "version": None,
        "sha256": "", "static": "FAIL", "release_authorized": False,
        "errors": [], "not_proven": [], "baseline_count": 0,
        "scope": "read-only archive inspection; NOT native host installation",
    }
    try:
        data = path.read_bytes()
        result["sha256"] = sha(data)
        if expected_sha and expected_sha.lower() != result["sha256"]:
            issue(result, "PINNED_PACKAGE_SHA_MISMATCH")
        with zipfile.ZipFile(io.BytesIO(data)) as z:
            files = hashes(z, result)
            if "release-set.json" in files:
                m, payload, keys = check_local_release(z, files, result)
            elif "sokna-install-manifest.json" in files:
                m, payload, keys = check_clean(z, files, "local", result)
            elif "manifest.json" in files:
                top = safe_json(z, "manifest.json")
                if top.get("format") == "sokna-public-deploy-v1":
                    m, payload, keys = check_clean(z, files, "public", result)
                elif top.get("format") == "sokna-component-package-v1" and top.get("component") == "public-edge":
                    m, payload, keys = check_public_update(z, files, result)
                elif top.get("format") == "sokna-component-package-v1" and top.get("component") == "local":
                    m, payload, keys = check_local_legacy_update(z, files, result)
                else:
                    issue(result, "UNSUPPORTED_RELEASE_FORMAT_OR_COMPONENT")
                    m, payload, keys = {}, {}, []
            elif expected_kind == "public-clean" and "VERSION.txt" in files:
                m, payload, keys = check_public_root_clean(z, files, result)
            else:
                issue(result, "RELEASE_MANIFEST_NOT_AT_ROOT")
                m, payload, keys = {}, {}, []
        if expected_kind != "auto" and result["kind"] != expected_kind:
            issue(result, "PACKAGE_KIND_MISMATCH")
        if result["version"] and re.search(r"\.35gg(?:-|$)", str(result["version"]), re.I):
            issue(result, "KNOWN_35GG_COLLISION_QUARANTINED")
        check_version_registry(registry, result)
        check_route_evidence(routes, result["sha256"], keys, result)
        if result["kind"].endswith("-update"):
            if baseline_catalog:
                catalog = json.loads(baseline_catalog.read_text(encoding="utf-8"))
                catalog_keys = []
                for row in catalog.get("entries", []):
                    if not isinstance(row, dict) or row.get("origin") not in ("full", "delta", "adoption"):
                        issue(result, "BASELINE_CATALOG_ORIGIN_MISSING")
                        continue
                    if not HEX64.fullmatch(str(row.get("source_archive_sha256", ""))):
                        issue(result, "BASELINE_CATALOG_SOURCE_HASH_MISSING")
                    catalog_keys.append(base_key(result["kind"].split("-")[0], row.get("identity", {})))
                if not catalog_keys or not set(catalog_keys).issubset(set(keys)):
                    issue(result, "VERIFIED_HISTORICAL_BASELINE_NOT_SUPPORTED")
                if len(catalog_keys) != len(set(catalog_keys)):
                    issue(result, "BASELINE_CATALOG_DUPLICATE")
            else:
                gap(result, "FULL_DELTA_ORIGIN_CATALOG_NOT_AUDITED")
        if source_dir:
            for rel, (digest, _) in payload.items():
                if rel in ("resources/build-provenance.json", "resources/release-identity-v1.json"):
                    continue
                source = source_dir / rel
                if not source.is_file() or sha(source.read_bytes()) != digest:
                    issue(result, "PACKAGE_DIFFERS_FROM_DECLARED_SOURCE")
                    break
        else:
            gap(result, "CANONICAL_SOURCE_BYTE_EQUIVALENCE_NOT_PROVEN")
        if paired:
            other_kind = "public-clean" if result["kind"] == "public-update" else "auto"
            other = audit(paired, expected_kind=other_kind, source_dir=None)
            if other["static"] == "FAIL":
                issue(result, "PAIRED_PACKAGE_INVALID")
            if other["version"] != result["version"]:
                issue(result, "UPDATE_CLEAN_TARGET_VERSION_MISMATCH")
            if not (result["kind"].endswith("-clean") ^ other["kind"].endswith("-clean")):
                issue(result, "PAIR_MUST_BE_CLEAN_AND_UPDATE")
            with zipfile.ZipFile(paired) as z:
                if other["kind"] == "local-update":
                    with zipfile.ZipFile(io.BytesIO(z.read("artifacts/local/web.zip"))) as i:
                        o = hashes(i, result)
                elif other["kind"] == "public-update":
                    o = {n[8:]: v for n, v in hashes(z, result).items() if n.startswith("payload/")}
                else:
                    o = {n: v for n, v in hashes(z, result).items() if n not in {"manifest.json", "sokna-install-manifest.json"}}
            common = set(payload) & set(o)
            if not common or any(payload[k] != o[k] for k in common):
                issue(result, "UPDATE_CLEAN_SHARED_CODE_MISMATCH")
        else:
            gap(result, "UPDATE_CLEAN_EQUIVALENCE_NOT_PROVEN")
        if accepted:
            matrix = json.loads(accepted.read_text(encoding="utf-8"))
            rows = matrix.get("required_files", [])
            if not isinstance(rows, list) or not rows:
                issue(result, "ACCEPTED_FIX_MATRIX_EMPTY")
            for row in rows:
                if not isinstance(row, dict) or not row.get("id") or not row.get("path"):
                    issue(result, "ACCEPTED_FIX_ROW_INVALID")
                    continue
                if row["path"] not in payload or payload[row["path"]][0] != row.get("sha256"):
                    issue(result, "ACCEPTED_FIX_FILE_MISSING_OR_CHANGED")
        else:
            gap(result, "ACCEPTED_FIX_PRESERVATION_NOT_PROVEN")
        gap(result, "REAL_WINDOWS_OR_PUBLIC_HOST_STAGE_ACTIVATE_READBACK_NOT_RUN_BY_THIS_TOOL")
        gap(result, "DB_BACKUP_MIGRATION_AND_RECOVERY_NOT_RUN_BY_THIS_TOOL")
        gap(result, "BUSINESS_AND_BROWSER_BEHAVIOR_NOT_RUN_BY_THIS_TOOL")
    except (OSError, KeyError, ValueError, TypeError, AttributeError, RuntimeError, zipfile.BadZipFile, json.JSONDecodeError, UnicodeDecodeError) as exc:
        issue(result, "PACKAGE_INSPECTION_ERROR_" + type(exc).__name__)
    result["errors"].sort()
    result["not_proven"].sort()
    result["static"] = "PASS" if not result["errors"] else "FAIL"
    return result


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("package", type=pathlib.Path)
    ap.add_argument("--kind", choices=("auto", "local-update", "local-legacy-update", "public-update", "local-clean", "public-clean"), default="auto")
    ap.add_argument("--expected-sha256")
    ap.add_argument("--baseline-catalog", type=pathlib.Path)
    ap.add_argument("--source-dir", type=pathlib.Path)
    ap.add_argument("--accepted-fixes", type=pathlib.Path)
    ap.add_argument("--paired-package", type=pathlib.Path)
    ap.add_argument("--version-registry", type=pathlib.Path)
    ap.add_argument("--route-evidence", type=pathlib.Path)
    ap.add_argument("--report", type=pathlib.Path)
    ap.add_argument("--require-delivery-ready", action="store_true",
                    help="Fail unless all host/behavior proof is available; this read-only tool NEVER grants it.")
    args = ap.parse_args()
    result = audit(args.package, args.kind, args.expected_sha256,
                   args.baseline_catalog, args.paired_package, args.source_dir, args.accepted_fixes,
                   args.version_registry, args.route_evidence)
    text = json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True)
    print(text)
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(text + "\n", encoding="utf-8")
    if result["static"] != "PASS":
        return 2
    if args.require_delivery_ready or result["not_proven"]:
        return 3 if args.require_delivery_ready else 0
    return 0


if __name__ == "__main__":
    sys.exit(main())
