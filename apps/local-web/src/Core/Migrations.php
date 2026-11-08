<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Migrations
{
    private const LOCK_NAME = 'sokna_v3_local_migrations';
    private const VERSION_CAPACITY = 160;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> versions applied during this call */
    public function migrate(?string $stopBefore = null): array
    {
        $this->ensureLedger();
        $this->acquireLock();
        try {
            $applied = array_fill_keys($this->appliedVersions(), true);
            $newlyApplied = [];
            $catalog = $this->catalog();
            if ($stopBefore !== null && !isset($catalog[$stopBefore])) {
                throw new RuntimeException("Unknown Local migration boundary '{$stopBefore}'.");
            }
            foreach ($catalog as $version => $path) {
                if ($stopBefore !== null && strcmp($version, $stopBefore) >= 0) break;
                if (isset($applied[$version])) continue;
                $sql = @file_get_contents($path);
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException("Migration '{$version}' is empty or unreadable.");
                }

                // Only the two audited data-only migrations are wrapped as
                // whole, durable InnoDB transactions. Their post-write SELECT
                // guards then rollback all DML plus the statement ledger on error.
                // DDL (0077) retains the separate forward-only recovery lifecycle.
                $statements = self::splitStatements($sql);
                $atomicDataOnly = str_starts_with($version, '0078_') || str_starts_with($version, '0079_');
                if ($atomicDataOnly) {
                    foreach ($statements as $statement) {
                        if (preg_match('/^(?:SELECT|INSERT|UPDATE|DELETE)\b/i', ltrim($statement)) !== 1) {
                            throw new RuntimeException('Data-only migration includes a nontransactional statement.');
                        }
                    }
                    $this->requireInnoDbForAtomicMigrations();
                    $pending = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM schema_migration_statements WHERE version=? AND state='running'"
                    );
                    $pending->execute([$version]);
                    $unreconciled = (int)$pending->fetchColumn();
                    $pending->closeCursor();
                    if ($unreconciled !== 0) {
                        throw new RuntimeException('Unverified old data-migration journal requires recovery, not blind replay.');
                    }
                    $this->pdo->beginTransaction();
                    try {
                        foreach ($statements as $ordinal => $statement) {
                            $this->applyStatement($version, $ordinal + 1, $statement);
                        }
                        $stmt = $this->pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
                        $stmt->execute([$version]);
                        $stmt->closeCursor();
                        $this->pdo->commit();
                    } catch (Throwable $error) {
                        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                        throw $error;
                    }
                } else {
                    // DDL may implicitly commit. Its statement journal and
                    // duplicate-DDL reconciliation must remain intact.
                    foreach ($statements as $ordinal => $statement) {
                        $this->applyStatement($version, $ordinal + 1, $statement);
                    }
                    $stmt = $this->pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
                    $stmt->execute([$version]);
                    $stmt->closeCursor();
                }
                $newlyApplied[] = $version;
            }
            if ($stopBefore === null) $this->installBundledCafePhotos();
            return $newlyApplied;
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array<string,string> version => absolute path */
    public function catalog(): array
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Local migration directory does not exist.');
        }

        $catalog = [];
        foreach (scandir($this->directory) ?: [] as $name) {
            if (!preg_match('/^(\d{4}_[a-z0-9_]+)\.sql$/D', $name, $matches)) continue;
            $version = $matches[1];
            if (strlen($version) > self::VERSION_CAPACITY) {
                throw new RuntimeException("Migration version '{$version}' exceeds supported migration version capacity.");
            }
            if (isset($catalog[$version])) throw new RuntimeException("Duplicate migration version '{$version}'.");
            $catalog[$version] = $this->directory . DIRECTORY_SEPARATOR . $name;
        }
        ksort($catalog, SORT_STRING);
        $this->preflightOpeningActivationSchema($catalog);
        // Preflight calls catalog() while Apache is still running; detect
        // damaged photo assets before any database/Apache mutation.
        $installer = $this->photoInstaller();
        if ($installer !== null) {
            $installer->validate();
            $installer->validateHostReadiness();
        }
        return $catalog;
    }

    /**
     * Read-only compatibility gate for the only non-transactional DDL after 0076.
     * Called by catalog() during the installed Maintenance's existing preflight,
     * before Apache is stopped and before any backup/DDL. No SQL migration file
     * is edited: their historical checksums remain byte-identical.
     *
     * Clean installs (no 0076 yet), already-updated 0077 hosts, and post-0077
     * forward recovery are intentionally not rejected by a premature guard.
     */
    private function preflightOpeningActivationSchema(array $catalog): void
    {
        if (!isset($catalog['0077_incremental_opening_inventory'])) return;
        $exists = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'");
        $hasLedger = (int)$exists->fetchColumn() === 1;
        $exists->closeCursor();
        if (!$hasLedger) return;

        $historyStmt = $this->pdo->query("SELECT version FROM schema_migrations WHERE version IN ('0076_late_served_accounting','0077_incremental_opening_inventory')");
        $history = $historyStmt->fetchAll(PDO::FETCH_COLUMN);
        $historyStmt->closeCursor();
        if (!in_array('0076_late_served_accounting', $history, true) ||
            in_array('0077_incremental_opening_inventory', $history, true)) return;

        // An inventory opening migration requires existing InnoDB parent tables,
        // correct unsigned primary keys, and collision-free foreign key names.
        $parents = [            'inventory_items' => 'int',
            'inventory_count_sessions' => 'bigint',
            'inventory_count_lines' => 'bigint',
        ];
        $parentStmt = $this->pdo->query(
            "SELECT t.TABLE_NAME AS table_name,t.ENGINE AS engine,c.COLUMN_TYPE AS column_type,c.COLUMN_KEY AS column_key " .
            "FROM information_schema.TABLES t JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=t.TABLE_SCHEMA " .
            "AND c.TABLE_NAME=t.TABLE_NAME AND c.COLUMN_NAME='id' WHERE t.TABLE_SCHEMA=DATABASE() " .
            "AND t.TABLE_NAME IN ('inventory_items','inventory_count_sessions','inventory_count_lines')"
        );
        $actual = [];
        foreach ($parentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $actual[(string)$row['table_name']] = $row;
        $parentStmt->closeCursor();
        foreach ($parents as $name => $type) {
            $row = $actual[$name] ?? null;
            if (!is_array($row) || strcasecmp((string)$row['engine'], 'InnoDB') !== 0 ||
                !preg_match('/^' . $type . '(?:\\(\\d+\\))? unsigned$/i', (string)$row['column_type']) ||
                (string)$row['column_key'] !== 'PRI') {
                throw new RuntimeException('LOCAL_0077_PARENT_SCHEMA_PREFLIGHT_FAILED:' . $name);
            }
        }

        $existingStmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_opening_activations'");
        $targetExists = (int)$existingStmt->fetchColumn() === 1;
        $existingStmt->closeCursor();
        if (!$targetExists) {
            $constraints = $this->pdo->query(
                "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS " .
                "WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN (" .
                "'fk_opening_activation_item','fk_opening_activation_session','fk_opening_activation_line')"
            );
            $collision = $constraints->fetch(PDO::FETCH_ASSOC);
            $constraints->closeCursor();
            if ($collision !== false) throw new RuntimeException('LOCAL_0077_FOREIGN_KEY_NAME_COLLISION');
            return;
        }

        // A previous interrupted CREATE TABLE IF NOT EXISTS may already have
        // committed the table. Allow forward resume only for an exact structure.
        $columnsStmt = $this->pdo->query(
            "SELECT COLUMN_NAME AS column_name,COLUMN_TYPE AS column_type,IS_NULLABLE AS nullable " .
            "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() " .
            "AND TABLE_NAME='inventory_opening_activations'"
        );
        $columns = [];
        foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $columns[(string)$row['column_name']] = $row;
        $columnsStmt->closeCursor();
        $expected = [
            'inventory_item_id' => ['int', 'NO'],
            'count_session_id' => ['bigint', 'NO'],
            'count_line_id' => ['bigint', 'NO'],
            'activated_at' => ['datetime', 'NO'],
            'actor_user_id' => ['int', 'YES'],
        ];
        if (count($columns) !== count($expected)) throw new RuntimeException('LOCAL_0077_EXISTING_TABLE_SHAPE_MISMATCH');
        foreach ($expected as $name => [$type, $nullable]) {
            $row = $columns[$name] ?? null;
            $pattern = $type === 'datetime' ? '/^datetime(?:\\(\\d+\\))?$/i'
                : '/^' . $type . '(?:\\(\\d+\\))? unsigned$/i';
            if (!is_array($row) || !preg_match($pattern, (string)$row['column_type']) ||
                (string)$row['nullable'] !== $nullable) {
                throw new RuntimeException('LOCAL_0077_EXISTING_TABLE_SHAPE_MISMATCH');
            }
        }
        $idxStmt = $this->pdo->query(
            "SELECT INDEX_NAME AS idx,SEQ_IN_INDEX AS seq,COLUMN_NAME AS col,NON_UNIQUE AS non_unique " .
            "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() " .
            "AND TABLE_NAME='inventory_opening_activations' ORDER BY INDEX_NAME,SEQ_IN_INDEX"
        );
        $indexes = [];
        foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[(string)$row['idx']]['columns'][] = (string)$row['col'];
            $indexes[(string)$row['idx']]['non_unique'] = (int)$row['non_unique'];
        }
        $idxStmt->closeCursor();
        if (($indexes['PRIMARY']['columns'] ?? []) !== ['inventory_item_id'] ||
            !array_filter($indexes, static fn(array $i): bool => $i['non_unique'] === 0 && $i['columns'] === ['count_line_id']) ||
            !array_filter($indexes, static fn(array $i): bool => $i['columns'] === ['count_session_id','activated_at'])) {
            throw new RuntimeException('LOCAL_0077_EXISTING_TABLE_INDEX_MISMATCH');
        }
        $fkStmt = $this->pdo->query(
            "SELECT COLUMN_NAME AS col,REFERENCED_TABLE_NAME AS ref_table,REFERENCED_COLUMN_NAME AS ref_col " .
            "FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() " .
            "AND TABLE_NAME='inventory_opening_activations' AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        $foreignKeys = [];
        foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $foreignKeys[(string)$row['col']] = [(string)$row['ref_table'],(string)$row['ref_col']];
        $fkStmt->closeCursor();
        if (count($foreignKeys) !== 3 ||
            ($foreignKeys['inventory_item_id'] ?? []) !== ['inventory_items','id'] ||
            ($foreignKeys['count_session_id'] ?? []) !== ['inventory_count_sessions','id'] ||
            ($foreignKeys['count_line_id'] ?? []) !== ['inventory_count_lines','id']) {
            throw new RuntimeException('LOCAL_0077_EXISTING_TABLE_FOREIGN_KEY_MISMATCH');
        }
    }

    /** @return list<string> */
    public function appliedVersions(): array
    {
        $stmt = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version');
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();
        return array_values(array_map('strval', $rows));
    }

    private function requireInnoDbForAtomicMigrations(): void
    {
        $q = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'");
        $nonTransactional = (int)$q->fetchColumn();
        $q->closeCursor();
        if ($nonTransactional !== 0) throw new RuntimeException('Atomic data migration requires all business tables to use InnoDB.');
    }

    private function bundleFile(): ?string
    {
        $candidate = dirname($this->directory, 2) . '/resources/cafe-photo-bundle-15aban/manifest.json';
        return is_file($candidate) ? $candidate : null;
    }

    private function photoInstaller(): ?CafePhotoBundle
    {
        $file = $this->bundleFile();
        if ($file === null) return null;
        require_once __DIR__ . '/CafePhotoBundle.php';
        return new CafePhotoBundle($this->pdo, $file);
    }

    private function installBundledCafePhotos(): void
    {
        $installer = $this->photoInstaller();
        if ($installer !== null) $installer->apply();
    }

    private function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'version VARCHAR(' . self::VERSION_CAPACITY . ') PRIMARY KEY,' .
            'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migration_statements (' .
            'version VARCHAR(' . self::VERSION_CAPACITY . ') NOT NULL,' .
            'ordinal_no INT UNSIGNED NOT NULL,' .
            'statement_sha256 CHAR(64) NOT NULL,' .
            "state VARCHAR(16) NOT NULL DEFAULT 'running'," .
            'started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            'applied_at DATETIME NULL,' .
            'PRIMARY KEY(version,ordinal_no),' .            'CONSTRAINT ck_schema_migration_statement_state CHECK (state IN (\'running\',\'applied\'))' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ensureVersionCapacity('schema_migrations');
        $this->ensureVersionCapacity('schema_migration_statements');
    }

    private function ensureVersionCapacity(string $table): void
    {
        if (!in_array($table, ['schema_migrations', 'schema_migration_statements'], true)) {
            throw new RuntimeException('Unsupported migration ledger table.');
        }
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'version'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $type = is_array($row) ? strtolower((string)($row['Type'] ?? '')) : '';
        if (preg_match('/^varchar\((\d+)\)$/', $type, $matches) === 1 && (int)$matches[1] >= self::VERSION_CAPACITY) {
            return;
        }
        $this->pdo->exec("ALTER TABLE `{$table}` MODIFY `version` VARCHAR(" . self::VERSION_CAPACITY . ") NOT NULL");
    }

    private function applyStatement(string $version, int $ordinal, string $statement): void
    {
        $hash = hash('sha256', $statement);
        $lookup = $this->pdo->prepare(
            'SELECT statement_sha256,state FROM schema_migration_statements WHERE version=? AND ordinal_no=? LIMIT 1'
        );
        $lookup->execute([$version, $ordinal]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        $lookup->closeCursor();
        if (is_array($row)) {
            if (!hash_equals((string)$row['statement_sha256'], $hash)) {
                throw new RuntimeException("Migration '{$version}' statement {$ordinal} changed after execution began.");
            }
            if ((string)$row['state'] === 'applied') return;
        } else {
            $insert = $this->pdo->prepare(
                "INSERT INTO schema_migration_statements(version,ordinal_no,statement_sha256,state) VALUES(?,?,?,'running')"
            );
            $insert->execute([$version, $ordinal, $hash]);
            $insert->closeCursor();
        }

        try {
            $this->executeStatementAndDrain($statement);
        } catch (PDOException $e) {
            // Only a previously journaled RUNNING statement may reconcile a known
            // duplicate-DDL result. Fresh migration errors are never hidden.
            if (!is_array($row) || (string)$row['state'] !== 'running' || !self::isRecoverableDuplicateDdl($e)) {
                throw new MigrationExecutionException($version,$ordinal,$e);
            }
        }
        $done = $this->pdo->prepare(
            "UPDATE schema_migration_statements SET state='applied',applied_at=NOW() WHERE version=? AND ordinal_no=?"
        );
        $done->execute([$version, $ordinal]);
        $done->closeCursor();
    }

    private function executeStatementAndDrain(string $statement): void
    {
        $stmt = $this->pdo->prepare($statement);
        try {
            $stmt->execute();
            do {
                while ($stmt->fetch(PDO::FETCH_NUM) !== false) {
                    // Consume result rows from SELECT/SHOW/CALL-style migration statements.
                }
            } while ($stmt->nextRowset());
        } finally {
            $stmt->closeCursor();
        }
    }

    private static function isRecoverableDuplicateDdl(PDOException $e): bool
    {
        $errno = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        return in_array($errno, [1050, 1060, 1061, 1091, 1359, 1826], true);
    }

    private function acquireLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
        $stmt->execute([self::LOCK_NAME]);
        $locked = (int)$stmt->fetchColumn();
        $stmt->closeCursor();
        if ($locked !== 1) {
            throw new RuntimeException('Could not acquire the Local migration lock.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([self::LOCK_NAME]);
            $stmt->fetchColumn();
            $stmt->closeCursor();
        } catch (Throwable) {
            // Connection teardown also releases MySQL advisory locks.
        }
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote === null) {
                if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                    $lineComment = true;
                    $i++;
                    continue;
                }
                if ($char === '#') {
                    $lineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $i++;
                    continue;
                }
                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;                    $buffer .= $char;
                    continue;
                }
                if ($char === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') $statements[] = $statement;
                    $buffer = '';
                    continue;
                }
                $buffer .= $char;
                continue;
            }

            $buffer .= $char;
            if ($char === '\\' && $quote !== '`' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }

        if ($quote !== null || $blockComment) {
            throw new RuntimeException('Migration SQL contains an unterminated quote or block comment.');
        }
        $statement = trim($buffer);
        if ($statement !== '') $statements[] = $statement;
        return $statements;
    }
}