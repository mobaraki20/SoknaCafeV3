#!/usr/bin/env python3
"""Validate V3 architecture/migration metadata without pretending product code exists yet."""

from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

REQUIRED_FILES = [
    "README.md",
    "START_HERE.md",
    "ARCHITECTURE.md",
    "PROJECT_LINEAGE.md",
    "UI_DESIGN_SYSTEM.md",
    "apps/local-web/README.md",
    "apps/public/README.md",
    "windows/runtime/README.md",
    "windows/print-agent/README.md",
    "platform/README.md",
    "contracts/README.md",
    "contracts/manifest.json",
    "contracts/local-public-realtime/README.md",
    "contracts/local-public-deferred/README.md",
    "contracts/runtime-api/README.md",
    "contracts/print-agent-api/README.md",
    "packaging/README.md",
    "docs/adr/README.md",
    "docs/migration/README.md",
    "docs/migration/MIGRATION_MATRIX.csv",
    "docs/ui-design-system/COMPONENT_REGISTRY.json",
    "docs/ui-design-system/PRODUCT_LANGUAGE_FA.md",
    "docs/ui-design-system/LEGACY_UI_DEBT_BASELINE.json",
    "COMPONENTS.json",
    "docs/product/COMPLETION_STATUS_POLICY_FA.md",
    "docs/product/MASTER_CAPABILITY_MATRIX.csv",
    "docs/product/MASTER_CAPABILITY_MATRIX.json",
    "tests/component-registry-gate.py",
    "tests/product-parity-gate.py",
]

MATRIX_HEADERS = [
    "legacy_scope",
    "legacy_owner_path",
    "capability",
    "v3_owner",
    "treatment",
    "data_owner",
    "contract_boundary",
    "behavior_ui_rule",
    "tests_gates",
    "legacy_cleanup",
    "risk_notes",
    "status",
    "completion_level",
]

ALLOWED_TREATMENTS = {
    "migrate",
    "refactor_then_migrate",
    "split",
    "preserve_external",
    "replace",
    "retire",
    "reference_only",
}
ALLOWED_STATUSES = {"inventory", "ready", "in_progress", "migrated", "blocked"}
ALLOWED_COMPLETION_LEVELS = {
    "INVENTORY_ONLY", "CORE_COMPLETE", "PRODUCT_OPEN", "PRODUCT_COMPLETE",
    "RELEASE_BLOCKED", "RELEASE_COMPLETE", "SUPERSEDED", "FUTURE_ONLY"
}
KNOWN_OWNER_TOKENS = {
    "apps/local-web",
    "apps/public",
    "windows/runtime",
    "windows/print-agent",
    "platform",
    "contracts",
    "packaging",
    "tests",
    "historical repository",
    "UI_DESIGN_SYSTEM.md",
    "docs/ui-design-system",
}

REQUIRED_CONTRACTS = {
    "local-public-realtime": "contracts/local-public-realtime/README.md",
    "local-public-deferred": "contracts/local-public-deferred/README.md",
    "runtime-api": "contracts/runtime-api/README.md",
    "print-agent-api": "contracts/print-agent-api/README.md",
}

COMPONENT_REQUIRED = {
    "local": ["apps/local-web/README.md"],
    "public": ["apps/public/README.md"],
    "runtime": ["windows/runtime/README.md", "contracts/runtime-api/README.md"],
    "print": ["windows/print-agent/README.md", "contracts/print-agent-api/README.md"],
    "platform": ["platform/README.md"],
    "contracts": ["contracts/README.md", "contracts/manifest.json"],
    "packaging": ["packaging/README.md"],
    "ui": ["UI_DESIGN_SYSTEM.md", "docs/ui-design-system/COMPONENT_REGISTRY.json"],
    "migration": ["docs/migration/README.md", "docs/migration/MIGRATION_MATRIX.csv"],
}


def fail(message: str) -> None:
    raise SystemExit(f"FOUNDATION VALIDATION FAILED: {message}")


def read_text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8-sig")


def validate_files() -> None:
    missing = [p for p in REQUIRED_FILES if not (ROOT / p).is_file()]
    if missing:
        fail("missing required files: " + ", ".join(missing))


def validate_authority() -> None:
    ui = read_text("UI_DESIGN_SYSTEM.md")
    lineage = read_text("PROJECT_LINEAGE.md")
    for name, text in [("UI_DESIGN_SYSTEM.md", ui), ("PROJECT_LINEAGE.md", lineage)]:
        if "SCDS-CANONICAL-2026-R1" not in text:
            fail(f"{name} does not name SCDS-CANONICAL-2026-R1")
    if "a46435cca57df5bd5b9770efd0bb95390528aa05" not in lineage:
        fail("PROJECT_LINEAGE.md lost the selected dev39 baseline SHA")
    if "work/reconcile-dev39" not in lineage:
        fail("PROJECT_LINEAGE.md lost the selected dev39 branch")

    registry = json.loads(read_text("docs/ui-design-system/COMPONENT_REGISTRY.json"))
    if registry.get("design_system_id") != "SCDS-CANONICAL-2026-R1":
        fail("component registry design_system_id is not canonical R1")
    if registry.get("previous_sokna_design_systems") != "rejected_not_authoritative":
        fail("component registry does not reject previous SOKNA Design System authority")
    ids = [entry.get("id") for entry in registry.get("components", [])]
    if not ids or len(ids) != len(set(ids)):
        fail("component registry IDs are empty or duplicated")

    debt = json.loads(read_text("docs/ui-design-system/LEGACY_UI_DEBT_BASELINE.json"))
    if debt.get("policy") != "ratchet_down_only_during_legacy_migration":
        fail("legacy UI debt policy must remain ratchet-down-only")


