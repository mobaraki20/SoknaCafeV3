<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;

final class PersonnelRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findActiveById(int $personnelId): ?array
    {
        if ($personnelId < 1) return null;
        $stmt = $this->pdo->prepare(
            'SELECT id,display_name,linked_user_id,personnel_code,job_title,active,notes ' .
            'FROM personnel WHERE id=? AND active=1 AND archived_at IS NULL LIMIT 1'
        );
        $stmt->execute([$personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function findActiveByLinkedUserId(int $userId): ?array
    {
        if ($userId < 1) return null;
        $stmt = $this->pdo->prepare(
            'SELECT id,display_name,linked_user_id,personnel_code,job_title,active,notes ' .
            'FROM personnel WHERE linked_user_id=? AND active=1 AND archived_at IS NULL LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function lockActiveByIdTx(int $personnelId): ?array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Personnel lock requires an open transaction.');
        if ($personnelId < 1) return null;
        $stmt = $this->pdo->prepare(
            'SELECT id,display_name,linked_user_id,personnel_code,job_title,active,notes ' .
            'FROM personnel WHERE id=? AND active=1 AND archived_at IS NULL LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
