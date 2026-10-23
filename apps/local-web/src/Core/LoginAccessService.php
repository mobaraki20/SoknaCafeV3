<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use PDO;
use Throwable;

final class LoginAccessService
{
    private const WINDOW_SECONDS = 900;
    private const FAILURE_LIMIT = 5;
    private const LOCK_SECONDS = 60;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,username:string,display_name:string,role:string}> */
    public function activeUsers(): array
    {
        $rows = $this->pdo->query('SELECT id,username,display_name,role FROM users WHERE active=1 ORDER BY display_name,username,id')->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_map(static fn(array $row): array => [
            'id' => (int)($row['id'] ?? 0),
            'username' => (string)($row['username'] ?? ''),
            'display_name' => (string)($row['display_name'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
        ], is_array($rows) ? $rows : []));
    }

    /** @return array{locked:bool,retry_after:int,failures:int} */
    public function status(int $userId): array
    {
        if ($userId < 1) return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
        try {
            $q = $this->pdo->prepare('SELECT failed_count,window_started_at,locked_until FROM auth_login_rate_limits WHERE user_id=? LIMIT 1');
            $q->execute([$userId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
            $now = time();
            $lockedUntil = self::timestamp((string)($row['locked_until'] ?? ''));
            if ($lockedUntil > $now) return ['locked' => true, 'retry_after' => max(1, $lockedUntil - $now), 'failures' => (int)($row['failed_count'] ?? 0)];
            $windowStart = self::timestamp((string)($row['window_started_at'] ?? ''));
            if ($windowStart > 0 && ($now - $windowStart) >= self::WINDOW_SECONDS) {
                $this->clear($userId);
                return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
            }
            if ($lockedUntil > 0 && $lockedUntil <= $now) {
                $this->clear($userId);
                return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
            }
            return ['locked' => false, 'retry_after' => 0, 'failures' => (int)($row['failed_count'] ?? 0)];
        } catch (Throwable) {
            // Login remains available if the optional throttle table is temporarily unavailable.
            return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
        }
    }

    /** @return array{locked:bool,retry_after:int,failures:int} */
    public function fail(int $userId): array
    {
        if ($userId < 1) return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
        try {
            $this->pdo->beginTransaction();
            $q = $this->pdo->prepare('SELECT failed_count,window_started_at,locked_until FROM auth_login_rate_limits WHERE user_id=? FOR UPDATE');
            $q->execute([$userId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            $now = time();
            $failures = 0;
            $windowStart = $now;
            if (is_array($row)) {
                $oldStart = self::timestamp((string)($row['window_started_at'] ?? ''));
                if ($oldStart > 0 && ($now - $oldStart) < self::WINDOW_SECONDS) {
                    $windowStart = $oldStart;
                    $failures = (int)($row['failed_count'] ?? 0);
                }
            }
            $failures++;
            $locked = $failures >= self::FAILURE_LIMIT;
            $lockedUntil = $locked ? gmdate('Y-m-d H:i:s', $now + self::LOCK_SECONDS) : null;
            $windowAt = gmdate('Y-m-d H:i:s', $windowStart);
            $up = $this->pdo->prepare('INSERT INTO auth_login_rate_limits(user_id,failed_count,window_started_at,last_failed_at,locked_until) VALUES(?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE failed_count=VALUES(failed_count),window_started_at=VALUES(window_started_at),last_failed_at=UTC_TIMESTAMP(),locked_until=VALUES(locked_until)');
            $up->execute([$userId, $failures, $windowAt, $lockedUntil]);
            $this->pdo->commit();
            return ['locked' => $locked, 'retry_after' => $locked ? self::LOCK_SECONDS : 0, 'failures' => $failures];
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['locked' => false, 'retry_after' => 0, 'failures' => 0];
        }
    }

    public function clear(int $userId): void
    {
        if ($userId < 1) return;
        try {
            $q = $this->pdo->prepare('DELETE FROM auth_login_rate_limits WHERE user_id=?');
            $q->execute([$userId]);
        } catch (Throwable) {
        }
    }

    public static function failureLimit(): int { return self::FAILURE_LIMIT; }
    public static function lockSeconds(): int { return self::LOCK_SECONDS; }

    private static function timestamp(string $value): int
    {
        if (trim($value) === '') return 0;
        $ts = \Sokna\Shared\IranTime::epoch($value,'UTC');
        return $ts === false ? 0 : $ts;
    }
}