def validate_contracts() -> None:
    manifest = json.loads(read_text("contracts/manifest.json"))
    contracts = manifest.get("contracts")
    if not isinstance(contracts, list) or not contracts:
        fail("contracts/manifest.json has no contracts")

    by_id = {}
    for entry in contracts:
        cid = entry.get("id")
        if not cid or cid in by_id:
            fail(f"contract id is missing or duplicated: {cid!r}")
        by_id[cid] = entry
        version = entry.get("version", "")
        if not version.endswith("-draft"):
            fail(f"foundation contract {cid} must remain explicitly draft until executable compatibility gates exist")
        if entry.get("compatibility") != "draft-no-stability-claim":
            fail(f"foundation contract {cid} makes an unsupported stability claim")
        spec = entry.get("spec")
        if not spec or not (ROOT / spec).is_file():
            fail(f"contract {cid} references missing spec {spec!r}")

    missing = sorted(set(REQUIRED_CONTRACTS) - set(by_id))
    if missing:
        fail("contract manifest missing: " + ", ".join(missing))

    for cid, spec in REQUIRED_CONTRACTS.items():
        if by_id[cid].get("spec") != spec:
            fail(f"contract {cid} spec mismatch")

    if by_id["local-public-realtime"].get("state_machine") == by_id["local-public-deferred"].get("state_machine"):
        fail("realtime and deferred contracts must remain distinct state machines")

    runtime = read_text("contracts/runtime-api/README.md")
    if "never writes Local business tables directly" not in runtime:
        fail("Runtime contract lost the no-direct-business-DB-write boundary")

    print_contract = read_text("contracts/print-agent-api/README.md")
    if "separate Windows deployable" not in print_contract:
        fail("Print Agent contract lost separate-deployable ownership")


def validate_matrix() -> None:
    path = ROOT / "docs/migration/MIGRATION_MATRIX.csv"
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames != MATRIX_HEADERS:
            fail(f"migration matrix headers differ: {reader.fieldnames!r}")
        rows = list(reader)

    if len(rows) < 20:
        fail(f"migration matrix unexpectedly small ({len(rows)} rows)")

    seen = set()
    for index, row in enumerate(rows, start=2):
        scope = row["legacy_scope"].strip()
        if not scope:
            fail(f"row {index}: missing legacy_scope")
        if scope in seen:
            fail(f"row {index}: duplicate legacy_scope {scope!r}")
        seen.add(scope)

        treatment = row["treatment"].strip()
        status = row["status"].strip()
        if treatment not in ALLOWED_TREATMENTS:
            fail(f"row {index} ({scope}): invalid treatment {treatment!r}")
        if status not in ALLOWED_STATUSES:
            fail(f"row {index} ({scope}): invalid status {status!r}")
        completion_level = row["completion_level"].strip()
        if completion_level not in ALLOWED_COMPLETION_LEVELS:
            fail(f"row {index} ({scope}): invalid completion_level {completion_level!r}")

        for field in MATRIX_HEADERS:
            if not row[field].strip():
                fail(f"row {index} ({scope}): empty {field}")

        owner = row["v3_owner"]
        if not any(token in owner for token in KNOWN_OWNER_TOKENS):
            fail(f"row {index} ({scope}): v3_owner does not reference a known V3 owner: {owner!r}")

    required_scopes = {
        "Windows Runtime",
        "Printing - OS execution",
        "Public Edge",
        "Realtime relay",
        "Deferred-safe work",
        "Installer/bootstrapper",
        "UI Design System",
        "Local database/schema",
    }
    missing_scopes = sorted(required_scopes - seen)
    if missing_scopes:
        fail("migration matrix missing critical scopes: " + ", ".join(missing_scopes))


def validate_component(component: str | None) -> None:
    if not component:
        return
    required = COMPONENT_REQUIRED.get(component)
    if required is None:
        fail(f"unknown component gate {component!r}")
    for path in required:
        if not (ROOT / path).is_file():
            fail(f"component {component}: missing {path}")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--component", choices=sorted(COMPONENT_REQUIRED))
    args = parser.parse_args()

    validate_files()
    validate_authority()
    validate_contracts()
    validate_matrix()
    validate_component(args.component)
    suffix = f" ({args.component})" if args.component else ""
    print(f"V3 foundation validation passed{suffix}.")


if __name__ == "__main__":
    main()
