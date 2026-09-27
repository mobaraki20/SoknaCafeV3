<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use PDO;
use Sokna\Local\Core\IdentityRepository;

final class SubscriberService
{
    public function __construct(private readonly PDO $pdo,private readonly IdentityRepository $identity) {}

    public static function normalizeMobile(string $mobile): string
    {
        $digits=preg_replace('/\D+/','',$mobile)??'';
        if(str_starts_with($digits,'0098'))$digits='0'.substr($digits,4);
        elseif(str_starts_with($digits,'98'))$digits='0'.substr($digits,2);
        return substr($digits,0,20);
    }

    public function create(array $data,array $user): array
    {
        $actor=$this->assertAdmin($user);
        $name=self::clip(trim((string)($data['name']??'')),160);
        $mobile=self::clip(trim((string)($data['mobile']??'')),30);
        $normalized=self::normalizeMobile($mobile);
        if($name===''||strlen($normalized)<7)throw new IntegrationException('invalid_subscriber','نام یا شماره مشتری معتبر نیست.',422);
        $active=(bool)($data['active']??true);
        $stmt=$this->pdo->prepare('INSERT INTO subscribers(name,mobile,mobile_normalized,active,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$name,$mobile,$normalized,$active?1:0,(int)$actor['id'],(int)$actor['id']]);
        return ['id'=>(int)$this->pdo->lastInsertId(),'name'=>$name,'mobile'=>$mobile,'active'=>$active,'balance'=>0];
    }

    public function update(int $subscriberId,array $data,array $user): array
    {
        $actor=$this->assertAdmin($user);$name=self::clip(trim((string)($data['name']??'')),160);$mobile=self::clip(trim((string)($data['mobile']??'')),30);
        $normalized=self::normalizeMobile($mobile);$active=(bool)($data['active']??true);
        if($subscriberId<1||$name===''||strlen($normalized)<7)throw new IntegrationException('invalid_subscriber','نام یا شماره مشتری معتبر نیست.',422);
        $stmt=$this->pdo->prepare('UPDATE subscribers SET name=?,mobile=?,mobile_normalized=?,active=?,updated_by_user_id=? WHERE id=?');
        $stmt->execute([$name,$mobile,$normalized,$active?1:0,(int)$actor['id'],$subscriberId]);
        if($stmt->rowCount()===0){$check=$this->pdo->prepare('SELECT id FROM subscribers WHERE id=?');$check->execute([$subscriberId]);if($check->fetchColumn()===false)throw new IntegrationException('subscriber_not_found','مشتری پیدا نشد.',404);}
        return ['id'=>$subscriberId,'name'=>$name,'mobile'=>$mobile,'active'=>$active];
    }

    public function search(string $query,int $limit=20): array
    {
        $query=trim($query);$limit=max(1,min(50,$limit));if($query==='')return [];
        $mobile=self::normalizeMobile($query);$hasDigits=$mobile!==''&&preg_match('/\d/',$query)===1;
        $select="SELECT s.*,COALESCE((SELECT l.balance_after FROM subscriber_ledger l WHERE l.subscriber_id=s.id ORDER BY l.id DESC LIMIT 1),0) balance FROM subscribers s WHERE s.active=1";
        if($hasDigits){
            if(strlen($mobile)<3)return [];
            $stmt=$this->pdo->prepare($select." AND s.mobile_normalized LIKE ? ORDER BY (s.mobile_normalized=?) DESC,(s.mobile_normalized LIKE ?) DESC,s.name,s.id LIMIT {$limit}");
            $stmt->execute(['%'.$mobile.'%',$mobile,$mobile.'%']);
        }else{
            if(self::textLength($query)<2)return [];
            $stmt=$this->pdo->prepare($select." AND s.name LIKE ? ORDER BY (s.name=?) DESC,(s.name LIKE ?) DESC,s.name,s.id LIMIT {$limit}");
            $stmt->execute(['%'.$query.'%',$query,$query.'%']);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertLedgerTx(
        int $subscriberId,string $entryType,int $amountDelta,int $actorUserId,int $financialPeriodId,
        ?int $sessionId=null,?int $relatedEntryId=null,?string $reference=null,?string $reason=null,
        ?array $invoiceSnapshot=null,?string $idempotencyKey=null
    ): array {
        $this->requireTx();
        if(!in_array($entryType,['invoice','payment','invoice_reversal','payment_reversal'],true))
            throw new IntegrationException('invalid_entry_type','نوع سند مشتری معتبر نیست.',422);
        $subscriberStmt=$this->pdo->prepare('SELECT id,name,active FROM subscribers WHERE id=? FOR UPDATE');
        $subscriberStmt->execute([$subscriberId]);$subscriber=$subscriberStmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($subscriber))throw new IntegrationException('subscriber_not_found','مشتری پیدا نشد.',404);
        $idempotencyKey=self::clip(trim((string)$idempotencyKey),190)?:null;
        if($idempotencyKey!==null){
            $dup=$this->pdo->prepare('SELECT * FROM subscriber_ledger WHERE idempotency_key=? LIMIT 1 FOR UPDATE');
            $dup->execute([$idempotencyKey]);$existing=$dup->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)){
                if((int)$existing['subscriber_id']!==$subscriberId||(string)$existing['entry_type']!==$entryType||(int)$existing['amount_delta']!==$amountDelta)
                    throw new IntegrationStateConflict('idempotency_conflict','شناسه درخواست مشتری قبلاً برای عملیات دیگری استفاده شده است.',409);
                return $this->ledgerResult($existing,$subscriber,true);
            }
        }
        $current=$this->balanceTx($subscriberId);$next=$current+$amountDelta;
        if($next<0)throw new IntegrationException('balance_underflow','مبلغ پرداخت از مانده حساب بیشتر است.',409);
        if($entryType==='invoice'&&(int)$subscriber['active']!==1)throw new IntegrationException('subscriber_inactive','این مشتری غیرفعال است.',409);
        $json=$invoiceSnapshot===null?null:json_encode($invoiceSnapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $stmt=$this->pdo->prepare('INSERT INTO subscriber_ledger(subscriber_id,financial_period_id,entry_type,amount_delta,balance_after,table_session_id,related_entry_id,reference,reason,invoice_snapshot_json,actor_user_id,idempotency_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$subscriberId,$financialPeriodId,$entryType,$amountDelta,$next,$sessionId,$relatedEntryId,self::clip(trim((string)$reference),120)?:null,self::clip(trim((string)$reason),300)?:null,$json,$actorUserId>0?$actorUserId:null,$idempotencyKey]);
        $row=['id'=>(int)$this->pdo->lastInsertId(),'subscriber_id'=>$subscriberId,'entry_type'=>$entryType,'amount_delta'=>$amountDelta,'balance_after'=>$next];
        return $this->ledgerResult($row,$subscriber,false);
    }

    public function reverseEntryTx(int $entryId,string $reason,int $actorUserId,string $idempotencyKey): array
    {
        $this->requireTx();$reason=self::clip(trim($reason),300);
        if($reason==='')throw new IntegrationException('reason_required','دلیل برگشت را وارد کن.',422);
        $stmt=$this->pdo->prepare('SELECT * FROM subscriber_ledger WHERE id=? FOR UPDATE');$stmt->execute([$entryId]);$entry=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($entry)||!in_array((string)$entry['entry_type'],['invoice','payment'],true))throw new IntegrationException('not_reversible','این سند مشتری قابل برگشت نیست.',409);
        $check=$this->pdo->prepare('SELECT * FROM subscriber_ledger WHERE related_entry_id=? LIMIT 1 FOR UPDATE');$check->execute([$entryId]);$existing=$check->fetch(PDO::FETCH_ASSOC);
        if(is_array($existing))return $this->ledgerResult($existing,['id'=>(int)$entry['subscriber_id'],'name'=>'','active'=>1],true);
        return $this->insertLedgerTx((int)$entry['subscriber_id'],(string)$entry['entry_type']==='invoice'?'invoice_reversal':'payment_reversal',-(int)$entry['amount_delta'],$actorUserId,(int)$entry['financial_period_id'],null,$entryId,(string)($entry['reference']??''),$reason,null,$idempotencyKey);
    }

    public function balanceTx(int $subscriberId): int
    {
        $stmt=$this->pdo->prepare('SELECT balance_after FROM subscriber_ledger WHERE subscriber_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$stmt->execute([$subscriberId]);$v=$stmt->fetchColumn();return $v===false?0:(int)$v;
    }
    private function ledgerResult(array $row,array $subscriber,bool $idempotent): array
    {
        return ['id'=>(int)$row['id'],'subscriber_id'=>(int)$row['subscriber_id'],'subscriber'=>$subscriber,'balance_before'=>(int)$row['balance_after']-(int)$row['amount_delta'],'balance_after'=>(int)$row['balance_after'],'amount_delta'=>(int)$row['amount_delta'],'entry_type'=>(string)$row['entry_type'],'idempotent'=>$idempotent];
    }
    private function assertAdmin(array $user): array
    {
        $fresh=$this->identity->findActiveById((int)($user['id']??0));if($fresh===null||(string)($fresh['role']??'')!=='admin')throw new IntegrationException('forbidden','فقط مدیر فعال می‌تواند مشترک بسازد.',403);return $fresh;
    }
    private function requireTx(): void{if(!$this->pdo->inTransaction())throw new \LogicException('Subscriber ledger mutation requires an open transaction.');}
    private static function clip(string $v,int $n): string{return function_exists('mb_substr')?mb_substr($v,0,$n,'UTF-8'):substr($v,0,$n);}
    private static function textLength(string $v): int{return function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v);}
}
