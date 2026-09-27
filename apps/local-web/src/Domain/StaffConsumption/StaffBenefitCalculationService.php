<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;

final class StaffBenefitCalculationService
{
    public function __construct(
        private readonly StaffBenefitRepository $repository,
        private readonly StaffBenefitCalculator $calculator,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
    ) {}

    /** @param list<array> $lines */
    public function quote(int $personnelId, array $lines, string $evaluationAt, array $actor, ?array $runtimeOverride=null): array
    {
        if($personnelId<1)throw new StaffConsumptionException('personnel_invalid','پرسنل برای محاسبه مزایا معتبر نیست.',422);
        $evaluationAt=$this->evaluationAt($evaluationAt);
        $runtime=$runtimeOverride===null?null:$this->authorizedRuntimeOverride($runtimeOverride,$actor);
        $profile=$this->repository->activeProfileForPersonnelAt($personnelId,substr($evaluationAt,0,10));
        $profilePresent=$profile!==null;
        $assigned=null;
        if($profilePresent&&(int)($profile['policy_id']??0)>0)$assigned=$this->repository->policyById((int)$profile['policy_id']);
        $default=$profilePresent?null:$this->repository->defaultActivePolicy();
        $selection=$this->calculator->selectPolicy($profilePresent,$profile,$assigned,$default);
        $rules=$selection['policy_id']!==null?$this->repository->activeRulesForPolicy((int)$selection['policy_id']):[];
        $stored=$this->repository->activeOverridesForPersonnelAt($personnelId,$evaluationAt);
        return $this->calculator->calculate($lines,[
            'evaluation_at'=>$evaluationAt,'personnel_id'=>$personnelId,
            'profile_present'=>$profilePresent,'profile'=>$profile,'assigned_policy'=>$assigned,'default_policy'=>$default,
            'policy_rules'=>$rules,'stored_overrides'=>$stored,'runtime_override'=>$runtime,
        ]);
    }

    private function authorizedRuntimeOverride(array $override,array $actor): array
    {
        $id=(int)($actor['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null||!$this->capabilities->has('staff_benefit_manage',$fresh))throw new StaffConsumptionException('benefit_override_forbidden','برای اعمال مزیت موردی دسترسی مدیریت مزایا لازم است.',403);
        $scope=(string)($override['scope_type']??'all');$scopeId=isset($override['scope_id'])?(int)$override['scope_id']:null;
        $type=(string)($override['benefit_type']??'none');$percent=isset($override['percent_bps'])?(int)$override['percent_bps']:null;$fixed=isset($override['fixed_amount'])?(int)$override['fixed_amount']:null;
        $reason=trim((string)($override['reason']??''));if($reason==='')throw new StaffConsumptionException('benefit_override_reason_required','دلیل مزیت موردی لازم است.',422);
        $this->validateDefinition($scope,$scopeId,$type,$percent,$fixed);
        return ['scope_type'=>$scope,'scope_id'=>$scopeId,'benefit_type'=>$type,'percent_bps'=>$percent,'fixed_amount'=>$fixed,'reason'=>$reason,'actor_user_id'=>(int)$fresh['id'],'active'=>1];
    }

    private function evaluationAt(string $value): string
    {
        $value=trim($value);
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        if($dt===false||$dt->format('Y-m-d H:i:s')!==$value)throw new StaffConsumptionException('evaluation_at_invalid','زمان محاسبه مزایا معتبر نیست.',422);
        return $value;
    }

    private function validateDefinition(string $scope,?int $scopeId,string $type,?int $percent,?int $fixed): void
    {
        if(!in_array($scope,['all','category','item'],true)||($scope==='all'&&$scopeId!==null)||($scope!=='all'&&($scopeId??0)<1))throw new StaffConsumptionException('benefit_scope_invalid','محدوده مزیت معتبر نیست.',422);
        if(!in_array($type,['none','free','percent','fixed'],true))throw new StaffConsumptionException('benefit_type_invalid','نوع مزیت معتبر نیست.',422);
        if($type==='percent'&&($percent===null||$percent<0||$percent>10000))throw new StaffConsumptionException('benefit_value_invalid','درصد مزیت معتبر نیست.',422);
        if($type==='fixed'&&($fixed===null||$fixed<0))throw new StaffConsumptionException('benefit_value_invalid','مبلغ ثابت مزیت معتبر نیست.',422);
        if(in_array($type,['none','free'],true)&&($percent!==null||$fixed!==null))throw new StaffConsumptionException('benefit_value_invalid','مقدار مزیت با نوع انتخاب‌شده سازگار نیست.',422);
        if($type==='percent'&&$fixed!==null)throw new StaffConsumptionException('benefit_value_invalid','مقدار مزیت با نوع انتخاب‌شده سازگار نیست.',422);
        if($type==='fixed'&&$percent!==null)throw new StaffConsumptionException('benefit_value_invalid','مقدار مزیت با نوع انتخاب‌شده سازگار نیست.',422);
    }
}
