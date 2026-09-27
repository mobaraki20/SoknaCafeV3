<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Auth;

use PDO;

final class PublicSessionStore
{
    public function __construct(private readonly PDO $pdo, private readonly int $ttlSeconds = 28800)
    {
    }

    public function issue(string $installationId, string $projectionId): array
    {
        $token = bin2hex(random_bytes(32));
        $ttl = max(900, $this->ttlSeconds);
        $stmt = $this->pdo->prepare(
            'INSERT INTO public_sessions(installation_id,projection_id,token_hash,expires_at) ' .
            'VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))'
        );
        $stmt->execute([$installationId, $projectionId, hash('sha256', $token), $ttl]);
        return ['token' => $token, 'expires_in' => $ttl];
    }

    public function resolve(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') return null;
        $stmt = $this->pdo->prepare(
            'SELECT s.installation_id,s.projection_id,s.expires_at,p.display_name,p.role,p.capabilities_json,p.preparation_areas_json ' .
            'FROM public_sessions s ' .
            'JOIN auth_projections p ON p.installation_id=s.installation_id AND p.projection_id=s.projection_id ' .
            'JOIN installations i ON i.installation_id=s.installation_id ' .
            'WHERE s.token_hash=? AND p.active=1 AND i.active=1 AND i.remote_enabled=1 AND s.expires_at>UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['capabilities'] = self::decodeList((string)($row['capabilities_json'] ?? '[]'));
        $row['preparation_areas'] = self::decodeList((string)($row['preparation_areas_json'] ?? '[]'));
        unset($row['capabilities_json'], $row['preparation_areas_json']);
        return $row;
    }

    public function revoke(string $token): void
    {
        $token=trim($token);
        if($token==='')return;
        $this->pdo->prepare('DELETE FROM public_sessions WHERE token_hash=?')->execute([hash('sha256',$token)]);
    }

    private static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
