#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import shutil
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "apps" / "public"
CORE = PUBLIC / "src" / "Core"
AUTH = PUBLIC / "src" / "Auth"
SECURITY = PUBLIC / "src" / "Security"
CONNECTIVITY = PUBLIC / "src" / "Connectivity"
REALTIME = PUBLIC / "src" / "Realtime"
HTTP = PUBLIC / "src" / "Http"
MIGRATION = PUBLIC / "database" / "migrations" / "0001_m3_public_edge_core.sql"

required = [
    PUBLIC / "bootstrap.php",
    CORE / "Config.php", CORE / "Database.php", CORE / "Migrations.php", CORE / "Bootstrap.php",
    AUTH / "AuthProjectionService.php", AUTH / "AuthThrottle.php", AUTH / "AuthSecurityAudit.php",
    AUTH / "PublicSessionStore.php", AUTH / "PublicLoginService.php",
    SECURITY / "SignedLocalRequestVerifier.php",
    CONNECTIVITY / "ConnectivityService.php",
    REALTIME / "RealtimeService.php",
    HTTP / "AuthHttpAdapter.php", HTTP / "ConnectivityHttpAdapter.php", HTTP / "RealtimeHttpAdapter.php",
    MIGRATION,
    ROOT / "tests" / "public-mysql-migration-selftest.php",
    ROOT / "tests" / "public-auth-projection-selftest.php",
    ROOT / "tests" / "public-login-selftest.php",
    ROOT / "tests" / "public-signed-local-request-selftest.php",
    ROOT / "tests" / "public-auth-http-adapter-selftest.php",
    ROOT / "tests" / "public-connectivity-selftest.php",
]
missing = [str(path.relative_to(ROOT)) for path in required if not path.is_file()]
if missing:
    raise SystemExit("Missing M3 Public files: " + ", ".join(missing))

sql = MIGRATION.read_text(encoding="utf-8").lower()
expected_tables = {
    "installations", "auth_projections", "public_sessions", "realtime_requests", "request_nonces",
    "installation_heartbeats", "deferred_work", "auth_login_throttle", "auth_security_audit",
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
for forbidden_secret in ("password", "verifier", "bearer", "token_hash"):
    if forbidden_secret in sql[throttle_start:audit_start] or forbidden_secret in sql[audit_start:]:
        raise SystemExit(f"Auth abuse-control tables contain forbidden secret field: {forbidden_secret}")

if "unique key uq_realtime_request(installation_id,request_id)" not in sql:
    raise SystemExit("Realtime idempotency key drifted")
if "primary key(installation_id,request_id)" not in sql:
    raise SystemExit("Deferred idempotency primary key missing")
if "lease_token_hash char(64)" not in sql or "lease_token char(64)" not in sql:
    raise SystemExit("Realtime/Deferred lease storage separation drifted")

projection_source = (AUTH / "AuthProjectionService.php").read_text(encoding="utf-8")
if "UPDATE auth_projections SET active=0 WHERE installation_id=?" not in projection_source:
    raise SystemExit("Authoritative projection sync no longer deactivates omitted projections")
if "ON DUPLICATE KEY UPDATE" not in projection_source:
    raise SystemExit("Projection sync lost replay-safe upsert behavior")

signed_source = (SECURITY / "SignedLocalRequestVerifier.php").read_text(encoding="utf-8")
for token in ("unknown_installation", "bad_signature", "replay_detected", "isDuplicateKey"):
    if token not in signed_source:
        raise SystemExit(f"Signed Local request verifier missing required taxonomy/safety token: {token}")
if "catch (PDOException $e)" not in signed_source or "if ($this->isDuplicateKey($e))" not in signed_source:
    raise SystemExit("Nonce insert no longer distinguishes duplicate-key replay from infrastructure failure")

auth_adapter = (HTTP / "AuthHttpAdapter.php").read_text(encoding="utf-8")
for forbidden_logic in ("password_verify", "INSERT INTO", "UPDATE auth_projections", "DELETE FROM auth_login_throttle"):
    if forbidden_logic in auth_adapter:
        raise SystemExit(f"HTTP adapter duplicated service-owned auth logic: {forbidden_logic}")
for delegated_call in ("signedLocalRequests()->verify", "authProjections()->sync", "loginService()->login"):
    if delegated_call not in auth_adapter:
        raise SystemExit(f"HTTP adapter no longer delegates to canonical service: {delegated_call}")

connectivity_adapter = (HTTP / "ConnectivityHttpAdapter.php").read_text(encoding="utf-8")
for forbidden_logic in ("INSERT INTO installation_heartbeats", "UPDATE installation_heartbeats"):
    if forbidden_logic in connectivity_adapter:
        raise SystemExit(f"Connectivity HTTP adapter duplicated persistence logic: {forbidden_logic}")
for delegated_call in ("signedLocalRequests()->verify", "connectivity()->heartbeat", "connectivity()->status"):
    if delegated_call not in connectivity_adapter:
        raise SystemExit(f"Connectivity adapter no longer delegates to canonical service: {delegated_call}")

realtime_source = (REALTIME / "RealtimeService.php").read_text(encoding="utf-8")
for token in (
    "orders.mutate", "finance.settle", "preparation.mutate", "orders.table_draft",
    "request_id_conflict", "lease_token_hash", "hash('sha256', $leaseToken)",
    "state='expired'", "deduplicated", "local_unavailable", "order_intake_disabled",
):
    if token not in realtime_source:
        raise SystemExit(f"Realtime service missing contract invariant: {token}")
if "deferred_work" in realtime_source:
    raise SystemExit("Realtime service must not absorb Deferred persistence/state")

realtime_adapter = (HTTP / "RealtimeHttpAdapter.php").read_text(encoding="utf-8")
for forbidden_logic in ("INSERT INTO realtime_requests", "UPDATE realtime_requests", "SELECT id,envelope_json FROM realtime_requests"):
    if forbidden_logic in realtime_adapter:
        raise SystemExit(f"Realtime HTTP adapter duplicated persistence logic: {forbidden_logic}")
for delegated_call in (
    "publicSessions()->resolve", "signedLocalRequests()->verify", "realtime()->enqueue",
    "realtime()->result", "realtime()->claim", "realtime()->ack",
):
    if delegated_call not in realtime_adapter:
        raise SystemExit(f"Realtime adapter no longer delegates to canonical service: {delegated_call}")

bootstrap_source = (CORE / "Bootstrap.php").read_text(encoding="utf-8")
if "function realtime(): RealtimeService" not in bootstrap_source:
    raise SystemExit("Public Bootstrap does not expose canonical Realtime service")

php = shutil.which("php")
if php is None:
    raise SystemExit("PHP CLI is required for the Public M3 gate")
php_files = [
    PUBLIC / "bootstrap.php",
    *sorted(CORE.glob("*.php")), *sorted(AUTH.glob("*.php")), *sorted(SECURITY.glob("*.php")),
    *sorted(CONNECTIVITY.glob("*.php")), *sorted(REALTIME.glob("*.php")), *sorted(HTTP.glob("*.php")),
    ROOT / "tests" / "public-mysql-migration-selftest.php",
    ROOT / "tests" / "public-auth-projection-selftest.php",
    ROOT / "tests" / "public-login-selftest.php",
    ROOT / "tests" / "public-signed-local-request-selftest.php",
    ROOT / "tests" / "public-auth-http-adapter-selftest.php",
    ROOT / "tests" / "public-connectivity-selftest.php",
]
for path in php_files:
    subprocess.run([php, "-l", str(path)], cwd=ROOT, check=True)

print("Public M3 persistence/auth/security/http/connectivity/realtime contract: OK")
