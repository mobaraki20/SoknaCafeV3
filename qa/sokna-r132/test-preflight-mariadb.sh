#!/usr/bin/env bash
# Disposable CI only. Never connect this test to a cafe database.
set -euo pipefail
cd "$(dirname "$0")/../.."
temp="$(mktemp -d)"
trap 'rm -rf "$temp"' EXIT
mkdir -p "$temp/root" "$temp/old/src/Core" "$temp/next/src/Core" "$temp/old/database/migrations" "$temp/next/database/migrations"
cp apps/local-web/src/Core/{Config.php,Database.php,Migrations.php,MigrationExecutionException.php} "$temp/next/src/Core/"
cat > "$temp/root/config.php" <<'PHP'
<?php
return ['db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'sokna_r132_test','user'=>'sokna_r132','pass'=>'ci-only-password','charset'=>'utf8mb4']];
PHP
cat > "$temp/old/database/migrations/0001_base.sql" <<'SQL'
CREATE TABLE demo_guard_data (id INT PRIMARY KEY, eligible INT NOT NULL) ENGINE=InnoDB;
SQL
cp "$temp/old/database/migrations/0001_base.sql" "$temp/next/database/migrations/0001_base.sql"
cat > "$temp/next/database/migrations/0002_guard.sql" <<'SQL'
-- A deliberate SELECT guard: eligibility must match one row.
SELECT (SELECT x FROM (SELECT 1 x UNION ALL SELECT 2 x) fail
  WHERE (SELECT COUNT(*) FROM demo_guard_data WHERE eligible=1)<>1);
UPDATE demo_guard_data SET eligible=2 WHERE id=1;
SQL
export MYSQL_PWD='ci-only-password'
mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 sokna_r132_test <<'SQL'
CREATE TABLE schema_migrations (
  version VARCHAR(160) PRIMARY KEY,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE schema_migration_statements (
  version VARCHAR(160) NOT NULL,ordinal_no INT UNSIGNED NOT NULL,
  statement_sha256 CHAR(64) NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'running',
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,applied_at DATETIME NULL,
  PRIMARY KEY(version,ordinal_no)
) ENGINE=InnoDB;
CREATE TABLE demo_guard_data (id INT PRIMARY KEY,eligible INT NOT NULL) ENGINE=InnoDB;
INSERT INTO demo_guard_data VALUES(1,0);
INSERT INTO schema_migrations(version) VALUES('0001_base');
SQL
plan="$(python3 - "$temp" <<'PY'
import hashlib,json,pathlib,sys
t=pathlib.Path(sys.argv[1])
def cat(p):
    return {x.stem:hashlib.sha256(x.read_bytes()).hexdigest()
            for x in sorted((p/'database/migrations').glob('*.sql'))}
def sha(c):
    data=''.join(k+chr(0)+c[k]+chr(10) for k in sorted(c)).encode()
    return 'migration-ledger-sha256-v1:'+hashlib.sha256(data).hexdigest()
a=cat(t/'old');b=cat(t/'next')
print(json.dumps({'SourceFingerprint':sha(a),'TargetFingerprint':sha(b),
    'SourceHead':'0001_base','TargetHead':'0002_guard',
    'SourceCount':len(a),'TargetCount':len(b)}))
PY
)"
# Existing embedded PHP runner (no synthetic copy of the guard code).
php_source="$(tail -n +2 windows/maintenance/src/Sokna.Maintenance.Core/RoutineLocalDatabase.php)"
set +e
php -r "$php_source" "$temp/root" "$temp/old" "$temp/next" preflight "$plan" '' '' > "$temp/blocked.out" 2>"$temp/blocked.err"
code=$?
set -e
test "$code" -eq 78 || { echo "FAIL: expected guard failure status=78 got $code"; cat "$temp/blocked.out"; exit 1; }
grep -Eq '^SOKNA_DB_SAFE_FAILURE migration=0002_guard ordinal=1 state=[A-Z0-9]{5} errno=[0-9]+$' "$temp/blocked.err"
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e 'SELECT eligible FROM demo_guard_data WHERE id=1')" = 0
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e 'SELECT COUNT(*) FROM schema_migration_statements')" = 0
echo 'PASS: failing guard is rejected before business writes or journal mutation'
mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 sokna_r132_test -e 'UPDATE demo_guard_data SET eligible=1 WHERE id=1'
php -r "$php_source" "$temp/root" "$temp/old" "$temp/next" preflight "$plan" '' '' > "$temp/passed.out" 2>"$temp/passed.err"
grep -qx 'SOKNA_DATABASE_OK' "$temp/passed.out"
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e 'SELECT eligible FROM demo_guard_data WHERE id=1')" = 1
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e 'SELECT COUNT(*) FROM schema_migration_statements')" = 0
echo 'PASS: eligible guard succeeds without applying target DML'
# Simulate data changing between preflight and the first SQL statement.
# The genuine forward migration engine must fail-closed, preserve its WAL,
# then resume from the same signed statements after safe external repair.
mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 sokna_r132_test -e 'UPDATE demo_guard_data SET eligible=0 WHERE id=1'
set +e
php -r "$php_source" "$temp/root" "$temp/old" "$temp/next" apply "$plan" '' '' > "$temp/forward.fail.out" 2>"$temp/forward.fail.err"
status=$?
set -e
test "$status" -eq 78
grep -q 'migration=0002_guard ordinal=1' "$temp/forward.fail.err"
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e "SELECT state FROM schema_migration_statements WHERE version='0002_guard' AND ordinal_no=1")" = 'running'
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e 'SELECT COUNT(*) FROM schema_migrations')" = 1
echo 'PASS: failed DB forward leaves an explicit RUNNING statement, not fake rollback'
# Preflight must not silently paper over a partial migration.
set +e
php -r "$php_source" "$temp/root" "$temp/old" "$temp/next" preflight "$plan" '' '' > "$temp/partial.out" 2>"$temp/partial.err"
status=$?
set -e
test "$status" -eq 68
echo 'PASS: interrupted migration remains blocked for an ordinary new activation'
mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 sokna_r132_test -e 'UPDATE demo_guard_data SET eligible=1 WHERE id=1'
php -r "$php_source" "$temp/root" "$temp/old" "$temp/next" apply "$plan" '' '' > "$temp/forward.passed.out" 2>"$temp/forward.passed.err"
grep -qx 'SOKNA_DATABASE_OK' "$temp/forward.passed.out"
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e 'SELECT eligible FROM demo_guard_data WHERE id=1')" = 2
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e "SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")" = 0
test "$(mariadb --protocol=tcp -h127.0.0.1 -usokna_r132 -N -B sokna_r132_test -e 'SELECT COUNT(*) FROM schema_migrations')" = 2
php -r "$php_source" "$temp/root" "$temp/old" "$temp/next" apply "$plan" '' '' > "$temp/forward.idempotent.out" 2>"$temp/forward.idempotent.err"
grep -qx 'SOKNA_DATABASE_OK' "$temp/forward.idempotent.out"
echo 'PASS: externally reconciled failed guard resumes forward safely and rerun is idempotent'

