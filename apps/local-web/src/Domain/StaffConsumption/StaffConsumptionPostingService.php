<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\StaffConsumption;

use PDO;
use Sokna\Local\Domain\Orders\OrderCatalogService;
use Sokna\Local\Domain\Orders\OrderCommitException;
use Sokna\Local\Domain\Orders\OrderCommitService;
use Throwable;

final class StaffConsumptionPostingService
{
    public const POSTING_VERSION='f1.3-v1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly StaffConsumptionFoundationService $foundation,
        private readonly StaffConsumptionRepository $repository,
        private readonly OrderCatalogService $catalog,
        private readonly StaffBenefitCalculationService $benefits,
        private readonly OrderCommitService $orders,
    ) {}

    public function postSelf(array $data,array $user): array
    {
        return $this->transactional(function() use($data,$user): array {
            $identity=$this->foundation->selfPostingIdentityTx($user);
            return $this->postTx($data,$identity['actor'],$identity['personnel']);
        });
    }

    public function postForPersonnel(int $personnelId,array $data,array $user): array
    {
        return $this->transactional(function() use($personnelId,$data,$user): array {
            $identity=$this->foundation->proxyPostingIdentityTx($personnelId,$user);
            return $this->postTx($data,$identity['actor'],$identity['personnel']);
        });
    }

    private function transactional(callable $callback): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$callback();
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function postTx(array $data,array $actor,array $personnel): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Staff Consumption posting requires an open transaction.');
        $actorId=(int)($actor['id']??0);$personnelId=(int)($personnel['id']??0);
        if($actorId<1||$personnelId<1)throw new StaffConsumptionException('posting_identity_invalid','هویت ثبت مصرف معتبر نیست.',500);

        $clientToken=$this->clientToken($data['request_token']??'');
        $duplicate=$this->repository->findByClientTokenForUpdate($clientToken);
        if($duplicate!==null){
            $this->assertDuplicateOwnership($duplicate,$actorId,$personnelId);
            return $this->duplicateResult($duplicate);
        }

        $occurredAt=$this->occurredAt($data['occurred_at']??null);
        try{
            $normalized=$this->catalog->normalizeRows($data['items']??null,false);
            $catalogLines=$this->catalog->snapshotRowsTx($normalized,'staff');
        }catch(OrderCommitException $e){
            throw new StaffConsumptionException($e->errorCode,$e->getMessage(),$e->httpStatus);
        }
        $quoteLines=array_map(static fn(array $line):array=>[
            'item_id'=>(int)$line['item_id'],'category_id'=>(int)$line['category_id'],'item_name'=>(string)$line['item_name'],
            'unit_price'=>(int)$line['unit_price'],'quantity'=>(int)$line['quantity'],
        ],$catalogLines);
        $runtimeOverride=is_array($data['runtime_override']??null)?$data['runtime_override']:null;
        $calculation=$this->benefits->quote($personnelId,$quoteLines,$occurredAt,$actor,$runtimeOverride);

        try{
            $order=$this->orders->commitTx([
                'source'=>'staff','order_context'=>'staff_consumption','actor_user_id'=>$actorId,
                'table_id'=>null,'session_id'=>null,'client_token'=>$clientToken,'status'=>'accounted',
                'customer_note'=>(string)($data['note']??''),'occurred_at'=>$occurredAt,'items'=>$normalized,
            ]);
        }catch(OrderCommitException $e){
            throw new StaffConsumptionException($e->errorCode,$e->getMessage(),$e->httpStatus);
        }
        if(!empty($order['duplicate'])){
            throw new StaffConsumptionException('idempotency_retry_required','ثبت هم‌زمان شناسایی شد؛ همان درخواست را دوباره ارسال کن.',409);
        }
        if((string)($order['order_context']??'')!=='staff_consumption'||(int)$order['total_amount']!==(int)$calculation['menu_value_amount']){
            throw new StaffConsumptionException('posting_snapshot_mismatch','Snapshot سفارش و محاسبه مزایا هم‌خوان نیست.',500);
        }

        $orderLines=is_array($order['lines']??null)?array_values($order['lines']):[];
        $calcLines=is_array($calculation['lines']??null)?array_values($calculation['lines']):[];
        if(count($orderLines)!==count($calcLines))throw new StaffConsumptionException('posting_line_mismatch','تعداد ردیف‌های سفارش و محاسبه مزایا هم‌خوان نیست.',500);
        foreach($orderLines as $i=>$ol)$this->assertLineMatch($ol,$calcLines[$i]??[],$i);

        $menuValue=(int)$calculation['menu_value_amount'];
        $benefit=(int)$calculation['benefit_amount'];
        $discount=(int)($calculation['discount_amount']??0);
        $payable=$menuValue-$benefit-$discount;
        if($payable<0)throw new StaffConsumptionException('posting_amount_invalid','مبلغ قابل پرداخت منفی شده است.',500);

        $policySnapshot=[
            'posting_version'=>self::POSTING_VERSION,
            'algorithm_version'=>(string)($calculation['algorithm_version']??''),
            'snapshot_sha256'=>(string)($calculation['snapshot_sha256']??''),
            'profile_resolution'=>(string)($calculation['profile_resolution']??''),
            'profile_id'=>$calculation['profile_id']??null,
            'policy_id'=>$calculation['policy_id']??null,
            'policy_key'=>$calculation['policy_key']??null,
            'runtime_override'=>$calculation['runtime_override']??null,
        ];
        $calculationJson=self::json($calculation);
        $policyJson=self::json($policySnapshot);
        $overrideId=$this->singleStoredOverrideId($calcLines);
        $publicCode='SC'.strtoupper(bin2hex(random_bytes(12)));
        $consumptionId=$this->repository->insertDocumentTx([
            'public_code'=>$publicCode,'client_token'=>$clientToken,'order_id'=>(int)$order['order_id'],
            'consumer_personnel_id'=>$personnelId,'recorded_by_user_id'=>$actorId,
            'benefit_policy_id'=>isset($calculation['policy_id'])&&$calculation['policy_id']!==null?(int)$calculation['policy_id']:null,
            'benefit_profile_id'=>isset($calculation['profile_id'])&&$calculation['profile_id']!==null?(int)$calculation['profile_id']:null,
            'benefit_override_id'=>$overrideId,
            'menu_value_amount'=>$menuValue,'benefit_amount'=>$benefit,'discount_amount'=>$discount,'payable_amount'=>$payable,
            'consumer_name_snapshot'=>(string)($personnel['display_name']??''),'policy_snapshot_json'=>$policyJson,
            'calculation_snapshot_json'=>$calculationJson,'business_date'=>(string)$order['business_date'],'business_shift_key'=>(string)$order['business_shift_key'],
        ]);

        foreach($orderLines as $i=>$orderLine){
            $calcLine=$calcLines[$i];
            $linePayable=(int)$calcLine['menu_line_amount']-(int)$calcLine['benefit_amount']-(int)($calcLine['discount_amount']??0);
            $this->repository->insertLineTx([
                'consumption_id'=>$consumptionId,'order_item_id'=>(int)$orderLine['order_item_id'],'item_id'=>(int)$orderLine['item_id'],
                'item_name_snapshot'=>(string)$orderLine['item_name'],'quantity'=>(int)$orderLine['quantity'],'menu_unit_price'=>(int)$orderLine['unit_price'],
                'menu_line_amount'=>(int)$calcLine['menu_line_amount'],'benefit_amount'=>(int)$calcLine['benefit_amount'],
                'discount_amount'=>(int)($calcLine['discount_amount']??0),'payable_amount'=>$linePayable,
                'calculation_snapshot_json'=>self::json([
                    'posting_version'=>self::POSTING_VERSION,'document_snapshot_sha256'=>(string)($calculation['snapshot_sha256']??''),
                    'line_index'=>$i,'line'=>$calcLine,
                ]),
            ]);
        }

        $this->audit('staff_consumption.posted','staff_consumption',$consumptionId,$actor,[
            'consumer_personnel_id'=>$personnelId,'consumer_name_snapshot'=>(string)($personnel['display_name']??''),
            'order_id'=>(int)$order['order_id'],'order_context'=>'staff_consumption','menu_value_amount'=>$menuValue,
            'benefit_amount'=>$benefit,'discount_amount'=>$discount,'payable_amount'=>$payable,'zero_payable'=>$payable===0,
            'calculation_snapshot_sha256'=>(string)($calculation['snapshot_sha256']??''),
        ]);

        return [
            'success'=>true,'duplicate'=>false,'consumption_id'=>$consumptionId,'public_code'=>$publicCode,
            'order_id'=>(int)$order['order_id'],'order_number'=>(int)$order['order_number'],'consumer_personnel_id'=>$personnelId,
            'recorded_by_user_id'=>$actorId,'menu_value_amount'=>$menuValue,'benefit_amount'=>$benefit,'discount_amount'=>$discount,
            'payable_amount'=>$payable,'zero_payable'=>$payable===0,'business_date'=>(string)$order['business_date'],
            'business_shift_key'=>(string)$order['business_shift_key'],'calculation_snapshot_sha256'=>(string)($calculation['snapshot_sha256']??''),
        ];
    }

    private function assertDuplicateOwnership(array $row,int $actorId,int $personnelId): void
    {
        if((int)$row['consumer_personnel_id']!==$personnelId||(int)$row['recorded_by_user_id']!==$actorId||(string)($row['order_context']??'')!=='staff_consumption')
            throw new StaffConsumptionException('idempotency_conflict','شناسه این ثبت قبلاً برای مصرف دیگری استفاده شده است.',409);
    }

    private function duplicateResult(array $row): array
    {
        return [
            'success'=>true,'duplicate'=>true,'consumption_id'=>(int)$row['id'],'public_code'=>(string)$row['public_code'],
            'order_id'=>(int)$row['order_id'],'order_number'=>(int)$row['business_order_number'],'consumer_personnel_id'=>(int)$row['consumer_personnel_id'],
            'recorded_by_user_id'=>(int)$row['recorded_by_user_id'],'menu_value_amount'=>(int)$row['menu_value_amount'],
            'benefit_amount'=>(int)$row['benefit_amount'],'discount_amount'=>(int)$row['discount_amount'],'payable_amount'=>(int)$row['payable_amount'],
            'zero_payable'=>(int)$row['payable_amount']===0,'business_date'=>(string)$row['business_date'],'business_shift_key'=>(string)$row['business_shift_key'],
        ];
    }

    private function assertLineMatch(array $orderLine,array $calcLine,int $index): void
    {
        if((int)($orderLine['item_id']??0)!==(int)($calcLine['item_id']??0)||
           (int)($orderLine['category_id']??0)!==(int)($calcLine['category_id']??0)||
           (int)($orderLine['unit_price']??-1)!==(int)($calcLine['unit_price']??-2)||
           (int)($orderLine['quantity']??0)!==(int)($calcLine['quantity']??0)||
           (int)($orderLine['line_total']??-1)!==(int)($calcLine['menu_line_amount']??-2))
            throw new StaffConsumptionException('posting_line_mismatch','ردیف شماره '.($index+1).' سفارش با Snapshot مزایا هم‌خوان نیست.',500);
    }

    private function singleStoredOverrideId(array $lines): ?int
    {
        $ids=[];
        foreach($lines as $line){
            $r=is_array($line['benefit_resolution']??null)?$line['benefit_resolution']:[];
            if((string)($r['source']??'')==='personnel_override'&&(int)($r['source_id']??0)>0)$ids[(int)$r['source_id']]=true;
        }
        $keys=array_keys($ids);
        return count($keys)===1?(int)$keys[0]:null;
    }

    private function clientToken(mixed $value): string
    {
        $raw=trim((string)$value);
        if(!preg_match('/^[A-Za-z0-9-]{16,74}$/',$raw))throw new StaffConsumptionException('invalid_request_token','شناسه امن ثبت مصرف معتبر نیست؛ صفحه را تازه کن.',422);
        return 'sc-'.$raw;
    }

    private function occurredAt(mixed $value): string
    {
        $raw=trim((string)($value??''));
        if($raw==='')return date('Y-m-d H:i:s');
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw);
        if($dt===false||$dt->format('Y-m-d H:i:s')!==$raw)throw new StaffConsumptionException('occurred_at_invalid','زمان ثبت مصرف معتبر نیست.',422);
        return $raw;
    }

    private function audit(string $action,string $entityType,int $entityId,array $actor,array $details): void
    {
        $this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')
            ->execute([(int)$actor['id'],(string)($actor['display_name']??''),$action,$entityType,(string)$entityId,self::json($details)]);
    }

    private static function json(array $value): string
    {
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
