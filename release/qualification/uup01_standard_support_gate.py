#!/usr/bin/env python3
"""UUP-01: permanent fail-closed release evidence gate for STANDARD support exports.

No host access, new collection scripts, private credentials or write operation.
The gate is intentionally not an installation authorization: real activation,
backup consistency and forward recovery still require independently attested UAT.
"""
from __future__ import annotations
import argparse
import gzip
import hashlib
import json
import re
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

SHA = re.compile(r"^[a-f0-9]{64}$")
EXPECTED_OWNER = {
    "src/Emergency/" + name for name in (
        "PublicUpdateException.php", "EmergencyAccessService.php",
        "PairingSecretStore.php", "PublicTakeoverService.php",
        "PublicUpdateService.php"
    )
}
EXPECTED_SERVICES = {"runtime", "print-agent", "maintenance"}


def obj(value):
    return value if isinstance(value, dict) else {}


def present_hash(value):
    return isinstance(value, str) and SHA.fullmatch(value.lower()) is not None


def parse_json(data, label):
    try:
        value = json.loads(data)
        if not isinstance(value, dict):
            raise ValueError("JSON root is not an object")
        return value
    except (ValueError, UnicodeDecodeError) as e:
        raise ValueError(label + ": " + type(e).__name__) from e


def read_zip_bundle(path: Path):
    try:
        with zipfile.ZipFile(path) as z:
            names = {}
            for info in z.infolist():
                name = info.filename.replace("\\", "/").rstrip("/")
                if not name or info.is_dir():
                    continue
                segments = name.split("/")
                if name.startswith("/") or ":" in segments[0] or \
                        any(x in ("", ".", "..") for x in segments) or name in names:
                    raise ValueError("unsafe or duplicated support archive path")
                names[name] = info.filename
            if z.testzip() is not None:
                raise ValueError("support ZIP CRC mismatch")
            if "support-v2-summary.json" not in names:
                raise ValueError("ordinary Windows support v2 summary missing")
            summary = parse_json(z.read(names["support-v2-summary.json"]), "windows summary")
            inventory = None
            if "windows/universal-source-inventory.json" in names:
                inventory = parse_json(z.read(names["windows/universal-source-inventory.json"]), "windows source inventory")
            return summary, inventory
    except (OSError, zipfile.BadZipFile, RuntimeError) as e:
        raise ValueError("Windows support archive unreadable") from e


