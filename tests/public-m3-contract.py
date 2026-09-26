#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "apps" / "public"
CORE = PUBLIC / "src" / "Core"
MIGRATION = PUBLIC / "database" / "migrations" / "0001_m3_public_edge_core.sql"

required = [
    PUBLIC / "bootstrap.php",
    CORE / "Config.php",
    CORE / "Database.php",
    CORE / "Migrations.php",
    CORE / "Bootstrap.php",
    MIGRATION,
    ROOT / "tests" / "public-mysql-migration-selftest.php",
]
missing = [str(path.relative_to(ROOT)) for path in required if not path.is_file()]
if missing:
    raise SystemExit("Missing M3 Public files: " + ", ".join(missing))

sql = MIGRATION.read_text(encoding="utf-8").lower()
expected_tables = {
    "installations",
    "auth_projections",
    "public_sessions",
    "realtime_requests",
    "request_nonces",
    "installation_heartbeats",
    "deferred_work",
    "auth_login_throttle",
    "auth_security_audit",
}
for table in expected_tables:
    if f"create table if not exists {table}" not in sql:
        raise SystemExit(f"M3 Public migration missing owned table: {table}")

for forbidden in ("guest_publish_revisions", "guest_active_revisions", "guest_availability_state", "remote_read_models"):
    if f"create table if not exists {forbidden}" in sql:
        raise SystemExit(f"M3 incorrectly absorbed M4 table: {forbidden}")

if "password_hash varchar(255) not null" not in sql:
    raise SystemExit("ADR 0003 verifier projection is missing from auth_projections")

throttle_start = sql.index("create table if not exists auth_login_throttle")
audit_start = sql.index("create table if not exists auth_security_audit")
throttle_sql = sql[throttle_start:audit_start]
audit_sql = sql[audit_start:]
for forbidden_secret in ("password", "verifier", "bearer", "token_hash"):
    if forbidden_secret in throttle_sql or forbidden_secret in audit_sql:
        raise SystemExit(f"Auth abuse-control tables contain forbidden secret field: {forbidden_secret}")

if "unique key uq_realtime_request(installation_id,request_id)" not in sql:
    raise SystemExit("Realtime idempotency key drifted")
if "primary key(installation_id,request_id)" not in sql:
    raise SystemExit("Deferred idempotency primary key missing")
if "lease_token_hash char(64)" not in sql:
    raise SystemExit("Realtime lease token must remain hashed")
if "lease_token char(64)" not in sql:
    raise SystemExit("Historical deferred lease token compatibility field missing")

php = shutil.which("php")
if php is None:
    raise SystemExit("PHP CLI is required for the Public M3 gate")
for path in [PUBLIC / "bootstrap.php", *sorted(CORE.glob("*.php")), ROOT / "tests" / "public-mysql-migration-selftest.php"]:
    subprocess.run([php, "-l", str(path)], cwd=ROOT, check=True)

print("Public M3 persistence contract: OK")
