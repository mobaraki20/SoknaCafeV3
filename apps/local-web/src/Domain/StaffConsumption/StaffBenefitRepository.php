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

    public function activeProfileForPersonnelAt(int $personnelId, string $date): ?array
    {
        if ($personnelId < 1) return null;
        $stmt=$this->pdo->prepare("SELECT * FROM staff_benefit_profiles WHERE personnel_id=? AND active=1 AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) LIMIT 1");
        $stmt->execute([$personnelId,$date,$date]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function defaultActivePolicy(): ?array
    {
        $row=$this->pdo->query('SELECT * FROM staff_benefit_policies WHERE active=1 AND is_default=1 ORDER BY priority,id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    /** @return list<array> */
    public function activeRulesForPolicy(int $policyId): array
    {
        if($policyId<1)return [];
        $stmt=$this->pdo->prepare('SELECT * FROM staff_benefit_policy_rules WHERE policy_id=? AND active=1 ORDER BY priority,id');
        $stmt->execute([$policyId]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return list<array> */
    public function activeOverridesForPersonnelAt(int $personnelId,string $dateTime): array
    {
        if($personnelId<1)return [];
        $stmt=$this->pdo->prepare("SELECT * FROM staff_benefit_overrides WHERE personnel_id=? AND active=1 AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) ORDER BY valid_from DESC,id DESC");
        $stmt->execute([$personnelId,$dateTime,$dateTime]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }
}
