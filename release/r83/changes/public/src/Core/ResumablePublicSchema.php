<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Core;

use PDO;
use RuntimeException;

/**
 * Resume only the two published additive Public schema changes, using server
 * metadata as the source of truth after potentially non-transactional DDL.
 *
 * A table/column/index which exists with incompatible shape FAILS CLOSED.
 * Legacy SQL remains immutable and is accepted only at its pinned SHA256.
 * Do not reuse this for arbitrary new migrations.
 */
final class ResumablePublicSchema
{
    private const PINNED = [
        '0007_authority_epoch_fencing' => '5321b5aaf5f980e3559cf4493531d2254a973f7144c183e1882271403c4a0d54',
        '0008_public_user_ui_preferences' => 'e1f896996172c50016c2b8235eb74caceece9e79a4dd966abf2d9aaee951cf9d',
    ];

    public static function supports(string $version): bool
    {
        return isset(self::PINNED[$version]);
    }

    public static function apply(PDO $pdo, string $version, string $rawSql): void
    {
        if (!self::supports($version) || !hash_equals(self::PINNED[$version], hash('sha256', $rawSql))) {
            throw new RuntimeException('Public forward schema migration identity mismatch: ' . $version);
        }
        $statements = Migrations::splitStatements($rawSql);
        if ($version === '0007_authority_epoch_fencing') {
            if (count($statements) !== 7) throw new RuntimeException('Public authority schema migration statement contract changed.');
            self::column($pdo, 'installations', 'authority_epoch', 'BIGINT UNSIGNED NOT NULL DEFAULT 1', 'order_intake_enabled', 'bigint unsigned', 'NO', '1');
            self::column($pdo, 'installations', 'authority_state', "VARCHAR(24) NOT NULL DEFAULT 'active'", 'authority_epoch', 'varchar(24)', 'NO', 'active');
            self::column($pdo, 'installations', 'authority_epoch_required', 'TINYINT(1) NOT NULL DEFAULT 0', 'authority_state', 'tinyint(1)', 'NO', '0');
            self::column($pdo, 'installations', 'authority_changed_at', 'DATETIME(6) NULL', 'authority_epoch_required', 'datetime(6)', 'YES', null);

            self::table($pdo, 'installation_authority_reenrollments', $statements[1], [
                'installation_id' => 'varchar(96)', 'from_epoch' => 'bigint unsigned',
                'to_epoch' => 'bigint unsigned', 'code_hash' => 'varchar(255)',
                'status' => 'varchar(20)', 'expires_at' => 'datetime',
            ]);
            self::index($pdo, 'installation_authority_reenrollments', 'idx_authority_reenroll_pending', ['installation_id', 'status', 'expires_at'], null);
            self::foreignKey($pdo, 'installation_authority_reenrollments', 'fk_authority_reenroll_installation', ['installation_id'], 'installations', ['installation_id']);

            self::column($pdo, 'realtime_requests', 'authority_epoch', 'BIGINT UNSIGNED NOT NULL DEFAULT 1', 'installation_id', 'bigint unsigned', 'NO', '1');
            self::index($pdo, 'realtime_requests', 'idx_realtime_authority', ['installation_id','authority_epoch','state','id'], 'ALTER TABLE `realtime_requests` ADD INDEX `idx_realtime_authority`(`installation_id`,`authority_epoch`,`state`,`id`)');
            self::column($pdo, 'deferred_work', 'authority_epoch', 'BIGINT UNSIGNED NOT NULL DEFAULT 1', 'installation_id', 'bigint unsigned', 'NO', '1');
            self::index($pdo, 'deferred_work', 'idx_deferred_authority', ['installation_id','authority_epoch','state','created_at'], 'ALTER TABLE `deferred_work` ADD INDEX `idx_deferred_authority`(`installation_id`,`authority_epoch`,`state`,`created_at`)');
            foreach (['remote_projection_cursors','remote_projection_model_cursors','remote_projection_events'] as $table) {
                self::column($pdo, $table, 'authority_epoch', 'BIGINT UNSIGNED NOT NULL DEFAULT 1', 'installation_id', 'bigint unsigned', 'NO', '1');
            }
        } else {
            if (count($statements) !== 1) throw new RuntimeException('Public preferences migration statement contract changed.');
            self::table($pdo, 'public_user_ui_preferences', $statements[0], [
                'installation_id' => 'varchar(96)', 'projection_id' => 'varchar(96)',
                'preferences_json' => 'longtext', 'updated_at' => 'timestamp',
            ]);
            self::index($pdo, 'public_user_ui_preferences', 'PRIMARY', ['installation_id','projection_id'], null);
            self::foreignKey($pdo, 'public_user_ui_preferences', 'fk_public_ui_pref_projection', ['installation_id','projection_id'], 'auth_projections', ['installation_id','projection_id']);
        }
    }

