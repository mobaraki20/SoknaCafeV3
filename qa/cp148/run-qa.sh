#!/usr/bin/env bash
# Disposable GitHub runner only; no deployment or production access.
set -euo pipefail
BASE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
QA_WORK=$1; export RUNNER_TEMP="$QA_WORK" QA_DB_PASSWORD=synthetic-cp116-ci-only SOKNA_QA_DISPOSABLE=YES TMPDIR="$QA_WORK"
[[ $(id -u) != 0 && -d "$QA_WORK/local" && -d "$QA_WORK/public" ]] || exit 2
php "$BASE/preflight.php"
cp "$BASE/"{cp144-test.php,cp144-public.php,cp144-sync.php,cp144-browser.cjs,media-cleanup-db.php} "$QA_WORK/"
cd "$QA_WORK"
set +e
php cp144-test.php "$QA_WORK/local" 2>&1 | tee cp144-db-results.txt
rc1=${PIPESTATUS[0]}
if [[ $rc1 != 0 || ! -f cp144-fixture.json ]]; then echo 'BLOCKED: Local synthetic fixture failed'; exit 1; fi
php cp144-public.php "$QA_WORK/public" 2>&1 | tee cp144-public-results.txt
rcp=${PIPESTATUS[0]}
php media-cleanup-db.php "$QA_WORK/local" 2>&1 | tee cp146-media-db-results.txt
rcm=${PIPESTATUS[0]}
python3 - <<'PY'
import socket,sys
for port in (18765,18766):
 s=socket.socket()
 try:s.bind(('127.0.0.1',port))
 except OSError:print('BLOCKED: port occupied',port);sys.exit(2)
 finally:s.close()
PY
[[ $? == 0 ]] || exit 2
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:18765 -t "$QA_WORK/local/public" >cp144-local.log 2>&1 & local_pid=$!
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:18766 -t "$QA_WORK/public" "$QA_WORK/public/index.php" >cp144-public.log 2>&1 & public_pid=$!
trap 'kill "$local_pid" "$public_pid" 2>/dev/null || true' EXIT
sleep 2
kill -0 "$local_pid" 2>/dev/null || { echo 'BLOCKED: Local server failed to start'; exit 2; }
rcs=1
if [[ $rcp == 0 ]] && kill -0 "$public_pid" 2>/dev/null; then
 php cp144-sync.php "$QA_WORK/local" init 2>&1 | tee cp144-sync-results.txt
 rcs=${PIPESTATUS[0]}
fi
export QA_LOCAL_ONLY=0
if [[ $rcp != 0 || $rcs != 0 ]]; then export QA_LOCAL_ONLY=1; fi
rcb=2
if [[ -f "$QA_WORK/pw/node_modules/playwright/package.json" ]]; then
 node cp144-browser.cjs 2>&1 | tee cp144-browser-results.txt
 rcb=${PIPESTATUS[0]}
else
 echo 'NOT RUN: Playwright unavailable' | tee cp144-browser-results.txt
fi
printf 'LocalFixture=%s PublicFixture=%s Sync=%s MediaDB=%s Browser=%s\n' "$rc1" "$rcp" "$rcs" "$rcm" "$rcb" | tee QA-summary.txt
[[ $rc1 == 0 && $rcp == 0 && $rcs == 0 && $rcm == 0 && $rcb == 0 ]]
