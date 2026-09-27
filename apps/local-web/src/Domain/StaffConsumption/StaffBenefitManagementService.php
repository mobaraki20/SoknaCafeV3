<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;
use PDOException;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class StaffBenefitManagementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
    ) {}

    public function savePolicy(array $data,array $actor): array
    {
        $user=$this->assertManager($actor);$id=(int)($data['id']??0);$key=$this->key($data['policy_key']??'');$name=$this->text($data['name']??'',160);
        if($key===''||$name==='')throw new StaffConsumptionException('benefit_policy_required','کلید و نام Policy لازم است.',422);
        $description=$this->nullableText($data['description']??null,500);$active=$this->bool($data['active']??true);$default=$this->bool($data['is_default']??false);$priority=$this->priority($data['priority']??100);
        $this->pdo->beginTransaction();
        try{
            if($default&&$active)$this->pdo->exec('UPDATE staff_benefit_policies SET is_default=0 WHERE is_default=1');
            if($id>0){$q=$this->pdo->prepare('SELECT id FROM staff_benefit_policies WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new StaffConsumptionException('benefit_policy_missing','Policy مزایا پیدا نشد.',404);$q=$this->pdo->prepare('UPDATE staff_benefit_policies SET policy_key=?,name=?,description=?,active=?,is_default=?,priority=?,updated_by_user_id=? WHERE id=?');$q->execute([$key,$name,$description,$active?1:0,$default&&$active?1:0,$priority,(int)$user['id'],$id]);}
            else{$q=$this->pdo->prepare('INSERT INTO staff_benefit_policies(policy_key,name,description,active,is_default,priority,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$key,$name,$description,$active?1:0,$default&&$active?1:0,$priority,(int)$user['id'],(int)$user['id']]);$id=(int)$this->pdo->lastInsertId();}
            $this->audit('staff_benefit.policy_saved','staff_benefit_policy',$id,$user,['policy_key'=>$key,'active'=>$active,'is_default'=>$default&&$active,'priority'=>$priority]);
            $this->pdo->commit();return ['id'=>$id];
        }catch(PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((int)($e->errorInfo[1]??0)===1062)throw new StaffConsumptionException('benefit_policy_duplicate','کلید Policy تکراری است.',409);throw $e;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function saveRule(array $data,array $actor): array
    {
        $user=$this->assertManager($actor);$id=(int)($data['id']??0);$policyId=(int)($data['policy_id']??0);if($policyId<1)throw new StaffConsumptionException('benefit_policy_required','Policy مزایا لازم است.',422);
        [$scope,$scopeId,$type,$percent,$fixed]=$this->definition($data);$priority=$this->priority($data['priority']??100);$active=$this->bool($data['active']??true);
        $this->pdo->beginTransaction();try{
            $this->assertPolicyTx($policyId);$this->assertScopeTargetTx($scope,$scopeId);
            if($id>0){$q=$this->pdo->prepare('SELECT id FROM staff_benefit_policy_rules WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new StaffConsumptionException('benefit_rule_missing','Rule مزایا پیدا نشد.',404);$q=$this->pdo->prepare('UPDATE staff_benefit_policy_rules SET policy_id=?,scope_type=?,scope_id=?,benefit_type=?,percent_bps=?,fixed_amount=?,priority=?,active=? WHERE id=?');$q->execute([$policyId,$scope,$scopeId,$type,$percent,$fixed,$priority,$active?1:0,$id]);}
            else{$q=$this->pdo->prepare('INSERT INTO staff_benefit_policy_rules(policy_id,scope_type,scope_id,benefit_type,percent_bps,fixed_amount,priority,active) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$policyId,$scope,$scopeId,$type,$percent,$fixed,$priority,$active?1:0]);$id=(int)$this->pdo->lastInsertId();}
            $this->audit('staff_benefit.rule_saved','staff_benefit_rule',$id,$user,['policy_id'=>$policyId,'scope_type'=>$scope,'scope_id'=>$scopeId,'benefit_type'=>$type,'priority'=>$priority,'active'=>$active]);$this->pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function assignProfile(array $data,array $actor): array
    {
        $user=$this->assertManager($actor);$personnelId=(int)($data['personnel_id']??0);if($personnelId<1)throw new StaffConsumptionException('personnel_invalid','پرسنل معتبر نیست.',422);
        $policyId=(int)($data['policy_id']??0);$policyId=$policyId>0?$policyId:null;$active=$this->bool($data['active']??true);$from=$this->date($data['valid_from']??null);$until=$this->date($data['valid_until']??null);$this->dateRange($from,$until);$notes=$this->nullableText($data['notes']??null,500);
        $this->pdo->beginTransaction();try{
            $this->assertPersonnelTx($personnelId);if($policyId!==null)$this->assertPolicyTx($policyId);
            $q=$this->pdo->prepare('SELECT id FROM staff_benefit_profiles WHERE personnel_id=? FOR UPDATE');$q->execute([$personnelId]);$id=(int)($q->fetchColumn()?:0);
            if($id>0){$q=$this->pdo->prepare('UPDATE staff_benefit_profiles SET policy_id=?,active=?,valid_from=?,valid_until=?,notes=?,updated_by_user_id=? WHERE id=?');$q->execute([$policyId,$active?1:0,$from,$until,$notes,(int)$user['id'],$id]);}
            else{$q=$this->pdo->prepare('INSERT INTO staff_benefit_profiles(personnel_id,policy_id,active,valid_from,valid_until,notes,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$personnelId,$policyId,$active?1:0,$from,$until,$notes,(int)$user['id'],(int)$user['id']]);$id=(int)$this->pdo->lastInsertId();}
            $this->audit('staff_benefit.profile_assigned','staff_benefit_profile',$id,$user,['personnel_id'=>$personnelId,'policy_id'=>$policyId,'active'=>$active,'valid_from'=>$from,'valid_until'=>$until,'explicit_no_benefit'=>$active&&$policyId===null]);$this->pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function saveOverride(array $data,array $actor): array
    {
        $user=$this->assertManager($actor);$id=(int)($data['id']??0);$personnelId=(int)($data['personnel_id']??0);if($personnelId<1)throw new StaffConsumptionException('personnel_invalid','پرسنل معتبر نیست.',422);
        [$scope,$scopeId,$type,$percent,$fixed]=$this->definition($data);$active=$this->bool($data['active']??true);$from=$this->dateTime($data['valid_from']??null);$until=$this->dateTime($data['valid_until']??null);$this->dateTimeRange($from,$until);$reason=$this->text($data['reason']??'',500);if($reason==='')throw new StaffConsumptionException('benefit_override_reason_required','دلیل Override مزایا لازم است.',422);
        $this->pdo->beginTransaction();try{
            $this->assertPersonnelTx($personnelId);$this->assertScopeTargetTx($scope,$scopeId);
            if($id>0){$q=$this->pdo->prepare('SELECT id FROM staff_benefit_overrides WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new StaffConsumptionException('benefit_override_missing','Override مزایا پیدا نشد.',404);$q=$this->pdo->prepare('UPDATE staff_benefit_overrides SET personnel_id=?,scope_type=?,scope_id=?,benefit_type=?,percent_bps=?,fixed_amount=?,active=?,valid_from=?,valid_until=?,reason=?,updated_by_user_id=? WHERE id=?');$q->execute([$personnelId,$scope,$scopeId,$type,$percent,$fixed,$active?1:0,$from,$until,$reason,(int)$user['id'],$id]);}
            else{$q=$this->pdo->prepare('INSERT INTO staff_benefit_overrides(personnel_id,scope_type,scope_id,benefit_type,percent_bps,fixed_amount,active,valid_from,valid_until,reason,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$personnelId,$scope,$scopeId,$type,$percent,$fixed,$active?1:0,$from,$until,$reason,(int)$user['id'],(int)$user['id']]);$id=(int)$this->pdo->lastInsertId();}
            $this->audit('staff_benefit.override_saved','staff_benefit_override',$id,$user,['personnel_id'=>$personnelId,'scope_type'=>$scope,'scope_id'=>$scopeId,'benefit_type'=>$type,'active'=>$active,'valid_from'=>$from,'valid_until'=>$until,'reason'=>$reason]);$this->pdo->commit();return ['id'=>$id];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function assertManager(array $actor): array{$id=(int)($actor['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;if($fresh===null||!$this->capabilities->has('staff_benefit_manage',$fresh))throw new StaffConsumptionException('forbidden','دسترسی مدیریت مزایای پرسنل فعال نیست.',403);return $fresh;}
    private function assertPolicyTx(int $id): void{$q=$this->pdo->prepare('SELECT id FROM staff_benefit_policies WHERE id=? FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new StaffConsumptionException('benefit_policy_missing','Policy مزایا پیدا نشد.',404);}
    private function assertPersonnelTx(int $id): void{$q=$this->pdo->prepare('SELECT id FROM personnel WHERE id=? AND archived_at IS NULL FOR UPDATE');$q->execute([$id]);if(!$q->fetchColumn())throw new StaffConsumptionException('personnel_unavailable','پرسنل پیدا نشد.',404);}
    private function assertScopeTargetTx(string $scope,?int $id): void{if($scope==='all')return;$table=$scope==='item'?'items':'categories';$q=$this->pdo->prepare("SELECT id FROM {$table} WHERE id=? FOR UPDATE");$q->execute([$id]);if(!$q->fetchColumn())throw new StaffConsumptionException('benefit_scope_target_missing','هدف محدوده مزایا پیدا نشد.',422);}
    private function definition(array $data): array{$scope=(string)($data['scope_type']??'all');$scopeId=isset($data['scope_id'])&&(int)$data['scope_id']>0?(int)$data['scope_id']:null;$type=(string)($data['benefit_type']??'none');$percent=isset($data['percent_bps'])&&$data['percent_bps']!==''?(int)$data['percent_bps']:null;$fixed=isset($data['fixed_amount'])&&$data['fixed_amount']!==''?(int)$data['fixed_amount']:null;if(!in_array($scope,['all','category','item'],true)||($scope==='all'&&$scopeId!==null)||($scope!=='all'&&$scopeId===null))throw new StaffConsumptionException('benefit_scope_invalid','محدوده مزیت معتبر نیست.',422);if(!in_array($type,['none','free','percent','fixed'],true))throw new StaffConsumptionException('benefit_type_invalid','نوع مزیت معتبر نیست.',422);if($type==='percent'&&($percent===null||$percent<0||$percent>10000))throw new StaffConsumptionException('benefit_value_invalid','درصد مزیت معتبر نیست.',422);if($type==='fixed'&&($fixed===null||$fixed<0))throw new StaffConsumptionException('benefit_value_invalid','مبلغ ثابت مزیت معتبر نیست.',422);if($type!=='percent')$percent=null;if($type!=='fixed')$fixed=null;return[$scope,$scopeId,$type,$percent,$fixed];}
    private function key(mixed $v): string{$v=strtolower(trim((string)$v));return preg_match('/^[a-z0-9][a-z0-9._-]{1,79}$/',$v)?$v:'';}
    private function priority(mixed $v): int{$n=(int)$v;if($n<0||$n>65535)throw new StaffConsumptionException('benefit_priority_invalid','اولویت مزایا معتبر نیست.',422);return $n;}
    private function text(mixed $v,int $max): string{return mb_substr(trim((string)$v),0,$max,'UTF-8');}
    private function nullableText(mixed $v,int $max): ?string{$s=$this->text($v,$max);return $s===''?null:$s;}
    private function bool(mixed $v): bool{return is_bool($v)?$v:in_array(strtolower(trim((string)$v)),['1','true','yes','on'],true);}
    private function date(mixed $v): ?string{$s=trim((string)($v??''));if($s==='')return null;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$s);if($d===false||$d->format('Y-m-d')!==$s)throw new StaffConsumptionException('benefit_date_invalid','تاریخ مزایا معتبر نیست.',422);return $s;}
    private function dateTime(mixed $v): ?string{$s=trim((string)($v??''));if($s==='')return null;$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$s);if($d===false||$d->format('Y-m-d H:i:s')!==$s)throw new StaffConsumptionException('benefit_date_invalid','زمان مزایا معتبر نیست.',422);return $s;}
    private function dateRange(?string $a,?string $b): void{if($a!==null&&$b!==null&&$b<$a)throw new StaffConsumptionException('benefit_date_range_invalid','بازه تاریخ مزایا معتبر نیست.',422);}
    private function dateTimeRange(?string $a,?string $b): void{if($a!==null&&$b!==null&&$b<$a)throw new StaffConsumptionException('benefit_date_range_invalid','بازه زمان مزایا معتبر نیست.',422);}
    private function audit(string $action,string $entity,int $id,array $actor,array $details): void{$q=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)');$q->execute([(int)$actor['id'],(string)($actor['display_name']??''),$action,$entity,(string)$id,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);}
}
