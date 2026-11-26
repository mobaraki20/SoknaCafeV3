#!/usr/bin/env bash
set -euo pipefail
BASE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
QA_WORK=$1; export RUNNER_TEMP="$QA_WORK" QA_DB_PASSWORD=synthetic-cp116-ci-only SOKNA_QA_DISPOSABLE=YES TMPDIR="$QA_WORK"
[[ $(id -u) != 0 && -d "$QA_WORK/local" ]] || exit 2
php "$BASE/../cp148/preflight.php"
cp "$BASE/"{fixture.php,cp154b-browser.cjs} "$QA_WORK/"
cd "$QA_WORK"
php fixture.php "$QA_WORK/local" 2>&1 | tee cp154b-db-results.txt
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:18765 -t "$QA_WORK/local/public" >cp154b-local.log 2>&1 & lp=$!
trap 'kill "$lp" 2>/dev/null || true' EXIT
sleep 2; kill -0 "$lp"
node cp154b-browser.cjs 2>&1 | tee cp154b-browser-results.txt

php -r '$x=json_decode(file_get_contents("cp154b-db-summary.json"),true);exit(count($x)===2&&!in_array(false,$x,true)?0:1);'
