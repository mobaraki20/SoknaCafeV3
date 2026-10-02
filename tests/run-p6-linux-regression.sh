#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
PHP_BIN="${PHP_BIN:-php}"

fail_env(){ echo "$1" >&2; exit 3; }
command -v "$PHP_BIN" >/dev/null 2>&1 || fail_env 'P6 Linux regression requires PHP.'
command -v python3 >/dev/null 2>&1 || fail_env 'P6 Linux regression requires Python 3.'
command -v node >/dev/null 2>&1 || fail_env 'P6 Linux regression requires Node.js.'
"$PHP_BIN" -r 'exit(in_array("mysql",PDO::getAvailableDrivers(),true)?0:1);' || fail_env 'P6 Linux regression requires pdo_mysql.'

export SOKNA_TEST_DB_HOST="${SOKNA_TEST_DB_HOST:-127.0.0.1}"
export SOKNA_TEST_DB_PORT="${SOKNA_TEST_DB_PORT:-3306}"
export SOKNA_TEST_DB_NAME="${SOKNA_TEST_DB_NAME:-sokna_m2}"
export SOKNA_TEST_DB_USER="${SOKNA_TEST_DB_USER:-sokna}"
export SOKNA_TEST_DB_PASS="${SOKNA_TEST_DB_PASS:-sokna}"
export SOKNA_TEST_DB_ROOT_USER="${SOKNA_TEST_DB_ROOT_USER:-root}"
export SOKNA_TEST_DB_ROOT_PASS="${SOKNA_TEST_DB_ROOT_PASS:-root}"

reset_local_db(){
  "$PHP_BIN" -r '
    $host=(string)getenv("SOKNA_TEST_DB_HOST");$port=(string)getenv("SOKNA_TEST_DB_PORT");$name=(string)getenv("SOKNA_TEST_DB_NAME");
    $user=(string)getenv("SOKNA_TEST_DB_ROOT_USER");$pass=(string)getenv("SOKNA_TEST_DB_ROOT_PASS");
    if(preg_match("/^[A-Za-z0-9_]+$/D",$name)!==1){fwrite(STDERR,"Unsafe P6 database name.\n");exit(2);}
    $pdo=new PDO("mysql:host=".$host.";port=".$port.";charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `".$name."`");
    $pdo->exec("CREATE DATABASE `".$name."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  '
}

./tests/run-g4-4-product-qualification.sh
reset_local_db
"$PHP_BIN" tests/local-mysql-migration-selftest.php
"$PHP_BIN" tests/local-m7-runtime-trigger-selftest.php
"$PHP_BIN" tests/local-m8-printing-selftest.php
"$PHP_BIN" tests/local-m10-release-selftest.php
"$PHP_BIN" tests/local-g2-support-bundle-pure-selftest.php
python3 apps/local-web/tests/g2-observability-contract.py
python3 tests/m7-runtime-gate.py
python3 tests/m8-print-agent-gate.py
python3 tests/m9-packaging-gate.py
python3 tests/m10-release-qualification.py
python3 tests/runtime-print-v1-contract.py
python3 tests/runtime-print-contract-audit.py
python3 tests/p6-integrated-regression-contract.py
printf 'P6 Linux Integrated Regression: PASS\n'
