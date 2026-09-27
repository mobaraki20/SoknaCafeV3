<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

final class Capabilities
{
    public const DEFINITIONS = [
        'orders_floor',
        'cashier_accounts',
        'preparation',
        'shift_supervision',
        'inventory_view',
        'inventory_cost_view',
        'inventory_operations',
        'inventory_finalize',
        'inventory_manage',
        'staff_consumption_self',
        'staff_consumption_proxy',
        'staff_benefit_manage',
        'staff_account_manage',
        'staff_consumption_reports',
        'remote_access',
        'remote_operations',
        'remote_preparation',
        'remote_inventory',
        'remote_inventory_cost',
        'remote_reports',
        'remote_deferred_context',
        'remote_order_actions',
        'remote_preparation_actions',
        'remote_table_drafts',
        'remote_settlement',
        'remote_supply',
        'remote_subscriber_payments',
    ];

    public function __construct(private readonly IdentityRepository $repository)
    {
    }

    /** @return list<string> */
    public function forUser(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId < 1) return [];
        if ((string)($user['role'] ?? '') === 'admin') return self::DEFINITIONS;

        $known = array_flip(self::DEFINITIONS);
        $result = [];
        foreach ($this->repository->capabilitiesForUser($userId) as $capability) {
            if (isset($known[$capability])) $result[] = $capability;
        }
        return array_values(array_unique($result));
    }

    public function has(string $capability, array $user): bool
    {
        if (!in_array($capability, self::DEFINITIONS, true)) return false;
        if ((int)($user['id'] ?? 0) < 1) return false;
        if ((string)($user['role'] ?? '') === 'admin') return true;
        return in_array($capability, $this->forUser($user), true);
    }

    /** @return list<string> */
    public function preparationAreas(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId < 1) return [];
        $allowed = ['kitchen' => true, 'bar' => true];
        $result = [];
        foreach ($this->repository->preparationAreasForUser($userId) as $area) {
            if (isset($allowed[$area])) $result[] = $area;
        }
        return array_values(array_unique($result));
    }
}
