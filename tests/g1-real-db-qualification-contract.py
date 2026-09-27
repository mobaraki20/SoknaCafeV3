#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
runner = (ROOT / 'tests/run-g1-real-db-qualification.sh').read_text(encoding='utf-8')
migration = (ROOT / 'tests/local-mysql-migration-selftest.php').read_text(encoding='utf-8')

def fail(msg: str) -> None:
    raise SystemExit(msg)

for version in ('0016_g1_guest_waiter', '0017_g1_admin_controls', '0018_g1_global_search'):
    if version not in migration:
        fail(f'G1 DB migration stack is missing {version}')
for table in ('table_session_clients', 'waiter_calls', 'personnel'):
    if table not in migration:
        fail(f'G1 real migration test does not assert table {table}')
for retired in ('center_user_projection_cache', 'center_sync_receipts'):
    if retired not in migration or 'Retired SOKNA Center table' not in migration:
        fail('G1 real migration test must fail if retired Center tables reappear')
for index in (
    'idx_g16c_items_active_name',
    'idx_g16c_categories_active_name',
    'idx_g16c_menus_name',
    'idx_g16c_users_active_display',
    'idx_g12b_tables_zone_active',
    'idx_g16c_tables_active_name',
    'idx_g12b_personnel_active_name',
):
    if index not in migration:
        fail(f'G1 real migration test does not assert index {index}')

required_runner_fragments = (
    'PDO::getAvailableDrivers()',
    'G1 DB qualification requires PHP pdo_mysql',
    'G1 DB qualification requires MariaDB 11.4.x',
    'reset_db',
    'tests/local-mysql-migration-selftest.php',
    'tests/local-m5-sellables-selftest.php',
    'tests/local-m5-settlement-selftest.php',
    'tests/local-m5-integrations-selftest.php',
    'tests/local-m7-runtime-trigger-selftest.php',
    'tests/local-m8-printing-selftest.php',
    'tests/local-m10-release-selftest.php',
    'tests/r1-center-hard-removal-gate.py',
    'tests/product-parity-gate.py --mode inventory',
    'tests/scds-m6-gate.py',
    'tests/validate-v3-foundation.py --component local',
)
for fragment in required_runner_fragments:
    if fragment not in runner:
        fail(f'G1 qualification runner missing contract fragment: {fragment}')
if not re.search(r'for test_file in "\$\{DB_TESTS\[@\]\}"; do\s+echo .*?\s+reset_db\s+', runner, re.S):
    fail('G1 qualification runner must reset the database before every DB self-test')
if 'G1 real MariaDB qualification: PASS' not in runner:
    fail('G1 qualification runner has no explicit PASS terminal')
print('G1 real DB qualification harness contract: OK')
