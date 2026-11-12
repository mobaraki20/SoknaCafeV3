<?php
declare(strict_types=1);
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_R171_DISPOSABLE') !== 'YES' || count($argv) !== 2) exit(90);
$root = $argv[1];
require_once $root.'/src/Core/Migrations.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r171_fresh;charset=utf8mb4', 'root', 'ci-root-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(120) NOT NULL, role VARCHAR(40) NOT NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('CREATE TABLE personnel (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, display_name VARCHAR(120) NOT NULL, job_title VARCHAR(120) NULL, linked_user_id INT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec('CREATE TABLE user_capabilities (user_id INT UNSIGNED NOT NULL, capability VARCHAR(80) NOT NULL, enabled TINYINT NOT NULL DEFAULT 0, PRIMARY KEY(user_id,capability)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$path = $root.'/database/migrations/0081_mohammad_buyer_inventory_expenses.sql';
$dir = sys_get_temp_dir().'/r171-only-'.bin2hex(random_bytes(4));
mkdir($dir);copy($path,$dir.'/0081_mohammad_buyer_inventory_expenses.sql');
try {
  $runner = new \Sokna\Local\Core\Migrations($pdo,$dir);
  if ($runner->migrate() !== ['0081_mohammad_buyer_inventory_expenses']) throw new RuntimeException('first migration not applied');
  $user = $pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
  if (count($user)!==1 || ($user[0]['role']??'')!=='operator' || (int)$user[0]['active']!==1 || !str_starts_with((string)$user[0]['password_hash'],'$2y$')) throw new RuntimeException('employee not created with hashed credential');
  $caps = $pdo->query('SELECT capability,enabled FROM user_capabilities')->fetchAll(PDO::FETCH_KEY_PAIR);
  foreach (['inventory_view','inventory_operations','inventory_manage','expenses_record'] as $k) if ((int)($caps[$k]??0)!==1) throw new RuntimeException('missing expected capability: '.$k);
  foreach (['cashier_accounts','staff_account_manage','remote_access'] as $k) if ((int)($caps[$k]??1)!==0) throw new RuntimeException('unexpected privileged capability: '.$k);
  if ($runner->migrate()!==[]) throw new RuntimeException('migration not idempotent');
  if ((int)$pdo->query('SELECT COUNT(*) FROM personnel')->fetchColumn()!==1) throw new RuntimeException('duplicate personnel');
  echo 'PASS R171 disposable MariaDB 11.4 one-time staff creation and role boundaries'.PHP_EOL;
} finally {unlink($dir.'/0081_mohammad_buyer_inventory_expenses.sql');rmdir($dir);}
