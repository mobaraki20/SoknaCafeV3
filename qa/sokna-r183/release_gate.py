#!/usr/bin/env python3
"""Fail-closed SOKNA release-set guard for the publish/build boundary.

A passing static result is not permission to install. That authority remains
exclusively with Maintenance, signed identities and its operational tests.
"""
import argparse
import datetime as dt
import hashlib
import json
import pathlib
import re
import stat
import sys
import zipfile

DENY_STATUS = re.compile(r"(?:^|[_\-\s])(?:UAT|NOT[_\-]?PRODUCTION|NOT[_\-]?FIELD[_\-]?APPROVED|NO[_\-]?PRODUCTION[_\-]?APPROVAL|NOT[_\-]?FOR[_\-]?INSTALL|TEST[_\-]?ONLY|ENGINEERING|SOURCE[_\-]?ONLY|DESIGN)(?:$|[_\-\s])", re.I)
IDENTITY = ("release_id", "version", "managed_tree_sha256", "schema_fingerprint", "migration_head", "authority_epoch_policy")
REQUIRED_LOCAL_QUAL = ("canary", "real_windows", "real_mariadb", "recovery_console_web_down", "business_backup_consistency_real_mariadb")
MAX_ENTRIES = 2000
MAX_TOTAL_UNCOMPRESSED = 1500 * 1024 * 1024
MAX_RATIO = 500


def _load_installed(support_path):
    if support_path is None:
        return None
    with zipfile.ZipFile(support_path) as z:
        matches = [n for n in z.namelist() if n.replace("\\", "/") == "maintenance/installed-release-identity.json"]
        if len(matches) != 1:
            raise ValueError("INSTALLED_IDENTITY_EVIDENCE_MISSING_OR_DUPLICATED")
        content = json.loads(z.read(matches[0]))
    local = content.get("identity", {}).get("local")
    if not isinstance(local, dict) or not all(local.get(k) for k in IDENTITY):
        raise ValueError("SUPPORT_LOCAL_IDENTITY_INVALID")
    return local


def _inspect_zip(z, errors):
    seen = set()
    total = 0
    if len(z.infolist()) > MAX_ENTRIES:
        errors.append("ZIP_ENTRY_LIMIT")
    for info in z.infolist():
        n = info.filename
        normalized = n.replace("\\", "/")
        if n in seen:
            errors.append("ZIP_DUPLICATE_ENTRY")
        seen.add(n)
        if not n or normalized.startswith("/") or ".." in normalized.split("/") or "\\" in n:
            errors.append("ZIP_UNSAFE_PATH")
        if stat.S_IFMT(info.external_attr >> 16) == stat.S_IFLNK:
            errors.append("ZIP_SYMLINK_UNSUPPORTED")
        total += info.file_size
        if info.file_size > 10_000_000 and info.file_size / max(1, info.compress_size) > MAX_RATIO:
            errors.append("ZIP_SUSPICIOUS_RATIO")
    if total > MAX_TOTAL_UNCOMPRESSED:
        errors.append("ZIP_UNCOMPRESSED_LIMIT")
    return seen


def _check_artifact(z, names, obj, errors):
    path = obj.get("path")
    if not path or path not in names:
        errors.append("ARTIFACT_MISSING")
        return
    digest = hashlib.sha256()
    size = 0
    with z.open(path) as fd:
        while chunk := fd.read(1024 * 1024):
            size += len(chunk)
            digest.update(chunk)
    if size != obj.get("size_bytes") or digest.hexdigest() != obj.get("sha256"):
        errors.append("ARTIFACT_DIGEST_OR_SIZE_MISMATCH")


