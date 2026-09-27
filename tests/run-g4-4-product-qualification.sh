#!/usr/bin/env bash
set -euo pipefail
PHP_BIN="${PHP_BIN:-php}"
fail_env(){ echo "$1" >&2; exit 3; }
command -v "$PHP_BIN" >/dev/null 2>&1 || fail_env 'G4.4 qualification requires PHP.'
command -v python3 >/dev/null 2>&1 || fail_env 'G4.4 qualification requires Python 3.'
command -v node >/dev/null 2>&1 || fail_env 'G4.4 qualification requires Node.js.'
"$PHP_BIN" tests/g4-4-print-template-pure-selftest.php
python3 tests/g4-4-print-template-contract.py
python3 tests/g4-4-scds-surface-contract.py
python3 tests/scds-m6-gate.py
python3 tests/g4-4-product-qualification-contract.py
"$PHP_BIN" -r 'exit(in_array("mysql",PDO::getAvailableDrivers(),true)?0:1);' || fail_env 'G4.4 qualification requires pdo_mysql.'
export SOKNA_TEST_DB_HOST="${SOKNA_TEST_DB_HOST:-127.0.0.1}" SOKNA_TEST_DB_PORT="${SOKNA_TEST_DB_PORT:-3306}" SOKNA_TEST_DB_NAME="${SOKNA_TEST_DB_NAME:-sokna_m2}" SOKNA_TEST_DB_USER="${SOKNA_TEST_DB_USER:-sokna}" SOKNA_TEST_DB_PASS="${SOKNA_TEST_DB_PASS:-sokna}" SOKNA_TEST_DB_ROOT_USER="${SOKNA_TEST_DB_ROOT_USER:-root}" SOKNA_TEST_DB_ROOT_PASS="${SOKNA_TEST_DB_ROOT_PASS:-root}"
server="$($PHP_BIN -r '$p=new PDO("mysql:host=".getenv("SOKNA_TEST_DB_HOST").";port=".getenv("SOKNA_TEST_DB_PORT").";charset=utf8mb4",getenv("SOKNA_TEST_DB_ROOT_USER"),getenv("SOKNA_TEST_DB_ROOT_PASS"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);echo $p->query("SELECT VERSION()")->fetchColumn();')"; echo "MariaDB server: $server"; [[ "$server" =~ ^11\.4([.-]|$) ]] || { echo 'G4.4 requires MariaDB 11.4.x' >&2; exit 4; }
./tests/run-g4-3-product-qualification.sh
"$PHP_BIN" tests/g4-4-print-template-selftest.php
python3 tests/g4-4-print-template-contract.py
python3 tests/g4-4-scds-surface-contract.py
python3 tests/scds-m6-gate.py
python3 tests/product-parity-gate.py --mode inventory
find apps/local-web/src apps/local-web/public apps/public/src -type f -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l >/dev/null
find apps/local-web/public/assets apps/public/assets -type f -name '*.js' -print0 | xargs -0 -n1 node --check
printf 'Local Bridge preview route contract retained: /v1/preview\n'
printf 'Physical printer/Windows preview UAT remains release-deferred.\n'
printf 'G4.4 Product Qualification: PASS\n'
