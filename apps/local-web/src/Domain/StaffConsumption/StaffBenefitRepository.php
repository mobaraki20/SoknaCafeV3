<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;

final class StaffBenefitRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function policyById(int $policyId): ?array
    {
        if ($policyId < 1) return null;
        $stmt = $this->pdo->prepare('SELECT * FROM staff_benefit_policies WHERE id=? LIMIT 1');
        $stmt->execute([$policyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function profileForPersonnel(int $personnelId): ?array
    {
        if ($personnelId < 1) return null;
        $stmt = $this->pdo->prepare('SELECT * FROM staff_benefit_profiles WHERE personnel_id=? LIMIT 1');
        $stmt->execute([$personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
