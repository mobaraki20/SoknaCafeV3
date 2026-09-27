<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;
use Sokna\Local\Core\Capabilities;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Orders\OrderCatalogService;
use Sokna\Local\Domain\Orders\OrderCommitException;
use Throwable;

final class StaffConsumptionWorkspaceService
{
    private const ACCESS=[
        'staff_consumption_self','staff_consumption_proxy','staff_benefit_manage','staff_account_manage','staff_consumption_reports',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
        private readonly PersonnelRepository $personnel,
        private readonly StaffConsumptionFoundationService $foundation,
        private readonly OrderCatalogService $catalog,
        private readonly StaffBenefitCalculationService $benefits,
    ) {}

    public function snapshot(array $user): array
    {
        $fresh=$this->assertWorkspaceAccess($user);$access=$this->accessMap($fresh);
        $self=$access['staff_consumption_self']?$this->personnel->findActiveByLinkedUserId((int)$fresh['id']):null;
        $needsPersonnel=$access['staff_consumption_proxy']||$access['staff_benefit_manage']||$access['staff_account_manage']||$access['staff_consumption_reports'];
        $personnel=$needsPersonnel?$this->activePersonnel():($self!==null?[$self]:[]);
        $catalog=($access['staff_consumption_self']||$access['staff_consumption_proxy'])?$this->catalog->staffCatalogRows():[];
        $history=[];
        if($access['staff_consumption_reports'])$history=$this->historyRows(null,50);
        elseif($self!==null)$history=$this->historyRows((int)$self['id'],30);
        return [
            'success'=>true,
            'access'=>$access,
            'self_personnel'=>$self,
            'personnel'=>$personnel,
            'catalog'=>$catalog,
            'history'=>$history,
            'benefits'=>$access['staff_benefit_manage']?$this->benefitManagementSnapshot():null,
            'report_defaults'=>['from'=>date('Y-m-01'),'to'=>date('Y-m-d')],
        ];
    }

    public function quoteSelf(array $data,array $user): array
    {
        $personnel=$this->foundation->selfConsumer($user);
        return $this->quote((int)$personnel['id'],$data,$user);
    }

    public function quoteForPersonnel(int $personnelId,array $data,array $user): array
    {
        $personnel=$this->foundation->proxyConsumer($personnelId,$user);
        return $this->quote((int)$personnel['id'],$data,$user);
    }

    public function history(?int $personnelId,array $user,int $limit=100): array
    {
        $fresh=$this->assertWorkspaceAccess($user);$canReport=$this->capabilities->has('staff_consumption_reports',$fresh);
        if($canReport)return ['success'=>true,'rows'=>$this->historyRows(($personnelId??0)>0?$personnelId:null,$limit)];
        if(!$this->capabilities->has('staff_consumption_self',$fresh))throw new StaffConsumptionException('forbidden','دسترسی مشاهده سابقه مصرف فعال نیست.',403);
        $self=$this->personnel->findActiveByLinkedUserId((int)$fresh['id']);
        if($self===null)throw new StaffConsumptionException('personnel_not_linked','برای این حساب پرسنل فعال متصل نشده است.',409);
        if(($personnelId??0)>0&&(int)$personnelId!==(int)$self['id'])throw new StaffConsumptionException('forbidden','این سابقه برای حساب شما قابل مشاهده نیست.',403);
        return ['success'=>true,'rows'=>$this->historyRows((int)$self['id'],$limit)];
    }

    private function quote(int $personnelId,array $data,array $user): array
    {
        $occurredAt=$this->dateTime($data['occurred_at']??null);$runtime=is_array($data['runtime_override']??null)?$data['runtime_override']:null;
        try{
            $normalized=$this->catalog->normalizeRows($data['items']??null,false);
            $this->pdo->beginTransaction();
            try{
                $catalogLines=$this->catalog->snapshotRowsTx($normalized,'staff');
                $quoteLines=array_map(static fn(array $line):array=>[
                    'item_id'=>(int)$line['item_id'],'category_id'=>(int)$line['category_id'],'item_name'=>(string)$line['item_name'],
                    'unit_price'=>(int)$line['unit_price'],'quantity'=>(int)$line['quantity'],
                ],$catalogLines);
                $quote=$this->benefits->quote($personnelId,$quoteLines,$occurredAt,$user,$runtime);
                $payable=(int)($quote['menu_value_amount']??0)-(int)($quote['benefit_amount']??0)-(int)($quote['discount_amount']??0);
                if($payable<0)throw new StaffConsumptionException('quote_amount_invalid','مبلغ قابل پرداخت محاسبه‌شده معتبر نیست.',500);
                $quoteView=$quote;$quoteView['payable_amount']=$payable;
                $this->pdo->commit();
            }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }catch(OrderCommitException $e){throw new StaffConsumptionException($e->errorCode,$e->getMessage(),$e->httpStatus);}
        return ['success'=>true,'quote'=>$quoteView,'occurred_at'=>$occurredAt];
    }

