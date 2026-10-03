<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/apps/local-web/bootstrap.php';

use Sokna\Local\Core\Migrations;

function migration_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$sample = <<<'SQL'
-- comment with ; must not split
CREATE TABLE example_a (value VARCHAR(40) DEFAULT 'a;b');
/* block ; comment */
INSERT INTO example_a(value) VALUES('it''s;safe');
# line ; comment
CREATE TABLE `example_b` (`value` TEXT);
SQL;

$statements = Migrations::splitStatements($sample);
if (count($statements) !== 3) migration_fail('Migration SQL parser did not preserve quoted/comment semicolons.');
if (!str_contains($statements[0], "'a;b'")) migration_fail('Quoted semicolon was corrupted.');
if (!str_contains($statements[1], "'it''s;safe'")) migration_fail('SQL escaped quote handling changed.');

$unterminatedRejected = false;
try {
    Migrations::splitStatements("INSERT INTO x VALUES('unterminated);");
} catch (RuntimeException) {
    $unterminatedRejected = true;
}
if (!$unterminatedRejected) migration_fail('Unterminated migration SQL was accepted.');

$path = dirname(__DIR__) . '/apps/local-web/database/migrations/0001_m2_platform_core.sql';
$sql = (string)file_get_contents($path);
$requiredTables = ['settings', 'users', 'user_capabilities', 'audit_log'];
foreach ($requiredTables as $table) {
    if (!preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . preg_quote($table, '/') . '\b/i', $sql)) {
        migration_fail("M2 migration is missing owned table {$table}.");
    }
}
if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+user_preparation_areas\b/i', $sql)) {
    migration_fail('M2 incorrectly claimed user_preparation_areas schema ownership.');
}
if (!str_contains($sql, 'actor_display_name_snapshot VARCHAR(160) NULL')) {
    migration_fail('Audit actor snapshot compatibility column is missing.');
}
if (!str_contains($sql, 'CONSTRAINT fk_user_capabilities_user')) {
    migration_fail('Capability authority foreign key is missing.');
}
if (!str_contains($sql, 'ON UPDATE CASCADE ON DELETE CASCADE')) {
    migration_fail('Capability authority cascade semantics are missing.');
}

fwrite(STDOUT, "Local Migrations M2 self-test: OK\n");
