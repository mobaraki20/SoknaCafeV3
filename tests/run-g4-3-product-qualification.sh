#!/usr/bin/env bash
set -euo pipefail
PHP_BIN="${PHP_BIN:-php}"
DB_HOST="${SOKNA_TEST_DB_HOST:-127.0.0.1}"; DB_PORT="${SOKNA_TEST_DB_PORT:-3306}"; DB_NAME="${SOKNA_TEST_DB_NAME:-sokna_m2}"; DB_USER="${SOKNA_TEST_DB_USER:-sokna}"; DB_PASS="${SOKNA_TEST_DB_PASS:-sokna}"; ROOT_USER="${SOKNA_TEST_DB_ROOT_USER:-root}"; ROOT_PASS="${SOKNA_TEST_DB_ROOT_PASS:-root}"
fail_env(){ echo "$1" >&2; exit 3; }
command -v "$PHP_BIN" >/dev/null 2>&1 || fail_env 'G4.3 qualification requires PHP.'
command -v python3 >/dev/null 2>&1 || fail_env 'G4.3 qualification requires Python 3.'
command -v node >/dev/null 2>&1 || fail_env 'G4.3 qualification requires Node.js.'
php tests/g4-3-pure-selftest.php
python3 tests/g4-3-business-extensions-contract.py
python3 tests/g4-3-product-qualification-contract.py
"$PHP_BIN" -r 'exit(in_array("mysql",PDO::getAvailableDrivers(),true)?0:1);' || fail_env 'G4.3 qualification requires pdo_mysql.'
"$PHP_BIN" -r 'exit(extension_loaded("sodium")?0:1);' || fail_env 'G4.3 qualification requires sodium.'
export SOKNA_TEST_DB_HOST="$DB_HOST" SOKNA_TEST_DB_PORT="$DB_PORT" SOKNA_TEST_DB_NAME="$DB_NAME" SOKNA_TEST_DB_USER="$DB_USER" SOKNA_TEST_DB_PASS="$DB_PASS" SOKNA_TEST_DB_ROOT_USER="$ROOT_USER" SOKNA_TEST_DB_ROOT_PASS="$ROOT_PASS"
server="$($PHP_BIN -r '$p=new PDO("mysql:host=".getenv("SOKNA_TEST_DB_HOST").";port=".getenv("SOKNA_TEST_DB_PORT").";charset=utf8mb4",getenv("SOKNA_TEST_DB_ROOT_USER"),getenv("SOKNA_TEST_DB_ROOT_PASS"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);echo $p->query("SELECT VERSION()")->fetchColumn();')"; echo "MariaDB server: $server"; [[ "$server" =~ ^11\.4([.-]|$) ]] || { echo 'G4.3 requires MariaDB 11.4.x' >&2; exit 4; }
./tests/run-g4-2-product-qualification.sh
"$PHP_BIN" <<'PHP'
<?php
$h=getenv('SOKNA_TEST_DB_HOST');$p=getenv('SOKNA_TEST_DB_PORT');$db=getenv('SOKNA_TEST_DB_NAME');$u=getenv('SOKNA_TEST_DB_USER');$pw=getenv('SOKNA_TEST_DB_PASS');$ru=getenv('SOKNA_TEST_DB_ROOT_USER');$rp=getenv('SOKNA_TEST_DB_ROOT_PASS');if(!preg_match('/^[A-Za-z0-9_]+$/D',$db))throw new RuntimeException('invalid db');$pdo=new PDO("mysql:host={$h};port={$p};charset=utf8mb4",$ru,$rp,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec("DROP DATABASE IF EXISTS `{$db}`");$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$qu=$pdo->quote($u);$qp=$pdo->quote($pw);$pdo->exec("CREATE USER IF NOT EXISTS {$qu}@'%' IDENTIFIED BY {$qp}");$pdo->exec("ALTER USER {$qu}@'%' IDENTIFIED BY {$qp}");$pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO {$qu}@'%'");$pdo->exec('FLUSH PRIVILEGES');
PHP
"$PHP_BIN" tests/g4-3-business-extensions-selftest.php
python3 tests/g4-3-business-extensions-contract.py
python3 tests/g4-3-product-qualification-contract.py
python3 tests/product-parity-gate.py --mode inventory
find apps/local-web/src apps/local-web/public apps/public/src -type f -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l >/dev/null
find apps/local-web/public/assets apps/public/assets -type f -name '*.js' -print0 | xargs -0 -n1 node --check
printf 'Runtime trigger verified: notifications.outbox\n'
printf 'G4.3 Product Qualification: PASS\n'
