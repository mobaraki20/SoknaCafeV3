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
QUAL_VERSION="${SOKNA_G3_QUAL_VERSION:-3.0.0-g3.4-qualification}"
QUAL_SOURCE_COMMIT="${SOKNA_G3_QUAL_SOURCE_COMMIT:-bc8e40800bba4735b110af2488afe5ec1ae377f3}"
OUT_DIR="${SOKNA_G3_QUAL_OUT_DIR:-qualification-output/g3.4}"

fail_env(){ echo "$1" >&2; exit 3; }

command -v "$PHP_BIN" >/dev/null 2>&1 || fail_env "G3 qualification requires PHP."
command -v python3 >/dev/null 2>&1 || fail_env "G3 qualification requires Python 3."
"$PHP_BIN" -r 'exit(in_array("mysql", PDO::getAvailableDrivers(), true) ? 0 : 1);' || fail_env "G3 qualification requires PHP pdo_mysql."
"$PHP_BIN" -r 'exit(class_exists("ZipArchive") ? 0 : 1);' || fail_env "G3 qualification requires PHP zip (ZipArchive)."
"$PHP_BIN" -r 'exit(extension_loaded("sodium") ? 0 : 1);' || fail_env "G3 qualification requires PHP sodium."

if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Unsafe SOKNA_TEST_DB_NAME: $DB_NAME" >&2
  exit 2
fi

export SOKNA_TEST_DB_HOST="$DB_HOST"
export SOKNA_TEST_DB_PORT="$DB_PORT"
export SOKNA_TEST_DB_NAME="$DB_NAME"
export SOKNA_TEST_DB_USER="$DB_USER"
export SOKNA_TEST_DB_PASS="$DB_PASS"
export SOKNA_TEST_DB_ROOT_USER="$ROOT_USER"
export SOKNA_TEST_DB_ROOT_PASS="$ROOT_PASS"

server_version="$($PHP_BIN -r '
$h=getenv("SOKNA_TEST_DB_HOST");$p=getenv("SOKNA_TEST_DB_PORT");
$u=getenv("SOKNA_TEST_DB_ROOT_USER");$pw=getenv("SOKNA_TEST_DB_ROOT_PASS");
$pdo=new PDO("mysql:host={$h};port={$p};charset=utf8mb4",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo $pdo->query("SELECT VERSION()")->fetchColumn();
')"
echo "MariaDB server: $server_version"
if [[ ! "$server_version" =~ ^11\.4([.-]|$) ]]; then
  echo "G3 qualification requires MariaDB 11.4.x; got: $server_version" >&2
  exit 4
fi

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
if(!preg_match('/^[A-Za-z0-9_]+$/D',$db)) throw new RuntimeException('invalid db name');
$pdo=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$ru,$rp,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$qu=$pdo->quote($u);$qp=$pdo->quote($pw);
$pdo->exec("CREATE USER IF NOT EXISTS {$qu}@'%' IDENTIFIED BY {$qp}");
$pdo->exec("ALTER USER {$qu}@'%' IDENTIFIED BY {$qp}");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO {$qu}@'%'");
$pdo->exec('FLUSH PRIVILEGES');
PHP
}

echo '==> Re-qualify G2 baseline'
./tests/run-g2-product-qualification.sh

PUBLIC_DB_TESTS=(
  tests/public-mysql-migration-selftest.php
  tests/public-auth-projection-selftest.php
  tests/public-login-selftest.php
  tests/public-signed-local-request-selftest.php
  tests/public-realtime-selftest.php
  tests/public-health-selftest.php
  tests/public-m4-guest-publish-selftest.php
  tests/public-m4-guest-compat-selftest.php
  tests/public-m4-guest-renderer-selftest.php
  tests/public-m4-guest-runtime-selftest.php
  tests/public-m4-remote-read-model-selftest.php
  tests/public-m4-failure-isolation-selftest.php
)
for test_file in "${PUBLIC_DB_TESTS[@]}"; do
  echo "==> $test_file"
  reset_db
  "$PHP_BIN" "$test_file"
done

