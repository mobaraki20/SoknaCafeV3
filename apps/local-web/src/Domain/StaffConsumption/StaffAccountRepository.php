<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;

final class StaffAccountRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function balanceForPersonnel(int $personnelId): int
    {
        if ($personnelId < 1) return 0;
        $stmt = $this->pdo->prepare('SELECT balance_after FROM staff_account_ledger WHERE personnel_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$personnelId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int)$value;
    }
}
