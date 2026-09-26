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

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> versions applied during this call */
    public function migrate(): array
    {
        $this->ensureLedger();
        $this->acquireLock();
        try {
            $applied = array_fill_keys($this->appliedVersions(), true);
            $newlyApplied = [];
            foreach ($this->catalog() as $version => $path) {
                if (isset($applied[$version])) continue;
                $sql = @file_get_contents($path);
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException("Migration '{$version}' is empty or unreadable.");
                }

                // MySQL DDL may implicitly commit. Each statement is journaled so a process
                // crash can reconcile a statement that committed before its ledger update.
                foreach (self::splitStatements($sql) as $ordinal => $statement) {
                    $this->applyStatement($version, $ordinal + 1, $statement);
                }
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
                $stmt->execute([$version]);
                $newlyApplied[] = $version;
            }
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
            if (strlen($version) > 40) {
                throw new RuntimeException("Migration version '{$version}' exceeds schema_migrations.version capacity.");
            }
            if (isset($catalog[$version])) throw new RuntimeException("Duplicate migration version '{$version}'.");
            $catalog[$version] = $this->directory . DIRECTORY_SEPARATOR . $name;
        }
        ksort($catalog, SORT_STRING);
        return $catalog;
    }

    /** @return list<string> */
    public function appliedVersions(): array
    {
        $rows = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', $rows));
    }

    private function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'version VARCHAR(40) PRIMARY KEY,' .
            'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migration_statements (' .
            'version VARCHAR(40) NOT NULL,' .
            'ordinal_no INT UNSIGNED NOT NULL,' .
            'statement_sha256 CHAR(64) NOT NULL,' .
            "state VARCHAR(16) NOT NULL DEFAULT 'running'," .
            'started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            'applied_at DATETIME NULL,' .
            'PRIMARY KEY(version,ordinal_no),' .
            'CONSTRAINT ck_schema_migration_statement_state CHECK (state IN (\'running\',\'applied\'))' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function applyStatement(string $version, int $ordinal, string $statement): void
    {
        $hash = hash('sha256', $statement);
        $lookup = $this->pdo->prepare(
            'SELECT statement_sha256,state FROM schema_migration_statements WHERE version=? AND ordinal_no=? LIMIT 1'
        );
        $lookup->execute([$version, $ordinal]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
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
        }

        try {
            $this->pdo->exec($statement);
        } catch (PDOException $e) {
            // Only a previously journaled RUNNING statement may reconcile a known
            // duplicate-DDL result. Fresh migration errors are never hidden.
            if (!is_array($row) || (string)$row['state'] !== 'running' || !self::isRecoverableDuplicateDdl($e)) {
                throw $e;
            }
        }
        $done = $this->pdo->prepare(
            "UPDATE schema_migration_statements SET state='applied',applied_at=NOW() WHERE version=? AND ordinal_no=?"
        );
        $done->execute([$version, $ordinal]);
    }

    private static function isRecoverableDuplicateDdl(PDOException $e): bool
    {
        $errno = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        return in_array($errno, [1050, 1060, 1061, 1826], true);
    }

    private function acquireLock(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
        $stmt->execute([self::LOCK_NAME]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the Local migration lock.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([self::LOCK_NAME]);
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
                    $quote = $char;
                    $buffer .= $char;
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
