<?php
declare(strict_types=1);

namespace Sokna\Local\Runtime;

use DateTimeImmutable;
use PDO;
use Throwable;
use Sokna\Local\Core\Observability;

final class RuntimeTriggerService
{
    /** @param array<string,callable> $handlers */
    private array $traceTimes = [];
    /** @param array<string,callable> $handlers */
    public function __construct(private readonly PDO $pdo,private readonly array $handlers,private readonly ?Observability $tracer=null) {}

    public function accept(array $request): array
    {
        $this->traceTimes=['entry'=>microtime(true)];
        $normalized=$this->validate($request);
        $this->traceTimes['validated']=microtime(true);
        // ARCH-05: attempt deadline/budget is transport control metadata, not durable identity.
        $hash=hash('sha256',self::canonicalJson($this->identityPayload($normalized)));
        $handler=$this->handlers[$normalized['trigger_key']]??null;
        if(!is_callable($handler))throw new RuntimeTriggerException('unsupported_trigger','این trigger در Local ثبت نشده است.',404);

        $existing=null;$receiptId=0;$acceptedAt='';
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT * FROM runtime_trigger_receipts WHERE request_id=? LIMIT 1 FOR UPDATE');
            $stmt->execute([$normalized['request_id']]);$existing=$stmt->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)){
                if(!hash_equals((string)$existing['request_hash'],$hash))throw new RuntimeTriggerException('request_id_conflict','شناسه Runtime قبلاً برای درخواست دیگری استفاده شده است.',409);
                $receiptId=(int)$existing['id'];$acceptedAt=(string)$existing['accepted_at'];
                $this->pdo->commit();
                $this->traceTimes['receipt_committed']=microtime(true);
                $state=(string)($existing['state']??'accepted');
                if(in_array($state,['completed','failed'],true)){
                    $this->traceTimes['reply_ready']=microtime(true);
                    $this->logTriggerTiming($normalized,$state,true);
                    return $this->response(true,$normalized,$acceptedAt,$state,$this->decodeResult((string)($existing['result_json']??'')));
                }
                // ARCH-09: an accepted receipt is recoverable work, not proof the worker finished.
                // Retrying the same request_id after a process crash must resume the worker.
                return $this->executeAccepted($receiptId,$normalized,$handler,$acceptedAt,true);
            }

            $requestedAtDb=(new DateTimeImmutable($normalized['requested_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $initial=json_encode(['execution'=>['state'=>'accepted','attempt'=>0,'accepted_at'=>gmdate('c')]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->pdo->prepare("INSERT INTO runtime_trigger_receipts(runtime_instance_id,request_id,request_hash,trigger_key,correlation_id,state,result_json,requested_at) VALUES(?,?,?,?,?,'accepted',?,?)")
                ->execute([$normalized['runtime_instance_id'],$normalized['request_id'],$hash,$normalized['trigger_key'],$normalized['correlation_id'],$initial,$requestedAtDb]);
            $receiptId=(int)$this->pdo->lastInsertId();
            $acceptedAt=(string)$this->pdo->query("SELECT accepted_at FROM runtime_trigger_receipts WHERE id={$receiptId}")->fetchColumn();
            $this->pdo->commit();
            $this->traceTimes['receipt_committed']=microtime(true);
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();$this->traceTimes['receipt_error']=microtime(true);$this->logTriggerTiming($normalized,'receipt_error',false);throw $e;}

        return $this->executeAccepted($receiptId,$normalized,$handler,$acceptedAt,false);
    }

    /** @param callable $handler */
    private function executeAccepted(int $receiptId,array $normalized,callable $handler,string $acceptedAt,bool $deduplicated): array
    {
        $lockName=$this->lockName((string)$normalized['request_id']);
        if(!$this->acquireLock($lockName)){
            $this->traceTimes['lock_unavailable']=microtime(true);
            $this->logTriggerTiming($normalized,'running_other_owner',true);
            return $this->response(true,$normalized,$acceptedAt,'accepted',['execution'=>['state'=>'running','worker_completed'=>false,'concurrent_owner'=>true]]);
        }
        try{
            $this->traceTimes['lock_acquired']=microtime(true);
            $stmt=$this->pdo->prepare('SELECT state,result_json,accepted_at FROM runtime_trigger_receipts WHERE id=? LIMIT 1');$stmt->execute([$receiptId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))throw new RuntimeTriggerException('receipt_missing','رسید Runtime پیدا نشد.',503);
            $state=(string)($row['state']??'accepted');$acceptedAt=(string)($row['accepted_at']??$acceptedAt);$previous=$this->decodeResult((string)($row['result_json']??''));
            if(in_array($state,['completed','failed'],true)){
                $this->traceTimes['reply_ready']=microtime(true);$this->logTriggerTiming($normalized,$state,true);
                return $this->response(true,$normalized,$acceptedAt,$state,$previous);
            }

            $prevExec=is_array($previous['execution']??null)?$previous['execution']:[];$attempt=max(0,(int)($prevExec['attempt']??0))+1;
            $startedIso=gmdate('c');
            $running=['execution'=>['state'=>'running','attempt'=>$attempt,'started_at'=>$startedIso,'resumed'=>$deduplicated,'worker_completed'=>false]];
            $this->writeAcceptedEvidence($receiptId,$running);

            $started=microtime(true);$this->traceTimes['worker_started']=$started;$context=$this->deadlineContext($normalized);
            if(($context['remaining_ms']??0)<=0){
                $result=['error_code'=>'trigger_deadline_exceeded','message'=>'Runtime trigger deadline elapsed before Local worker execution.'];$state='failed';
            }else{
                try{
                    $result=$handler($context);
                    if(!is_array($result))$result=['ok'=>true];
                    // A successful HTTP/handler invocation is not a successful business relay.
                    // Contained WAN errors return success=false without throwing. Persist them as
                    // failed so Runtime doesn't report an unsuccessful relay as completed.
                    $state=($result['success']??true)===false?'failed':'completed';
                    if($state==='failed'&&trim((string)($result['error_code']??''))==='')
                        $result['error_code']='handler_reported_failure';
                }
                catch(Throwable $e){
                    $code=property_exists($e,'errorCode')&&trim((string)$e->errorCode)!==''?(string)$e->errorCode:'handler_failed';
                    $result=[
                        'error_code'=>$code,'exception_class'=>get_class($e),'exception_detail_code'=>self::exceptionDetailCode($e),
                        'exception_fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20),'message'=>'Runtime-triggered Local worker failed.',
                    ];$state='failed';
                }
            }
            $this->traceTimes['worker_finished']=microtime(true);
            $deadlineExceeded=$this->remainingMs((int)$context['deadline_epoch_ms'])<=0;
            $result['runtime']=$this->runtimeEvidence($context,$started,$deadlineExceeded);
            $result['execution']=[
                'state'=>$state,'attempt'=>$attempt,'started_at'=>$startedIso,'finished_at'=>gmdate('c'),'resumed'=>$deduplicated,
                'worker_completed'=>true,'worker_success'=>$state==='completed',
            ];
            $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $this->pdo->prepare('UPDATE runtime_trigger_receipts SET state=?,result_json=? WHERE id=? AND state=\'accepted\'')->execute([$state,$json,$receiptId]);
            $this->traceTimes['receipt_finalized']=microtime(true);$this->logTriggerTiming($normalized,$state,true);
            return $this->response($deduplicated,$normalized,$acceptedAt,$state,$result);
        }catch(Throwable $e){
            $this->traceTimes['uncaught_exception']=microtime(true);
            $this->logTriggerTiming($normalized,'uncaught_exception',false);
            throw $e;
        }finally{$this->releaseLock($lockName);}
    }

    /** Only slow/failed samples enter the bounded existing application journal. Never include payloads, auth, SQL or business identifiers. */
    private function logTriggerTiming(array $normalized,string $state,bool $completed): void
    {
        if($this->tracer===null)return;
        $times=$this->traceTimes;$started=(float)($times['entry']??0);if($started<=0)return;
        $now=microtime(true);$elapsed=(int)round(($now-$started)*1000);
        $key=(string)($normalized['trigger_key']??'');
        $threshold=$key==='public.relay_sync'?1000:2000;
        if($elapsed<$threshold&&$state==='completed'&&$completed)return;
        $ms=static fn(string $a,string $b):?int=>isset($times[$a],$times[$b])?max(0,(int)round(((float)$times[$b]-(float)$times[$a])*1000)):null;
        $deadline=(int)($normalized['deadline_epoch_ms']??0);
        $this->tracer->logEvent($state==='completed'?'info':'warning','runtime.trigger_pipeline_timing',[
            'trigger_key'=>$key,'request_hash'=>substr(hash('sha256',(string)($normalized['request_id']??'')),0,16),
            'state'=>$state,'finished'=>$completed,'elapsed_ms'=>$elapsed,
            'validate_ms'=>$ms('entry','validated'),'receipt_ms'=>$ms('validated','receipt_committed'),
            'lock_ms'=>$ms('receipt_committed','lock_acquired'),
            'worker_ms'=>$ms('worker_started','worker_finished'),
            'finish_receipt_ms'=>$ms('worker_finished','receipt_finalized'),
            'remaining_ms'=>$deadline>0?$deadline-(int)floor($now*1000):null,
        ]);
    }

    /** Housekeeping belongs to maintenance.health, never the receipt acceptance transaction. */
    public function recoverAbandonedAccepted(int $limit=12): array
    {
        if($this->pdo->inTransaction())throw new \LogicException('Runtime recovery requires its own transaction.');
        $limit=max(1,min(25,$limit));$recovered=0;
        // Maximum admitted trigger budget is 60s. A live owner still holds its request lock.
        $stmt=$this->pdo->prepare(
            "SELECT id,request_id FROM runtime_trigger_receipts WHERE state='accepted' " .
            "AND accepted_at<=DATE_SUB(CURRENT_TIMESTAMP(),INTERVAL 65 SECOND) ORDER BY accepted_at ASC,id ASC LIMIT {$limit}"
        );
        $stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as $row){
            $id=(int)($row['id']??0);$requestId=(string)($row['request_id']??'');if($id<=0||$requestId==='')continue;
            $lockName=$this->lockName($requestId);if(!$this->acquireLock($lockName))continue;
            try{
                $this->pdo->beginTransaction();
                $check=$this->pdo->prepare("SELECT state,result_json FROM runtime_trigger_receipts WHERE id=? LIMIT 1 FOR UPDATE");$check->execute([$id]);$current=$check->fetch(PDO::FETCH_ASSOC);
                if(!is_array($current)||(string)$current['state']!=='accepted'){$this->pdo->commit();continue;}
                $previous=$this->decodeResult((string)($current['result_json']??''));$exec=is_array($previous['execution']??null)?$previous['execution']:[];
                $result=[
                    'error_code'=>'worker_interrupted_after_acceptance','message'=>'Previous Runtime worker stopped after durable acceptance; the next scheduled trigger is the recovery path.',
                    'execution'=>[
                        'state'=>'failed','attempt'=>max(1,(int)($exec['attempt']??1)),'started_at'=>(string)($exec['started_at']??''),
                        'finished_at'=>gmdate('c'),'worker_completed'=>false,'worker_success'=>false,'recovery'=>'next_trigger',
                    ],
                ];
                $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $update=$this->pdo->prepare("UPDATE runtime_trigger_receipts SET state='failed',result_json=? WHERE id=? AND state='accepted'");$update->execute([$json,$id]);$recovered+=$update->rowCount();
                $this->pdo->commit();
            }catch(Throwable){if($this->pdo->inTransaction())$this->pdo->rollBack();}finally{$this->releaseLock($lockName);}
        }
        return ['examined'=>count($rows),'recovered'=>$recovered];
    }

    private function response(bool $deduplicated,array $normalized,string $acceptedAt,string $state,array $result=[]): array
    {
        if(trim($acceptedAt)==='')throw new \RuntimeException('runtime_receipt_timestamp_missing');
        $accepted=(new DateTimeImmutable($acceptedAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        $execution=is_array($result['execution']??null)?$result['execution']:[];$executionState=(string)($execution['state']??$state);
        return [
            'success'=>true,'accepted'=>true,'delivery_state'=>'accepted','deduplicated'=>$deduplicated,
            'trigger_key'=>(string)$normalized['trigger_key'],'accepted_at'=>$accepted,'correlation_id'=>(string)$normalized['correlation_id'],
            'execution_state'=>$executionState,'worker_completed'=>(bool)($execution['worker_completed']??in_array($state,['completed','failed'],true)),
            'worker_success'=>$state==='completed'&&($result['success']??true)!==false,
            'worker_error_code'=>$state==='failed'||($result['success']??true)===false
                ?(string)($result['error_code']??'worker_failed'):'',
        ];
    }

    private function writeAcceptedEvidence(int $receiptId,array $result): void
    {
        $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $this->pdo->prepare("UPDATE runtime_trigger_receipts SET result_json=? WHERE id=? AND state='accepted'")->execute([$json,$receiptId]);
    }

    private function acquireLock(string $name): bool
    {
        try{$stmt=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$name]);return (int)$stmt->fetchColumn()===1;}catch(Throwable){return false;}
    }
    private function releaseLock(string $name): void
    {
        try{$stmt=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$stmt->execute([$name]);}catch(Throwable){}
    }
    private function lockName(string $requestId): string{return 'sokna_rt_job_'.substr(hash('sha256',$requestId),0,48);}
    /** @return array<string,mixed> */
    private function decodeResult(string $json): array{$v=json_decode($json,true);return is_array($v)?$v:[];}

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

        $budget=(int)($request['budget_ms']??0);if($budget<=0)$budget=$this->defaultBudgetMs($key);
        if($budget<250||$budget>60000)throw new RuntimeTriggerException('invalid_request','budget_ms خارج از محدوده مجاز است.',400);
        $deadlineText=trim((string)($request['deadline_at']??''));$deadlineEpochMs=0;
        if($deadlineText!==''){
            if(!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/',$deadlineText))throw new RuntimeTriggerException('invalid_request','deadline_at باید offset زمانی صریح داشته باشد.',400);
            try{$deadline=new DateTimeImmutable($deadlineText);}catch(Throwable){throw new RuntimeTriggerException('invalid_request','deadline_at معتبر نیست.',400);}
            $deadlineEpochMs=((int)$deadline->format('U'))*1000+(int)substr($deadline->format('v'),0,3);
            $nowMs=(int)floor(microtime(true)*1000);if($deadlineEpochMs>$nowMs+120000)throw new RuntimeTriggerException('invalid_request','deadline_at بیش از حد دور است.',400);
        }
        if($deadlineEpochMs<=0)$deadlineEpochMs=(int)floor(microtime(true)*1000)+$budget;
        return ['request_id'=>$requestId,'runtime_instance_id'=>$instance,'trigger_key'=>$key,'requested_at'=>$canonicalAt,'correlation_id'=>$correlation,'budget_ms'=>$budget,'deadline_epoch_ms'=>$deadlineEpochMs,'deadline_at'=>self::formatEpochMs($deadlineEpochMs)];
    }

    /** @return array<string,mixed> */
    private function identityPayload(array $normalized): array
    {
        return ['request_id'=>$normalized['request_id'],'runtime_instance_id'=>$normalized['runtime_instance_id'],'trigger_key'=>$normalized['trigger_key'],'requested_at'=>$normalized['requested_at'],'correlation_id'=>$normalized['correlation_id']];
    }

    /** @return array<string,mixed> */
    private function deadlineContext(array $normalized): array
    {
        $deadline=(int)$normalized['deadline_epoch_ms'];
        return ['request_id'=>(string)$normalized['request_id'],'trigger_key'=>(string)$normalized['trigger_key'],'correlation_id'=>(string)$normalized['correlation_id'],'requested_at'=>(string)$normalized['requested_at'],'deadline_at'=>(string)$normalized['deadline_at'],'deadline_epoch_ms'=>$deadline,'budget_ms'=>(int)$normalized['budget_ms'],'remaining_ms'=>$this->remainingMs($deadline)];
    }

    /** @return array<string,mixed> */
    private function runtimeEvidence(array $context,float $started,bool $deadlineExceeded): array
    {
        return ['budget_ms'=>(int)($context['budget_ms']??0),'deadline_at'=>(string)($context['deadline_at']??''),'elapsed_ms'=>max(0,(int)round((microtime(true)-$started)*1000)),'deadline_exceeded'=>$deadlineExceeded];
    }

    private function defaultBudgetMs(string $key): int
    {
        return match($key){'public.relay_sync','public.live_projection_sync'=>4500,'public.projection_sync'=>15000,'inventory.order_events','notifications.outbox'=>8000,'maintenance.health'=>10000,default=>10000};
    }

    private function remainingMs(int $deadlineEpochMs): int{return max(0,$deadlineEpochMs-(int)floor(microtime(true)*1000));}
    private static function formatEpochMs(int $epochMs): string
    {
        $seconds=intdiv($epochMs,1000);$millis=$epochMs%1000;return gmdate('Y-m-d\TH:i:s',$seconds).'.'.str_pad((string)$millis,3,'0',STR_PAD_LEFT).'Z';
    }

    private static function exceptionDetailCode(Throwable $e): string
    {
        $message=strtolower($e->getMessage());
        if($e instanceof \Sokna\Local\Domain\PublicEdge\PublicEdgeSyncException&&trim($e->detailCode)!=='')return $e->detailCode;
        if($e instanceof \PDOException&&str_contains($message,'already an active transaction'))return 'pdo_nested_transaction';
        if($e instanceof \PDOException)return 'pdo_exception';
        if($e instanceof \TypeError)return 'type_error';
        if($e instanceof \JsonException)return 'json_exception';
        if($e instanceof \RuntimeException)return 'runtime_exception';
        return 'unclassified_exception';
    }
    private static function canonicalJson(array $value): string{ksort($value,SORT_STRING);return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
