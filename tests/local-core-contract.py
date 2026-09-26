#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import shutil
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
LOCAL = ROOT / "apps" / "local-web"
CORE = LOCAL / "src" / "Core"

required = [
    LOCAL / "bootstrap.php",
    CORE / "Config.php",
    CORE / "Database.php",
    CORE / "Observability.php",
    CORE / "Bootstrap.php",
    ROOT / "tests" / "local-core-selftest.php",
]

missing = [str(path.relative_to(ROOT)) for path in required if not path.is_file()]
if missing:
    raise SystemExit("Missing M2 Local Core files: " + ", ".join(missing))

forbidden_tokens = {
    "programdata": "Windows data-root discovery belongs to Setup/Platform, not Local Core",
    "winspool": "Winspool belongs to Print Agent",
    "powershell": "elevated/script lifecycle belongs outside Local Core",
    "servicecontroller": "Windows SCM ownership belongs to Runtime/Setup",
    "microsoft.win32": "Windows Registry ownership belongs outside Local Core",
    "windows/runtime": "Local Core must not import Runtime implementation",
    "windows/print-agent": "Local Core must not import Print Agent implementation",
}

violations: list[str] = []
for path in sorted(CORE.glob("*.php")):
    text = path.read_text(encoding="utf-8").lower()
    for token, reason in forbidden_tokens.items():
        if token in text:
            violations.append(f"{path.relative_to(ROOT)}: {token}: {reason}")

if violations:
    raise SystemExit("Forbidden Local Core ownership detected:\n" + "\n".join(violations))

php = shutil.which("php")
if php is None:
    raise SystemExit("PHP CLI is required for the Local Web M2 gate")

for path in required:
    if path.suffix == ".php":
        subprocess.run([php, "-l", str(path)], cwd=ROOT, check=True)

subprocess.run([php, str(ROOT / "tests" / "local-core-selftest.php")], cwd=ROOT, check=True)
print("Local Core M2 contract: OK")
