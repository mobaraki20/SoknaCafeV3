<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use PDO;
use Sokna\Local\Core\Observability;
use Sokna\Local\Relay\DeferredDispatchService;
use Sokna\Local\Relay\RealtimeDispatchService;
use Sokna\Local\Domain\LanStaff\LanStaffRealtimeReceiptService;
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
        private readonly LanStaffRealtimeReceiptService $realtimeReceipts,
        private readonly PublicEdgePublisherService $publisher,
    ) {}

    public function sync(int $realtimeLimit=25,int $deferredLimit=25,int $reconcileLimit=100,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        if(!$this->client->configured())return [
            'success'=>true,'configured'=>false,'realtime'=>0,'deferred'=>0,'reconciled'=>0,
        ];
        // Fallback/legacy callers may omit a Runtime deadline. In that case derive one from
        // the transport timeout so every downstream branch receives a defined bounded budget.
        if($deadlineEpochMs===null)$deadlineEpochMs=(int)floor(microtime(true)*1000)+max(1000,min(30000,$transportTimeoutSeconds*1000));
        $this->requireDeadlineBudget($deadlineEpochMs,'relay_sync_start');
        $realtimeLimit=max(1,min(100,$realtimeLimit));
        $deferredLimit=max(1,min(100,$deferredLimit));
        $reconcileLimit=max(1,min(250,$reconcileLimit));
        $realtime=$this->drainRealtime($realtimeLimit,$transportTimeoutSeconds,$deadlineEpochMs);
        $deferred=$this->drainDeferred($deferredLimit,$transportTimeoutSeconds,$deadlineEpochMs);
        $reconciled=$this->deadlineAvailable($deadlineEpochMs,100)?$this->reconcileDeferred($reconcileLimit,$transportTimeoutSeconds,$deadlineEpochMs):0;
        $out=['success'=>true,'configured'=>true,'realtime'=>$realtime,'deferred'=>$deferred,'reconciled'=>$reconciled];
        // The one-second Runtime fast lane can be idle for hours. Trigger receipts already
        // prove healthy empty cycles; only business activity needs a product log entry.
        if($realtime>0||$deferred>0||$reconciled>0){
            $this->observability->logEvent('info','public.relay_sync_completed',$out+['origin'=>$this->client->safeOrigin()]);
        }
        return $out;
    }

    /**
     * Fast-lane owner: only the realtime queue belongs on the one-second Runtime cadence.
     * Deferred work and projection delivery are intentionally excluded so an idle fast lane
     * performs a single bounded Public round-trip instead of three/four sequential WAN calls.
     */
    public function syncRealtimeOnly(int $realtimeLimit=25,int $transportTimeoutSeconds=2,?int $deadlineEpochMs=null): array
    {
        if(!$this->client->configured())return ['success'=>true,'configured'=>false,'realtime'=>0];
        if($deadlineEpochMs===null)$deadlineEpochMs=(int)floor(microtime(true)*1000)+max(1000,min(10000,$transportTimeoutSeconds*1000));
        $this->requireDeadlineBudget($deadlineEpochMs,'relay_realtime_start');
        $realtimeLimit=max(1,min(100,$realtimeLimit));
        $realtime=$this->drainRealtime($realtimeLimit,$transportTimeoutSeconds,$deadlineEpochMs);
        $out=['success'=>true,'configured'=>true,'realtime'=>$realtime];
        if($realtime>0)$this->observability->logEvent('info','public.relay_realtime_completed',$out+['origin'=>$this->client->safeOrigin()]);
        return $out;
    }

    /**
     * Transport v2 realtime exchange. One WAN call carries heartbeat + durable ACK retries
     * and receives a bounded command batch. Local business dispatch remains canonical and
     * idempotent; terminal ACKs are persisted before the next network attempt.
     */
    public function syncRealtimeExchange(int $maxRequests=8,int $transportTimeoutSeconds=3,?int $deadlineEpochMs=null): array
    {
        if(!$this->client->configured())return ['success'=>true,'configured'=>false,'protocol'=>2,'processed'=>0,'acked'=>0];
        $maxRequests=max(1,min(10,$maxRequests));$transportTimeoutSeconds=max(1,min(10,$transportTimeoutSeconds));
        $this->requireDeadlineBudget($deadlineEpochMs,'realtime_exchange_start');
        $acks=$this->pendingRealtimeAcks(25);
        // Staggered-upgrade negotiation: never push the larger fast-path projection payload to
        // an older Public. The new Public advertises support on the normal exchange first; the
        // following tick may then piggy-back a dirty critical model.
        // The 1-second command lane must carry ACKs, heartbeat, and commands ONLY.
        // Even one large model can saturate the short WAN upload window and allow
        // Public to claim a command whose response cannot get back to Local.
        // Projection lives on public.projection_sync; command-driven flush below
        // keeps read-your-writes models only for genuinely executed commands.
        $projectionBatch=['items'=>[],'pending_events'=>0,'model_keys'=>[]];
        $payload=[
            'protocol'=>2,'lease_seconds'=>30,'max_requests'=>$maxRequests,
            'heartbeat'=>$this->publisher->transportHeartbeatPayloadIfDue(15),'acks'=>$acks,
            'ack_reconcile'=>$this->pendingRealtimeAckReconciliations(25),
            'projection_models'=>is_array($projectionBatch['items']??null)?$projectionBatch['items']:[],
            'deadline_epoch_ms'=>$deadlineEpochMs??0,
            'budget_ms'=>$this->exchangeBudgetMs($deadlineEpochMs,$transportTimeoutSeconds),
        ];
        $response=$this->client->postRaw('/api/v1/local/realtime/exchange',$payload,$transportTimeoutSeconds,$deadlineEpochMs);
        $status=(int)($response['status']??0);$body=is_array($response['body']??null)?$response['body']:[];
        if($status===404&&in_array((string)($body['error']??''),['route_not_found','unknown_route'],true)){
            $this->requireDeadlineBudget($deadlineEpochMs,'realtime_legacy_fallback');
            $acked=$this->flushLegacyRealtimeAcks($transportTimeoutSeconds,$deadlineEpochMs);
            $processed=$this->drainRealtime($maxRequests,$transportTimeoutSeconds,$deadlineEpochMs);
            return ['success'=>true,'configured'=>true,'protocol'=>1,'fallback'=>true,'processed'=>$processed,'acked'=>$acked];
        }
        $body=$this->requireOk($response,'realtime_exchange_failed');
        $this->recordRealtimeExchangeCapabilities(is_array($body['capabilities']??null)?$body['capabilities']:[]);
        $this->publisher->markHeartbeatExchangeSuccess($payload['heartbeat'],is_array($body)?$body:[]);
        $projectionFast=$this->publisher->applyRealtimeProjectionResults($projectionBatch,is_array($body['projection_results']??null)?$body['projection_results']:[]);
        $acked=$this->applyExchangeAckResults(is_array($body['ack_results']??null)?$body['ack_results']:[]);
        $reconciledAcks=$this->applyAckReconciliationResults(is_array($body['ack_reconcile_results']??null)?$body['ack_reconcile_results']:[]);
        $processed=0;$unexpected=0;$processedKinds=[];
        foreach((array)($body['requests']??[]) as $claimed){
            if(!$this->deadlineAvailable($deadlineEpochMs,75))break;
            if(!is_array($claimed))continue;
            $envelope=is_array($claimed['request']??null)?$claimed['request']:[];$lease=trim((string)($claimed['lease_token']??''));
            $requestId=trim((string)($envelope['request_id']??''));$kind=(string)($envelope['kind']??'');
            if($requestId===''||$lease==='')continue;
            $traceId=LanStaffRealtimeReceiptService::traceId($this->client->installationId(),$requestId);
            $atomicMutation=$this->realtime->isMutation($envelope);
            try{
                if($atomicMutation){
                    $receipt=$this->realtimeReceipts->dispatchAtomic(
                        $this->client->installationId(),
                        $envelope,
                        fn()=>$this->dispatchRealtimeMutationWithVisibility($envelope),
                        function(array $terminal) use($requestId,$lease): void {
                            $this->queueRealtimeAck(
                                $requestId,$lease,(string)($terminal['state']??'rejected'),
                                is_array($terminal['result']??null)?$terminal['result']:[],
                                (string)($terminal['error_code']??'')
                            );
                        }
                    );
                }else{
                    $receipt=$this->realtimeReceipts->dispatch($this->client->installationId(),$envelope,fn()=>$this->realtime->dispatch($envelope));
                }
                $state=(string)($receipt['state']??'unknown_review');$result=is_array($receipt['result']??null)?$receipt['result']:[];$error=(string)($receipt['error_code']??'');
            }catch(Throwable $e){
                $unexpected++;
                $this->observability->logEvent('error','public.realtime_dispatch_exception',[
                    'request_id'=>$requestId,'trace_id'=>$traceId,'kind'=>$kind,'error_code'=>$atomicMutation?'atomic_dispatch_unconfirmed':'local_dispatch_exception','exception_class'=>get_class($e),
                    'exception_fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20),
                    'atomic_mutation'=>$atomicMutation,
                ]);
                if($atomicMutation){
                    // ARCH-04: never invent a terminal ACK outside the atomic DB unit. A commit
                    // acknowledgement can be lost while the transaction actually committed; the
                    // same request_id must be reclaimed/replayed to discover the canonical receipt.
                    break;
                }
                $state='unknown_review';$result=[];$error='local_dispatch_exception';
            }
            if(!in_array($state,['committed','rejected','unknown_review'],true)){$state='unknown_review';$error=$error!==''?$error:'local_dispatch_state_invalid';}
            if(!$atomicMutation)$this->queueRealtimeAck($requestId,$lease,$state,$result,$error);
            $processed++;if($atomicMutation)$processedKinds[]=$kind;
            $this->observability->logEvent($state==='committed'?'info':'warning','public.realtime_dispatched',[
                'request_id'=>$requestId,'trace_id'=>$traceId,'kind'=>$kind,'state'=>$state,'error_code'=>$error,'transport_protocol'=>2,
            ]);
            if($unexpected>=3)break;
        }
        $commitFlush=['attempted'=>false,'success'=>true,'acked'=>0,'projection_models'=>0,'delivered_events'=>0];
        if($processed>0||$this->supportsRealtimeProjectionFastPath()){
            if(!$this->deadlineAvailable($deadlineEpochMs,150)){$commitFlush=['attempted'=>false,'success'=>true,'deferred'=>true,'reason'=>'deadline_budget_low','acked'=>0,'projection_models'=>0,'delivered_events'=>0];}
            else try{$commitFlush=$this->flushCommittedRealtimeState($processedKinds,$transportTimeoutSeconds,$deadlineEpochMs,$processed===0);}
            catch(PublicEdgeSyncException $e){$commitFlush=['attempted'=>true,'success'=>false,'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode];}
            catch(Throwable $e){$commitFlush=['attempted'=>true,'success'=>false,'error_code'=>'realtime_commit_projection_exception','exception_class'=>get_class($e),'exception_fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20)];$this->observability->logEvent('warning','public.realtime_commit_projection_exception',$commitFlush);}
        }
        $out=['success'=>true,'configured'=>true,'protocol'=>2,'processed'=>$processed,'acked'=>$acked,'ack_reconciled'=>$reconciledAcks,'deadline_remaining_ms'=>$this->deadlineRemainingMs($deadlineEpochMs),'pending_acks'=>$this->pendingRealtimeAckCount(),'reconcile_acks'=>$this->pendingRealtimeAckReconciliationCount(),'projection_fast'=>$projectionFast,'commit_flush'=>$commitFlush];
        if($processed>0||$acked>0||$reconciledAcks>0||($commitFlush['attempted']??false)||((int)($projectionFast['delivered_events']??0)>0))$this->observability->logEvent('info','public.relay_exchange_completed',$out+['origin'=>$this->client->safeOrigin()]);
        return $out;
    }

    /**
     * Command-driven hot path. ACK and read-model state are sent immediately after the Local
     * transaction commits instead of waiting for the next scheduler tick / 30-second lane.
     * Idle fast-lane ticks also drain critical dirty models left by an earlier short budget
     * or timeout. A clean outbox adds no model build or WAN request. Both outboxes stay durable.
     *
     * @param list<string> $processedKinds
     */
    private function flushCommittedRealtimeState(array $processedKinds,int $transportTimeoutSeconds,?int $deadlineEpochMs=null,bool $retryDirty=false): array
    {
        // Publish the durable terminal result before model building or upload. A large
        // projection must not consume the ACK budget or delay read-only quote/review.
        $acks=$this->pendingRealtimeAcks(25);$reconcile=$this->pendingRealtimeAckReconciliations(25);
        $acked=0;$reconciledAcks=0;$ackAttempted=$acks!==[]||$reconcile!==[];
        if($ackAttempted){
            $payload=['protocol'=>2,'lease_seconds'=>30,'max_requests'=>0,'heartbeat'=>[],
                'acks'=>$acks,'ack_reconcile'=>$reconcile,'projection_models'=>[],
                'deadline_epoch_ms'=>$deadlineEpochMs??0,
                'budget_ms'=>$this->exchangeBudgetMs($deadlineEpochMs,max(1,min(3,$transportTimeoutSeconds)))];
            $response=$this->client->postRaw('/api/v1/local/realtime/exchange',$payload,max(1,min(3,$transportTimeoutSeconds)),$deadlineEpochMs);
            $body=$this->requireOk($response,'realtime_commit_ack_failed');
            $this->recordRealtimeExchangeCapabilities(is_array($body['capabilities']??null)?$body['capabilities']:[]);
            $acked=$this->applyExchangeAckResults(is_array($body['ack_results']??null)?$body['ack_results']:[]);
            $reconciledAcks=$this->applyAckReconciliationResults(is_array($body['ack_reconcile_results']??null)?$body['ack_reconcile_results']:[]);
        }
        $out=['attempted'=>$ackAttempted,'success'=>true,'acked'=>$acked,'ack_reconciled'=>$reconciledAcks,
            'projection_models'=>0,'delivered_events'=>0,'model_keys'=>[],
            'pending_acks'=>$this->pendingRealtimeAckCount(),'reconcile_acks'=>$this->pendingRealtimeAckReconciliationCount()];
        if(($processedKinds===[]&&!$retryDirty)||!$this->supportsRealtimeProjectionFastPath())return $out;
        if($this->publisher->liveProjectionWorkerFresh())return $out+['deferred'=>true,'reason'=>'independent_live_projection'];
        $retry=$this->projectionRetryState();
        if($retry===null)return $out+['deferred'=>true,'reason'=>'projection_retry_state_unavailable'];
        if($retry['next_attempt_epoch']>time())return $out+['deferred'=>true,'reason'=>'projection_retry_backoff','retry_after_seconds'=>$retry['next_attempt_epoch']-time()];
        // A sub-second upload budget repeatedly timed out on the home WAN. Leave the
        // durable invalidation for the next fast tick instead of spending it on a build.
        if(!$this->deadlineAvailable($deadlineEpochMs,2500))return $out+['deferred'=>true,'reason'=>'projection_budget_low'];
        $allowed=$processedKinds!==[]?$this->projectionKeysForKinds($processedKinds):['operations','guest_operational','table_draft_context','preparation'];
        $buildStarted=microtime(true);
        try{$projectionBatch=$this->publisher->prepareRealtimeProjectionBatch(160,1,$allowed);}
        catch(Throwable){
            $out['success']=false;$out['error_code']='projection_build_failed';
            $out['retry_after_seconds']=$this->recordProjectionRetry(false,(int)$retry['failures']);
            return $out;
        }
        $out['projection_build_ms']=(int)round((microtime(true)-$buildStarted)*1000);
        $models=is_array($projectionBatch['items']??null)?$projectionBatch['items']:[];
        if($models===[])return $out;
        if(!$this->deadlineAvailable($deadlineEpochMs,1800))return $out+['deferred'=>true,'reason'=>'projection_build_exhausted_budget'];
        $out['attempted']=true;$out['projection_models']=count($models);
        $out['model_keys']=(array)($projectionBatch['model_keys']??[]);
        $out['projection_sequence']=(int)($models[0]['up_to_sequence']??0);
        $out['projection_source_version']=(string)($models[0]['model']['source_version']??'');
        $out['projection_bytes']=strlen(json_encode($models[0],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        try{
            // Use the same dedicated per-model endpoint as the periodic projection lane.
            // ACKs have already been sent; no command lease is involved in this upload.
            $response=$this->client->postRaw('/api/v1/local/read-model-v2',$models[0],4,$deadlineEpochMs);
            $body=$this->requireOk($response,'realtime_commit_projection_failed');
            $projection=$this->publisher->applyRealtimeProjectionResults($projectionBatch,[$body]);
            $out['delivered_events']=(int)($projection['delivered_events']??0);
            $applied=(array)($projection['applied']??[]);
            $confirmed=count($applied)===count($models)&&(array)($projection['failed']??[])===[];
            $out['success']=$confirmed;
            if(!$confirmed)$out['error_code']=(string)(array_values((array)($projection['failed']??[]))[0]??'projection_ack_unconfirmed');
            $out['retry_after_seconds']=$this->recordProjectionRetry($confirmed,(int)$retry['failures']);
        }catch(PublicEdgeSyncException $e){
            // ACK counts remain truthful; only projection remains pending for retry.
            $out['success']=false;$out['error_code']=$e->errorCode;$out['detail_code']=$e->detailCode;
            $out['retry_after_seconds']=$this->recordProjectionRetry(false,(int)$retry['failures']);
        }
        return $out;
    }

    /** Projection-only pacing survives PHP requests. Never gates command pickup or terminal ACKs. */
    private function projectionRetryState(): ?array
    {
        try{
            $q=$this->pdo->query("SELECT detail_json FROM public_sync_state WHERE channel='realtime_projection_retry' LIMIT 1");
            $raw=$q->fetchColumn();$d=$raw?json_decode((string)$raw,true):[];
            if(!is_array($d))$d=[];
            $next=(int)($d['next_attempt_epoch']??0);$now=time();
            // Invalid or stale future state must not create an unbounded circuit.
            if($next<0||$next>$now+60)$next=0;
            return ['failures'=>max(0,min(5,(int)($d['failures']??0))),'next_attempt_epoch'=>$next];
        }catch(Throwable){return null;}
    }

    private function recordProjectionRetry(bool $success,int $previousFailures): int
    {
        $failures=$success?0:min(5,max(0,$previousFailures)+1);
        $delay=$success?2:min(30,2**$failures);
        try{
            $detail=json_encode(['failures'=>$failures,'next_attempt_epoch'=>time()+$delay],JSON_THROW_ON_ERROR);
            $q=$this->pdo->prepare("INSERT INTO public_sync_state(channel,source_version,status,detail_json,last_attempt_at) VALUES('realtime_projection_retry','transport-v2','ok',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='ok',detail_json=VALUES(detail_json),last_attempt_at=UTC_TIMESTAMP()");
            $q->execute([$detail]);
        }catch(Throwable){}
        return $delay;
    }

    /** @return array<string,mixed> */
    private function dispatchRealtimeMutationWithVisibility(array $envelope): array
    {
        $result=$this->realtime->dispatch($envelope);
        $kind=trim((string)($envelope['kind']??''));
        $requirements=$this->publisher->captureVisibilityRequirements($this->projectionKeysForKinds([$kind]));
        if($requirements!==[]){
            $result['affected_model_requirements']=$requirements;
            // Staff Hall/Cashier already owns operations.read. Never expose table cards to Guest/preparation-only actors.
            if(preg_match('/^user:[1-9][0-9]*$/D',(string)($envelope['actor_projection_id']??''))===1&&
                (str_starts_with($kind,'table_draft.')||str_starts_with($kind,'settlement.')||in_array($kind,['hall.table_move','hall.order_item_adjust'],true))){
                $patch=$this->publisher->committedTableStates((array)($envelope['payload']??[]),$result,$requirements);
                if($patch!==null)$result['committed_table_states']=$patch;
            }
            $trace=is_array($result['_transport_trace']??null)?$result['_transport_trace']:[];
            $result['_transport_trace']=$trace+['visibility_requirements_captured_at'=>gmdate('c')];
        }
        return $result;
    }

    /** @param list<string> $kinds @return list<string> */
    private function projectionKeysForKinds(array $kinds): array
    {
        $keys=[];$add=static function(array &$into,array $more):void{foreach($more as $key)if(!in_array($key,$into,true))$into[]=$key;};
        foreach($kinds as $kind){$kind=(string)$kind;
            if(str_starts_with($kind,'operations.')){$add($keys,['operations_workspace','inventory','inventory_cost','deferred_context']);continue;}
            if(str_starts_with($kind,'guest_order.')||str_starts_with($kind,'order.')){$add($keys,['guest_operational','operations']);continue;}
            if(str_starts_with($kind,'table_draft.')){$add($keys,['operations','table_draft_context']);continue;}
            if(str_starts_with($kind,'waiter_call.')||$kind==='hall.waiter_status'){$add($keys,['guest_operational','operations']);continue;}
            if(str_starts_with($kind,'settlement.')){$add($keys,['operations','guest_operational']);continue;}
            if($kind==='preparation.mutate'){$add($keys,['preparation','operations']);continue;}
            if(str_starts_with($kind,'notification.push.')){$add($keys,['notifications']);continue;}
            $add($keys,['operations','guest_operational']);
        }
        return $keys!==[]?$keys:['operations','guest_operational'];
    }

    /**
     * Slow-lane owner for deferred/reconcile work. This stays off the one-second relay lane.
     */
    public function syncDeferredOnly(int $deferredLimit=25,int $reconcileLimit=100,int $transportTimeoutSeconds=2,?int $deadlineEpochMs=null): array
    {
        if(!$this->client->configured())return ['success'=>true,'configured'=>false,'deferred'=>0,'reconciled'=>0];
        $deferredLimit=max(1,min(100,$deferredLimit));
        $reconcileLimit=max(1,min(250,$reconcileLimit));
        $deferred=$this->drainDeferred($deferredLimit,$transportTimeoutSeconds,$deadlineEpochMs);
        $reconciled=$this->deadlineAvailable($deadlineEpochMs,100)?$this->reconcileDeferred($reconcileLimit,$transportTimeoutSeconds,$deadlineEpochMs):0;
        $out=['success'=>true,'configured'=>true,'deferred'=>$deferred,'reconciled'=>$reconciled];
        if($deferred>0||$reconciled>0)$this->observability->logEvent('info','public.relay_deferred_completed',$out+['origin'=>$this->client->safeOrigin()]);
        return $out;
    }


    /**
     * Safe end-to-end proof of Public -> Local realtime delivery.
     * No business entity is touched: Public queues diagnostics.ping, Local claims,
     * dispatches a no-op receipt, ACKs it, then verifies the Public terminal result.
     */
    public function diagnosticProbe(int $transportTimeoutSeconds=3): array
    {
        $transportTimeoutSeconds=max(1,min(10,$transportTimeoutSeconds));
        $correlationId=$this->observability->correlationId();
        $requestId='diag-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6));
        $started=microtime(true);$hop='public_enqueue';$processed=0;
        if(!$this->client->configured())return [
            'success'=>false,'request_id'=>$requestId,'correlation_id'=>$correlationId,
            'hop'=>'configuration','error_code'=>'public_not_configured','processed'=>0,
        ];
        $this->observability->logEvent('info','public.relay_probe_started',[
            'request_id'=>$requestId,'hop'=>$hop,'origin'=>$this->client->safeOrigin(),
        ],$correlationId);
        try{
            $start=$this->client->postRaw('/api/v1/local/diagnostics/relay-probe/start',[
                'request_id'=>$requestId,'correlation_id'=>$correlationId,
            ],$transportTimeoutSeconds);
            $this->requireOk($start,'relay_probe_enqueue_failed');
            $this->observability->logEvent('info','public.relay_probe_enqueued',['request_id'=>$requestId],$correlationId);

            $resultBody=[];$hop='realtime_exchange_dispatch_ack';$transportProtocol=0;
            for($round=0;$round<5;$round++){
                $exchange=$this->syncRealtimeExchange(8,$transportTimeoutSeconds);
                $transportProtocol=max($transportProtocol,(int)($exchange['protocol']??0));
                $processed+=max(0,(int)($exchange['processed']??0));
                $hop='public_result';
                $result=$this->client->postRaw('/api/v1/local/diagnostics/relay-probe/result',['request_id'=>$requestId],$transportTimeoutSeconds);
                $body=$this->requireOk($result,'relay_probe_result_failed');$resultBody=$body;
                if(($body['terminal']??false)===true)break;
                $hop='realtime_claim_dispatch_ack';
                usleep(50000);
            }
            $state=(string)($resultBody['state']??'');
            $error=(string)($resultBody['error_code']??'');
            $payload=is_array($resultBody['result']??null)?$resultBody['result']:[];
            $identityOk=hash_equals($requestId,trim((string)($payload['probe_id']??'')));
            $success=$state==='committed'&&($payload['success']??false)===true&&$identityOk;
            if(!$success){
                $code=$error!==''?$error:($state!==''?'relay_probe_'.$state:'relay_probe_not_terminal');
                $this->observability->logEvent('error','public.relay_probe_failed',[
                    'request_id'=>$requestId,'hop'=>$this->probeHop($code),'error_code'=>$code,'state'=>$state,'processed'=>$processed,
                ],$correlationId);
                return [
                    'success'=>false,'request_id'=>$requestId,'correlation_id'=>$correlationId,
                    'hop'=>$this->probeHop($code),'error_code'=>$code,'state'=>$state,'processed'=>$processed,
                    'latency_ms'=>(int)round((microtime(true)-$started)*1000),
                ];
            }
            $out=[
                'success'=>true,'request_id'=>$requestId,'correlation_id'=>$correlationId,'hop'=>'complete',
                'error_code'=>'','state'=>$state,'processed'=>$processed,
                'latency_ms'=>(int)round((microtime(true)-$started)*1000),'transport_protocol'=>$transportProtocol,
                'public_created_at'=>(string)($resultBody['created_at']??''),
                'public_claimed_at'=>(string)($resultBody['claimed_at']??''),
                'public_completed_at'=>(string)($resultBody['updated_at']??''),
                'local_received_at'=>(string)($payload['local_received_at']??''),
            ];
            $this->observability->logEvent('info','public.relay_probe_completed',$out,$correlationId);
            return $out;
        }catch(PublicEdgeSyncException $e){
            $code=$e->errorCode!==''?$e->errorCode:'relay_probe_failed';
            $this->observability->logEvent('error','public.relay_probe_failed',[
                'request_id'=>$requestId,'hop'=>$this->probeHop($code,$hop),'error_code'=>$code,'http_status'=>$e->httpStatus,'processed'=>$processed,
            ],$correlationId);
            return [
                'success'=>false,'request_id'=>$requestId,'correlation_id'=>$correlationId,
                'hop'=>$this->probeHop($code,$hop),'error_code'=>$code,'http_status'=>$e->httpStatus,'processed'=>$processed,
                'latency_ms'=>(int)round((microtime(true)-$started)*1000),
            ];
        }catch(Throwable $e){
            $this->observability->logEvent('error','public.relay_probe_failed',[
                'request_id'=>$requestId,'hop'=>$hop,'error_code'=>'relay_probe_exception','exception'=>get_class($e),'processed'=>$processed,
            ],$correlationId);
            return [
                'success'=>false,'request_id'=>$requestId,'correlation_id'=>$correlationId,
                'hop'=>$hop,'error_code'=>'relay_probe_exception','processed'=>$processed,
                'latency_ms'=>(int)round((microtime(true)-$started)*1000),
            ];
        }
    }

    private function probeHop(string $code,string $fallback='relay'): string
    {
        if(str_contains($code,'claim'))return 'public_claim';
        if(str_contains($code,'ack')||str_contains($code,'lease'))return 'public_ack';
        if(str_contains($code,'dispatch')||str_contains($code,'unknown_review'))return 'local_dispatch';
        if(str_contains($code,'result'))return 'public_result';
        if(str_contains($code,'enqueue')||str_contains($code,'probe_not_found'))return 'public_enqueue';
        if(in_array($code,['public_unreachable','public_not_configured','public_path_invalid'],true))return 'public_transport';
        return $fallback;
    }

    private function drainRealtime(int $limit,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): int
    {
        $done=0;$consecutiveUnexpected=0;
        for($i=0;$i<$limit;$i++){
            if(!$this->deadlineAvailable($deadlineEpochMs,100))break;
            // Claim transport failures are systemic: stop immediately instead of spinning.
            $claim=$this->client->postRaw('/api/v1/local/realtime/claim',['lease_seconds'=>30,'wait_ms'=>$done===0?min(850,max(0,$this->deadlineRemainingMs($deadlineEpochMs)-150)):0],$transportTimeoutSeconds,$deadlineEpochMs);
            if($this->emptyQueue($claim))break;
            $body=$this->requireOk($claim,'realtime_claim_failed');
            $envelope=is_array($body['request']??null)?$body['request']:[];
            $lease=trim((string)($body['lease_token']??''));
            $requestId=trim((string)($envelope['request_id']??''));$kind=(string)($envelope['kind']??'');
            $claimWaitedMs=max(0,(int)($body['waited_ms']??0));
            if($requestId===''||$lease==='')throw new PublicEdgeSyncException('realtime_claim_invalid','پاسخ claim صف Realtime معتبر نیست.',502);
            $this->observability->logEvent('info','public.realtime_claimed',['request_id'=>$requestId,'kind'=>$kind,'claim_waited_ms'=>$claimWaitedMs]);

            $unexpected=null;$atomicMutation=$this->realtime->isMutation($envelope);
            try{
                if($atomicMutation){
                    $receipt=$this->realtimeReceipts->dispatchAtomic(
                        $this->client->installationId(),
                        $envelope,
                        fn()=>$this->dispatchRealtimeMutationWithVisibility($envelope),
                        function(array $terminal) use($requestId,$lease): void {
                            $this->queueRealtimeAck(
                                $requestId,$lease,(string)($terminal['state']??'rejected'),
                                is_array($terminal['result']??null)?$terminal['result']:[],
                                (string)($terminal['error_code']??'')
                            );
                        }
                    );
                }else{
                    $receipt=$this->realtimeReceipts->dispatch($this->client->installationId(),$envelope,fn()=>$this->realtime->dispatch($envelope));
                }
                $state=(string)($receipt['state']??'unknown_review');$result=is_array($receipt['result']??null)?$receipt['result']:[];$error=(string)($receipt['error_code']??'');
            }catch(Throwable $e){
                $unexpected=$e;
                $this->observability->logEvent('error','public.realtime_dispatch_exception',[
                    'request_id'=>$requestId,'kind'=>$kind,'error_code'=>$atomicMutation?'atomic_dispatch_unconfirmed':'local_dispatch_exception',
                    'exception_class'=>get_class($e),'exception_fingerprint'=>substr(hash('sha256',get_class($e).'|'.$e->getMessage()),0,20),
                    'atomic_mutation'=>$atomicMutation,
                ]);
                if($atomicMutation)throw new PublicEdgeSyncException('atomic_dispatch_unconfirmed','نتیجه تراکنش محلی قطعی نشده است؛ همان درخواست با همان شناسه بازیابی می‌شود.',503);
                $state='unknown_review';$result=[];$error='local_dispatch_exception';
            }
            if(!in_array($state,['committed','rejected','unknown_review'],true)){$state='unknown_review';$error=$error!==''?$error:'local_dispatch_state_invalid';}
            $this->observability->logEvent($state==='committed'?'info':'warning','public.realtime_dispatched',['request_id'=>$requestId,'kind'=>$kind,'state'=>$state,'error_code'=>$error,'idempotent'=>(bool)($receipt['idempotent']??false)]);
            if($state!=='committed')$this->observability->logEvent($state==='rejected'?'warning':'error','public.realtime_dispatch_failed',['request_id'=>$requestId,'kind'=>$kind,'state'=>$state,'error_code'=>$error,'idempotent'=>(bool)($receipt['idempotent']??false)]);

            // ACK transport failure is systemic because the request lease cannot be safely
            // terminalized; stop rather than claiming more work whose outcome cannot be returned.
            $ack=$this->client->postRaw('/api/v1/local/realtime/ack',[
                'request_id'=>$requestId,'lease_token'=>$lease,'state'=>$state,'result'=>$result,'error_code'=>$error,
            ],$transportTimeoutSeconds,$deadlineEpochMs);
            $this->requireOk($ack,'realtime_ack_failed');
            if($atomicMutation)$this->clearRealtimeAck($requestId);
            $this->observability->logEvent('info','public.realtime_acked',['request_id'=>$requestId,'kind'=>$kind,'state'=>$state]);
            $done++;

            if($unexpected instanceof Throwable){
                $consecutiveUnexpected++;
                // One malformed/poison request must not block later work, but a systemic Local
                // failure must not mass-terminalize the whole queue as unknown_review.
                if($consecutiveUnexpected>=3)throw new PublicEdgeSyncException('realtime_dispatch_systemic_failure','چند درخواست پیاپی در dispatch محلی با خطای غیرمنتظره متوقف شدند.',500);
            }else{$consecutiveUnexpected=0;}
        }
        return $done;
    }

    private function drainDeferred(int $limit,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): int
    {
        $done=0;$installationId=$this->client->installationId();
        for($i=0;$i<$limit;$i++){
            if(!$this->deadlineAvailable($deadlineEpochMs,100))break;
            $claim=$this->client->postRaw('/api/v1/local/deferred/claim',['lease_seconds'=>60],$transportTimeoutSeconds,$deadlineEpochMs);
            if($this->emptyQueue($claim))break;
            $body=$this->requireOk($claim,'deferred_claim_failed');
            $envelope=is_array($body['request']??null)?$body['request']:[];
            $lease=trim((string)($body['lease_token']??''));
            $requestId=trim((string)($envelope['request_id']??''));
            if($requestId===''||$lease==='')throw new PublicEdgeSyncException('deferred_claim_invalid','پاسخ claim صف Deferred معتبر نیست.',502);
            try{$out=$this->deferred->dispatch($installationId,$envelope);}catch(Throwable $e){
                $out=['error_code'=>property_exists($e,'errorCode')?(string)$e->errorCode:'local_dispatch_exception'];
                $this->observability->logEvent('error','public.deferred_dispatch_failed',['request_id'=>$requestId,'kind'=>(string)($envelope['kind']??''),'error_code'=>$out['error_code']]);
                throw $e; // Lease expiry retries; a crash or DB outage is not a business rejection.
            }
            $state=(string)($out['state']??'');if(!in_array($state,['committed','needs_review','rejected'],true))throw new PublicEdgeSyncException('deferred_result_invalid','نتیجه اجرای کار معوق معتبر نیست؛ کار برای تلاش دوباره حفظ شد.',502);
            $ack=$this->client->postRaw('/api/v1/local/deferred/ack',[
                'request_id'=>$requestId,'lease_token'=>$lease,'state'=>$state,
                'result'=>is_array($out['result']??null)?$out['result']:[],
                'error_code'=>(string)($out['error_code']??''),
            ],$transportTimeoutSeconds,$deadlineEpochMs);
            $this->requireOk($ack,'deferred_ack_failed');$done++;
        }
        return $done;
    }

    private function reconcileDeferred(int $limit,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): int
    {
        $stmt=$this->pdo->query(
            "SELECT id,request_id,state,result_json,error_code FROM deferred_work_receipts " .
            "WHERE public_reconcile_pending=1 AND state IN ('committed','rejected') ORDER BY id LIMIT {$limit}"
        );
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];$done=0;
        foreach($rows as $row){
            if(!$this->deadlineAvailable($deadlineEpochMs,100))break;
            $result=json_decode((string)($row['result_json']??''),true);if(!is_array($result))$result=[];
            $response=$this->client->postRaw('/api/v1/local/deferred/reconcile',[
                'request_id'=>(string)$row['request_id'],'state'=>(string)$row['state'],'result'=>$result,'error_code'=>(string)($row['error_code']??''),
            ],$transportTimeoutSeconds,$deadlineEpochMs);
            $this->requireOk($response,'deferred_reconcile_failed');
            $update=$this->pdo->prepare('UPDATE deferred_work_receipts SET public_reconcile_pending=0 WHERE id=? AND public_reconcile_pending=1');
            $update->execute([(int)$row['id']]);$done+=$update->rowCount()>0?1:0;
        }
        return $done;
    }

    /** @return list<array<string,mixed>> */
    private function supportsRealtimeProjectionFastPath(): bool
    {
        try{
            $q=$this->pdo->query("SELECT status,detail_json FROM public_sync_state WHERE channel='realtime_exchange_capability' LIMIT 1");
            $row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row)||(string)($row['status']??'')!=='ok')return false;
            $detail=json_decode((string)($row['detail_json']??''),true);if(!is_array($detail))return false;
            return (int)($detail['projection_models']??0)>=1;
        }catch(Throwable){return false;}
    }

    private function recordRealtimeExchangeCapabilities(array $capabilities): void
    {
        // A successful exchange is also capability truth. If a rollback lands on an older
        // Public that omits this feature, close the fast path again instead of trusting stale state.
        $supported=(int)($capabilities['transport_protocol']??0)===2&&(int)($capabilities['projection_models']??0)>=1;
        try{
            $detail=json_encode([
                'transport_protocol'=>$supported?2:0,
                'projection_models'=>$supported?min(2,max(1,(int)$capabilities['projection_models'])):0,
                'atomic_table_draft_finalize'=>$supported&&(bool)($capabilities['atomic_table_draft_finalize']??false),
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $status=$supported?'ok':'disabled';
            $q=$this->pdo->prepare("INSERT INTO public_sync_state(channel,source_version,status,last_http_status,detail_json,last_attempt_at,last_success_at) VALUES('realtime_exchange_capability','transport-v2',?,200,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),status=VALUES(status),last_http_status=200,detail_json=VALUES(detail_json),last_attempt_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP()");
            $q->execute([$status,$detail]);
        }catch(Throwable){}
    }

    private function pendingRealtimeAcks(int $limit): array
    {
        $limit=max(1,min(25,$limit));
        try{$rows=$this->pdo->query("SELECT request_id,lease_token,state,result_json,error_code FROM public_realtime_ack_outbox WHERE delivery_state='pending' ORDER BY updated_at ASC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];}
        catch(Throwable){return [];}
        $out=[];foreach($rows as $row){$result=json_decode((string)($row['result_json']??''),true);$out[]=[
            'request_id'=>(string)$row['request_id'],'lease_token'=>(string)$row['lease_token'],'state'=>(string)$row['state'],
            'result'=>is_array($result)?$result:[],'error_code'=>(string)($row['error_code']??''),
        ];}
        return $out;
    }

    /** @return list<array{request_id:string}> */
    private function pendingRealtimeAckReconciliations(int $limit): array
    {
        $limit=max(1,min(25,$limit));
        try{$rows=$this->pdo->query("SELECT request_id FROM public_realtime_ack_outbox WHERE delivery_state='reconcile' AND (last_attempt_at IS NULL OR last_attempt_at<=DATE_SUB(NOW(6),INTERVAL 5 SECOND)) ORDER BY updated_at ASC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];}
        catch(Throwable){return [];}
        $out=[];foreach($rows as $row){$id=trim((string)($row['request_id']??''));if($id!=='')$out[]=['request_id'=>$id];}
        return $out;
    }

    private function queueRealtimeAck(string $requestId,string $lease,string $state,array $result,string $error): void
    {
        $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($json))$json='{}';
        $q=$this->pdo->prepare("INSERT INTO public_realtime_ack_outbox(request_id,lease_token,state,result_json,error_code,delivery_state,last_delivery_error,attempt_count) VALUES(?,?,?,?,?,'pending',NULL,0) ON DUPLICATE KEY UPDATE lease_token=VALUES(lease_token),state=VALUES(state),result_json=VALUES(result_json),error_code=VALUES(error_code),delivery_state='pending',last_delivery_error=NULL,attempt_count=0,updated_at=CURRENT_TIMESTAMP(6)");
        $q->execute([$requestId,$lease,$state,$json,$error!==''?$error:null]);
    }

    private function markRealtimeAckForReconciliation(string $requestId,string $reason): void
    {
        $reason=substr(trim($reason)!==''?trim($reason):'ack_reconciliation_required',0,80);
        $q=$this->pdo->prepare("UPDATE public_realtime_ack_outbox SET delivery_state='reconcile',last_delivery_error=?,last_attempt_at=NOW(6),attempt_count=attempt_count+1 WHERE request_id=?");
        $q->execute([$reason,$requestId]);
        $this->observability->logEvent('warning','public.realtime_ack_reconciliation_required',['request_id'=>$requestId,'reason'=>$reason]);
    }

    private function applyExchangeAckResults(array $rows): int
    {
        $done=0;foreach($rows as $row){if(!is_array($row))continue;$id=trim((string)($row['request_id']??''));if($id==='')continue;
            $ok=($row['ok']??false)===true;$error=(string)($row['error']??'');
            if($ok){
                $q=$this->pdo->prepare('DELETE FROM public_realtime_ack_outbox WHERE request_id=?');$q->execute([$id]);$done+=$q->rowCount()>0?1:0;
            }elseif(in_array($error,['lease_conflict','not_found','terminal_conflict'],true)){
                $this->markRealtimeAckForReconciliation($id,$error);
            }else{
                $q=$this->pdo->prepare('UPDATE public_realtime_ack_outbox SET last_attempt_at=NOW(6),last_delivery_error=?,attempt_count=attempt_count+1 WHERE request_id=?');$q->execute([$error!==''?$error:'ack_failed',$id]);
            }
        }return $done;
    }

    private function applyAckReconciliationResults(array $rows): int
    {
        $done=0;
        foreach($rows as $row){
            if(!is_array($row))continue;$id=trim((string)($row['request_id']??''));if($id==='')continue;
            $q=$this->pdo->prepare("SELECT state,result_json,error_code FROM public_realtime_ack_outbox WHERE request_id=? AND delivery_state='reconcile' LIMIT 1");
            $q->execute([$id]);$local=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($local))continue;
            $exists=($row['exists']??false)===true;$terminal=($row['terminal']??false)===true;
            if(!$exists){
                $u=$this->pdo->prepare("UPDATE public_realtime_ack_outbox SET last_attempt_at=NOW(6),last_delivery_error='not_found',attempt_count=attempt_count+1 WHERE request_id=?");$u->execute([$id]);
                continue;
            }
            if(!$terminal){
                $u=$this->pdo->prepare("UPDATE public_realtime_ack_outbox SET last_attempt_at=NOW(6),last_delivery_error='awaiting_terminal',attempt_count=attempt_count+1 WHERE request_id=?");$u->execute([$id]);
                continue;
            }
            $localResult=json_decode((string)($local['result_json']??''),true);if(!is_array($localResult))$localResult=[];
            $remoteResult=is_array($row['result']??null)?$row['result']:[];
            $same=(string)$local['state']===(string)($row['state']??'')
                &&(string)($local['error_code']??'')===(string)($row['error_code']??'')
                &&self::canonicalJson($localResult)===self::canonicalJson($remoteResult);
            if($same){
                $d=$this->pdo->prepare('DELETE FROM public_realtime_ack_outbox WHERE request_id=?');$d->execute([$id]);$done+=$d->rowCount()>0?1:0;
                $this->observability->logEvent('info','public.realtime_ack_reconciled',['request_id'=>$id,'state'=>(string)$local['state']]);
            }else{
                $u=$this->pdo->prepare("UPDATE public_realtime_ack_outbox SET last_attempt_at=NOW(6),last_delivery_error='terminal_mismatch',attempt_count=attempt_count+1 WHERE request_id=?");$u->execute([$id]);
                $this->observability->logEvent('error','public.realtime_ack_terminal_mismatch',['request_id'=>$id,'local_state'=>(string)$local['state'],'public_state'=>(string)($row['state']??'')]);
            }
        }
        return $done;
    }

    private function exchangeBudgetMs(?int $deadlineEpochMs,int $transportTimeoutSeconds): int
    {
        $transport=max(1,min(10,$transportTimeoutSeconds))*1000;
        $remaining=$this->deadlineRemainingMs($deadlineEpochMs);
        if($remaining===PHP_INT_MAX)return $transport;
        return max(100,min($transport,$remaining));
    }

    private function deadlineRemainingMs(?int $deadlineEpochMs): int
    {
        if($deadlineEpochMs===null||$deadlineEpochMs<=0)return PHP_INT_MAX;
        return max(0,$deadlineEpochMs-(int)floor(microtime(true)*1000));
    }

    private function deadlineAvailable(?int $deadlineEpochMs,int $reserveMs=0): bool
    {
        return $this->deadlineRemainingMs($deadlineEpochMs)>max(0,$reserveMs);
    }

    private function requireDeadlineBudget(?int $deadlineEpochMs,string $phase): void
    {
        if($this->deadlineAvailable($deadlineEpochMs,25))return;
        throw new PublicEdgeSyncException('public_deadline_exceeded','بودجه زمانی چرخه Public تمام شده است.',504,'deadline_'.$phase);
    }

    private static function canonicalJson(mixed $value): string
    {
        $normalize=static function(mixed $v) use (&$normalize): mixed {
            if(!is_array($v))return $v;
            if(array_is_list($v))return array_map($normalize,$v);
            ksort($v,SORT_STRING);foreach($v as $k=>$item)$v[$k]=$normalize($item);return $v;
        };
        $json=json_encode($normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
        return is_string($json)?$json:'{}';
    }

    private function flushLegacyRealtimeAcks(int $transportTimeoutSeconds,?int $deadlineEpochMs=null): int
    {
        $done=0;foreach($this->pendingRealtimeAcks(25) as $ack){
            if(!$this->deadlineAvailable($deadlineEpochMs,100))break;
            $response=$this->client->postRaw('/api/v1/local/realtime/ack',$ack,$transportTimeoutSeconds,$deadlineEpochMs);
            $status=(int)($response['status']??0);$body=is_array($response['body']??null)?$response['body']:[];$error=(string)($body['error']??'');
            if($status>=200&&$status<300&&($body['ok']??false)===true){
                $q=$this->pdo->prepare('DELETE FROM public_realtime_ack_outbox WHERE request_id=?');$q->execute([(string)$ack['request_id']]);$done+=$q->rowCount()>0?1:0;
            }elseif(in_array($error,['lease_conflict','not_found','terminal_conflict'],true)){
                $this->markRealtimeAckForReconciliation((string)$ack['request_id'],$error);
            }else{
                $q=$this->pdo->prepare('UPDATE public_realtime_ack_outbox SET last_attempt_at=NOW(6),last_delivery_error=?,attempt_count=attempt_count+1 WHERE request_id=?');$q->execute([$error!==''?$error:'ack_failed',(string)$ack['request_id']]);
            }
        }return $done;
    }

    private function clearRealtimeAck(string $requestId): void
    {
        $q=$this->pdo->prepare('DELETE FROM public_realtime_ack_outbox WHERE request_id=?');
        $q->execute([$requestId]);
    }

    private function pendingRealtimeAckCount(): int
    {try{return (int)$this->pdo->query("SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE delivery_state='pending'")->fetchColumn();}catch(Throwable){return 0;}}

    private function pendingRealtimeAckReconciliationCount(): int
    {try{return (int)$this->pdo->query("SELECT COUNT(*) FROM public_realtime_ack_outbox WHERE delivery_state='reconcile'")->fetchColumn();}catch(Throwable){return 0;}}

    private function emptyQueue(array $response): bool
    {
        return (int)($response['status']??0)===404 && (string)(($response['body']['error']??''))==='empty_queue';
    }

    private function requireOk(array $response,string $fallback): array
    {
        $status=(int)($response['status']??0);$body=is_array($response['body']??null)?$response['body']:[];
        if($status<200||$status>=300||($body['ok']??false)!==true){
            $code=(string)($body['error']??$fallback);
            throw new PublicEdgeSyncException($code,'عملیات Relay با Public Edge پذیرفته نشد.',$status?:502,'',(string)($response['failure_class']??'domain'),(int)($response['retry_after_seconds']??0));
        }
        return $body;
    }
}
