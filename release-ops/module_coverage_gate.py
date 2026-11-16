#!/usr/bin/env python3
"""Detect unreviewed source modules before an approved Local/Public release.

This is a minimal STRUCTURAL admission gate, not a behavioral/pass claim.
The caller must independently verify full immutable source SHA/tree.
Never mutate product files or an installed host.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import pathlib
import sys
import zipfile


def inspect(source: pathlib.Path, component: str, map_file: pathlib.Path, register: pathlib.Path) -> dict:
    result = {
        "format": "sokna-module-coverage-admission-v1", "component": component,
        "source_sha256": None, "status": "FAIL", "module_inventory_only": True,
        "business_behavior_qualified": False, "production_authorized": False,
        "unexpected": [], "missing": [], "bad_references": [], "errors": []
    }
    try:
        m = json.loads(map_file.read_text(encoding="utf-8"))
        r = json.loads(register.read_text(encoding="utf-8"))
        if m.get("format") != "sokna-module-coverage-map-v1" or len(r.get("rows", [])) < 1:
            raise ValueError("COVERAGE_MAP_OR_REGISTER_INVALID")
        known = {str(row["id"]) for row in r["rows"]}
        if len(known) != len(r["rows"]):
            raise ValueError("DUPLICATE_RISK_IDS")
        if component not in ("local", "public"):
            raise ValueError("UNKNOWN_COMPONENT")
        top_map = m["local_src"] if component == "local" else m["public_src"]
        domain_map = m["local_domain"] if component == "local" else None
        for group in (top_map, domain_map):
            if group is None:
                continue
            if not isinstance(group, dict) or not group:
                raise ValueError("EMPTY_MODULE_MAP")
            for name, ids in group.items():
                if (not name or "/" in name or "\\" in name
                    or not isinstance(ids, list) or not ids):
                    raise ValueError("BAD_MODULE_MAPPING")
                for risk in ids:
                    if risk not in known:
                        result["bad_references"].append(name + ":" + str(risk))
        if not source.is_file():
            raise ValueError("SOURCE_ZIP_MISSING")
        result["source_sha256"] = hashlib.sha256(source.read_bytes()).hexdigest()
        with zipfile.ZipFile(source) as z:
            if z.testzip() is not None:
                raise ValueError("SOURCE_ZIP_CRC_FAILED")
            paths = z.namelist()
            if len(paths) != len(set(paths)):
                raise ValueError("SOURCE_ZIP_DUPLICATE_NAMES")
            if any(x.startswith("/") or "\\" in x or any(v in (".", "..")
                   for v in x.rstrip("/").split("/")) for x in paths):
                raise ValueError("UNSAFE_SOURCE_ZIP_PATH")
        top = {s.split("/")[1] for s in paths if s.startswith("src/") and len(s.split("/")) >= 3}
        for name in sorted(top - set(top_map)):
            result["unexpected"].append("src/" + name)
        for name in sorted(set(top_map) - top):
            result["missing"].append("src/" + name)
        if domain_map is not None:
            sub = {s.split("/")[2] for s in paths if s.startswith("src/Domain/") and len(s.split("/")) >= 4}
            for name in sorted(sub - set(domain_map)):
                result["unexpected"].append("src/Domain/" + name)
            for name in sorted(set(domain_map) - sub):
                result["missing"].append("src/Domain/" + name)
        result["top_modules"] = len(top)
        result["domain_modules"] = len(sub) if domain_map is not None else None
    except (OSError, ValueError, KeyError, TypeError, zipfile.BadZipFile, UnicodeError) as e:
        result["errors"].append(type(e).__name__ + ":" + str(e)[:140])
    if not result["unexpected"] and not result["missing"] and not result["bad_references"] and not result["errors"]:
        result["status"] = "PASS_INVENTORY_ONLY"
    return result


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", type=pathlib.Path, required=True)
    p.add_argument("--component", choices=("local", "public"), required=True)
    p.add_argument("--map", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("module-coverage-map.json"))
    p.add_argument("--register", type=pathlib.Path,
                   default=pathlib.Path(__file__).with_name("INDEPENDENT_COVERAGE_REGISTER_20261116.json"))
    p.add_argument("--report", type=pathlib.Path)
    args = p.parse_args()
    out = inspect(args.source, args.component, args.map, args.register)
    s = json.dumps(out, ensure_ascii=False, indent=2, sort_keys=True)
    print(s)
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(s + "\n", encoding="utf-8")
    return 0 if out["status"] == "PASS_INVENTORY_ONLY" else 2


if __name__ == "__main__":
    sys.exit(main())
