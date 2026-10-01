<?php
declare(strict_types=1);

namespace Sokna\Local\Runtime;

use DateTimeImmutable;
use PDO;
use Throwable;

final class RuntimeTriggerService
{
    /** @param array<string,callable():array> $handlers */
    public function __construct(private readonly PDO $pdo,private readonly array $handlers) {}

    public function accept(array $request): array
    {
        $normalized=$this->validate($request);$hash=hash('sha256',self::canonicalJson($normalized));
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT * FROM runtime_trigger_receipts WHERE request_id=? LIMIT 1 FOR UPDATE');$stmt->execute([$normalized['request_id']]);$existing=$stmt->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)){
                if(!hash_equals((string)$existing['request_hash'],$hash))throw new RuntimeTriggerException('request_id_conflict','شناسه Runtime قبلاً برای درخواست دیگری استفاده شده است.',409);
                $this->pdo->commit();
                return $this->response(true,(string)$existing['trigger_key'],(string)$existing['accepted_at'],(string)$existing['correlation_id']);
            }
            $handler=$this->handlers[$normalized['trigger_key']]??null;if(!is_callable($handler))throw new RuntimeTriggerException('unsupported_trigger','این trigger در Local ثبت نشده است.',404);
            $requestedAtDb=(new DateTimeImmutable($normalized['requested_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->pdo->prepare("INSERT INTO runtime_trigger_receipts(runtime_instance_id,request_id,request_hash,trigger_key,correlation_id,state,result_json,requested_at) VALUES(?,?,?,?,?,'accepted','{}',?)")
                ->execute([$normalized['runtime_instance_id'],$normalized['request_id'],$hash,$normalized['trigger_key'],$normalized['correlation_id'],$requestedAtDb]);
            $receiptId=(int)$this->pdo->lastInsertId();
            try{$result=$handler();if(!is_array($result))$result=['ok'=>true];$state='completed';}
            catch(Throwable $e){$result=['error_code'=>'handler_failed','message'=>'Runtime-triggered Local worker failed.'];$state='failed';}
            $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->pdo->prepare('UPDATE runtime_trigger_receipts SET state=?,result_json=? WHERE id=?')->execute([$state,$json,$receiptId]);
            $acceptedAt=(string)$this->pdo->query("SELECT accepted_at FROM runtime_trigger_receipts WHERE id={$receiptId}")->fetchColumn();
            $this->pdo->commit();
            return $this->response(false,$normalized['trigger_key'],$acceptedAt,$normalized['correlation_id']);
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function response(bool $deduplicated,string $triggerKey,string $acceptedAt,string $correlationId): array
    {
        if(trim($acceptedAt)==='')throw new \RuntimeException('runtime_receipt_timestamp_missing');
        $accepted=(new DateTimeImmutable($acceptedAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        return ['success'=>true,'accepted'=>true,'deduplicated'=>$deduplicated,'trigger_key'=>$triggerKey,'accepted_at'=>$accepted,'correlation_id'=>$correlationId];
    }

    private function validate(array $request): array
    {
        foreach(['command','executable','args','arguments','powershell','shell','sql','table','query','business_payload','entity','entity_id','payload'] as $forbidden)
            if(array_key_exists($forbidden,$request))throw new RuntimeTriggerException('invalid_request','Runtime حق ارسال command/payload اجرایی یا business payload ندارد.',400);
        $requestId=trim((string)($request['request_id']??''));$instance=trim((string)($request['runtime_instance_id']??''));$key=trim((string)($request['trigger_key']??''));$correlation=trim((string)($request['correlation_id']??''));$at=trim((string)($request['requested_at']??''));
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/',$requestId))throw new RuntimeTriggerException('invalid_request','request_id معتبر نیست.',400);
        if(strlen($instance)<8||strlen($instance)>128)throw new RuntimeTriggerException('invalid_request','runtime_instance_id معتبر نیست.',400);
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/',$key))throw new RuntimeTriggerException('invalid_request','trigger_key معتبر نیست.',400);
        if(strlen($correlation)<8||strlen($correlation)>128)throw new RuntimeTriggerException('invalid_request','correlation_id معتبر نیست.',400);
        if(!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/',$at))throw new RuntimeTriggerException('invalid_request','requested_at باید offset زمانی صریح داشته باشد.',400);
        try{$dt=new DateTimeImmutable($at);}catch(Throwable){throw new RuntimeTriggerException('invalid_request','requested_at معتبر نیست.',400);}
        if($dt->getTimestamp()>time()+300||$dt->getTimestamp()<time()-86400)throw new RuntimeTriggerException('invalid_request','requested_at خارج از پنجره مجاز است.',400);
        $canonicalAt=$dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        return ['request_id'=>$requestId,'runtime_instance_id'=>$instance,'trigger_key'=>$key,'requested_at'=>$canonicalAt,'correlation_id'=>$correlation];
    }
    private static function canonicalJson(array $value): string{ksort($value,SORT_STRING);return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
