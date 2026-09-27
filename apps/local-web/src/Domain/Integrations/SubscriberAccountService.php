<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Domain\Finance\FinancialPeriodIdentityService;
use Sokna\Local\Domain\Orders\BusinessClock;
use Throwable;

final class SubscriberAccountService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly FinancialPeriodIdentityService $periods,
        private readonly BusinessClock $clock,
        private readonly SubscriberService $subscribers,
    ) {}

    public function payment(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertActive($user);
            $subscriberId=(int)($data['subscriber_id']??0);$amount=(int)($data['amount']??0);
            if($subscriberId<1||$amount<1)throw new IntegrationException('invalid_payment','مشتری و مبلغ پرداخت معتبر نیست.',422);
            $requestId=$this->requestId((string)($data['request_id']??''));
            $businessDate=(string)$this->clock->assignment()['business_date'];
            $period=$this->periods->forDateTx($businessDate,(int)$actor['id']);
            $entry=$this->subscribers->insertLedgerTx(
                $subscriberId,'payment',-$amount,(int)$actor['id'],(int)$period['id'],null,null,
                trim((string)($data['reference']??''))?:null,null,null,'subscriber:payment:'.$requestId
            );
            $this->pdo->commit();return $entry;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function reversePayment(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);$entryId=(int)($data['entry_id']??0);$reason=trim((string)($data['reason']??''));
            if($entryId<1||$reason==='')throw new IntegrationException('invalid_reversal','پرداخت و دلیل برگشت را مشخص کن.',422);
            $requestId=$this->requestId((string)($data['request_id']??''));
            $entry=$this->subscribers->reverseEntryTx($entryId,$reason,(int)$actor['id'],'subscriber:payment-reversal:'.$requestId);
            $this->pdo->commit();return $entry;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function assertActive(array $user): array
    {
        $fresh=$this->identity->findActiveById((int)($user['id']??0));
        if($fresh===null)throw new IntegrationException('forbidden','حساب کاربری فعال نیست.',403);return $fresh;
    }
    private function assertAdmin(array $user): array
    {
        $fresh=$this->assertActive($user);if((string)($fresh['role']??'')!=='admin')throw new IntegrationException('forbidden','فقط مدیر فعال می‌تواند پرداخت را برگشت بزند.',403);return $fresh;
    }
    private function requestId(string $value): string
    {
        $value=trim($value);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/',$value))throw new IntegrationException('invalid_request_id','شناسه درخواست معتبر نیست.',422);return $value;
    }
}