python3 tests/public-m3-contract.py
python3 tests/public-m3-transport-contract.py
python3 tests/public-m4-schema-contract.py

python3 tests/g3-public-deploy-contract.py
"$PHP_BIN" tests/public-g3-deploy-pure-selftest.php
reset_db
"$PHP_BIN" tests/public-g3-deploy-selftest.php
python3 tests/public-g3-package-selftest.py

python3 tests/g3-remote-staff-publisher-contract.py
"$PHP_BIN" tests/local-g3-public-sync-pure-selftest.php
reset_db
"$PHP_BIN" tests/g3-remote-staff-publisher-selftest.php

python3 tests/g3-emergency-public-update-contract.py
"$PHP_BIN" tests/public-g3-emergency-pure-selftest.php
"$PHP_BIN" tests/local-g3-public-reenroll-pure-selftest.php
"$PHP_BIN" tests/public-g3-emergency-update-takeover-selftest.php
python3 tests/public-g3-update-package-selftest.py

rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR"
DEPLOY_ZIP="$OUT_DIR/SoknaCafeV3-public-edge-${QUAL_VERSION}.zip"
UPDATE_ZIP="$OUT_DIR/SoknaCafeV3-public-edge-update-${QUAL_VERSION}.zip"
python3 apps/public/tools/build-deploy-package.py \
  --source apps/public \
  --version "$QUAL_VERSION" \
  --source-commit "$QUAL_SOURCE_COMMIT" \
  --out "$DEPLOY_ZIP"
python3 apps/public/tools/build-update-package.py \
  --source apps/public \
  --version "$QUAL_VERSION" \
  --source-commit "$QUAL_SOURCE_COMMIT" \
  --out "$UPDATE_ZIP"

python3 - "$DEPLOY_ZIP" "$UPDATE_ZIP" "$QUAL_VERSION" "$QUAL_SOURCE_COMMIT" <<'PY'
import json, sys, zipfile
deploy, update, version, source = sys.argv[1:]
with zipfile.ZipFile(deploy) as z:
    names=set(z.namelist())
    manifest=json.loads(z.read('manifest.json'))
    assert manifest['format']=='sokna-public-deploy-v1'
    assert manifest['version']==version
    assert manifest['source_commit']==source
    assert manifest['document_root']=='public'
    assert 'public/index.php' in names
    assert 'public/emergency.php' in names
    assert 'config.php' not in names
    assert not any(n.startswith('storage/') or '/storage/' in n for n in names)
with zipfile.ZipFile(update) as z:
    names=set(z.namelist())
    manifest=json.loads(z.read('manifest.json'))
    assert manifest['format']=='sokna-component-package-v1'
    assert manifest['component']=='public-edge'
    assert manifest['version']==version
    assert manifest['source_commit']==source
    protected=('payload/public/emergency.php','payload/src/Emergency/','payload/resources/update-trust-v1.json','payload/config.php','payload/storage/')
    assert 'payload/public/index.php' in names
    assert 'payload/bootstrap.php' in names
    assert 'payload/public/emergency.php' not in names
    assert not any(n.startswith('payload/src/Emergency/') for n in names)
    assert 'payload/resources/update-trust-v1.json' not in names
    assert 'payload/config.php' not in names
    assert not any(n.startswith('payload/storage/') for n in names)
print('G3.4 final Public deploy/update artifact qualification: OK')
PY

sha256sum "$DEPLOY_ZIP" "$UPDATE_ZIP" | tee "$OUT_DIR/CHECKSUMS.sha256"

python3 tests/r1-center-hard-removal-gate.py
python3 tests/product-parity-gate.py --mode inventory
python3 tests/scds-m6-gate.py
python3 tests/validate-v3-foundation.py --component public
python3 tests/component-registry-gate.py
python3 tests/g3-product-qualification-contract.py
find apps/public -type f -name '*.php' -print0 | xargs -0 -n1 "$PHP_BIN" -l >/dev/null
find apps/public -type f -name '*.js' -print0 | xargs -0 -n1 node --check

printf 'G3 Product Qualification: PASS\n'
