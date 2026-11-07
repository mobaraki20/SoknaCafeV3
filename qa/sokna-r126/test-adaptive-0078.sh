#!/usr/bin/env bash
# R126 home UAT qualification. Synthetic databases only. Do not use on a café computer.
set -euo pipefail
base=/tmp/sokna-r115-exact/local
[ -f "$base/src/Core/Migrations.php" ]
cp qa/sokna-r126/0078_adaptive_tea.sql "$base/database/migrations/0078_tea_herbal_three_sizes.sql"
export MYSQL_PWD=root
mariadb --protocol=tcp -h127.0.0.1 -P3306 -uroot -e "CREATE DATABASE sokna_r126_empty CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON sokna_r126_empty.* TO 'sokna'@'%'; FLUSH PRIVILEGES;"
unset MYSQL_PWD
# Recreate schema only (zero business/variant families), then carry over only the known 77-version ledger.
export MYSQL_PWD=sokna
mariadb-dump --protocol=tcp -h127.0.0.1 -P3306 -usokna --no-data --skip-lock-tables sokna_r115_full | mariadb --protocol=tcp -h127.0.0.1 -P3306 -usokna sokna_r126_empty
unset MYSQL_PWD
export MYSQL_PWD=root
mariadb --protocol=tcp -h127.0.0.1 -P3306 -uroot -e 'INSERT INTO sokna_r126_empty.schema_migrations SELECT * FROM sokna_r115_full.schema_migrations;'
unset MYSQL_PWD
cat >/tmp/sokna-r126-adaptive-test.php <<'PHP'
<?php
declare(strict_types=1);
require '/tmp/sokna-r115-exact/local/src/Core/MigrationExecutionException.php';
require '/tmp/sokna-r115-exact/local/src/Core/Migrations.php';
function db(string $name):PDO{return new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$name.';charset=utf8mb4','sokna','sokna', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
foreach (['sokna_r115_full'=>9,'sokna_r126_empty'=>0] as $dbname=>$expected) {
  $pdo=db($dbname);
  $migrations=new Sokna\Local\Core\Migrations($pdo,'/tmp/sokna-r115-exact/local/database/migrations');
  $count=(int)$pdo->query("SELECT COUNT(*) FROM item_variant_families WHERE family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11')")->fetchColumn();
  if($count!==$expected)throw new RuntimeException("Test setup expected $expected families, got $count");
  $before=(int)$pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn();
  $applied=$migrations->migrate();
  if($applied!==['0078_tea_herbal_three_sizes'])throw new RuntimeException('Expected only 0078 to apply');
  if($migrations->migrate()!==[])throw new RuntimeException('Reapply was not idempotent');
  $after=(int)$pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn();
  if($before!==$after)throw new RuntimeException('Historic order items count changed');
  $sql="SELECT COUNT(*) FROM item_variant_families f WHERE f.family_key IN ('h297-03','h297-04','h297-05','h297-06','h297-07','h297-08','h297-09','h297-10','h297-11') AND (SELECT COUNT(*) FROM item_variant_members m WHERE m.family_id=f.id)=3";
  $actual=(int)$pdo->query($sql)->fetchColumn();
  if($actual!==$expected)throw new RuntimeException("Expected $expected expanded families, got $actual");
  $running=(int)$pdo->query("SELECT COUNT(*) FROM schema_migration_statements WHERE state<>'applied'")->fetchColumn();
  if($running!==0)throw new RuntimeException('Unfinished statement ledger');
  echo "PASS R126 DB variant families=$expected; no business order mutation; safe rerun\n";
}
PHP
php /tmp/sokna-r126-adaptive-test.php