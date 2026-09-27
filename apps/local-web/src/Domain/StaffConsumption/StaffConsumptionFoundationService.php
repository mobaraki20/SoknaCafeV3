<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;

final class StaffConsumptionFoundationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly PersonnelRepository $personnel,
    ) {}

    public function selfConsumer(array $user): array
    {
        $actor = $this->assertCapability($user, 'staff_consumption_self');
        $personnel = $this->personnel->findActiveByLinkedUserId((int)$actor['id']);
        if ($personnel === null) {
            throw new StaffConsumptionException('personnel_not_linked', 'برای این حساب پرسنل فعال متصل نشده است.', 409);
        }
        return $personnel;
    }

    public function proxyConsumer(int $personnelId, array $user): array
    {
        $this->assertCapability($user, 'staff_consumption_proxy');
        $personnel = $this->personnel->findActiveById($personnelId);
        if ($personnel === null) {
            throw new StaffConsumptionException('personnel_unavailable', 'پرسنل فعال پیدا نشد.', 404);
        }
        return $personnel;
    }

    /** @return array{actor:array,personnel:array} */
    public function selfPostingIdentityTx(array $user): array
    {
        $this->requireTx();
        $actor=$this->assertCapability($user,'staff_consumption_self');
        $personnel=$this->personnel->lockActiveByLinkedUserIdTx((int)$actor['id']);
        if($personnel===null)throw new StaffConsumptionException('personnel_not_linked','برای این حساب پرسنل فعال متصل نشده است.',409);
        return ['actor'=>$actor,'personnel'=>$personnel];
    }

    /** @return array{actor:array,personnel:array} */
    public function proxyPostingIdentityTx(int $personnelId,array $user): array
    {
        $this->requireTx();
        $actor=$this->assertCapability($user,'staff_consumption_proxy');
        $personnel=$this->personnel->lockActiveByIdTx($personnelId);
        if($personnel===null)throw new StaffConsumptionException('personnel_unavailable','پرسنل فعال پیدا نشد.',404);
        return ['actor'=>$actor,'personnel'=>$personnel];
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Staff Consumption posting identity requires an open transaction.');
    }

    private function assertCapability(array $user, string $capability): array
    {
        $userId = (int)($user['id'] ?? 0);
        $fresh = $userId > 0 ? $this->identity->findActiveById($userId) : null;
        if ($fresh === null || !$this->capabilities->has($capability, $fresh)) {
            throw new StaffConsumptionException('forbidden', 'دسترسی ثبت مصرف پرسنلی برای این حساب فعال نیست.', 403);
        }
        return $fresh;
    }
}
