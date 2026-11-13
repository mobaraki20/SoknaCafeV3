<?php
declare(strict_types=1);
// Scoped disposable CI only, never cafe/home DB. No hardcoded connection outside CI.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R185_DISPOSABLE') !== 'YES' || getenv('RUNNER_OS') !== 'Linux') {
    throw new RuntimeException('R185_DISPOSABLE_GITHUB_MARIADB_ONLY');
}
$root = rtrim($argv[1] ?? '', '/');
$migration = $root . '/database/migrations/0081_mohammad_buyer_inventory_expenses.sql';
if (!is_file($migration)) throw new RuntimeException('R185_REAL_0081_MIGRATION_MISSING');
$sql = file_get_contents($migration);
if ($sql === false) throw new RuntimeException('R185_MIGRATION_UNREADABLE');
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', 'ci-root-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db = 'sokna_r185';
$pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
$pdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$db}`");
$pdo->exec("CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(120) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'operator', active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_users_role_active(role,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE user_capabilities(user_id INT UNSIGNED NOT NULL, capability VARCHAR(50) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (user_id, capability), CONSTRAINT fk_user_caps FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE personnel(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, display_name VARCHAR(120) NOT NULL, linked_user_id INT UNSIGNED NULL, personnel_code VARCHAR(40) NULL, job_title VARCHAR(120) NULL, active TINYINT(1) NOT NULL DEFAULT 1, notes VARCHAR(500) NULL, archived_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_personnel_user(linked_user_id), UNIQUE KEY uq_personnel_code(personnel_code), CONSTRAINT fk_personnel_user FOREIGN KEY(linked_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO users(username,password_hash,display_name,role,active) VALUES ('operator.fixture','not-a-real-password','Fixture Operator','operator',1)");
function check(bool $ok, string $code): void { if (!$ok) throw new RuntimeException('R185_' . $code); echo 'PASS_R185_' . $code . "\n"; }
function applyMigration(PDO $pdo, string $sql): void {
    $stmts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    if (!is_array($stmts)) throw new RuntimeException('MIGRATION_SPLIT_FAIL');
    foreach ($stmts as $statement) {
        $statement = trim($statement);
        if ($statement === '' || str_starts_with($statement, '--')) {
            // Keep SELECT/DML after leading SQL comment lines.
            $statement = preg_replace('/^(?:\s*--[^\r\n]*\r?\n)+/', '', $statement) ?? '';
            if (trim($statement) === '') continue;
        }
        if (preg_match('/^SELECT\b/i', trim($statement))) $pdo->query($statement)->closeCursor();
        else $pdo->exec($statement);
    }
}
$pdo->beginTransaction(); applyMigration($pdo,$sql);$pdo->commit();
check((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()===2,'MIGRATION_INSERTED_ONE_USER_WITH_PREEXISTING_PRESERVED');
check((int)$pdo->query("SELECT COUNT(*) FROM personnel")->fetchColumn()===1,'MIGRATION_PERSONNEL_CREATED_ONCE');
check((int)$pdo->query("SELECT COUNT(*) FROM user_capabilities")->fetchColumn()>=15,'MIGRATION_CAPABILITIES_PROVISIONED');
check((int)$pdo->query("SELECT COUNT(*) FROM user_capabilities c JOIN users u ON u.id=c.user_id WHERE c.capability='remote_access' AND c.enabled<>0")->fetchColumn()===0,'MIGRATION_REMOTE_ACCESS_DENIED');
$fp1 = $pdo->query("SELECT CONCAT(COUNT(*),':',SUM(enabled)) FROM user_capabilities")->fetchColumn();
$pdo->beginTransaction(); applyMigration($pdo,$sql);$pdo->commit();
$fp2 = $pdo->query("SELECT CONCAT(COUNT(*),':',SUM(enabled)) FROM user_capabilities")->fetchColumn();
check($fp1===$fp2 && (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()===2,'MIGRATION_REPLAY_IDEMPOTENT');
$pdo->beginTransaction();
$pdo->exec("UPDATE users SET role='admin' WHERE username='mohammad.mobaraki'");
$pdo->commit();
$before = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$rejected=false;
try {$pdo->beginTransaction(); applyMigration($pdo,$sql); $pdo->commit();}
catch (PDOException $e) {if ($pdo->inTransaction()) $pdo->rollBack(); $rejected=true;}
check($rejected && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before,'MIGRATION_IDENTITY_CONFLICT_DENIED_WITHOUT_INSERTS');
$pdo->exec("UPDATE users SET role='operator' WHERE username='mohammad.mobaraki'");
check((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='operator.fixture'")->fetchColumn()===1,'PREEXISTING_ACCOUNT_UNTOUCHED');
file_put_contents(getenv('RUNNER_TEMP') . '/R185-MARIADB-0081-RESULT.json', json_encode(['format'=>'sokna-r185-mariadb-0081-v1','result'=>'PASS','preexisting_preserved'=>true,'idempotent'=>true,'identity_collision_rejected'=>true,'remote_access_disabled'=>true,'production_db_touched'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "PASS_R185_REAL_0081_MARIADB_TRANSACTION_TEST\n";
