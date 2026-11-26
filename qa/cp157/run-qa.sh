#!/usr/bin/env bash
set -euo pipefail
BASE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
QA_WORK=$1; export RUNNER_TEMP="$QA_WORK" QA_DB_PASSWORD=synthetic-cp116-ci-only SOKNA_QA_DISPOSABLE=YES TMPDIR="$QA_WORK"
[[ $(id -u) != 0 && -d "$QA_WORK/local" && -d "$QA_WORK/public" ]] || exit 2
php "$BASE/../cp148/preflight.php"
cp "$BASE/"{fixture.php,public-fixture.php,worker.php,cp157-browser.cjs} "$QA_WORK/"
cd "$QA_WORK"; set +e
php fixture.php "$QA_WORK/local" 2>&1 | tee cp157-db-results.txt
rcf=${PIPESTATUS[0]}; if [[ $rcf != 0 || ! -f cp144-fixture.json ]]; then echo 'BLOCKED Local fixture'; exit 2; fi
php public-fixture.php "$QA_WORK/public" 2>&1 | tee cp157-public-results.txt
rcp=${PIPESTATUS[0]}
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:18765 -t "$QA_WORK/local/public" >cp157-local.log 2>&1 & lp=$!
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:18766 -t "$QA_WORK/public" "$QA_WORK/public/index.php" >cp157-public.log 2>&1 & pp=$!
wp=''; trap 'kill "$lp" "$pp" 2>/dev/null || true; if [[ -n "$wp" ]];then kill "$wp" 2>/dev/null || true;fi' EXIT
sleep 2; kill -0 "$lp" 2>/dev/null || exit 2
rcs=1; if [[ $rcp == 0 ]] && kill -0 "$pp" 2>/dev/null;then php worker.php "$QA_WORK/local" init 2>&1 | tee cp157-sync-results.txt;rcs=${PIPESTATUS[0]};fi
export QA_LOCAL_ONLY=0; if [[ $rcp != 0 || $rcs != 0 ]];then export QA_LOCAL_ONLY=1;else php worker.php "$QA_WORK/local" >cp157-worker.log 2>&1 & wp=$!;fi
rcb=2; if [[ -f "$QA_WORK/pw/node_modules/playwright/package.json" ]];then node cp157-browser.cjs 2>&1 | tee cp157-browser-results.txt; rcb=${PIPESTATUS[0]};else echo 'NOT RUN: Playwright unavailable' | tee cp157-browser-results.txt;fi
rcd=0
printf 'LocalFixture=%s PublicFixture=%s Sync=%s DB=%s Browser=%s\n' "$rcf" "$rcp" "$rcs" "$rcd" "$rcb" | tee QA-summary.txt
[[ $rcf == 0 && $rcp == 0 && $rcs == 0 && $rcd == 0 && $rcb == 0 ]]
