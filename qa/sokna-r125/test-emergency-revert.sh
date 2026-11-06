#!/usr/bin/env bash
# Isolated, synthetic MariaDB 11.4 safety-qualification of exact R125 0077/0078 state.
set -euo pipefail
: "${MYSQL_HOST:?}" "${MYSQL_USER:?}" "${MYSQL_DATABASE:?}"
export MYSQL_PWD="$MYSQL_PASSWORD"
mysql_cmd=(mariadb --protocol=tcp -h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" --database="$MYSQL_DATABASE" --batch --skip-column-names)
"${mysql_cmd[@]}" <<'SQL'
DROP TABLE IF EXISTS inventory_opening_activations;
DROP TABLE IF EXISTS schema_migration_statements;
DROP TABLE IF EXISTS schema_migrations;
DROP TABLE IF EXISTS qa_business_orders;
CREATE TABLE qa_business_orders(id INT PRIMARY KEY,amount INT NOT NULL) ENGINE=InnoDB;
INSERT INTO qa_business_orders VALUES(1,700000);
CREATE TABLE schema_migrations (version VARCHAR(160) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE schema_migration_statements (version VARCHAR(160) NOT NULL,ordinal_no INT UNSIGNED NOT NULL,statement_sha256 CHAR(64) NOT NULL,state VARCHAR(16) NOT NULL,started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, applied_at DATETIME NULL, PRIMARY KEY (version,ordinal_no)) ENGINE=InnoDB;
CREATE TABLE inventory_opening_activations (inventory_item_id INT UNSIGNED NOT NULL PRIMARY KEY,count_session_id BIGINT UNSIGNED NOT NULL,count_line_id BIGINT UNSIGNED NOT NULL UNIQUE,activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,actor_user_id INT UNSIGNED NULL) ENGINE=InnoDB;
INSERT INTO schema_migrations (version) VALUES ('0076_late_served_accounting'),('0077_incremental_opening_inventory');
INSERT INTO schema_migration_statements (version,ordinal_no,statement_sha256,state) VALUES
('0077_incremental_opening_inventory',1,REPEAT('a',64),'applied'),
('0078_tea_herbal_three_sizes',1,REPEAT('b',64),'running');
SQL
# The actual executable repair checks the exact target PHP migration SQL hashes and version hashes.
preflight() {
 local got
 got=$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM schema_migrations WHERE version > '0076_late_served_accounting';")
 [[ "$got" = "1" ]] || return 20
 got=$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM schema_migration_statements WHERE state <> 'applied';")
 [[ "$got" = "1" ]] || return 21
 got=$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM inventory_opening_activations;")
 [[ "$got" = "0" ]] || return 22
 got=$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM schema_migration_statements WHERE version='0078_tea_herbal_three_sizes' AND ordinal_no=1 AND state='running';")
 [[ "$got" = "1" ]] || return 23
}
preflight || { echo 'FAIL baseline not exact'; exit 1; }
"${mysql_cmd[@]}" -e "INSERT INTO inventory_opening_activations(inventory_item_id,count_session_id,count_line_id) VALUES(42,1,1);"
if preflight; then echo 'FAIL: nonempty table did not block rollback';exit 1;fi
"${mysql_cmd[@]}" -e "DELETE FROM inventory_opening_activations;"
"${mysql_cmd[@]}" -e "INSERT INTO schema_migrations(version) VALUES('0079_cafe_staff_initial_accounts');"
if preflight; then echo 'FAIL: unexpected migration did not block rollback';exit 1;fi
"${mysql_cmd[@]}" -e "DELETE FROM schema_migrations WHERE version='0079_cafe_staff_initial_accounts';"
preflight || { echo 'FAIL baseline not exact after contaminated cases'; exit 1; }
"${mysql_cmd[@]}" <<'SQL'
SELECT GET_LOCK('sokna_v3_local_migrations',5) INTO @g;
DROP TABLE inventory_opening_activations;
START TRANSACTION;
DELETE FROM schema_migration_statements WHERE version='0078_tea_herbal_three_sizes' AND ordinal_no=1 AND state='running';
DELETE FROM schema_migration_statements WHERE version='0077_incremental_opening_inventory' AND ordinal_no=1 AND state='applied';
DELETE FROM schema_migrations WHERE version='0077_incremental_opening_inventory';
COMMIT;
SELECT RELEASE_LOCK('sokna_v3_local_migrations') INTO @r;
SQL
head=$("${mysql_cmd[@]}" -e 'SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1;')
[[ "$head" == '0076_late_served_accounting' ]]
[[ "$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM schema_migration_statements WHERE version>='0077_incremental_opening_inventory';")" == "0" ]]
[[ "$("${mysql_cmd[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='inventory_opening_activations';")" == "0" ]]
[[ "$("${mysql_cmd[@]}" -e "SELECT amount FROM qa_business_orders WHERE id=1;")" == "700000" ]]
if preflight; then echo 'FAIL: rerun should refuse';exit 1;fi
echo 'PASS: synthetic MariaDB 11.4 exact revert, unexpected migration and nonempty table rejected, idempotent fail-closed, business row retained'