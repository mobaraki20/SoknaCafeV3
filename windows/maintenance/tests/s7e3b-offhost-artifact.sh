#!/usr/bin/env bash
# S7E3B: TWO independent GitHub hosted Linux VMs, upload/download of encrypted
# SAMPLE MariaDB backup. Does not prove real KMS custody or production RPO/RTO.
set -euo pipefail
mode="${1:-}"
if [[ "${GITHUB_ACTIONS:-}" != true || "${RUNNER_OS:-}" != Linux ||
      "${GITHUB_WORKFLOW:-}" != 'M10 Release Qualification' ||
      "${SOKNA_S7E3B_CI_ONLY:-}" != 1 ||
      "${SOKNA_TEST_DB_HOST:-}" != 127.0.0.1 ||
      "${SOKNA_TEST_DB_ROOT_USER:-}" != root ||
      "${SOKNA_TEST_DB_PORT:-}" != 3306 ||
      -z "${SOKNA_TEST_DB_ROOT_PASS:-}" ||
      ! -d "${RUNNER_TEMP:-/absent}" ]]; then
  echo S7E3B_DISPOSABLE_LINUX_CI_ONLY >&2; exit 64
fi
for bin in mariadb mariadb-dump openssl gzip sha256sum python3; do
  command -v "$bin" >/dev/null || { echo "S7E3B_MISSING:$bin" >&2; exit 65; }
done
export MYSQL_PWD="$SOKNA_TEST_DB_ROOT_PASS"
DB_PREFIX="sokna_s7e3b_"
MYSQL=(mariadb --protocol=tcp -h "$SOKNA_TEST_DB_HOST" -P "$SOKNA_TEST_DB_PORT" -u root --batch --skip-column-names)
DUMP=(mariadb-dump --protocol=tcp -h "$SOKNA_TEST_DB_HOST" -P "$SOKNA_TEST_DB_PORT" -u root --single-transaction --quick --skip-lock-tables)
tmp="$(mktemp -d "$RUNNER_TEMP/sokna-s7e3b-XXXXXXXX")"
cleanup() { rm -rf -- "$tmp"; }
trap cleanup EXIT
case "$mode" in
  export)
    out="${SOKNA_ARTIFACT_OUTPUT:?}"
    mkdir -p "$out/backup" "$out/test-only-key"
    db="${DB_PREFIX}$(python3 -c 'import secrets;print(secrets.token_hex(4))')"
    trap '"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$db\`;" >/dev/null 2>&1 || true; cleanup' EXIT
    "${MYSQL[@]}" -e "CREATE DATABASE \`$db\`;"
    "${MYSQL[@]}" "$db" -e "CREATE TABLE proof (id INT PRIMARY KEY, amount INT NOT NULL) ENGINE=InnoDB;
