<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use PDO;
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

                // MySQL DDL may implicitly commit, so migration files must be replay-safe.
                // The ledger marker is written only after every statement succeeds.
                foreach (self::splitStatements($sql) as $statement) {
                    $this->pdo->exec($statement);
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
