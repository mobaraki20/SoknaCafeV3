<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Auth;

use PDO;
use Throwable;

final class AuthThrottle
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $failureLimit = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $blockSeconds = 900,
    ) {
    }

    public static function accountKey(string $username): string
    {
        return hash('sha256', strtolower(trim($username)));
    }

    public static function originKey(string $origin): string
    {
        $origin = trim($origin);
        return hash('sha256', $origin !== '' ? $origin : 'unknown');
    }

    public function retryAfter(string $installationId, string $accountKey, string $originKey): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT UNIX_TIMESTAMP(blocked_until) FROM auth_login_throttle WHERE installation_id=? AND account_key=? AND origin_key=? LIMIT 1'
        );
        $stmt->execute([$installationId, $accountKey, $originKey]);
        $blockedUntil = max(0, (int)($stmt->fetchColumn() ?: 0));
        return max(0, $blockedUntil - time());
    }

    public function registerFailure(string $installationId, string $accountKey, string $originKey): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT failed_count,UNIX_TIMESTAMP(window_started_at) window_started_at FROM auth_login_throttle ' .
                'WHERE installation_id=? AND account_key=? AND origin_key=? FOR UPDATE'
            );
            $stmt->execute([$installationId, $accountKey, $originKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $now = time();

            if (!is_array($row)) {
                $count = 1;
                $this->pdo->prepare(
                    'INSERT INTO auth_login_throttle(installation_id,account_key,origin_key,failed_count,window_started_at,blocked_until) ' .
                    'VALUES(?,?,?,?,UTC_TIMESTAMP(),NULL)'
                )->execute([$installationId, $accountKey, $originKey, $count]);
            } else {
                $windowStarted = (int)($row['window_started_at'] ?? 0);
                $insideWindow = $windowStarted > 0 && $windowStarted >= $now - $this->windowSeconds;
                $count = $insideWindow ? (int)($row['failed_count'] ?? 0) + 1 : 1;
                $blocked = $count >= $this->failureLimit;
                $sql = 'UPDATE auth_login_throttle SET failed_count=?, ' .
                    ($insideWindow ? '' : 'window_started_at=UTC_TIMESTAMP(), ') .
                    'blocked_until=' . ($blocked ? 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)' : 'NULL') .
                    ' WHERE installation_id=? AND account_key=? AND origin_key=?';
                $params = [$count];
                if ($blocked) $params[] = $this->blockSeconds;
                array_push($params, $installationId, $accountKey, $originKey);
                $this->pdo->prepare($sql)->execute($params);
            }

            if ($count >= $this->failureLimit) {
                $this->pdo->prepare(
                    'UPDATE auth_login_throttle SET blocked_until=COALESCE(blocked_until,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)) ' .
                    'WHERE installation_id=? AND account_key=? AND origin_key=?'
                )->execute([$this->blockSeconds, $installationId, $accountKey, $originKey]);
            }

            $this->pdo->commit();
            return $this->retryAfter($installationId, $accountKey, $originKey);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function clear(string $installationId, string $accountKey, string $originKey): void
    {
        $this->pdo->prepare('DELETE FROM auth_login_throttle WHERE installation_id=? AND account_key=? AND origin_key=?')
            ->execute([$installationId, $accountKey, $originKey]);
    }
}
