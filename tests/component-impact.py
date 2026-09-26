#!/usr/bin/env python3
"""Map changed paths to V3 component gates for GitHub Actions."""

from __future__ import annotations

import argparse
from pathlib import Path

CATEGORIES = {
    "local": ("apps/local-web/",),
    "public": ("apps/public/",),
    "runtime": ("windows/runtime/",),
    "print": ("windows/print-agent/",),
    "platform": ("platform/",),
    "contracts": ("contracts/",),
    "packaging": ("packaging/",),
    "migration": ("docs/migration/",),
    "ui": ("UI_DESIGN_SYSTEM.md", "docs/ui-design-system/"),
}

GLOBAL_PATHS = {
    "ARCHITECTURE.md",
    "PROJECT_LINEAGE.md",
    "START_HERE.md",
    "README.md",
    "tests/validate-v3-foundation.py",
    "tests/component-impact.py",
    ".github/workflows/v3-component-gates.yml",
}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--files", required=True, help="newline-separated changed paths file")
    parser.add_argument("--github-output", required=True)
    args = parser.parse_args()

    files = [line.strip() for line in Path(args.files).read_text(encoding="utf-8").splitlines() if line.strip()]
    impact = {name: False for name in CATEGORIES}
    global_change = any(path in GLOBAL_PATHS or path.startswith("docs/adr/") or path.startswith("tests/") for path in files)

    for path in files:
        for name, prefixes in CATEGORIES.items():
            if any(path == prefix or path.startswith(prefix) for prefix in prefixes):
                impact[name] = True

    if global_change:
        for name in impact:
            impact[name] = True

    with Path(args.github_output).open("a", encoding="utf-8") as handle:
        handle.write(f"any={'true' if any(impact.values()) else 'false'}\n")
        handle.write(f"global={'true' if global_change else 'false'}\n")
        for name in sorted(impact):
            handle.write(f"{name}={'true' if impact[name] else 'false'}\n")

    print("Changed paths:")
    for path in files:
        print(f"  - {path}")
    print("Impact:")
    for name in sorted(impact):
        print(f"  {name}: {impact[name]}")


if __name__ == "__main__":
    main()
