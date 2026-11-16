#!/usr/bin/env python3
"""Read-only database migration gate for source and historical installable baseline.

No database access, no application code edits, no DDL execution, no inference that
a same-number migration is compatible. Companions: disposable MariaDB replay
and the real Maintenance/Public host acceptance.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import pathlib
import re
import sys
import zipfile

from source_gate import inspect as inspect_source


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def issue(report: dict, message: str) -> None:
    if message not in report["errors"]:
        report["errors"].append(message)


def pending(report: dict, message: str) -> None:
    if message not in report["not_proven"]:
        report["not_proven"].append(message)


def checked_entries(blob: bytes, label: str) -> dict[str, bytes]:
    result = {}
    with zipfile.ZipFile(io.BytesIO(blob)) as z:
        if z.testzip() is not None:
            raise ValueError("ZIP_CRC_FAILED_" + label)
        if len(z.infolist()) > 12000:
            raise ValueError("ZIP_ENTRIES_EXCEEDED_" + label)
        seen = set()
        for e in z.infolist():
            path = e.filename
            if e.is_dir():
                continue
            if (not path or "\\" in path or path.startswith("/")
                or any(segment in ("", ".", "..") for segment in path.split("/"))
                or path in seen):
                raise ValueError("UNSAFE_ZIP_PATH_" + label)
            seen.add(path)
            if e.file_size > 128 * 1024 * 1024:
                raise ValueError("ZIP_ENTRY_TOO_LARGE_" + label)
            result[path] = z.read(path)
    return result


def source_files(path: pathlib.Path, kind: str) -> dict[str, bytes]:
    entries = checked_entries(path.read_bytes(), kind)
    if kind == "source":
        if "VERSION.txt" not in entries or "database/migrations" not in "\n".join(entries):
            raise ValueError("SOURCE_EXPECTED_FLAT_ARCHIVE")
        return entries
    if kind == "local-maintenance":
        if set(entries) != {"release-set.json", "artifacts/local/web.zip"}:
            raise ValueError("LOCAL_RELEASE_SET_EXPECTED")
        return checked_entries(entries["artifacts/local/web.zip"], "local-inner")
    if kind == "public-update":
        if "manifest.json" not in entries or not any(k.startswith("payload/") for k in entries):
            raise ValueError("PUBLIC_COMPONENT_ARCHIVE_EXPECTED")
        return {k[8:]: value for k, value in entries.items() if k.startswith("payload/")}
    raise ValueError("UNRECOGNIZED_BASELINE_FORMAT")


def migrations(files: dict[str, bytes]) -> dict[str, str]:
    paths = sorted(n for n in files
                   if n.startswith("database/migrations/") and n.lower().endswith(".sql"))
    return {name: sha(files[name]) for name in paths}


def manifest_digest(m: dict[str, str]) -> str:
    return sha("".join(path + "\0" + digest + "\n" for path, digest in sorted(m.items())).encode())


DESTRUCTIVE = re.compile(
    r"\b(?:DROP\s+(?:TABLE|DATABASE|COLUMN)|TRUNCATE\s+TABLE|DELETE\s+FROM|"
    r"RENAME\s+TABLE|ALTER\s+TABLE\b[^;]*\bDROP\b)\b", re.IGNORECASE | re.DOTALL
)


def remove_sql_comments(value: str) -> str:
    return re.sub(r"/\*.*?\*/|--[^\n]*|#[^\n]*", "", value, flags=re.DOTALL)


def analyze(source: pathlib.Path, component: str, checkpoint: str,
            register: pathlib.Path, baseline: pathlib.Path | None = None,
            baseline_kind: str | None = None, baseline_sha256: str | None = None) -> dict:
    report = {
        "format": "sokna-database-migration-admission-v1",
        "component": component,
        "checkpoint": checkpoint,
        "source_zip_sha256": "",
        "migration_count": 0,
        "migration_manifest_sha256": "",
        "baseline_migration_count": None,
        "baseline_zip_sha256": None,
        "new_migrations": [],
        "changed_previous_migrations": [],
        "missing_previous_migrations": [],
        "errors": [],
        "not_proven": [],
        "static": "FAIL",
        "db_install_qualified": False,
        "live_db_read_or_mutation": False,
    }
    try:
        pinned = inspect_source(source, checkpoint, component, register)
        if pinned["source_integrity"] != "PASS":
            issue(report, "PINNED_SOURCE_NOT_VERIFIED")
            report["errors"] += pinned["errors"]
            return report
        report["source_zip_sha256"] = pinned["sha256"]
        new_src = source_files(source, "source")
        new = migrations(new_src)
        report["migration_count"] = len(new)
        report["migration_manifest_sha256"] = manifest_digest(new)
        expected = pinned["migration_count"]
        if len(new) != expected:
            issue(report, "SOURCE_MIGRATION_COUNT_MISMATCH")
        if any(n.lower() != n for n in new):
            issue(report, "MIGRATION_FILENAME_CASE_AMBIGUOUS")
        if any(not new_src[n].strip() for n in new):
            issue(report, "EMPTY_MIGRATION_SQL")
        if baseline is None:
            pending(report, "PREVIOUS_INSTALLED_MIGRATIONS_NOT_COMPARED")
        else:
            if not baseline_kind or not baseline_sha256:
                issue(report, "EXACT_BASELINE_KIND_AND_SHA_REQUIRED")
            elif sha(baseline.read_bytes()) != baseline_sha256.lower():
                issue(report, "HISTORICAL_BASELINE_ZIP_SHA_MISMATCH")
            else:
                old = migrations(source_files(baseline, baseline_kind))
                report["baseline_zip_sha256"] = baseline_sha256.lower()
                report["baseline_migration_count"] = len(old)
                changed = sorted(k for k in set(new) & set(old) if new[k] != old[k])
                missing = sorted(set(old) - set(new))
                added = sorted(set(new) - set(old))
                report["changed_previous_migrations"] = changed
                report["missing_previous_migrations"] = missing
                report["new_migrations"] = added
                if changed:
                    issue(report, "ALREADY_APPLIED_MIGRATION_SQL_CHANGED")
                if missing:
                    issue(report, "ALREADY_APPLIED_MIGRATION_REMOVED")
                if list(new)[:len(old)] != list(old):
                    issue(report, "PREVIOUS_MIGRATION_ORDER_NOT_PREFIX")
                for name in added:
                    if DESTRUCTIVE.search(remove_sql_comments(new_src[name].decode("utf-8", errors="replace"))):
                        issue(report, "NEW_MIGRATION_CONTAINS_POTENTIALLY_DESTRUCTIVE_SQL")
                if added:
                    pending(report, "NEW_MIGRATION_REQUIRES_REAL_UPGRADE_BACKUP_AND_RESTORE_TEST")
        pending(report, "REAL_EXISTING_DATABASE_UPGRADE_NOT_EXECUTED_BY_STATIC_GATE")
        pending(report, "LIVE_BACKUP_RESTORE_AND_FORWARD_FENCE_NOT_PROVEN")
        pending(report, "BUSINESS_ROWS_AND_TRANSACTION_IDENTITY_NOT_VERIFIED_HERE")
    except (OSError, KeyError, TypeError, ValueError, UnicodeError, zipfile.BadZipFile) as exc:
        issue(report, "DB_GATE_INSPECTION_ERROR_" + type(exc).__name__)
    finally:
        report["errors"] = sorted(set(report["errors"]))
        report["not_proven"] = sorted(set(report["not_proven"]))
        report["static"] = "PASS" if not report["errors"] else "FAIL"
    return report


def main() -> int:
    a = argparse.ArgumentParser(description=__doc__)
    a.add_argument("--source", type=pathlib.Path, required=True)
    a.add_argument("--component", choices=("local", "public"), required=True)
    a.add_argument("--checkpoint", required=True)
    a.add_argument("--register", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("source-register.json"))
    a.add_argument("--baseline", type=pathlib.Path)
    a.add_argument("--baseline-kind", choices=("local-maintenance", "public-update", "source"))
    a.add_argument("--baseline-sha256")
    a.add_argument("--require-baseline", action="store_true")
    a.add_argument("--report", type=pathlib.Path)
    args = a.parse_args()
    out = analyze(args.source, args.component, args.checkpoint, args.register,
                  args.baseline, args.baseline_kind, args.baseline_sha256)
    print(json.dumps(out, ensure_ascii=False, indent=2, sort_keys=True))
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(out, ensure_ascii=False, indent=2, sort_keys=True) + "\n")
    if out["static"] != "PASS":
        return 2
    if args.require_baseline and args.baseline is None:
        return 3
    return 0


if __name__ == "__main__":
    sys.exit(main())
