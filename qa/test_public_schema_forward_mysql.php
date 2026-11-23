<?php
/** Disposable MariaDB 11.x Public migration crash/replay contract on exact R306 source.
 *  Does NOT exercise the Emergency HTTP coordinator / fence or a customer host.
 */
declare(strict_types=1);
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('SOKNA_QA_SCHEMA_FORWARD_ONLY') !== 'yes') {
    throw new RuntimeException('DISPOSABLE_CI_ONLY');
}
$src = realpath((string)($argv[1] ?? ''));
if ($src === false || !is_file($src . '/src/Core/Migrations.php')) throw new RuntimeException('SOURCE_NOT_FOUND');
require $src . '/src/Core/Migrations.php';
$host = getenv('SOKNA_QA_DB_HOST') ?: '127.0.0.1';
$pass = (string)getenv('SOKNA_QA_DB_PASSWORD');
if ($pass !== 'disposable-pass28-only') throw new RuntimeException('TEST_PASSWORD_GUARD');
$admin = new PDO('mysql:host=' . $host . ';port=3306;charset=utf8mb4', 'root', $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$names = [];
$logs = [];
function check(bool $condition, string $label): void {
    global $logs;
    if (!$condition) throw new RuntimeException('FAIL_' . $label);
    $logs[] = $label;
}
function scalar(PDO $pdo, string $sql): int {return (int)$pdo->query($sql)->fetchColumn();}
function versionCount(PDO $pdo): int {return scalar($pdo, 'SELECT COUNT(*) FROM schema_migrations');}
function newDb(PDO $admin, string $host, string $pass, array &$names): PDO {
    $db = 'sokna_qa_emergency_' . bin2hex(random_bytes(5));
    $admin->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $names[] = $db;
    return new PDO('mysql:host=' . $host . ';port=3306;dbname=' . $db . ';charset=utf8mb4', 'root', $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function baseline(PDO $pdo, string $src, string $base): void {
    $m = new \Sokna\PublicEdge\Core\Migrations($pdo,$base);
    check(count($m->migrate()) === 8, 'first_eight_migrations');
    check(versionCount($pdo) === 8, 'baseline_ledger_eight');
    $full = new \Sokna\PublicEdge\Core\Migrations($pdo,$src . '/database/migrations');
    check(count($full->pendingVersions()) === 3, 'preflight_three_pending_no_mutation');
    check(versionCount($pdo) === 8, 'preflight_readonly');
}
$work = sys_get_temp_dir() . '/sokna-pass28-' . bin2hex(random_bytes(5));
$base = $work . '/base'; $bad = $work . '/bad';
mkdir($base,0700,true); mkdir($bad,0700,true);
$all = glob($src . '/database/migrations/*.sql');
if (count($all) !== 11) throw new RuntimeException('UNEXPECTED_CATALOG_SIZE');
foreach ($all as $p) {
    $n = basename($p);
    if ((int)substr($n,0,4) <= 8) copy($p,$base . '/' . $n);
    copy($p,$bad . '/' . $n);
}
// Bad migration never reaches SQL; pinned sha must reject even a comment-only change.
file_put_contents($bad . '/0009_public_move_owner_setup.sql', "\n-- tamper test\n", FILE_APPEND);
try {
    // Case A: Interrupted DDL after first CREATE TABLE, before ledger record.
    $pdo = newDb($admin,$host,$pass,$names);
    baseline($pdo,$src,$base);
    $sql = file_get_contents($src . '/database/migrations/0009_public_move_owner_setup.sql');
    $statements = \Sokna\PublicEdge\Core\Migrations::splitStatements($sql);
    check(count($statements) === 4, 'owner_migration_has_four_statements');
    $pdo->exec($statements[0]);
    $pdo->exec("INSERT INTO public_move_owner_slots(public_instance_id) VALUES ('keep-existing-owner-slot')");
    check(versionCount($pdo) === 8, 'interrupted_ddl_ledger_unchanged');
    $full = new \Sokna\PublicEdge\Core\Migrations($pdo,$src . '/database/migrations');
    check(count($full->migrate()) === 3, 'resume_8_to_11');
    check(versionCount($pdo) === 11, 'resume_ledger_eleven');
    check(scalar($pdo,"SELECT COUNT(*) FROM public_move_owner_slots WHERE public_instance_id='keep-existing-owner-slot'") === 1, 'partial_ddl_row_preserved');
    check(count($full->migrate()) === 0, 'idempotent_second_run');
    // Case B: Crash after column DDL but before INSERT into migration ledger.
    $pdo->exec("DELETE FROM schema_migrations WHERE version='0011_public_move_intake_pause'");
    check(versionCount($pdo) === 10, 'interrupted_column_ledger_ten');
    check(count($full->migrate()) === 1 && versionCount($pdo) === 11, 'reconcile_existing_column_without_duplicate');
    check(scalar($pdo,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='installations' AND COLUMN_NAME='public_move_intake_paused'") === 1,'column_created_once');
    // Case C: incompatible partial table must be blocked; never mutated automatically.
    $pdo2 = newDb($admin,$host,$pass,$names);
    baseline($pdo2,$src,$base);
    $pdo2->exec('CREATE TABLE public_move_owner_slots (public_instance_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    try { (new \Sokna\PublicEdge\Core\Migrations($pdo2,$src . '/database/migrations'))->migrate();throw new RuntimeException('SHOULD_HAVE_FAILED_INCOMPATIBLE_SCHEMA'); }
    catch (Throwable $e) {check(str_contains($e->getMessage(),'incompatible schema'), 'incompatible_schema_rejected');}
    check(versionCount($pdo2) === 8, 'incompatible_schema_no_ledger_advance');
    check(scalar($pdo2,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='public_move_owner_slots' AND COLUMN_TYPE='int(11)'") === 1 || scalar($pdo2,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='public_move_owner_slots' AND DATA_TYPE='int'") === 1, 'incompatible_table_not_rewritten');
    // Case D: SQL SHA mismatch prevents execution/ledger changes.
    $pdo3 = newDb($admin,$host,$pass,$names);
    baseline($pdo3,$src,$base);
    try { (new \Sokna\PublicEdge\Core\Migrations($pdo3,$bad))->migrate();throw new RuntimeException('SHOULD_HAVE_FAILED_SQL_TAMPER'); }
    catch (Throwable $e) {check(str_contains($e->getMessage(),'identity mismatch'), 'sql_sha_tamper_rejected');}
    check(versionCount($pdo3) === 8, 'tamper_no_ledger_advance');
    check(scalar($pdo3,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='public_move_owner_slots'") === 0,'tamper_no_ddl');
    check(count((new \Sokna\PublicEdge\Core\Migrations($pdo3,$src . '/database/migrations'))->migrate()) === 3 && versionCount($pdo3) === 11, 'resume_from_sha_rejection');
    echo json_encode(['status'=>'PASS','scope'=>'disposable MariaDB 11, R306 source migrations 0001-0011, NOT Emergency HTTP/state fence','checks'=>count($logs),'assertions'=>$logs,'db_cleaned_after_test'=>true,'production_authorized'=>false], JSON_PRETTY_PRINT) . "\n";
} finally {
    foreach ($names as $name) {try {$admin->exec('DROP DATABASE `' . $name . '`');}catch (Throwable) {}}
    foreach (glob($base.'/*') ?: [] as $f) @unlink($f);
    foreach (glob($bad.'/*') ?: [] as $f) @unlink($f);
    @rmdir($base);@rmdir($bad);@rmdir($work);
}