#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
LOCAL = ROOT / "apps" / "local-web"
CORE = LOCAL / "src" / "Core"
MIGRATIONS = LOCAL / "database" / "migrations"

required = [
    LOCAL / "bootstrap.php",
    CORE / "Config.php",
    CORE / "Database.php",
    CORE / "Observability.php",
    CORE / "IdentityRepository.php",
    CORE / "PdoIdentityRepository.php",
    CORE / "Capabilities.php",
    CORE / "Session.php",
    CORE / "Auth.php",
    CORE / "Migrations.php",
    CORE / "Bootstrap.php",
    MIGRATIONS / "0001_m2_platform_core.sql",
    ROOT / "tests" / "local-core-selftest.php",
    ROOT / "tests" / "local-auth-selftest.php",
    ROOT / "tests" / "local-migrations-selftest.php",
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

migration_sql = (MIGRATIONS / "0001_m2_platform_core.sql").read_text(encoding="utf-8").lower()
if "create table if not exists user_preparation_areas" in migration_sql:
    raise SystemExit("M2 migration incorrectly owns user_preparation_areas")
for table in ("settings", "users", "user_capabilities", "audit_log"):
    if f"create table if not exists {table}" not in migration_sql:
        raise SystemExit(f"M2 migration missing owned table: {table}")

php = shutil.which("php")
if php is None:
    raise SystemExit("PHP CLI is required for the Local Web M2 gate")

php_files = [
    LOCAL / "bootstrap.php",
    *sorted(CORE.glob("*.php")),
    ROOT / "tests" / "local-core-selftest.php",
    ROOT / "tests" / "local-auth-selftest.php",
    ROOT / "tests" / "local-migrations-selftest.php",
]
for path in php_files:
    subprocess.run([php, "-l", str(path)], cwd=ROOT, check=True)

subprocess.run([php, str(ROOT / "tests" / "local-core-selftest.php")], cwd=ROOT, check=True)
subprocess.run([php, str(ROOT / "tests" / "local-auth-selftest.php")], cwd=ROOT, check=True)
subprocess.run([php, str(ROOT / "tests" / "local-migrations-selftest.php")], cwd=ROOT, check=True)
print("Local Core M2 contract: OK")