def check(package_path, installed=None, desired_target=None):
    package_path = pathlib.Path(package_path)
    errors = []
    result = {
        "file": package_path.name,
        "size_bytes": package_path.stat().st_size,
        "classification": "unknown",
        "static_gate": "DENY",
        "maintenance_stage_authorized": False,
        "operational_activation_verified": False,
        "reasons": errors,
    }
    try:
        with zipfile.ZipFile(package_path) as z:
            names = _inspect_zip(z, errors)
            if "release-set.json" not in names:
                result["classification"] = "wrapper_or_source_archive"
                result["nested_zips"] = sorted(n for n in names if n.lower().endswith(".zip"))[:12]
                errors.append("ROOT_RELEASE_SET_MISSING")
                return result
            if names != {"release-set.json", "artifacts/local/web.zip"}:
                errors.append("UNEXPECTED_ROOT_FILES_FOR_LOCAL_RELEASE")
            result["classification"] = "local_release_candidate"
            manifest = json.loads(z.read("release-set.json"))
            target = (manifest.get("targets") or {}).get("local") or {}
            result.update(release_set_id=manifest.get("release_set_id"), target_version=target.get("version"),
                          baseline_count=len(manifest.get("supported_baselines") or []))
            if manifest.get("format") != "sokna-maintenance-release-set-v2.1" or manifest.get("schema_version") != "2.1":
                errors.append("RELEASE_SCHEMA_INVALID")
            if manifest.get("release_type") != "local_only":
                errors.append("RELEASE_TYPE_NOT_LOCAL_ONLY")
            if manifest.get("for_install") is not True:
                errors.append("FOR_INSTALL_NOT_TRUE")
            if manifest.get("production_pass") is not True:
                errors.append("PRODUCTION_PASS_NOT_TRUE")
            if DENY_STATUS.search(str(manifest.get("status", ""))):
                errors.append("UAT_STATUS_PROHIBITED")
            for k in IDENTITY:
                if not target.get(k):
                    errors.append("TARGET_" + k.upper() + "_MISSING")
            if desired_target is not None and target.get("version") != desired_target:
                errors.append("TARGET_VERSION_UNEXPECTED")
            qual = manifest.get("qualification") or {}
            for k in REQUIRED_LOCAL_QUAL:
                if qual.get(k) is not True:
                    errors.append("QUALIFICATION_" + k.upper() + "_NOT_PROVEN")
            if not qual.get("failure_matrix_id") or int(qual.get("failure_matrix_cases_required") or 0) < 80:
                errors.append("QUALIFICATION_FAILURE_MATRIX_INVALID")
            backup = manifest.get("pre_update_backup_policy") or {}
            if backup.get("required") is not True or backup.get("on_failure") != "reject_pre_mutation" or backup.get("system_recovery_snapshot_required") is not True:
                errors.append("PRE_UPDATE_BACKUP_POLICY_UNSAFE")
            plan = manifest.get("install_plan") or []
            if not plan or plan[0].get("phase") != "preflight" or plan[0].get("mutation") is not False:
                errors.append("INSTALL_PLAN_UNSAFE_PREFLIGHT")
            baselines = manifest.get("supported_baselines") or []
            if not baselines:
                errors.append("NO_EXACT_SUPPORTED_BASELINES")
            match = installed is None
            if installed is not None:
                match = any(all(((row.get("local") or {}).get(k) == installed.get(k)) for k in IDENTITY) for row in baselines)
                if not match:
                    errors.append("INSTALLED_IDENTITY_NOT_IN_BASELINE_LIST")
            result["installed_identity_match"] = match if installed is not None else None
            artifacts = manifest.get("artifacts") or {}
            if "local-web" not in artifacts:
                errors.append("LOCAL_ARTIFACT_NOT_DECLARED")
            for artifact in artifacts.values():
                _check_artifact(z, names, artifact, errors)
            # Validity of a signature is deliberately not inferred from an alleged signature field.
            result["signature_verified"] = False
            result["status"] = manifest.get("status")
    except (zipfile.BadZipFile, ValueError, KeyError, json.JSONDecodeError, OSError) as exc:
        errors.append("INSPECTION_FAILED_" + type(exc).__name__)
    result["reasons"] = sorted(set(errors))
    if not errors:
        result["static_gate"] = "PASS_STATIC_ONLY"
    return result


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("packages", nargs="+", type=pathlib.Path)
    ap.add_argument("--support-v2", type=pathlib.Path, default=None)
    ap.add_argument("--expect-version", default=None)
    ap.add_argument("--output", type=pathlib.Path, default=None)
    args = ap.parse_args(argv)
    try:
        installed = _load_installed(args.support_v2)
    except Exception as exc:
        print(json.dumps({"error": "SUPPORT_IDENTITY_READ_FAILED", "type": type(exc).__name__}))
        return 3
    checks = [check(path, installed, args.expect_version) for path in args.packages]
    result = {
        "format": "sokna-release-guard-v1",
        "purpose": "offline_prepublication_filter_not_installer",
        "installed_version": installed.get("version") if installed else None,
        "checks": checks,
        "all_static_checks_pass": all(c["static_gate"] == "PASS_STATIC_ONLY" for c in checks),
        "ready_for_production_install": False,
        "not_proven_by_this_tool": ["ECDSA signature", "real stage", "activate", "readback", "database backup restore", "rollback", "production install qualification"],
    }
    text = json.dumps(result, indent=2, ensure_ascii=False)
    print(text)
    if args.output:
        args.output.write_text(text + "\n", encoding="utf-8")
    return 0 if result["all_static_checks_pass"] else 2


if __name__ == "__main__":
    sys.exit(main())
