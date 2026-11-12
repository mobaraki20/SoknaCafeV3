<?php
declare(strict_types=1);
// Use only a disposable MariaDB instance. Never connect to cafe or home production.
if(getenv('SOKNA_R182_DISPOSABLE')!=='YES')throw new RuntimeException('DISPOSABLE_GATE_REQUIRED');
$src=$argv[1]??throw new RuntimeException('Missing source root');
require_once "$src/src/Domain/GuestContent/GuestContentException.php";
require_once "$src/src/Domain/GuestContent/ThemePackageManager.php";
require_once "$src/src/Domain/GuestContent/GuestContentService.php";
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r182;charset=utf8mb4','root','ci-root-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP TABLE IF EXISTS guest_theme_packages');
$pdo->exec("CREATE TABLE guest_theme_packages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, theme_key VARCHAR(64) NOT NULL, version VARCHAR(32) NOT NULL, name VARCHAR(80) NOT NULL, fingerprint CHAR(64) NOT NULL, package_json JSON NOT NULL, source_kind VARCHAR(16) NOT NULL DEFAULT 'imported', installed_by_user_id INT UNSIGNED NULL, installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_guest_theme_key_version(theme_key,version), UNIQUE KEY uq_guest_theme_fingerprint(fingerprint), CONSTRAINT ck_guest_theme_source_kind CHECK (source_kind IN ('builtin','imported'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$add=$pdo->prepare("INSERT INTO guest_theme_packages(theme_key,version,name,fingerprint,package_json,source_kind) VALUES(?,?,'archived',?,?,'imported')");
for($i=0;$i<400;$i++)$add->execute(['sokna-classic',"1.0.$i",hash('sha256',"archived-version-$i"),'{}']);
$themes=new \Sokna\Local\Domain\GuestContent\ThemePackageManager("$src/resources/guest-themes");
$cls=new ReflectionClass(\Sokna\Local\Domain\GuestContent\GuestContentService::class);
$service=$cls->newInstanceWithoutConstructor();
$cls->getProperty('pdo')->setValue($service,$pdo);
$cls->getProperty('themes')->setValue($service,$themes);
$register=$cls->getMethod('ensureBuiltinThemePackages');$register->setAccessible(true);
$register->invoke($service);
$rows=$pdo->query("SELECT version,fingerprint FROM guest_theme_packages WHERE theme_key='sokna-classic' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if(count($rows)!==401||end($rows)['version']!=='1.0.400')throw new RuntimeException('REGISTRY_EXHAUSTION_NOT_RESOLVED');
for($i=0;$i<400;$i++)if($rows[$i]['fingerprint']!==hash('sha256',"archived-version-$i"))throw new RuntimeException('HISTORICAL_FINGERPRINT_MUTATED');
$before=(int)$pdo->query('SELECT COUNT(*) FROM guest_theme_packages')->fetchColumn();
$register->invoke($service);
$after=(int)$pdo->query('SELECT COUNT(*) FROM guest_theme_packages')->fetchColumn();
if($before!==$after)throw new RuntimeException('BUILTIN_REGISTRY_NOT_IDEMPOTENT');
if($after!==405)throw new RuntimeException('BUILTIN_REGISTRY_MISSING_FIVE_THEMES');
echo "PASS R182_REAL_MARIADB_400_VERSIONS_PRESERVED_AND_IDEMPOTENT\n";
