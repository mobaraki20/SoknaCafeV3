<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Supply;

use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Preparation\PreparationAccessService;

final class SupplyAccessService
{
    public function __construct(
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly PreparationAccessService $preparationAccess,
    ) {}

    public function actor(array $user): array
    {
        $id=(int)($user['id']??0);
        if($id<1)throw new SupplyException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($id);
        if($fresh===null)throw new SupplyException('forbidden','حساب کاربری فعال نیست.',403);
        return $fresh;
    }

    public function canManagePurchases(array $user): bool
    {
        $fresh=$this->actor($user);
        if((string)($fresh['role']??'')==='admin')return true;
        return $this->capabilities->has('inventory_operations',$fresh)
            || $this->capabilities->has('inventory_finalize',$fresh)
            || $this->capabilities->has('inventory_manage',$fresh);
    }

    public function canReportNeeds(array $user): bool
    {
        $fresh=$this->actor($user);
        if((string)($fresh['role']??'')==='admin')return true;
        return $this->capabilities->has('preparation',$fresh)
            || $this->capabilities->has('inventory_operations',$fresh)
            || $this->capabilities->has('inventory_finalize',$fresh)
            || $this->capabilities->has('inventory_manage',$fresh)
            || $this->capabilities->has('shift_supervision',$fresh);
    }

    /** @return list<string> */
    public function allowedNeedDepartments(array $user): array
    {
        $fresh=$this->actor($user);
        if((string)($fresh['role']??'')==='admin'
            || $this->capabilities->has('inventory_operations',$fresh)
            || $this->capabilities->has('inventory_finalize',$fresh)
            || $this->capabilities->has('inventory_manage',$fresh)
            || $this->capabilities->has('shift_supervision',$fresh)) {
            return ['kitchen','bar','shared'];
        }
        $ctx=$this->preparationAccess->context($fresh);
        return array_values(array_intersect(['kitchen','bar'],$ctx['assigned_areas']));
    }

    public function assertReporter(array $user,string $department): array
    {
        $fresh=$this->actor($user);
        if(!$this->canReportNeeds($fresh))
            throw new SupplyException('forbidden','دسترسی ثبت درخواست خرید برای این حساب فعال نیست.',403);
        if(!in_array($department,$this->allowedNeedDepartments($fresh),true))
            throw new SupplyException('forbidden_department','این بخش برای ثبت درخواست خرید در اختیار کاربر نیست.',403,['department'=>$department]);
        return $fresh;
    }

    public function assertBuyer(array $user): array
    {
        $fresh=$this->actor($user);
        if(!$this->canManagePurchases($fresh))
            throw new SupplyException('forbidden','دسترسی خرید و تحویل برای این حساب فعال نیست.',403);
        return $fresh;
    }
}
