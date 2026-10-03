<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Tax;

use DateTimeImmutable;
use PDO;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class TaxService
{
    public const POLICIES=['inherit_default','exempt','custom_rate'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
    ) {}

    public function createRateVersion(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $bps=array_key_exists('rate_bps',$data)
                ?max(0,min(10000,(int)$data['rate_bps']))
                :self::rateBpsNormalize($data['rate_percent']??'');
            $effective=$this->normalizeEffectiveAt((string)($data['effective_from']??''));
            $existing=(int)$this->pdo->query('SELECT COUNT(*) FROM tax_rate_versions FOR UPDATE')->fetchColumn();
            if($existing===0){
                if(strtotime($effective)>time()+60)
                    throw new TaxException('initial_rate_future','برای راه‌اندازی اولیه مالیات، نخستین نرخ باید از همین حالا مؤثر باشد.',409);
                if($this->hasLiveAccountRowsTx())
                    throw new TaxException('live_accounts','برای ثبت نخستین نرخ مالیات، ابتدا حساب‌های باز فعلی را تعیین تکلیف کنید.',409);
            }
            $stmt=$this->pdo->prepare('INSERT INTO tax_rate_versions(rate_bps,effective_from,created_by_user_id) VALUES(?,?,?)');
            $stmt->execute([$bps,$effective,(int)$actor['id']]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit('tax.rate_version_created','tax_rate_version',$id,(int)$actor['id'],[
                'rate_bps'=>$bps,'effective_from'=>$effective,
            ]);
            $this->pdo->commit();
            return ['id'=>$id,'rate_bps'=>$bps,'effective_from'=>$effective];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function createItemPolicyVersion(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $itemId=(int)($data['item_id']??0);
            $policy=(string)($data['policy']??'inherit_default');
            if($itemId<1||!in_array($policy,self::POLICIES,true))
                throw new TaxException('invalid_policy','سیاست مالیاتی کالا معتبر نیست.',422);
            $custom=null;
            if($policy==='custom_rate'){
                $custom=array_key_exists('custom_rate_bps',$data)
                    ?(int)$data['custom_rate_bps']
                    :self::rateBpsNormalize($data['custom_rate_percent']??'');
                if($custom<0||$custom>10000)throw new TaxException('invalid_rate','نرخ اختصاصی مالیات معتبر نیست.',422);
            }
            $effective=$this->normalizeEffectiveAt((string)($data['effective_from']??''));
            $check=$this->pdo->prepare('SELECT id,name FROM items WHERE id=? LIMIT 1 FOR UPDATE');
            $check->execute([$itemId]);$item=$check->fetch(PDO::FETCH_ASSOC);
            if(!is_array($item))throw new TaxException('item_not_found','کالای منو پیدا نشد.',404);
            $stmt=$this->pdo->prepare(
                'INSERT INTO tax_item_policy_versions(item_id,policy,custom_rate_bps,effective_from,created_by_user_id) VALUES(?,?,?,?,?)'
            );
            $stmt->execute([$itemId,$policy,$custom,$effective,(int)$actor['id']]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit('tax.item_policy_version_created','tax_item_policy_version',$id,(int)$actor['id'],[
                'item_id'=>$itemId,'item_name'=>(string)$item['name'],'policy'=>$policy,
                'custom_rate_bps'=>$custom,'effective_from'=>$effective,
            ]);
            $this->pdo->commit();
            return ['id'=>$id,'item_id'=>$itemId,'policy'=>$policy,'custom_rate_bps'=>$custom,'effective_from'=>$effective];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function setEnabled(bool $enabled,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $current=$this->settingBoolTx('module.tax.enabled',false);
            if($current===$enabled){
                $this->pdo->commit();
                return ['enabled'=>$enabled,'changed'=>false];
            }
            if($this->hasLiveAccountRowsTx())
                throw new TaxException('live_accounts','برای تغییر وضعیت مالیات، ابتدا حساب‌های باز فعلی را تعیین تکلیف کنید.',409);
            $this->pdo->prepare(
                "INSERT INTO settings(setting_key,setting_value) VALUES('module.tax.enabled',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            )->execute([$enabled?'1':'0']);
            $this->audit($enabled?'tax.enabled':'tax.disabled','setting',0,(int)$actor['id'],[]);
            $this->pdo->commit();
            return ['enabled'=>$enabled,'changed'=>true];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** Snapshot Tax ownership for a newly created order line. Caller owns transaction. */
    public function orderLineSnapshotTx(int $itemId,?string $at=null): array
    {
        $this->requireTx();
        if($itemId<1||!$this->settingBoolTx('module.tax.enabled',false))
            return self::disabledSnapshot();

        $at=$this->normalizeLookupAt($at);
        $default=$this->defaultRateRowTx($at);
        if($default===null)return self::disabledSnapshot();

        $policyRow=$this->currentPolicyRowTx($itemId,$at);
        $policy=(string)($policyRow['policy']??'inherit_default');
        if(!in_array($policy,self::POLICIES,true))$policy='inherit_default';
        $rate=match($policy){
            'exempt'=>0,
            'custom_rate'=>max(0,min(10000,(int)($policyRow['custom_rate_bps']??0))),
            default=>max(0,min(10000,(int)$default['rate_bps'])),
        };
        return [
            'policy'=>$policy,
            'rate_bps'=>$rate,
            'rate_version_id'=>(int)$default['id'],
            'policy_version_id'=>$policyRow?(int)$policyRow['id']:null,
        ];
    }

    public function profileMapTx(array $itemIds,?string $at=null): array
    {
        $this->requireTx();
        $out=[];
        foreach(array_values(array_unique(array_filter(array_map('intval',$itemIds),static fn(int $id):bool=>$id>0))) as $id)
            $out[$id]=$this->orderLineSnapshotTx($id,$at);
        return $out;
    }

    public function runtimeReadyTx(): bool
    {
        $this->requireTx();
        if(!$this->settingBoolTx('module.tax.enabled',false))return false;
        return $this->defaultRateRowTx(date('Y-m-d H:i:s'))!==null;
    }

    public static function rateBpsNormalize(mixed $value): int
    {
        $raw=trim((string)$value);
        if($raw==='')return 0;
        $normalized=str_replace(['٪','%','٫',','],['','','.','.'],$raw);
        if(!is_numeric($normalized))throw new TaxException('invalid_rate','نرخ مالیات معتبر نیست.',422);
        $percent=(float)$normalized;
        if($percent<0||$percent>100)throw new TaxException('invalid_rate','نرخ مالیات باید بین صفر تا صد درصد باشد.',422);
        return (int)round($percent*100,0,PHP_ROUND_HALF_UP);
    }

    /** Integer half-up rounding without multiplying the full monetary amount by the rate. */
    public static function roundAmount(int $taxableAmount,int $rateBps): int
    {
        if($taxableAmount<=0||$rateBps<=0)return 0;
        $rateBps=min(10000,$rateBps);
        $whole=intdiv($taxableAmount,10000)*$rateBps;
        $remainder=$taxableAmount%10000;
        return $whole+intdiv(($remainder*$rateBps)+5000,10000);
    }

    public static function proportionalTarget(int $totalValue,int $basisTotal,int $cumulativeBasis,bool $final=false): int
    {
        if($totalValue<=0||$basisTotal<=0||$cumulativeBasis<=0)return 0;
        if($final||$cumulativeBasis>=$basisTotal)return $totalValue;
        $whole=intdiv($totalValue,$basisTotal)*$cumulativeBasis;
        $rem=$totalValue%$basisTotal;
        $fraction=intdiv(($rem*$cumulativeBasis)+intdiv($basisTotal,2),$basisTotal);
        return max(0,min($totalValue,$whole+$fraction));
    }

    public static function allocateInvoiceDiscount(array $lines,int $discount): array
    {
        $grossTotal=array_sum(array_map(static fn(array $l):int=>max(0,(int)($l['gross_amount']??0)),$lines));
        $discount=max(0,min($discount,$grossTotal));
        $runningGross=0;$allocated=0;$count=count($lines);
        foreach($lines as $index=>&$line){
            $gross=max(0,(int)($line['gross_amount']??0));$runningGross+=$gross;
            $lineDiscount=$index===$count-1
                ?$discount-$allocated
                :max(0,self::proportionalTarget($discount,$grossTotal,$runningGross,false)-$allocated);
            $lineDiscount=min($gross,$lineDiscount);
            $line['invoice_discount_amount']=$lineDiscount;
            $line['invoice_net_amount']=$gross-$lineDiscount;
            $allocated+=$lineDiscount;
        }
        unset($line);
        return $lines;
    }

    public static function calculateInvoiceLines(array $lines,int $discount): array
    {
        usort($lines,static fn(array $a,array $b):int=>((int)($a['order_item_id']??$a['id']??0))<=>((int)($b['order_item_id']??$b['id']??0)));
        foreach($lines as &$line){
            $qty=max(0,(int)($line['quantity']??0));
            $unit=max(0,(int)($line['unit_price']??$line['unit_price_snapshot']??0));
            $line['gross_amount']=isset($line['gross_amount'])?max(0,(int)$line['gross_amount']):$unit*$qty;
            $line['tax_policy_snapshot']=(string)($line['tax_policy_snapshot']??'disabled');
            $line['tax_rate_bps_snapshot']=max(0,min(10000,(int)($line['tax_rate_bps_snapshot']??0)));
        }
        unset($line);
        $lines=self::allocateInvoiceDiscount($lines,$discount);
        $subtotal=0;$allocatedDiscount=0;$taxable=0;$tax=0;$net=0;
        foreach($lines as &$line){
            $gross=(int)$line['gross_amount'];
            $lineDiscount=(int)$line['invoice_discount_amount'];
            $lineNet=$gross-$lineDiscount;
            $policy=(string)$line['tax_policy_snapshot'];
            $rate=(int)$line['tax_rate_bps_snapshot'];
            $lineTaxable=($policy!=='disabled'&&$policy!=='exempt')?$lineNet:0;
            $lineTax=self::roundAmount($lineTaxable,$rate);
            $line['invoice_taxable_amount']=$lineTaxable;
            $line['invoice_tax_amount']=$lineTax;
            $line['invoice_final_amount']=$lineNet+$lineTax;
            $subtotal+=$gross;$allocatedDiscount+=$lineDiscount;$net+=$lineNet;$taxable+=$lineTaxable;$tax+=$lineTax;
        }
        unset($line);
        return [
            'lines'=>$lines,'subtotal'=>$subtotal,'discount'=>$allocatedDiscount,'net'=>$net,
            'taxable'=>$taxable,'tax'=>$tax,'total'=>$net+$tax,
        ];
    }

    private function defaultRateRowTx(string $at): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT * FROM tax_rate_versions WHERE effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$at]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function currentPolicyRowTx(int $itemId,string $at): ?array
    {
        $stmt=$this->pdo->prepare(
            'SELECT * FROM tax_item_policy_versions WHERE item_id=? AND effective_from<=? ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$itemId,$at]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function hasLiveAccountRowsTx(): bool
    {
        $this->requireTx();
        $sql="SELECT 1
              FROM table_sessions s
              JOIN orders o ON o.session_id=s.id
              JOIN order_items oi ON oi.order_id=o.id AND oi.quantity>0
              WHERE s.status IN('active','pending')
                AND o.status IN('pending_approval','new','accounted')
              LIMIT 1 FOR UPDATE";
        return (bool)$this->pdo->query($sql)->fetchColumn();
    }

    private function settingBoolTx(string $key,bool $default): bool
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$key]);$value=$stmt->fetchColumn();
        if($value===false||$value===null)return $default;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function assertAdmin(array $user): array
    {
        $id=(int)($user['id']??0);
        if($id<1)throw new TaxException('forbidden','حساب کاربری معتبر نیست.',403);
        $fresh=$this->identity->findActiveById($id);
        if($fresh===null||(string)($fresh['role']??'')!=='admin')
            throw new TaxException('forbidden','فقط مدیر فعال می‌تواند تنظیمات مالیات را تغییر دهد.',403);
        return $fresh;
    }

    private function normalizeEffectiveAt(string $value): string
    {
        $value=trim($value);
        if($value==='')return date('Y-m-d H:i:s');
        try{$dt=new DateTimeImmutable($value);}catch(Throwable){throw new TaxException('invalid_effective_at','زمان شروع مالیات معتبر نیست.',422);}
        return $dt->format('Y-m-d H:i:s');
    }

    private function normalizeLookupAt(?string $value): string
    {
        $value=trim((string)$value);
        if($value==='')return date('Y-m-d H:i:s');
        try{$dt=new DateTimeImmutable($value);}catch(Throwable){throw new TaxException('invalid_effective_at','زمان مالیات معتبر نیست.',422);}
        return $dt->format('Y-m-d H:i:s');
    }

    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void
    {
        $display=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
        $display->execute([$actorId]);$name=$display->fetchColumn();
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)'
        )->execute([$actorId,$name!==false?$name:null,$action,$entityType,(string)$entityId,$json?:'{}']);
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Tax canonical operation requires an open transaction.');
    }

    private static function disabledSnapshot(): array
    {
        return ['policy'=>'disabled','rate_bps'=>0,'rate_version_id'=>null,'policy_version_id'=>null];
    }
}
