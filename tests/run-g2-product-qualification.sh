#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"
DB_HOST="${SOKNA_TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${SOKNA_TEST_DB_PORT:-3306}"
DB_NAME="${SOKNA_TEST_DB_NAME:-sokna_m2}"
DB_USER="${SOKNA_TEST_DB_USER:-sokna}"
DB_PASS="${SOKNA_TEST_DB_PASS:-sokna}"
ROOT_USER="${SOKNA_TEST_DB_ROOT_USER:-root}"
ROOT_PASS="${SOKNA_TEST_DB_ROOT_PASS:-root}"

fail_env(){ echo "$1" >&2; exit 3; }

command -v "$PHP_BIN" >/dev/null 2>&1 || fail_env "G2 qualification requires PHP."
"$PHP_BIN" -r 'exit(in_array("mysql", PDO::getAvailableDrivers(), true) ? 0 : 1);' || fail_env "G2 qualification requires PHP pdo_mysql."
"$PHP_BIN" -r 'exit(class_exists("ZipArchive") ? 0 : 1);' || fail_env "G2 qualification requires PHP zip (ZipArchive)."
"$PHP_BIN" -r 'exit(extension_loaded("sodium") ? 0 : 1);' || fail_env "G2 qualification requires PHP sodium."

export SOKNA_TEST_DB_HOST="$DB_HOST"
export SOKNA_TEST_DB_PORT="$DB_PORT"
export SOKNA_TEST_DB_NAME="$DB_NAME"
export SOKNA_TEST_DB_USER="$DB_USER"
export SOKNA_TEST_DB_PASS="$DB_PASS"
export SOKNA_TEST_DB_ROOT_USER="$ROOT_USER"
export SOKNA_TEST_DB_ROOT_PASS="$ROOT_PASS"

# Re-qualify the full G1/F1 DB surface first. This also validates MariaDB 11.4.x.
./tests/run-f1-real-db-qualification.sh

reset_db(){
  "$PHP_BIN" <<'PHP'
<?php
$host=getenv('SOKNA_TEST_DB_HOST')?:'127.0.0.1';
$port=getenv('SOKNA_TEST_DB_PORT')?:'3306';
$db=getenv('SOKNA_TEST_DB_NAME')?:'sokna_m2';
$u=getenv('SOKNA_TEST_DB_USER')?:'sokna';
$pw=getenv('SOKNA_TEST_DB_PASS')?:'sokna';
$ru=getenv('SOKNA_TEST_DB_ROOT_USER')?:'root';
$rp=getenv('SOKNA_TEST_DB_ROOT_PASS')?:'root';
$pdo=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$ru,$rp,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!preg_match('/^[A-Za-z0-9_]+$/D',$db)) throw new RuntimeException('invalid db name');
$pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$qu=$pdo->quote($u);$qp=$pdo->quote($pw);
$pdo->exec("CREATE USER IF NOT EXISTS {$qu}@'%' IDENTIFIED BY {$qp}");
$pdo->exec("ALTER USER {$qu}@'%' IDENTIFIED BY {$qp}");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO {$qu}@'%'");
$pdo->exec('FLUSH PRIVILEGES');
PHP
}

# G2 product scenarios, each with clean DB where relevant.
echo '==> tests/local-g2-browser-setup-selftest.php'
reset_db
"$PHP_BIN" tests/local-g2-browser-setup-selftest.php

echo '==> tests/local-g2-observability-selftest.php'
reset_db
"$PHP_BIN" tests/local-g2-observability-selftest.php

echo '==> tests/local-g2-update-lifecycle-selftest.php'
"$PHP_BIN" tests/local-g2-update-lifecycle-selftest.php

# Pure security/state checks.
"$PHP_BIN" tests/local-g2-browser-setup-pure-selftest.php
"$PHP_BIN" tests/local-g2-support-bundle-pure-selftest.php
"$PHP_BIN" tests/local-g2-update-lifecycle-pure-selftest.php

# Final product/gate regression.
python3 tests/r1-center-hard-removal-gate.py
python3 tests/product-parity-gate.py --mode inventory
python3 tests/scds-m6-gate.py
python3 tests/validate-v3-foundation.py --component local
python3 tests/component-registry-gate.py
python3 tests/g2-product-qualification-contract.py
for test_file in apps/local-web/tests/*.py; do python3 "$test_file"; done
find apps/local-web -type f -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l >/dev/null
find apps/local-web/public -type f -name '*.js' -print0 | xargs -0 -n1 node --check

echo 'G2 Product Qualification: PASS'
