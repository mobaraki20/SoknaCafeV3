#!/usr/bin/env bash
set -euo pipefail
BASE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
QA_WORK=$1; export RUNNER_TEMP="$QA_WORK" QA_DB_PASSWORD=synthetic-cp116-ci-only SOKNA_QA_DISPOSABLE=YES TMPDIR="$QA_WORK"
[[ $(id -u) != 0 && -d "$QA_WORK/local" ]] || exit 2
php "$BASE/../cp148/preflight.php"
cp "$BASE/"{fixture.php,cp154-browser.cjs} "$QA_WORK/"
cd "$QA_WORK"
php fixture.php "$QA_WORK/local" 2>&1 | tee cp154-db-results.txt
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:18765 -t "$QA_WORK/local/public" >cp154-local.log 2>&1 & lp=$!
trap 'kill "$lp" 2>/dev/null || true' EXIT
sleep 2; kill -0 "$lp"
node cp154-browser.cjs 2>&1 | tee cp154-browser-results.txt
