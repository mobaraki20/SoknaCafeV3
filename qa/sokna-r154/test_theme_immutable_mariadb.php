<?php
declare(strict_types=1);
// Disposable CI-only MariaDB fixture.  No production host or private café data.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "MARIADB_TEST_ONLY_IN_DISPOSABLE_CI\n"); exit(78);
}
$root = (string)($argv[1] ?? '');
foreach (['src/Domain/GuestContent/ThemePackageManager.php','src/Domain/GuestContent/GuestContentException.php','src/Domain/GuestContent/GuestContentService.php'] as $path) {
    if (!is_file($root.'/'.$path)) throw new RuntimeException('EXTRACTED_WEB_SOURCE_MISSING_'.$path);
    require_once $root.'/'.$path;
}
use Sokna\Local\Domain\GuestContent\GuestContentService;
use Sokna\Local\Domain\GuestContent\ThemePackageManager;
function need(bool $ok,string $message): void {if (!$ok) throw new RuntimeException('R154_THEME_TEST_'.$message);echo "PASS {$message}\n";}
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=sokna_r154_ci;charset=utf8mb4','root','r154-ci-only',[
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('DROP TABLE IF EXISTS guest_content_revisions');
$pdo->exec('DROP TABLE IF EXISTS guest_content_config');
$pdo->exec('DROP TABLE IF EXISTS guest_theme_packages');
$pdo->exec("CREATE TABLE guest_theme_packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, theme_key VARCHAR(64) NOT NULL,
    version VARCHAR(32) NOT NULL, name VARCHAR(80) NOT NULL, fingerprint CHAR(64) NOT NULL,
    package_json JSON NOT NULL, source_kind VARCHAR(16) NOT NULL DEFAULT 'imported',
    installed_by_user_id INT UNSIGNED NULL, installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_guest_theme_key_version(theme_key,version),
    UNIQUE KEY uq_guest_theme_fingerprint(fingerprint),
    CONSTRAINT ck_guest_theme_source_kind CHECK (source_kind IN ('builtin','imported'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE guest_content_config (id INT PRIMARY KEY, published_theme_package_id BIGINT UNSIGNED,
    CONSTRAINT fk_guest_content_published_theme_package FOREIGN KEY(published_theme_package_id)
    REFERENCES guest_theme_packages(id) ON DELETE RESTRICT)");
$pdo->exec("CREATE TABLE guest_content_revisions (revision BIGINT UNSIGNED PRIMARY KEY,
    theme_package_id BIGINT UNSIGNED NOT NULL,
    CONSTRAINT fk_guest_revision_theme_package FOREIGN KEY(theme_package_id)
    REFERENCES guest_theme_packages(id) ON DELETE RESTRICT)");
$manager = new ThemePackageManager($root.'/resources/guest-themes');
$bundled=$manager->packages();
need(count($bundled)>=2,'ACTUAL_R153_BUILTIN_THEMES_LOADED');
$theme=$bundled[0];$legacy=$theme;unset($legacy['fingerprint']);
$legacy['name']=mb_substr((string)$legacy['name'],0,45).' نسخه تاریخی';
$old=$manager->validateManifest($legacy);
need($old['fingerprint']!==$theme['fingerprint'] && $old['version']===$theme['version'],'HISTORIC_THEME_SAME_NAME_VERSION_DIFFERENT_HASH');
$insert=$pdo->prepare("INSERT INTO guest_theme_packages(theme_key,version,name,fingerprint,package_json,source_kind) VALUES(?,?,?,?,?,'builtin')");
$insert->execute([$old['theme_key'],$old['version'],$old['name'],$old['fingerprint'],json_encode($legacy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
$historicId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO guest_content_config(id,published_theme_package_id) VALUES(1,?)')->execute([$historicId]);
$pdo->prepare('INSERT INTO guest_content_revisions(revision,theme_package_id) VALUES(1,?)')->execute([$historicId]);
$reflection=new ReflectionClass(GuestContentService::class);
$service=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('pdo')->setValue($service,$pdo);
$reflection->getProperty('themes')->setValue($service,$manager);
$ensure=$reflection->getMethod('ensureBuiltinThemePackages');
$ensure->invoke($service);
$row=$pdo->query('SELECT id,version,fingerprint,package_json FROM guest_theme_packages WHERE id='.$historicId)->fetch();
need(is_array($row)&&$row['version']===$old['version']&&$row['fingerprint']===$old['fingerprint'],'HISTORIC_ROW_BYTES_NOT_REWRITTEN');
$refs=$pdo->query('SELECT c.published_theme_package_id,r.theme_package_id FROM guest_content_config c JOIN guest_content_revisions r ON r.revision=1')->fetch();
need((int)$refs['published_theme_package_id']===$historicId&&(int)$refs['theme_package_id']===$historicId,'PUBLISHED_AND_HISTORY_FK_PRESERVED');
$q=$pdo->prepare('SELECT id,version,fingerprint,package_json FROM guest_theme_packages WHERE theme_key=? ORDER BY id');$q->execute([$theme['theme_key']]);$rows=$q->fetchAll();
need(count($rows)===2,'CONFLICT_RESOLVED_WITH_ONE_ADDITIONAL_IMMUTABLE_VERSION');
$successor=$rows[1];
need($successor['version']!==$old['version']&&$successor['fingerprint']!==$old['fingerprint'],'NEW_VERSION_AND_FINGERPRINT_UNIQUE');
$payload=json_decode($successor['package_json'],true);
$verified=$manager->validateManifest($payload);
need($verified['fingerprint']===$successor['fingerprint'],'NEW_PACKAGE_CANONICAL_FINGERPRINT_VALID');
$byId=$reflection->getMethod('themePackageById');
$legacyRead=$byId->invoke($service,$historicId);
need($legacyRead['fingerprint']===$old['fingerprint'],'HISTORIC_PACKAGE_STILL_READABLE');
$lookup=$reflection->getMethod('themePackageByLegacyKey');
$current=$lookup->invoke($service,(string)$theme['theme_key']);
need($current['fingerprint']===$successor['fingerprint'],'LEGACY_KEY_RESOLVES_NEW_SAFE_BUILTIN');
$countBefore=(int)$pdo->query('SELECT COUNT(*) FROM guest_theme_packages')->fetchColumn();
$ensure->invoke($service);
$countAfter=(int)$pdo->query('SELECT COUNT(*) FROM guest_theme_packages')->fetchColumn();
need($countBefore===$countAfter,'RETRY_IDEMPOTENT_NO_DUPLICATE_THEME_ROWS');
need((int)$pdo->query('SELECT COUNT(*) FROM guest_content_revisions')->fetchColumn()===1,'PUBLISH_HISTORY_NOT_DELETED');
echo "PASS R154 REAL_MARIADB_IMMUTABLE_BUILTIN_THEME_CONFLICT_REGRESSION\n";