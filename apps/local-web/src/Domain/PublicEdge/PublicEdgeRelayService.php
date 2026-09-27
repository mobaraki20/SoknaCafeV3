<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use PDO;
use Sokna\Local\Core\Observability;
use Sokna\Local\Relay\DeferredDispatchService;
use Sokna\Local\Relay\RealtimeDispatchService;
use Throwable;

/**
 * Local-owned pull worker for Public realtime/deferred queues.
 * Public stores transport state only; all business mutations remain canonical Local owners.
 */
final class PublicEdgeRelayService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicEdgeSyncClient $client,
        private readonly RealtimeDispatchService $realtime,
        private readonly DeferredDispatchService $deferred,
        private readonly Observability $observability,
    ) {}

    public function sync(int $realtimeLimit=25,int $deferredLimit=25,int $reconcileLimit=100): array
    {
        if(!$this->client->configured())return [
            'success'=>true,'configured'=>false,'realtime'=>0,'deferred'=>0,'reconciled'=>0,
        ];
        $realtimeLimit=max(1,min(100,$realtimeLimit));
        $deferredLimit=max(1,min(100,$deferredLimit));
        $reconcileLimit=max(1,min(250,$reconcileLimit));
        $realtime=$this->drainRealtime($realtimeLimit);
        $deferred=$this->drainDeferred($deferredLimit);
        $reconciled=$this->reconcileDeferred($reconcileLimit);
        $out=['success'=>true,'configured'=>true,'realtime'=>$realtime,'deferred'=>$deferred,'reconciled'=>$reconciled];
        $this->observability->logEvent('info','public.relay_sync_completed',$out+['origin'=>$this->client->safeOrigin()]);
        return $out;
    }

    private function drainRealtime(int $limit): int
    {
        $done=0;
        for($i=0;$i<$limit;$i++){
            $claim=$this->client->postRaw('/api/v1/local/realtime/claim',['lease_seconds'=>30]);
            if($this->emptyQueue($claim))break;
            $body=$this->requireOk($claim,'realtime_claim_failed');
            $envelope=is_array($body['request']??null)?$body['request']:[];
            $lease=trim((string)($body['lease_token']??''));
            $requestId=trim((string)($envelope['request_id']??''));
            if($requestId===''||$lease==='')throw new PublicEdgeSyncException('realtime_claim_invalid','پاسخ claim صف Realtime معتبر نیست.',502);
            $state='committed';$result=[];$error='';
            try{$result=$this->realtime->dispatch($envelope);}catch(Throwable $e){
                $state=property_exists($e,'errorCode')?'rejected':'unknown_review';
                $error=property_exists($e,'errorCode')?(string)$e->errorCode:'local_dispatch_exception';
                $this->observability->logEvent($state==='rejected'?'warning':'error','public.realtime_dispatch_failed',['request_id'=>$requestId,'kind'=>(string)($envelope['kind']??''),'state'=>$state,'error_code'=>$error]);
            }
            $ack=$this->client->postRaw('/api/v1/local/realtime/ack',[
                'request_id'=>$requestId,'lease_token'=>$lease,'state'=>$state,'result'=>$result,'error_code'=>$error,
            ]);
            $this->requireOk($ack,'realtime_ack_failed');$done++;
        }
        return $done;
    }

    private function drainDeferred(int $limit): int
    {
        $done=0;$installationId=$this->client->installationId();
        for($i=0;$i<$limit;$i++){
            $claim=$this->client->postRaw('/api/v1/local/deferred/claim',['lease_seconds'=>60]);
            if($this->emptyQueue($claim))break;
            $body=$this->requireOk($claim,'deferred_claim_failed');
            $envelope=is_array($body['request']??null)?$body['request']:[];
            $lease=trim((string)($body['lease_token']??''));
            $requestId=trim((string)($envelope['request_id']??''));
            if($requestId===''||$lease==='')throw new PublicEdgeSyncException('deferred_claim_invalid','پاسخ claim صف Deferred معتبر نیست.',502);
            try{$out=$this->deferred->dispatch($installationId,$envelope);}catch(Throwable $e){
                $out=['state'=>'rejected','result'=>[],'error_code'=>property_exists($e,'errorCode')?(string)$e->errorCode:'local_dispatch_exception'];
                $this->observability->logEvent('error','public.deferred_dispatch_failed',['request_id'=>$requestId,'kind'=>(string)($envelope['kind']??''),'error_code'=>$out['error_code']]);
            }
            $state=(string)($out['state']??'rejected');if(!in_array($state,['committed','needs_review','rejected'],true))$state='rejected';
            $ack=$this->client->postRaw('/api/v1/local/deferred/ack',[
                'request_id'=>$requestId,'lease_token'=>$lease,'state'=>$state,
                'result'=>is_array($out['result']??null)?$out['result']:[],
                'error_code'=>(string)($out['error_code']??''),
            ]);
            $this->requireOk($ack,'deferred_ack_failed');$done++;
        }
        return $done;
    }

    private function reconcileDeferred(int $limit): int
    {
        $stmt=$this->pdo->query(
            "SELECT id,request_id,state,result_json,error_code FROM deferred_work_receipts " .
            "WHERE public_reconcile_pending=1 AND state IN ('committed','rejected') ORDER BY id LIMIT {$limit}"
        );
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];$done=0;
        foreach($rows as $row){
            $result=json_decode((string)($row['result_json']??''),true);if(!is_array($result))$result=[];
            $response=$this->client->postRaw('/api/v1/local/deferred/reconcile',[
                'request_id'=>(string)$row['request_id'],'state'=>(string)$row['state'],'result'=>$result,'error_code'=>(string)($row['error_code']??''),
            ]);
            $this->requireOk($response,'deferred_reconcile_failed');
            $update=$this->pdo->prepare('UPDATE deferred_work_receipts SET public_reconcile_pending=0 WHERE id=? AND public_reconcile_pending=1');
            $update->execute([(int)$row['id']]);$done+=$update->rowCount()>0?1:0;
        }
        return $done;
    }

    private function emptyQueue(array $response): bool
    {
        return (int)($response['status']??0)===404 && (string)(($response['body']['error']??''))==='empty_queue';
    }

    private function requireOk(array $response,string $fallback): array
    {
        $status=(int)($response['status']??0);$body=is_array($response['body']??null)?$response['body']:[];
        if($status<200||$status>=300||($body['ok']??false)!==true)
            throw new PublicEdgeSyncException((string)($body['error']??$fallback),'عملیات Relay با Public Edge پذیرفته نشد.',$status?:502);
        return $body;
    }
}
