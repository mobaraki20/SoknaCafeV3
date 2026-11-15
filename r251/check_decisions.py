#!/usr/bin/env python3
"""Validate durable decision history for the customer-independent Sokna migration work."""
from __future__ import annotations
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parent.parent
doc = json.loads((ROOT / "r251/decisions.json").read_text(encoding="utf-8"))
assert doc.get("format") == "sokna-migration-decision-register-v1"
assert doc.get("schema_version") == 1
assert doc.get("change_policy", {}).get("append_only_history") is True
assert doc.get("change_policy", {}).get("fixtures_never_product_defaults") is True

entries = doc["decisions"]
by_id = {entry["id"]: entry for entry in entries}
assert len(by_id) == len(entries) and len(entries) >= 18, "duplicate or missing decision"
allowed = {"ACTIVE", "SUPERSEDED", "REJECTED", "PROPOSED"}
for entry in entries:
    assert re.fullmatch(r"(?:MIG|OLD)-\d{3}", entry["id"])
    assert entry["scope"] in {"product", "implementation", "deployment-fixture", "historical"}
    assert entry["status"] in allowed
    assert bool(entry.get("summary")) and bool(entry.get("date"))
    target = entry.get("superseded_by")
    if entry["status"] in {"SUPERSEDED", "REJECTED"}:
        assert target in by_id, (entry["id"], "must reference a surviving decision")
        assert by_id[target]["status"] == "ACTIVE", entry["id"]
        assert target != entry["id"]
    else:
        assert target is None, (entry["id"], "active/proposed must not be superseded")
assert all(entry["status"] == "ACTIVE" for entry in entries if entry["id"] in {
    "MIG-001", "MIG-002", "MIG-003", "MIG-004", "MIG-007", "MIG-009", "MIG-010", "MIG-011"
})
for fix in doc["fixtures"]:
    assert fix["kind"] == "regression_only" and fix.get("id")
engine = (ROOT / "apps/local-web/src/Domain/PublicEdge/PublicMoveDecisionEngine.php").read_text(encoding="utf-8")
for fixture in doc["fixtures"]:
    for key in ("source", "target"):
        if fixture.get(key):
            assert fixture[key].lower() not in engine.lower(), "a customer fixture leaked into product code"
for forbidden in ("soknaspace", "soknagroup", "231ff", "35ic", "cafe-alpha", "bistro-beta"):
    assert forbidden not in engine.lower(), f"case-specific text leaked into engine: {forbidden}"
checkpoint = (ROOT / "r251/START-HERE-FA.md").read_text(encoding="utf-8")
assert "DECISIONS-FA.md" in checkpoint and "STATUS.json" in checkpoint
print("R251_DECISIONS_OK active=%s historical=%s fixtures=%s" % (
    sum(e["status"] == "ACTIVE" for e in entries),
    sum(e["status"] in {"SUPERSEDED", "REJECTED"} for e in entries),
    len(doc["fixtures"])
))
