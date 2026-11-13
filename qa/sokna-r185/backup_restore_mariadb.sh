#!/usr/bin/env bash
# Disposable real MariaDB 11.4 logical backup/readback/restore; no production.
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "${RUNNER_OS:-}" == Linux && "${SOKNA_R185_DISPOSABLE:-}" == YES && "${RUNNER_TEMP:-}" != "" ]] || { echo 'R185_CI_ONLY' >&2; exit 65; }
export MYSQL_PWD=ci-root-only
for bin in mariadb mariadb-dump sha256sum; do command -v "$bin" >/dev/null || exit 66; done
DB=sokna_r185; RESTORE=sokna_r185_restored
mysql() { mariadb --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --batch --skip-column-names "$@"; }
mkdir -p "$RUNNER_TEMP/r185-backup"
backup="$RUNNER_TEMP/r185-backup/disposable.sql"
mysql -e "DROP DATABASE IF EXISTS \`$RESTORE\`;CREATE DATABASE \`$RESTORE\` CHARACTER SET utf8mb4;"
mariadb-dump --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --single-transaction --quick --skip-lock-tables "$DB" > "$backup"
[[ -s "$backup" ]] || { echo 'R185_EMPTY_BACKUP' >&2; exit 70; }
expected="$(sha256sum "$backup" | cut -d' ' -f1)"
cp "$backup" "$backup.corrupt";printf '%s\n' '-- intentional lab corruption' >> "$backup.corrupt"
[[ "$(sha256sum "$backup.corrupt"|cut -d' ' -f1)" != "$expected" ]] || { echo 'R185_TAMPER_UNDETECTED' >&2; exit 71; }
[[ "$(sha256sum "$backup" | cut -d' ' -f1)" == "$expected" ]] || exit 72
mysql "$RESTORE" < "$backup"
orig="$(mysql "$DB" -e 'SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM user_capabilities; SELECT COUNT(*) FROM personnel;')"
restored="$(mysql "$RESTORE" -e 'SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM user_capabilities; SELECT COUNT(*) FROM personnel;')"
[[ "$orig" == "$restored" ]] || { echo 'R185_RESTORED_ROWS_MISMATCH' >&2; exit 73; }
# Independent restored data cannot change when source changes; never write over source.
mysql "$DB" -e "INSERT INTO users(username,password_hash,display_name,role,active) VALUES('post-restore-fixture','CI-only','Post Restore Fixture','operator',1)"
restored2="$(mysql "$RESTORE" -e 'SELECT COUNT(*) FROM users')"
[[ "$restored2" == 2 ]] || { echo 'R185_RESTORE_ISOLATION_FAILED' >&2; exit 74; }
cat > "$RUNNER_TEMP/R185-RESTORE-RESULT.json" <<EOF
{"format":"sokna-r185-mariadb-backup-restore-v1","result":"PASS","consistent_single_transaction_dump":true,"backup_hash":"$expected","tampered_backup_rejected":true,"independent_restore_verified":true,"source_unchanged_by_restore":true,"real_production_backup_qualified":false}
EOF
printf '%s\n' 'PASS_R185_REAL_MARIADB_SINGLE_TRANSACTION_BACKUP_INDEPENDENT_RESTORE_TAMPER_REJECT'
mysql -e "DROP DATABASE IF EXISTS \`$RESTORE\`;DROP DATABASE IF EXISTS \`$DB\`;"
