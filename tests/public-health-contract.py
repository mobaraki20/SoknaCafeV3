#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "apps" / "public"
CORE = PUBLIC / "src" / "Core"
HEALTH = PUBLIC / "src" / "Health"
HTTP = PUBLIC / "src" / "Http"

required = [
    CORE / "SafeErrors.php",
    HEALTH / "PublicHealthService.php",
    HTTP / "HealthHttpAdapter.php",
    ROOT / "tests" / "public-health-selftest.php",
]
missing = [str(path.relative_to(ROOT)) for path in required if not path.is_file()]
if missing:
    raise SystemExit("Missing Public health/error files: " + ", ".join(missing))

safe = (CORE / "SafeErrors.php").read_text(encoding="utf-8")
for forbidden in ("getMessage(", "getTrace(", "getTraceAsString(", "var_dump(", "print_r("):
    if forbidden in safe:
        raise SystemExit(f"SafeErrors may expose internal exception data: {forbidden}")
for token in ("public_internal_error", "correlation_id", "random_bytes(16)"):
    if token not in safe:
        raise SystemExit(f"SafeErrors missing invariant: {token}")

health = (HEALTH / "PublicHealthService.php").read_text(encoding="utf-8")
for token in ("SELECT 1", "schema_migrations", "public_unavailable", "public-edge"):
    if token not in health:
        raise SystemExit(f"Public health service missing invariant: {token}")
for forbidden_table in (
    "orders", "order_items", "inventory_movements", "financial_periods", "users",
    "guest_publish_revisions", "remote_read_models",
):
    if forbidden_table in health:
        raise SystemExit(f"Public health depends on non-health/business table: {forbidden_table}")
for forbidden in ("getMessage(", "getTrace(", "getTraceAsString("):
    if forbidden in health:
        raise SystemExit(f"Public health leaks exception detail: {forbidden}")

adapter = (HTTP / "HealthHttpAdapter.php").read_text(encoding="utf-8")
if "health()->status" not in adapter:
    raise SystemExit("Health HTTP adapter no longer delegates to Public health owner")
for forbidden in ("SELECT ", "INSERT ", "UPDATE ", "DELETE "):
    if forbidden in adapter:
        raise SystemExit(f"Health HTTP adapter duplicated persistence logic: {forbidden}")

bootstrap = (CORE / "Bootstrap.php").read_text(encoding="utf-8")
if "function health(): PublicHealthService" not in bootstrap:
    raise SystemExit("Public Bootstrap does not expose PublicHealthService")

php = shutil.which("php")
if php is None:
    raise SystemExit("PHP CLI is required for the Public health gate")
for path in required:
    if path.suffix == ".php":
        subprocess.run([php, "-l", str(path)], cwd=ROOT, check=True)

print("Public M3 health/safe-error contract: OK")
