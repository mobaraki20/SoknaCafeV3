<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Auth;

use PDO;

final class PublicLoginService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthThrottle $throttle,
        private readonly PublicSessionStore $sessions,
        private readonly AuthSecurityAudit $audit,
    ) {
    }

    public function login(
        string $installationId,
        string $username,
        string $password,
        string $origin = '',
        string $correlationId = '',
    ): array {
        $installationId = trim($installationId);
        $username = trim($username);
        if ($installationId === '' || $username === '' || $password === '') {
            return ['ok' => false, 'error' => 'invalid_credentials', 'status' => 400];
        }

        $accountKey = AuthThrottle::accountKey($username);
        $originKey = AuthThrottle::originKey($origin);
        $installationKnown = $this->installationExists($installationId);

        if ($installationKnown) {
            $retryAfter = $this->throttle->retryAfter($installationId, $accountKey, $originKey);
            if ($retryAfter > 0) {
                $this->audit->record($installationId, null, 'login_throttled', $accountKey, $originKey, $correlationId, [
                    'retry_after' => $retryAfter,
                ]);
                return ['ok' => false, 'error' => 'too_many_attempts', 'status' => 429, 'retry_after' => $retryAfter];
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.projection_id,p.display_name,p.role,p.password_hash,p.capabilities_json,p.preparation_areas_json ' .
            'FROM auth_projections p JOIN installations i ON i.installation_id=p.installation_id ' .
            'WHERE p.installation_id=? AND p.username=? AND p.active=1 AND i.active=1 AND i.remote_enabled=1 LIMIT 1'
        );
        $stmt->execute([$installationId, $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !password_verify($password, (string)($row['password_hash'] ?? ''))) {
            if ($installationKnown) {
                $retryAfter = $this->throttle->registerFailure($installationId, $accountKey, $originKey);
                $this->audit->record($installationId, is_array($row) ? (string)($row['projection_id'] ?? '') : null, 'login_failed', $accountKey, $originKey, $correlationId, [
                    'blocked' => $retryAfter > 0,
                ]);
            }
            return ['ok' => false, 'error' => 'invalid_credentials', 'status' => 401];
        }

        $this->throttle->clear($installationId, $accountKey, $originKey);
        $issued = $this->sessions->issue($installationId, (string)$row['projection_id']);
        $this->audit->record($installationId, (string)$row['projection_id'], 'login_succeeded', $accountKey, $originKey, $correlationId);

        return [
            'ok' => true,
            'status' => 200,
            'token' => (string)$issued['token'],
            'expires_in' => (int)$issued['expires_in'],
            'projection_id' => (string)$row['projection_id'],
            'display_name' => (string)($row['display_name'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'capabilities' => self::decodeList((string)($row['capabilities_json'] ?? '[]')),
            'preparation_areas' => self::decodeList((string)($row['preparation_areas_json'] ?? '[]')),
        ];
    }

    private function installationExists(string $installationId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM installations WHERE installation_id=? LIMIT 1');
        $stmt->execute([$installationId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
