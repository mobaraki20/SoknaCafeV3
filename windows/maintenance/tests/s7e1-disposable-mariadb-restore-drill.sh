#!/usr/bin/env bash
# F1-S7E1: isolated MariaDB logical backup/restore rehearsal.
# Bounded to the GitHub M10 Docker service; creates/drops ONLY random lab schemas.
# No Local/Public business DB, no actual schema cutover, no release permission.
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != "true" || "${RUNNER_OS:-}" != "Linux" ||
      "${GITHUB_WORKFLOW:-}" != "M10 Release Qualification" ||
      "${SOKNA_TEST_DB_HOST:-}" != "127.0.0.1" ||
      "${SOKNA_TEST_DB_PORT:-}" != "3306" ||
      "${SOKNA_TEST_DB_ROOT_USER:-}" != "root" ||
      -z "${SOKNA_TEST_DB_ROOT_PASS:-}" ||
      ! -d "${RUNNER_TEMP:-/nonexistent}" ]]; then
  echo "S7E1_DISPOSABLE_M10_MARIADB_ONLY" >&2
  exit 64
fi

for tool in mariadb mariadb-dump sha256sum python3; do
  command -v "$tool" >/dev/null || { echo "S7E1_CLIENT_MISSING:$tool" >&2; exit 65; }
done
export MYSQL_PWD="$SOKNA_TEST_DB_ROOT_PASS"
HOST="$SOKNA_TEST_DB_HOST"
PORT="$SOKNA_TEST_DB_PORT"
USER="$SOKNA_TEST_DB_ROOT_USER"
suffix=$(python3 -c 'import secrets; print(secrets.token_hex(4))')
source_db="sokna_s7e1_${suffix}"
restore_db="${source_db}_restored"
tmp=$(mktemp -d "$RUNNER_TEMP/sokna-s7e1-XXXXXX")

mysql_cmd() {
  mariadb --protocol=tcp -h "$HOST" -P "$PORT" -u "$USER" --batch --skip-column-names "$@"
}
cleanup() {
  mysql_cmd -e "DROP DATABASE IF EXISTS \`$restore_db\`; DROP DATABASE IF EXISTS \`$source_db\`;" >/dev/null 2>&1 || true
  rm -rf -- "$tmp"
}
trap cleanup EXIT

mysql_cmd -e "CREATE DATABASE \`$source_db\`; CREATE DATABASE \`$restore_db\`;"
mysql_cmd "$source_db" -e "
CREATE TABLE audit_fixture (id INT NOT NULL PRIMARY KEY, label VARCHAR(48) NOT NULL, price INT NOT NULL) ENGINE=InnoDB;
INSERT INTO audit_fixture (id,label,price) VALUES (1,'before-backup-a',200),(2,'before-backup-b',400),(3,'before-backup-c',600);
"

before=$(mysql_cmd "$source_db" -e "SELECT COUNT(*),SUM(price) FROM audit_fixture;")
[[ "$before" == $'3\t1200' ]] || { echo "S7E1_SEED_INCONSISTENT" >&2; exit 66; }

# Single-transaction logical backup, with bounded machine-local snapshot.
mariadb-dump --protocol=tcp -h "$HOST" -P "$PORT" -u "$USER" \
  --single-transaction --quick --skip-lock-tables "$source_db" > "$tmp/backup.sql"
[[ -s "$tmp/backup.sql" ]] || { echo "S7E1_EMPTY_BACKUP" >&2; exit 67; }
original_sha=$(sha256sum "$tmp/backup.sql" | awk '{print $1}')
cp "$tmp/backup.sql" "$tmp/corrupt.sql"
printf '%s\n' "-- deliberate corruption" >> "$tmp/corrupt.sql"
corrupt_sha=$(sha256sum "$tmp/corrupt.sql" | awk '{print $1}')
[[ "$corrupt_sha" != "$original_sha" ]] || { echo "S7E1_TAMPER_UNDETECTED" >&2; exit 68; }

# No restore is permitted without matching expected backup hash.
[[ "$(sha256sum "$tmp/backup.sql" | awk '{print $1}')" == "$original_sha" ]] ||
  { echo "S7E1_BACKUP_HASH_MISMATCH" >&2; exit 69; }
mysql_cmd "$restore_db" < "$tmp/backup.sql"
restored=$(mysql_cmd "$restore_db" -e "SELECT COUNT(*),SUM(price) FROM audit_fixture;")
[[ "$restored" == "$before" ]] || { echo "S7E1_RESTORE_DATA_MISMATCH" >&2; exit 70; }

# Independent schema-forward exercise: the old snapshot remains separate,
# never applied over the forward-migrated source DB.
mysql_cmd "$source_db" -e "
ALTER TABLE audit_fixture ADD COLUMN migration_stamp INT NOT NULL DEFAULT 1;
UPDATE audit_fixture SET price=price+100 WHERE id=1;
INSERT INTO audit_fixture (id,label,price,migration_stamp) VALUES (4,'post-forward',900,2);
"
forward=$(mysql_cmd "$source_db" -e "SELECT COUNT(*),SUM(price) FROM audit_fixture;")
unchanged=$(mysql_cmd "$restore_db" -e "SELECT COUNT(*),SUM(price) FROM audit_fixture;")
forward_col=$(mysql_cmd "$source_db" -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$source_db' AND TABLE_NAME='audit_fixture' AND COLUMN_NAME='migration_stamp';")
restored_col=$(mysql_cmd "$restore_db" -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$restore_db' AND TABLE_NAME='audit_fixture' AND COLUMN_NAME='migration_stamp';")
[[ "$forward" == $'4\t2200' && "$unchanged" == "$before" &&
   "$forward_col" == 1 && "$restored_col" == 0 ]] ||
  { echo "S7E1_FORWARD_OR_RESTORE_ISOLATION_FAILED" >&2; exit 71; }

echo '{"format":"sokna-f1-s7e1-isolated-mariadb-drill-v1","result":"PASS","mariadb_container_only":true,"logical_consistent_backup":true,"sha256_integrity_checked":true,"independent_restore_verified":true,"forward_schema_isolated":true,"real_production_backup_qualified":false,"backup_retention_offhost_tested":false,"witness_stage_provenance_complete":false,"crash_during_real_schema_migration_tested":false,"db_forward_rollback_authorized":false,"panel_update_ready":false}'
