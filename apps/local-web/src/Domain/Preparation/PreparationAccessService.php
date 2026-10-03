<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Preparation;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;

final class PreparationAccessService
{
    public const AREAS=['kitchen','bar'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
    ) {}

    /** @return array<string,mixed> */
    public function context(?array $user): array
    {
        $userId=(int)($user['id']??0);
        if($userId<1)return self::emptyContext();

        $fresh=$this->identity->findActiveById($userId);
        if($fresh===null)return self::emptyContext();

        $role=(string)($fresh['role']??'');
        $isAdmin=$role==='admin';
        $hasPreparation=$this->capabilities->has('preparation',$fresh);
        $hasSupervision=$this->capabilities->has('shift_supervision',$fresh);
        $assigned=$hasPreparation?$this->assignedAreas($userId):[];

        $globalMonitor=$isAdmin||$hasSupervision;
        $visible=$globalMonitor?self::AREAS:$assigned;

        // Frozen Phase 6A rule: admin role alone is never an operational grant.
        // Supervision expands visibility, never mutation.
        $actionable=(!$isAdmin&&$hasPreparation)?$assigned:[];

        return [
            'user'=>$fresh,
            'can_view'=>$visible!==[],
            'monitor_only'=>$visible!==[]&&$actionable===[],
            'can_mutate'=>$actionable!==[],
            'assigned_areas'=>$assigned,
            'visible_areas'=>$visible,
            'actionable_areas'=>$actionable,
            'has_preparation'=>$hasPreparation,
            'has_shift_supervision'=>$hasSupervision,
            'is_admin'=>$isAdmin,
        ];
    }

    /** @return list<string> */
    public function assignedAreas(int $userId): array
    {
        if($userId<1)return [];
        $stmt=$this->pdo->prepare(
            "SELECT area_key FROM user_preparation_areas
             WHERE user_id=? AND area_key IN ('kitchen','bar')
             ORDER BY FIELD(area_key,'kitchen','bar')"
        );
        $stmt->execute([$userId]);
        return array_values(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function canMutateArea(string $area,?array $user): bool
    {
        if(!in_array($area,self::AREAS,true))return false;
        return in_array($area,$this->context($user)['actionable_areas'],true);
    }

    private static function emptyContext(): array
    {
        return [
            'user'=>null,
            'can_view'=>false,'monitor_only'=>false,'can_mutate'=>false,
            'assigned_areas'=>[],'visible_areas'=>[],'actionable_areas'=>[],
            'has_preparation'=>false,'has_shift_supervision'=>false,'is_admin'=>false,
        ];
    }
}
