<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/apps/local-web/src/Core/Migrations.php';
use Sokna\Local\Core\Migrations;

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','ci-root-only',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$pdo->exec('DROP DATABASE IF EXISTS sokna_r140_preflight');
$pdo->exec('CREATE DATABASE sokna_r140_preflight CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE sokna_r140_preflight');
$pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(160) PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec("INSERT INTO schema_migrations(version) VALUES('0076_late_served_accounting')");
$pdo->exec('CREATE TABLE inventory_items(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE inventory_count_sessions(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE inventory_count_lines(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$tmp=sys_get_temp_dir().'/sokna-r140-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
file_put_contents($tmp.'/0077_incremental_opening_inventory.sql','CREATE TABLE sample_unexecuted(id INT);');
$check=function(bool $expected,string $label)use($pdo,$tmp):void{
    $ok=false;
    try {
        (new Migrations($pdo,$tmp))->catalog();
        $ok=true;
    }catch (RuntimeException $e){
        if($expected)throw $e;
        if(!str_starts_with($e->getMessage(),'LOCAL_0077_')) throw $e;
    }
    if($ok!==$expected)throw new RuntimeException('R140 failed case '.$label);
    echo 'PASS '.$label.PHP_EOL;
};
try {
    $check(true,'healthy parent tables before DDL');
    $newTable=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_opening_activations'")->fetchColumn();
    if($newTable!==0)throw new RuntimeException('preflight modified database');
    echo 'PASS preflight did not create table'.PHP_EOL;

    $pdo->exec('ALTER TABLE inventory_items ENGINE=MyISAM');
    $check(false,'non InnoDB parent rejected');
    $pdo->exec('ALTER TABLE inventory_items ENGINE=InnoDB');

    $pdo->exec('CREATE TABLE other_table(id INT UNSIGNED PRIMARY KEY,item_id INT UNSIGNED, CONSTRAINT fk_opening_activation_item FOREIGN KEY(item_id) REFERENCES inventory_items(id)) ENGINE=InnoDB');
    $check(false,'foreign key name collision rejected');
    $pdo->exec('DROP TABLE other_table');

    $pdo->exec('CREATE TABLE inventory_opening_activations (
      inventory_item_id INT UNSIGNED NOT NULL PRIMARY KEY,
      count_session_id BIGINT UNSIGNED NOT NULL,
      count_line_id BIGINT UNSIGNED NOT NULL UNIQUE,
      activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      actor_user_id INT UNSIGNED NULL,
      CONSTRAINT fk_opening_activation_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE RESTRICT,
      CONSTRAINT fk_opening_activation_session FOREIGN KEY (count_session_id) REFERENCES inventory_count_sessions(id) ON UPDATE CASCADE ON DELETE RESTRICT,
      CONSTRAINT fk_opening_activation_line FOREIGN KEY (count_line_id) REFERENCES inventory_count_lines(id) ON UPDATE CASCADE ON DELETE RESTRICT,
      INDEX idx_opening_activation_session (count_session_id, activated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $check(true,'already-created correct interrupted DDL resumes');
    // MariaDB requires an index supporting the FK when the expected composite index is dropped.
    $pdo->exec('ALTER TABLE inventory_opening_activations ADD INDEX fk_session_support_tmp(count_session_id)');
    $pdo->exec('ALTER TABLE inventory_opening_activations DROP INDEX idx_opening_activation_session');
    $check(false,'wrong existing index rejected');
    $pdo->exec('ALTER TABLE inventory_opening_activations ADD INDEX idx_opening_activation_session(count_session_id,activated_at)');
    $pdo->exec('ALTER TABLE inventory_opening_activations DROP INDEX fk_session_support_tmp');
    $pdo->exec('ALTER TABLE inventory_opening_activations DROP FOREIGN KEY fk_opening_activation_line');
    $check(false,'wrong existing foreign key rejected');
    $pdo->exec('ALTER TABLE inventory_opening_activations ADD CONSTRAINT fk_opening_activation_line FOREIGN KEY (count_line_id) REFERENCES inventory_count_lines(id) ON UPDATE CASCADE ON DELETE RESTRICT');
    $pdo->exec('ALTER TABLE inventory_opening_activations MODIFY COLUMN actor_user_id INT UNSIGNED NOT NULL');
    $check(false,'wrong nullable column rejected');
    echo 'R140_REAL_MARIADB_0077_READONLY_PREFLIGHT_PASS'.PHP_EOL;
}finally {
    @unlink($tmp.'/0077_incremental_opening_inventory.sql');
    @rmdir($tmp);
    $pdo->exec('DROP DATABASE IF EXISTS sokna_r140_preflight');
}