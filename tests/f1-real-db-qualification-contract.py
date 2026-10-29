#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
runner = (ROOT / 'tests/run-f1-real-db-qualification.sh').read_text(encoding='utf-8')

def fail(msg: str) -> None:
    raise SystemExit(msg)

required = (
    'PDO::getAvailableDrivers()',
    'F1 DB qualification requires PHP pdo_mysql',
    'F1 DB qualification requires MariaDB 11.4.x',
    'reset_db',
    # G1 real-DB regression surface
    'tests/local-mysql-migration-selftest.php',
    'tests/local-m5-orders-selftest.php',
    'tests/local-m5-financial-period-selftest.php',
    'tests/local-m5-preparation-selftest.php',
    'tests/local-m5-inventory-selftest.php',
    'tests/local-m5-settlement-selftest.php',
    'tests/local-m8-printing-selftest.php',
    'tests/local-m10-release-selftest.php',
    # F1.1-F1.6 real-DB scenarios
    'tests/local-f1-staff-consumption-foundation-selftest.php',
    'tests/local-f1-staff-benefit-calculation-selftest.php',
    'tests/local-f1-staff-benefit-policy-selftest.php',
    'tests/local-f1-staff-consumption-posting-selftest.php',
    'tests/local-f1-staff-account-selftest.php',
    'tests/local-f1-staff-operational-cost-selftest.php',
    'tests/local-f1-staff-operational-integration-selftest.php',
    'tests/local-f1-staff-ui-reporting-selftest.php',
    # non-DB governance/regression gates
    'tests/r1-center-hard-removal-gate.py',
    'tests/product-parity-gate.py --mode inventory',
    'tests/scds-m6-gate.py',
    'tests/validate-v3-foundation.py --component local',
    'tests/component-registry-gate.py',
    'tests/g1-real-db-qualification-contract.py',
    'apps/local-web/tests/*.py',
    "find apps/local-web -type f -name '*.php'",
    "find apps/local-web/public -type f -name '*.js'",
    'F1 Product Qualification: PASS',
)
for fragment in required:
    if fragment not in runner:
        fail(f'F1 qualification runner missing contract fragment: {fragment}')

# An isolated subshell per test must reset the disposable DB, run the test,
# and collect any failure; no failed scenario can be omitted from the final gate.
loop_start=runner.find('for test_file in "${DB_TESTS[@]}"; do')
loop_end=runner.find('\ndone',loop_start)
loop=runner[loop_start:loop_end] if loop_start>=0 and loop_end>loop_start else ''
if not (re.search(r'if\s+!\s*\(\s*set -euo pipefail\s+reset_db\b',loop,re.S)
    and '"$PHP_BIN" "$test_file"' in loop
    and 'failed_db_tests+=("$test_file")' in loop
    and "if (( ${#failed_db_tests[@]} > 0 )); then" in runner
    and "exit 1" in runner[loop_end:]):
    fail('F1 qualification runner must reset and fail closed on every isolated DB self-test')

# Product scenarios must not silently disappear from the source tree.
for name in (
    'local-f1-staff-consumption-foundation-selftest.php',
    'local-f1-staff-benefit-calculation-selftest.php',
    'local-f1-staff-benefit-policy-selftest.php',
    'local-f1-staff-consumption-posting-selftest.php',
    'local-f1-staff-account-selftest.php',
    'local-f1-staff-operational-cost-selftest.php',
    'local-f1-staff-operational-integration-selftest.php',
    'local-f1-staff-ui-reporting-selftest.php',
):
    if not (ROOT / 'tests' / name).is_file():
        fail(f'F1 qualification source scenario missing: {name}')

# Frozen product decisions must be represented in the DB scenarios.
foundation = (ROOT / 'tests/local-f1-staff-consumption-foundation-selftest.php').read_text(encoding='utf-8')
posting = (ROOT / 'tests/local-f1-staff-consumption-posting-selftest.php').read_text(encoding='utf-8')
account = (ROOT / 'tests/local-f1-staff-account-selftest.php').read_text(encoding='utf-8')
ops = (ROOT / 'tests/local-f1-staff-operational-integration-selftest.php').read_text(encoding='utf-8')
ui = (ROOT / 'tests/local-f1-staff-ui-reporting-selftest.php').read_text(encoding='utf-8')
for fragment, body, label in (
    ('linked_user_id', foundation, 'Personnel optional login linkage'),
    ('No Login', posting, 'proxy Personnel without login'),
    ('zero', posting.lower(), 'zero-payable posting'),
    ('waiver', account.lower(), 'Benefit/Waiver financial boundary'),
    ('staff_consumption', ops, 'non-table operational context'),
    ('consumer_name', ui, 'consumer reporting identity'),
    ('recorder_name', ui, 'recorder reporting identity'),
):
    if fragment not in body:
        fail(f'F1 qualification scenario missing frozen decision evidence: {label}')

print('F1 real DB qualification harness contract: OK')
