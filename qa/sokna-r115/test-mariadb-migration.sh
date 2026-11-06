#!/usr/bin/env bash
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
: "${MYSQL_HOST:=127.0.0.1}"
: "${MYSQL_PORT:=3306}"
: "${MYSQL_USER:=sokna}"
: "${MYSQL_PASSWORD:=sokna}"
: "${MYSQL_DATABASE:=sokna_migration_qa}"
export MYSQL_PWD="$MYSQL_PASSWORD"
mysql_cmd=(mariadb --protocol=tcp -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" "$MYSQL_DATABASE" --batch --skip-column-names)

echo "Verifying migration file identity from exact R115 archive"
echo '6091a37fa5a087351c2614b3e11b259f446c09dbb6b878adf6da59f81540a9d9  qa/sokna-r115/0077_incremental_opening_inventory.sql' | sha256sum -c -

cat > /tmp/sokna-r115-qa-schema.sql <<'SQL'
CREATE TABLE inventory_items (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(60) NOT NULL) ENGINE=InnoDB;
CREATE TABLE inventory_count_sessions (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE inventory_count_lines (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO inventory_items (id,name) VALUES (1,'zero confirmed'),(2,'uncounted'),(3,'later day');
INSERT INTO inventory_count_sessions (id) VALUES (10),(11);
INSERT INTO inventory_count_lines (id) VALUES (100),(101);
SQL
"${mysql_cmd[@]}" < /tmp/sokna-r115-qa-schema.sql
"${mysql_cmd[@]}" < qa/sokna-r115/0077_incremental_opening_inventory.sql
"${mysql_cmd[@]}" < qa/sokna-r115/0077_incremental_opening_inventory.sql

printf '%s\n' "INSERT INTO inventory_opening_activations (inventory_item_id,count_session_id,count_line_id,actor_user_id) VALUES(1,10,100,4);" | "${mysql_cmd[@]}"
present=$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM inventory_opening_activations WHERE inventory_item_id=1")
uncounted=$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM inventory_opening_activations WHERE inventory_item_id=2")
test "$present" = "1" && test "$uncounted" = "0"
echo 'PASS: zero-confirmed can be activated and an uncounted item stays unactivated'

# Duplicate count line must not be shared by two ingredients.
if "${mysql_cmd[@]}" -e "INSERT INTO inventory_opening_activations (inventory_item_id,count_session_id,count_line_id) VALUES(3,10,100)"; then
  echo 'FAIL: reused count_line_id accepted' >&2; exit 1
fi
echo 'PASS: count line cannot activate multiple ingredients'

# Referential integrity is essential to prevent losing count provenance.
if "${mysql_cmd[@]}" -e "INSERT INTO inventory_opening_activations (inventory_item_id,count_session_id,count_line_id) VALUES(2,99,101)"; then
  echo 'FAIL: unknown count session accepted' >&2; exit 1
fi
echo 'PASS: invalid count session rejected'

# Only an actual second-day count activates the remaining ingredient.
"${mysql_cmd[@]}" -e "INSERT INTO inventory_opening_activations (inventory_item_id,count_session_id,count_line_id) VALUES(3,11,101)"
later=$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM inventory_opening_activations WHERE inventory_item_id=3 AND count_session_id=11")
test "$later" = "1"
echo 'PASS: day-two count activation persists'

# Deletion of referenced count session must fail, guarding audit provenance.
if "${mysql_cmd[@]}" -e "DELETE FROM inventory_count_sessions WHERE id=11"; then
  echo 'FAIL: referenced count session could be deleted' >&2; exit 1
fi
echo 'PASS: count provenance deletion blocked'

# PDO transaction rollback must not leave an activation.
php -r '
$pdo=new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",getenv("MYSQL_HOST"),getenv("MYSQL_PORT"),getenv("MYSQL_DATABASE")),getenv("MYSQL_USER"),getenv("MYSQL_PASSWORD"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$pdo->exec("INSERT INTO inventory_opening_activations(inventory_item_id,count_session_id,count_line_id) VALUES(2,11,102)");
$pdo->rollBack();
$n=(int)$pdo->query("SELECT COUNT(*) FROM inventory_opening_activations WHERE inventory_item_id=2")->fetchColumn();
if ($n!==0) {fwrite(STDERR,"transaction rollback failed\n");exit(1);}
echo "PASS: transaction rollback leaves ingredient unactivated\n";
'
echo 'R115 isolated MariaDB migration checks PASSED.'
