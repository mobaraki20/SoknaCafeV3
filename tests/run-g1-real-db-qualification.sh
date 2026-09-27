#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
HOST="${SOKNA_TEST_DB_HOST:-127.0.0.1}"
PORT="${SOKNA_TEST_DB_PORT:-3306}"
DB="${SOKNA_TEST_DB_NAME:-sokna_m2}"
USER="${SOKNA_TEST_DB_USER:-sokna}"
PASS="${SOKNA_TEST_DB_PASS:-sokna}"
ROOT_USER="${SOKNA_TEST_DB_ROOT_USER:-root}"
ROOT_PASS="${SOKNA_TEST_DB_ROOT_PASS:-root}"

if [[ ! "$DB" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Unsafe SOKNA_TEST_DB_NAME: $DB" >&2
  exit 2
fi

if ! "$PHP_BIN" -r 'exit(in_array("mysql", PDO::getAvailableDrivers(), true) ? 0 : 1);'; then
  echo "G1 DB qualification requires PHP pdo_mysql." >&2
  exit 3
fi

export SOKNA_TEST_DB_HOST="$HOST" SOKNA_TEST_DB_PORT="$PORT" SOKNA_TEST_DB_NAME="$DB"
export SOKNA_TEST_DB_USER="$USER" SOKNA_TEST_DB_PASS="$PASS"
export SOKNA_TEST_DB_ROOT_USER="$ROOT_USER" SOKNA_TEST_DB_ROOT_PASS="$ROOT_PASS"

server_version="$($PHP_BIN -r '
$h=getenv("SOKNA_TEST_DB_HOST");$p=getenv("SOKNA_TEST_DB_PORT");
$u=getenv("SOKNA_TEST_DB_ROOT_USER");$pw=getenv("SOKNA_TEST_DB_ROOT_PASS");
$pdo=new PDO("mysql:host={$h};port={$p};charset=utf8mb4",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo $pdo->query("SELECT VERSION() ")->fetchColumn();
')"
echo "MariaDB server: $server_version"
if [[ ! "$server_version" =~ ^11\.4([.-]|$) ]]; then
  echo "G1 DB qualification requires MariaDB 11.4.x; got: $server_version" >&2
  exit 4
fi

reset_db() {
  "$PHP_BIN" <<'PHP'
<?php
$h=getenv("SOKNA_TEST_DB_HOST");$p=getenv("SOKNA_TEST_DB_PORT");$db=getenv("SOKNA_TEST_DB_NAME");
$ru=getenv("SOKNA_TEST_DB_ROOT_USER");$rp=getenv("SOKNA_TEST_DB_ROOT_PASS");
$u=getenv("SOKNA_TEST_DB_USER");$pw=getenv("SOKNA_TEST_DB_PASS");
if (!preg_match("/^[A-Za-z0-9_]+$/",$db)) { fwrite(STDERR,"unsafe db name\n"); exit(2); }
$pdo=new PDO("mysql:host={$h};port={$p};charset=utf8mb4",$ru,$rp,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$qu=$pdo->quote($u);$qp=$pdo->quote($pw);
$pdo->exec("CREATE USER IF NOT EXISTS {$qu}@'%' IDENTIFIED BY {$qp}");
$pdo->exec("ALTER USER {$qu}@'%' IDENTIFIED BY {$qp}");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO {$qu}@'%'");
$pdo->exec("FLUSH PRIVILEGES");
PHP
}


DB_TESTS=(
  tests/local-mysql-migration-selftest.php
  tests/local-m5-orders-selftest.php
  tests/local-m5-financial-period-selftest.php
  tests/local-m5-table-draft-selftest.php
  tests/local-m5-preparation-selftest.php
  tests/local-m5-sellables-selftest.php
  tests/local-m5-tax-selftest.php
  tests/local-m5-supply-selftest.php
  tests/local-m5-inventory-selftest.php
  tests/local-m5-expenses-selftest.php
  tests/local-m5-settlement-selftest.php
  tests/local-m5-integrations-selftest.php
  tests/local-m7-runtime-trigger-selftest.php
  tests/local-m8-printing-selftest.php
  tests/local-m10-release-selftest.php
)

for test_file in "${DB_TESTS[@]}"; do
  echo "==> $test_file"
  reset_db
  "$PHP_BIN" "$test_file"
done

reset_db

python3 tests/r1-center-hard-removal-gate.py
python3 tests/product-parity-gate.py --mode inventory
python3 tests/scds-m6-gate.py
python3 tests/validate-v3-foundation.py --component local
python3 tests/component-registry-gate.py
for test_file in apps/local-web/tests/*.py; do python3 "$test_file"; done
find apps/local-web -type f -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l >/dev/null
find apps/local-web/public -type f -name '*.js' -print0 | xargs -0 -n1 node --check

echo "G1 real MariaDB qualification: PASS"
