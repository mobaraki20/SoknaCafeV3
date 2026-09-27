#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSV_PATH = ROOT / "docs/product/MASTER_CAPABILITY_MATRIX.csv"
JSON_PATH = ROOT / "docs/product/MASTER_CAPABILITY_MATRIX.json"

REQUIRED_FIELDS = [
    "id", "area", "initial_requirement", "legacy_evidence", "v3_current", "status",
    "final_decision", "required_action", "priority", "owner", "evidence",
    "completion_level", "required_for_product", "required_for_release"
]
ALLOWED_PRIORITIES = {"P0", "P1", "P2", "P3"}
ALLOWED_COMPLETION = {
    "INVENTORY_ONLY", "CORE_COMPLETE", "PRODUCT_OPEN", "PRODUCT_COMPLETE",
    "RELEASE_BLOCKED", "RELEASE_COMPLETE", "SUPERSEDED", "FUTURE_ONLY"
}
ALLOWED_REQUIRED = {"yes", "no"}
EXPECTED_PRIORITY_COUNTS = {"P0": 23, "P1": 15, "P2": 10, "P3": 2}
PRODUCT_CLOSED = {"PRODUCT_COMPLETE", "RELEASE_BLOCKED", "RELEASE_COMPLETE", "SUPERSEDED", "FUTURE_ONLY"}
RELEASE_CLOSED = {"RELEASE_COMPLETE", "SUPERSEDED", "FUTURE_ONLY"}

def fail(msg: str) -> None:
    raise SystemExit("PRODUCT PARITY GATE FAILED: " + msg)

def load_rows() -> list[dict[str,str]]:
    if not CSV_PATH.is_file() or not JSON_PATH.is_file():
        fail("master capability matrix CSV/JSON missing")
    with CSV_PATH.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        if reader.fieldnames != REQUIRED_FIELDS:
            fail(f"CSV fields drifted: {reader.fieldnames!r}")
        rows = list(reader)
    data = json.loads(JSON_PATH.read_text(encoding="utf-8"))
    if data.get("schema_version") != 2 or data.get("status") != "canonical_master_capability_matrix":
        fail("JSON matrix metadata invalid")
    if data.get("rows") != rows:
        fail("CSV and JSON matrices differ")
    return rows

def validate_inventory(rows: list[dict[str,str]]) -> None:
    if len(rows) != 50:
        fail(f"expected 50 capabilities, got {len(rows)}")
    expected_ids = [f"A{i:02d}" for i in range(1,51)]
    ids = [r["id"] for r in rows]
    if ids != expected_ids:
        fail("capability IDs must be exactly A01..A50 in order")
    if len(ids) != len(set(ids)):
        fail("duplicate capability IDs")
    priorities = Counter(r["priority"] for r in rows)
    if dict(priorities) != EXPECTED_PRIORITY_COUNTS:
        fail(f"priority counts drifted: {dict(priorities)}")
    for r in rows:
        rid = r["id"]
        for field in REQUIRED_FIELDS:
            if not str(r.get(field, "")).strip():
                fail(f"{rid}: empty {field}")
        if r["priority"] not in ALLOWED_PRIORITIES:
            fail(f"{rid}: invalid priority {r['priority']}")
        if r["completion_level"] not in ALLOWED_COMPLETION:
            fail(f"{rid}: invalid completion_level {r['completion_level']}")
        if r["required_for_product"] not in ALLOWED_REQUIRED or r["required_for_release"] not in ALLOWED_REQUIRED:
            fail(f"{rid}: invalid required flag")
        if r["required_for_release"] == "yes" and r["required_for_product"] != "yes":
            fail(f"{rid}: release-required capability must also be product-required")
        unresolved_markers = {"UNKNOWN", "TBD", "UNRESOLVED"}
        if any(str(v).strip().upper() in unresolved_markers for v in r.values()):
            fail(f"{rid}: unresolved marker remains in canonical matrix")

def validate_product(rows: list[dict[str,str]]) -> None:
    open_rows = [r for r in rows if r["required_for_product"] == "yes" and r["completion_level"] not in PRODUCT_CLOSED]
    if open_rows:
        summary = ", ".join(f"{r['id']}={r['completion_level']}" for r in open_rows[:20])
        if len(open_rows) > 20: summary += f", ... +{len(open_rows)-20}"
        fail(f"{len(open_rows)} product-required capabilities still open: {summary}")

def validate_release(rows: list[dict[str,str]]) -> None:
    validate_product(rows)
    open_rows = [r for r in rows if r["required_for_release"] == "yes" and r["completion_level"] not in RELEASE_CLOSED]
    if open_rows:
        summary = ", ".join(f"{r['id']}={r['completion_level']}" for r in open_rows[:20])
        if len(open_rows) > 20: summary += f", ... +{len(open_rows)-20}"
        fail(f"{len(open_rows)} release-required capabilities not RELEASE_COMPLETE: {summary}")

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--mode", choices=["inventory", "product", "release"], default="inventory")
    args = ap.parse_args()
    rows = load_rows()
    validate_inventory(rows)
    if args.mode in {"product", "release"}:
        validate_product(rows)
    if args.mode == "release":
        validate_release(rows)
    counts = Counter(r["completion_level"] for r in rows)
    print(f"PASS product parity gate mode={args.mode} rows={len(rows)} completion={dict(counts)}")

if __name__ == "__main__":
    main()