    private function activePersonnel(): array
    {
        return $this->pdo->query(
            "SELECT p.id,p.display_name,p.personnel_code,p.job_title,p.linked_user_id,u.username linked_username " .
            "FROM personnel p LEFT JOIN users u ON u.id=p.linked_user_id " .
            "WHERE p.active=1 AND p.archived_at IS NULL ORDER BY p.display_name,p.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function historyRows(?int $personnelId,int $limit): array
    {
        $limit=max(1,min(250,$limit));$params=[];$where="sc.status='posted'";
        if(($personnelId??0)>0){$where.=' AND sc.consumer_personnel_id=?';$params[]=$personnelId;}
        $sql="SELECT sc.id,sc.public_code,sc.consumer_personnel_id,sc.recorded_by_user_id,sc.consumer_name_snapshot," .
            "u.display_name recorder_name,sc.menu_value_amount,sc.benefit_amount,sc.discount_amount,sc.payable_amount,sc.known_cost_amount," .
            "sc.business_date,sc.business_shift_key,sc.created_at," .
            "(SELECT GROUP_CONCAT(CONCAT(l.item_name_snapshot,' × ',l.quantity) ORDER BY l.id SEPARATOR '، ') FROM staff_consumption_lines l WHERE l.consumption_id=sc.id) items_summary " .
            "FROM staff_consumptions sc JOIN users u ON u.id=sc.recorded_by_user_id WHERE {$where} ORDER BY sc.business_date DESC,sc.id DESC LIMIT {$limit}";
        $q=$this->pdo->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function benefitManagementSnapshot(): array
    {
        $policies=$this->pdo->query(
            'SELECT p.*, (SELECT COUNT(*) FROM staff_benefit_policy_rules r WHERE r.policy_id=p.id) rule_count FROM staff_benefit_policies p ORDER BY p.active DESC,p.is_default DESC,p.priority,p.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $rules=$this->pdo->query(
            "SELECT r.*,p.name policy_name,CASE WHEN r.scope_type='item' THEN i.name WHEN r.scope_type='category' THEN c.name ELSE 'همه اقلام' END scope_label " .
            "FROM staff_benefit_policy_rules r JOIN staff_benefit_policies p ON p.id=r.policy_id " .
            "LEFT JOIN items i ON r.scope_type='item' AND i.id=r.scope_id LEFT JOIN categories c ON r.scope_type='category' AND c.id=r.scope_id " .
            'ORDER BY p.priority,p.id,r.priority,r.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $profiles=$this->pdo->query(
            'SELECT pr.*,p.display_name personnel_name,pol.name policy_name FROM staff_benefit_profiles pr JOIN personnel p ON p.id=pr.personnel_id LEFT JOIN staff_benefit_policies pol ON pol.id=pr.policy_id ORDER BY p.display_name,p.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $overrides=$this->pdo->query(
            "SELECT o.*,p.display_name personnel_name,CASE WHEN o.scope_type='item' THEN i.name WHEN o.scope_type='category' THEN c.name ELSE 'همه اقلام' END scope_label " .
            "FROM staff_benefit_overrides o JOIN personnel p ON p.id=o.personnel_id " .
            "LEFT JOIN items i ON o.scope_type='item' AND i.id=o.scope_id LEFT JOIN categories c ON o.scope_type='category' AND c.id=o.scope_id " .
            'ORDER BY o.active DESC,p.display_name,o.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $scopeTargets=[
            'categories'=>$this->pdo->query('SELECT id,name,active FROM categories ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC),
            'items'=>$this->pdo->query('SELECT id,name,category_id,active FROM items ORDER BY category_id,sort_order,id')->fetchAll(PDO::FETCH_ASSOC),
        ];
        return ['policies'=>$policies,'rules'=>$rules,'profiles'=>$profiles,'overrides'=>$overrides,'scope_targets'=>$scopeTargets];
    }

    private function assertWorkspaceAccess(array $user): array
    {
        $fresh=$this->identity->findActiveById((int)($user['id']??0));
        if($fresh===null)throw new StaffConsumptionException('forbidden','حساب فعال پیدا نشد.',403);
        foreach(self::ACCESS as $cap)if($this->capabilities->has($cap,$fresh))return $fresh;
        throw new StaffConsumptionException('forbidden','دسترسی فضای مصرف پرسنل فعال نیست.',403);
    }

    private function accessMap(array $user): array
    {
        $result=[];foreach(self::ACCESS as $cap)$result[$cap]=$this->capabilities->has($cap,$user);return $result;
    }

    private function dateTime(mixed $value): string
    {
        $v=trim((string)($value??''));if($v==='')return date('Y-m-d H:i:s');
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v);
        if($dt===false||$dt->format('Y-m-d H:i:s')!==$v)throw new StaffConsumptionException('occurred_at_invalid','زمان مصرف معتبر نیست.',422);
        return $v;
    }
}
