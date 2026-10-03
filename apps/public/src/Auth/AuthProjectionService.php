<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Auth;

use PDO;
use RuntimeException;
use Throwable;

final class AuthProjectionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Apply one authoritative Local projection set for an installation.
     * Returns the number of valid projection rows applied.
     */
    public function sync(string $installationId, array $rows): int
    {
        $installationId = trim($installationId);
        if ($installationId === '') throw new RuntimeException('installation_id is required');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE auth_projections SET active=0 WHERE installation_id=?')->execute([$installationId]);
            $count = 0;

            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $projectionId = trim((string)($row['projection_id'] ?? ''));
                $username = trim((string)($row['username'] ?? ''));
                $displayName = trim((string)($row['display_name'] ?? $username));
                $role = trim((string)($row['role'] ?? ''));
                $passwordHash = (string)($row['password_hash'] ?? '');
                if ($projectionId === '' || $username === '' || $passwordHash === '') continue;

                $capabilities = json_encode(
                    is_array($row['capabilities'] ?? null) ? array_values($row['capabilities']) : [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
                $areas = json_encode(
                    is_array($row['preparation_areas'] ?? null) ? array_values($row['preparation_areas']) : [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
                $version = max(1, (int)($row['projection_version'] ?? 1));
                $active = !empty($row['active']) ? 1 : 0;

                $stmt = $this->pdo->prepare(
                    'INSERT INTO auth_projections(' .
                    'installation_id,projection_id,username,display_name,role,password_hash,capabilities_json,preparation_areas_json,projection_version,active' .
                    ') VALUES(?,?,?,?,?,?,?,?,?,?) ' .
                    'ON DUPLICATE KEY UPDATE ' .
                    'username=VALUES(username),display_name=VALUES(display_name),role=VALUES(role),' .
                    'password_hash=VALUES(password_hash),capabilities_json=VALUES(capabilities_json),' .
                    'preparation_areas_json=VALUES(preparation_areas_json),projection_version=VALUES(projection_version),active=VALUES(active)'
                );
                $stmt->execute([
                    $installationId,
                    $projectionId,
                    $username,
                    $displayName,
                    $role,
                    $passwordHash,
                    $capabilities,
                    $areas,
                    $version,
                    $active,
                ]);
                $count++;
            }

            $this->pdo->commit();
            return $count;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