    private static function column(PDO $pdo, string $table, string $name, string $definition, string $after, string $type, string $nullable, ?string $default): void
    {
        $row = self::columnInfo($pdo, $table, $name);
        if ($row === null) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $definition . ' AFTER `' . $after . '`');
            $row = self::columnInfo($pdo, $table, $name);
        }
        self::assertColumn($table, $name, $row, $type, $nullable, $default);
    }

    private static function table(PDO $pdo, string $name, string $ddl, array $columnTypes): void
    {
        $pdo->exec($ddl); // IF NOT EXISTS; never drop or replace a table
        foreach ($columnTypes as $column => $type) {
            $row = self::columnInfo($pdo, $name, $column);
            if (!is_array($row) || !self::typeMatches((string)$row['COLUMN_TYPE'], $type)) {
                throw new RuntimeException('Public table exists with incompatible schema: ' . $name . '.' . $column);
            }
        }
    }

    private static function assertColumn(string $table, string $name, ?array $row, string $type, string $nullable, ?string $default): void
    {
        if ($row === null || !self::typeMatches((string)$row['COLUMN_TYPE'], $type) || strtoupper((string)$row['IS_NULLABLE']) !== $nullable
            || ($default !== null && trim((string)$row['COLUMN_DEFAULT'], "'\"") !== $default)
            || ($default === null && $row['COLUMN_DEFAULT'] !== null && strtoupper(trim((string)$row['COLUMN_DEFAULT'])) !== 'NULL')) {
            throw new RuntimeException('Public column exists with incompatible schema: ' . $table . '.' . $name);
        }
    }

    private static function typeMatches(string $actual, string $expected): bool
    {
        // MariaDB 10.x can report BIGINT(20) UNSIGNED, while newer engines
        // report BIGINT UNSIGNED. Integer display width is not schema meaning.
        $normalize = static function (string $type): string {
            $type = strtolower(trim(preg_replace('/\s+/', ' ', $type)));
            return (string)preg_replace('/\b(bigint|int|smallint|mediumint)\(\d+\)/', '$1', $type);
        };
        $a = $normalize($actual);
        return $a === $normalize($expected) || ($expected === 'longtext' && $a === 'json');
    }

    private static function columnInfo(PDO $pdo, string $table, string $column): ?array
    {
        $st = $pdo->prepare('SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$table, $column]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private static function index(PDO $pdo, string $table, string $index, array $expected, ?string $createSql): void
    {
        $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX');
        $st->execute([$table, $index]);
        $actual = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($actual === [] && $createSql !== null) {
            $pdo->exec($createSql);
            $st->execute([$table, $index]);
            $actual = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($actual !== $expected) throw new RuntimeException('Public index differs from required schema: ' . $table . '.' . $index);
    }

    private static function foreignKey(PDO $pdo, string $table, string $name, array $columns, string $refTable, array $refColumns): void
    {
        $st = $pdo->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY ORDINAL_POSITION');
        $st->execute([$table, $name]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($columns)) {
            throw new RuntimeException('Public foreign key differs from required schema: ' . $table . '.' . $name);
        }
        foreach ($rows as $i => $row) {
            if (($row['COLUMN_NAME'] ?? null) !== $columns[$i]
                || ($row['REFERENCED_TABLE_NAME'] ?? null) !== $refTable
                || ($row['REFERENCED_COLUMN_NAME'] ?? null) !== $refColumns[$i]) {
                throw new RuntimeException('Public foreign key differs from required schema: ' . $table . '.' . $name);
            }
        }
        $rule = $pdo->prepare('SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
        $rule->execute([$table,$name]);
        if (strtoupper((string)$rule->fetchColumn()) !== 'CASCADE') {
            throw new RuntimeException('Public foreign key delete rule differs: ' . $table . '.' . $name);
        }
    }
}
