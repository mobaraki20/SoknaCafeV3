<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use PDO;

final class PdoIdentityRepository implements IdentityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActiveById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,username,display_name,role,active FROM users WHERE id=? AND active=1 LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($user) ? $user : null;
    }

    public function findActiveByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,username,password_hash,display_name,role,active FROM users WHERE username=? AND active=1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($user) ? $user : null;
    }

    public function capabilitiesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT capability,enabled FROM user_capabilities WHERE user_id=?');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if ((int)($row['enabled'] ?? 0) === 1) $result[] = (string)($row['capability'] ?? '');
        }
        return array_values(array_filter($result, static fn(string $value): bool => $value !== ''));
    }

    public function preparationAreasForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT area_key FROM user_preparation_areas WHERE user_id=? AND area_key IN ('kitchen','bar') ORDER BY area_key");
        $stmt->execute([$userId]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
}
