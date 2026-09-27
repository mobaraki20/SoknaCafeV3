#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REG = ROOT / "COMPONENTS.json"
REQUIRED_FIELDS = {
    "workstreams", "owned_paths", "read_only_dependencies", "compatibility_dependencies",
    "tests_gates", "package_artifact", "version_owner", "release_model", "install_model",
    "update_owner", "recovery_owner", "health_surface", "cross_component_writes",
    "must_not_own", "current_state"
}
REQUIRED_COMPONENTS = {
    "local-web", "public-edge", "windows-runtime", "print-agent",
    "windows-services-packaging", "infrastructure-compatibility", "shared-contracts",
    "ui-design-system", "product-governance"
}

def fail(msg: str) -> None:
    raise SystemExit("COMPONENT REGISTRY GATE FAILED: " + msg)

x = json.loads(REG.read_text(encoding="utf-8"))
if x.get("schema_version") != 2:
    fail("schema_version must be 2")
if x.get("status") != "canonical_component_scope_registry":
    fail("registry is not canonical")
rules = x.get("rules", {})
if rules.get("one_active_workstream") is not True:
    fail("one_active_workstream must be true")
if rules.get("daily_execution") != "local_workspace_with_library_checkpoints":
    fail("daily execution policy drifted")
if rules.get("github_transfer") != "final_transfer_only_unless_explicitly_overridden":
    fail("GitHub transfer policy drifted")
components = x.get("components", {})
missing = REQUIRED_COMPONENTS - set(components)
if missing:
    fail("missing components: " + ", ".join(sorted(missing)))

owned_exact: dict[str, str] = {}
for name, c in components.items():
    missing_fields = REQUIRED_FIELDS - set(c)
    if missing_fields:
        fail(f"{name}: missing fields {sorted(missing_fields)}")
    if not c["owned_paths"]:
        fail(f"{name}: no owned_paths")
    if not c["tests_gates"]:
        fail(f"{name}: no tests_gates")
    for p in c["owned_paths"]:
        if p in owned_exact and {name, owned_exact[p]} != {"local-web", "ui-design-system"}:
            fail(f"exact owned path duplicated: {p} -> {owned_exact[p]}, {name}")
        owned_exact[p] = name

if "Local Web payload" not in components["windows-services-packaging"]["must_not_own"]:
    fail("Windows Services packaging may not own Local Web payload")
if components["local-web"]["install_model"] != "browser_setup_wizard":
    fail("Local install model must remain browser_setup_wizard")
if components["public-edge"]["recovery_owner"] != "public_emergency_console":
    fail("Public recovery owner must remain emergency console")
if components["infrastructure-compatibility"]["release_model"] != "external_dependency_compatibility":
    fail("Infrastructure must remain external dependency compatibility")

print("PASS component registry governance")