def assess(windows_zip: Path, public_json: Path, local_gzip: Path | None = None,
           max_hours: int = 72) -> dict:
    blockers: list[str] = []
    def block(code):
        if code not in blockers:
            blockers.append(code)

    windows, inventory = read_zip_bundle(windows_zip)
    public = parse_json(public_json.read_bytes(), "Public support")
    if windows.get("format") != "sokna-windows-support-v2":
        block("WINDOWS_SUPPORT_FORMAT_UNVERIFIED")
    if public.get("format") != "sokna-public-operator-support-v1":
        block("PUBLIC_SUPPORT_FORMAT_UNVERIFIED")
    if windows.get("completeness") != "complete":
        block("WINDOWS_SUPPORT_INCOMPLETE")
    if windows.get("collection_errors"):
        block("WINDOWS_COLLECTION_ERRORS")

    snapshot = obj(public.get("system"))
    health = obj(snapshot.get("health"))
    routine = obj(snapshot.get("routine_update"))
    if snapshot.get("ok") is not True or health.get("ok") is not True:
        block("PUBLIC_HEALTH_UNVERIFIED")
    if health.get("pending_migrations") != 0:
        block("PUBLIC_PENDING_MIGRATIONS_OR_UNKNOWN")
    actual_version = health.get("version")
    if not actual_version or actual_version == "unknown" or \
            actual_version != routine.get("current_version") or \
            actual_version != routine.get("active_version"):
        block("PUBLIC_DISK_ROUTINE_VERSION_DRIFT")
    emergency_version = obj(health.get("update")).get("active_version")
    if not emergency_version or emergency_version != actual_version:
        block("PUBLIC_EMERGENCY_ROUTINE_OWNER_DRIFT")
    if routine.get("managed_tree_matches_expected") is not True or \
            not present_hash(routine.get("managed_tree_sha256")) or \
            routine.get("managed_tree_sha256") != routine.get("expected_managed_tree_sha256"):
        block("PUBLIC_MANAGED_TREE_NOT_VERIFIED")
    if isinstance(routine.get("db_forward_fence"), dict):
        block("PUBLIC_DB_FORWARD_RECOVERY_FENCE")
    if isinstance(routine.get("pending_verification"), dict) or \
            isinstance(routine.get("activation_journal"), dict) or \
            routine.get("interrupted_activation") is True:
        block("PUBLIC_LIFECYCLE_IN_PROGRESS")
    if routine.get("protocol") != 2 or routine.get("format") != "sokna-public-delta-v2":
        block("PUBLIC_ROUTINE_PROTOCOL_UNVERIFIED")

    owner = obj(snapshot.get("stable_emergency_owner"))
    files = owner.get("files")
    if owner.get("status") != "verified" or \
            not isinstance(files, list) or len(files) != 5 or \
            {obj(x).get("path") for x in files} != EXPECTED_OWNER or \
            any(obj(x).get("status") != "verified" or not present_hash(obj(x).get("sha256"))
                for x in files):
        block("PUBLIC_STABLE_EMERGENCY_OWNER_UNVERIFIED")

    source_sha = None
    local_version = None
    if not inventory or inventory.get("format") != "sokna-support-universal-fingerprints-v1":
        block("WINDOWS_STANDARD_SUPPORT_SOURCE_INVENTORY_MISSING")
    else:
        services = inventory.get("services")
        service_list = [obj(x) for x in services] if isinstance(services, list) else []
        verified = {x.get("component") for x in service_list
                    if x.get("status") == "verified" and present_hash(x.get("sha256"))}
        if not EXPECTED_SERVICES <= verified:
            block("WINDOWS_SERVICE_BINARIES_NOT_FINGERPRINTED")
        local = obj(inventory.get("local"))
        matches = local.get("matches")
        matches = matches if isinstance(matches, list) else []
        if local.get("status") != "verified" or len(matches) != 1 or \
                obj(matches[0]).get("status") != "verified" or \
                not present_hash(obj(obj(matches[0]).get("source")).get("sha256")):
            block("WINDOWS_LOCAL_SOURCE_IDENTITY_AMBIGUOUS")
        else:
            source_sha = obj(matches[0]["source"]).get("sha256").lower()
            local_version = matches[0].get("version")

    local_bundle_ok = False
    if local_gzip is None:
        block("LOCAL_ORDINARY_SUPPORT_NOT_PROVIDED")
    else:
        try:
            with gzip.open(local_gzip, "rb") as f:
                support = parse_json(f.read(25_000_001), "Local standard support")
            if support.get("format") != "sokna-support-bundle-v1":
                block("LOCAL_SUPPORT_FORMAT_UNVERIFIED")
            local_id = obj(support.get("local_source_identity"))
            local_bundle_ok = local_id.get("status") == "verified" and \
                    present_hash(local_id.get("sha256")) and \
                    local_id.get("version_coherence") == "match" and \
                    isinstance(local_id.get("version"), str) and local_id["version"] != "unknown"
            if not local_bundle_ok:
                block("LOCAL_INSTALLED_VERSION_COHERENCE_UNVERIFIED")
            if local_bundle_ok and source_sha and source_sha != local_id.get("sha256"):
                block("WINDOWS_LOCAL_SUPPORT_IDENTITY_DISAGREES")
            if local_bundle_ok and local_version and local_version != local_id.get("version"):
                block("WINDOWS_LOCAL_SUPPORT_VERSION_DISAGREES")
        except (OSError, ValueError, EOFError, gzip.BadGzipFile):
            block("LOCAL_STANDARD_SUPPORT_UNREADABLE")

    # Bounded freshness avoids treating last week's clean system as live host proof.
    for label, value in (
        ("WINDOWS", windows.get("created_at_utc")),
        ("PUBLIC", public.get("generated_at"))
    ):
        try:
            stamp = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
            if stamp.tzinfo is None:
                raise ValueError("naive timestamp")
            hours = (datetime.now(timezone.utc) - stamp.astimezone(timezone.utc)).total_seconds() / 3600
            if not (0 <= hours <= max_hours):
                block(label + "_SUPPORT_NOT_FRESH")
        except ValueError:
            block(label + "_SUPPORT_TIMESTAMP_UNVERIFIED")

    # Support bundles can prove host observations, NOT actual activation/
    # recovery or backup consistency. Never turn this analyzer into an
    # executable permit, even if a caller edits these input JSON files.
    block("REAL_MULTI_BASELINE_ACTIVATION_RECOVERY_UAT_NOT_ATTESTED")
    return {
        "format": "sokna-uup01-standard-support-release-gate-v1",
        "release_scope": "universal_multi_baseline_not_per_machine",
        "decision": "BLOCK_RELEASE",
        "installation_authorized": False,
        "host_mutation_performed": False,
        "observations": {
            "public_disk_version": actual_version,
            "public_routine_version": routine.get("active_version"),
            "public_emergency_recorded_version": emergency_version,
            "public_db_forward_fence_active": isinstance(routine.get("db_forward_fence"), dict),
            "windows_support_complete": windows.get("completeness") == "complete",
            "windows_standard_source_inventory_available": inventory is not None,
            "windows_local_updater_sha256": source_sha,
            "local_support_coherent": local_bundle_ok,
            "stable_emergency_owner_proven": "PUBLIC_STABLE_EMERGENCY_OWNER_UNVERIFIED" not in blockers
        },
        "blockers": sorted(blockers)
    }


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--windows-support", type=Path, required=True)
    p.add_argument("--public-support", type=Path, required=True)
    p.add_argument("--local-support", type=Path)
    p.add_argument("--output", type=Path)
    args = p.parse_args()
    try:
        result = assess(args.windows_support, args.public_support, args.local_support)
    except (OSError, ValueError, KeyError) as e:
        print("BLOCK_RELEASE: input_unreadable " + type(e).__name__, file=sys.stderr)
        return 2
    raw = json.dumps(result, ensure_ascii=False, indent=2) + "\n"
    if args.output:
        args.output.write_text(raw, encoding="utf-8")
    else:
        print(raw, end="")
    return 1  # This command is a publishing gate. Never authorize install.


if __name__ == "__main__":
    raise SystemExit(main())