INSERT INTO proof (id,amount) VALUES (1,10),(2,20),(3,30);"
    before="$("${MYSQL[@]}" "$db" -e 'SELECT COUNT(*),SUM(amount) FROM proof')"
    [[ "$before" == $'3\t60' ]] || { echo S7E3B_BAD_SEED >&2; exit 66; }
    "${DUMP[@]}" "$db" | gzip -9 > "$tmp/plain.sql.gz"
    [[ -s "$tmp/plain.sql.gz" ]] || { echo S7E3B_NO_DUMP >&2; exit 67; }
    openssl req -new -x509 -nodes -newkey rsa:3072 -days 1 \
      -keyout "$out/test-only-key/decrypt-test-fixture.key" \
      -out "$out/backup/recipient-test-fixture.crt" \
      -subj '/CN=SOKNA-CI-TEST-KEY-NEVER-PRODUCTION' >/dev/null 2>&1
    chmod 600 "$out/test-only-key/decrypt-test-fixture.key"
    openssl cms -encrypt -binary -aes256 -outform DER \
      -in "$tmp/plain.sql.gz" -out "$out/backup/backup.cms" \
      "$out/backup/recipient-test-fixture.crt"
    openssl dgst -sha256 -sign "$out/test-only-key/decrypt-test-fixture.key" \
      -out "$out/backup/backup.sig" "$out/backup/backup.cms"
    sha="$(sha256sum "$out/backup/backup.cms" | cut -d' ' -f1)"
    [[ -n "$sha" && "$sha" =~ ^[0-9a-f]{64}$ ]] || exit 68
    [[ "$(od -An -t x1 -N4 "$out/backup/backup.cms" | tr -d ' \n')" != '1f8b0800' ]] || {
      echo S7E3B_BACKUP_EXPOSES_RAW_GZIP >&2; exit 69; }
    # No unencrypted SQL or JSON business dump in uploaded artifacts.
    printf '%s\n' "$sha" > "$out/backup/backup.sha256"
    if [[ -n "${GITHUB_OUTPUT:-}" ]]; then echo "backup_sha256=$sha" >> "$GITHUB_OUTPUT"; fi
    echo '{"format":"sokna-f1-s7e3b-offhost-export-v1","result":"PASS","test_only":true,"encrypted_payload":true,"private_key_stored_in_separate_ci_artifact":true,"production_key_custody_qualified":false}'
    ;;
  restore)
    in_dir="${SOKNA_ARTIFACT_INPUT:?}"
    encrypted="$in_dir/backup/backup.cms"
    signature="$in_dir/backup/backup.sig"
    cert="$in_dir/backup/recipient-test-fixture.crt"
    key="$in_dir/test-only-key/decrypt-test-fixture.key"
    for p in "$encrypted" "$signature" "$cert" "$key"; do [[ -s "$p" ]] || { echo S7E3B_ARTIFACT_NOT_FOUND >&2; exit 70; }; done
    # GitHub job output crosses runner boundary via separate control-plane,
    # while encrypted ciphertext is transported by Actions artifacts.
    expected="${SOKNA_EXPECTED_BACKUP_SHA256:?}"
    actual="$(sha256sum "$encrypted" | cut -d' ' -f1)"
    [[ "$expected" == "$actual" && "$(cat "$in_dir/backup/backup.sha256")" == "$actual" ]] || {
      echo S7E3B_REMOTE_HASH_MISMATCH >&2; exit 71; }
    openssl x509 -in "$cert" -pubkey -noout > "$tmp/pub.pem"
    openssl dgst -sha256 -verify "$tmp/pub.pem" -signature "$signature" "$encrypted" >/dev/null || {
      echo S7E3B_REMOTE_SIGNATURE_INVALID >&2; exit 72; }
    cp "$encrypted" "$tmp/corrupt.cms"
    python3 - "$tmp/corrupt.cms" <<'PY'
import sys
p=sys.argv[1]
b=bytearray(open(p,'rb').read())
assert len(b)>300
b[-60] ^= 1
open(p,'wb').write(b)
PY
    if openssl dgst -sha256 -verify "$tmp/pub.pem" -signature "$signature" "$tmp/corrupt.cms" >/dev/null 2>&1; then
      echo S7E3B_CORRUPTED_CIPHERTEXT_AUTHORIZED >&2; exit 73
    fi
    openssl cms -decrypt -binary -inform DER \
      -in "$encrypted" -recip "$cert" -inkey "$key" -out "$tmp/dump.sql.gz"
    gzip -t "$tmp/dump.sql.gz"
    gzip -cd "$tmp/dump.sql.gz" > "$tmp/restore.sql"
    restored="${DB_PREFIX}restored_$(python3 -c 'import secrets;print(secrets.token_hex(4))')"
    trap '"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$restored\`;" >/dev/null 2>&1 || true; cleanup' EXIT
    "${MYSQL[@]}" -e "CREATE DATABASE \`$restored\`;"
    "${MYSQL[@]}" "$restored" < "$tmp/restore.sql"
    got="$("${MYSQL[@]}" "$restored" -e 'SELECT COUNT(*),SUM(amount) FROM proof')"
    [[ "$got" == $'3\t60' ]] || { echo S7E3B_OFFHOST_RESTORED_DATA_MISMATCH >&2; exit 74; }
    echo '{"format":"sokna-f1-s7e3b-offhost-restore-v1","result":"PASS","independent_github_hosted_runner":true,"encrypted_artifact_cross_runner":true,"signed_ciphertext_verified":true,"tamper_test_rejected":true,"real_mariadb_restore_verified":true,"sample_only":true,"production_key_custody_qualified":false,"production_offsite_store_qualified":false,"panel_update_ready":false}'
    ;;
  *) echo S7E3B_BAD_MODE >&2; exit 75;;
esac