<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
use PDO;
use Sokna\Local\Core\Config;
use Sokna\Local\Core\Observability;
use Sokna\Local\Runtime\RuntimeEvidence;
use Throwable;
final class PublicEdgePublisherService
{
    private readonly PDO $pdo;
    private readonly PublicEdgeSyncClient $client;
    private readonly PublicProjectionBuilder $builder;
    private readonly Observability $obs;
    private readonly ?PublicAuthorityStateService $authorityState;
    private readonly ?Config $config;
    private readonly string $localVersion;

    /**
     * Mixed-generation safe constructor. During an in-place self-update an Apache worker may
     * still execute the previous Bootstrap while this class has already been replaced. The
     * fifth argument in 230zb was the Local version string; newer Bootstrap generations pass
     * authority/config before the version. Accept both shapes until the process boundary clears.
     */
    public function __construct(
        PDO $pdo,
        PublicEdgeSyncClient $client,
        PublicProjectionBuilder $builder,
        Observability $obs,
        PublicAuthorityStateService|string|null $authorityOrVersion=null,
        ?Config $config=null,
        string $localVersion='unknown'
    ){
        $this->pdo=$pdo;$this->client=$client;$this->builder=$builder;$this->obs=$obs;
        if(is_string($authorityOrVersion)){
            $this->authorityState=null;$this->config=null;$this->localVersion=trim($authorityOrVersion)!==''?$authorityOrVersion:'unknown';
        }else{
            $this->authorityState=$authorityOrVersion;$this->config=$config;$this->localVersion=$localVersion;
        }
    }

    public function snapshot(): array
    {
        $rows=$this->pdo->query('SELECT channel,source_version,status,last_http_status,detail_json,last_attempt_at,last_success_at FROM public_sync_state ORDER BY channel')->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){$detail=json_decode((string)($row['detail_json']??''),true);$row['detail']=is_array($detail)?$detail:[];unset($row['detail_json']);}unset($row);
        return ['configured'=>$this->client->configured(),'origin'=>$this->client->safeOrigin(),'installation_id'=>$this->client->installationId(),'channels'=>$rows];
    }

    /** Guest publication state against the exact current Local projection. */
    public function guestPublicationState(): array
    {
        $guest=$this->builder->guestPublish();
        $availability=$this->builder->availability();
        $manifest=is_array($guest['media_manifest']??null)?$guest['media_manifest']:[];
        $expected=[
            'revision_id'=>(string)($guest['revision_id']??''),
            'content_hash'=>(string)($guest['content_hash']??''),
            'media_version'=>PublicProjectionBuilder::hash($manifest),
            'availability_version'=>(string)($availability['version']??PublicProjectionBuilder::hash($availability)),
        ];
        $base=$this->snapshot();$by=[];foreach((array)$base['channels'] as $row)$by[(string)($row['channel']??'')]=$row;
        $publish=$by['guest_publish']??[];$media=$by['guest_media']??[];$avail=$by['availability']??[];
        $publishDetail=is_array($publish['detail']??null)?$publish['detail']:[];
        $mediaDetail=is_array($media['detail']??null)?$media['detail']:[];
        $publishOk=(string)($publish['status']??'')==='ok'
            &&hash_equals($expected['content_hash'],(string)($publish['source_version']??''))
            &&hash_equals($expected['revision_id'],(string)($publishDetail['revision_id']??''))
            &&!empty($publishDetail['active']);
        $mediaRequired=$manifest!==[];
        $mediaOk=!$mediaRequired||((string)($media['status']??'')==='ok'&&hash_equals($expected['media_version'],(string)($media['source_version']??'')));
        $availabilityOk=(string)($avail['status']??'')==='ok'&&hash_equals($expected['availability_version'],(string)($avail['source_version']??''));
        $criticalError=$this->currentGuestError($by,$expected,false);
        $mediaError=$this->currentMediaError($media,$expected['media_version']);
        $criticalReady=$publishOk&&$availabilityOk;
        $missingCount=max(0,(int)($mediaDetail['missing_count']??$publishDetail['missing_media_count']??0));

        if(!$base['configured']){$status='not_configured';$message='وب عمومی هنوز متصل نشده است.';}
        elseif($criticalError!==null){$status='error';$message=(string)$criticalError['message'];}
        elseif($criticalReady&&!$mediaOk){
            $status='partial';
            $message=$missingCount>0
                ?'منو و سفارش به‌روز است؛ '.self::faNumber($missingCount).' تصویر هنوز همگام نشده است.'
                :'منو و سفارش به‌روز است؛ تصاویر هنوز کامل همگام نشده‌اند.';
        }
        elseif($criticalReady){$status='synced';$message='منو، وضعیت سفارش و تصاویر با وب عمومی همگام هستند.';}
        elseif($publishOk){$status='partial';$message='نسخه منو در وب عمومی فعال شده، اما وضعیت سفارش هنوز کامل همگام نیست.';}
        else{$status='pending';$message='نسخه فعلی هنوز از وب عمومی تأیید نگرفته است.';}

        $visibleError=$criticalError??$mediaError;
        return [
            'configured'=>(bool)$base['configured'],'origin'=>(string)$base['origin'],'installation_id'=>(string)$base['installation_id'],
            'status'=>$status,'message'=>$message,'expected_revision_id'=>$expected['revision_id'],'expected_content_hash'=>$expected['content_hash'],
            'critical_ready'=>$criticalReady,'ordering_ready'=>$criticalReady,'media_ready'=>$mediaOk,'media_missing_count'=>$missingCount,
            'last_ack_at'=>$publishOk?(string)($publish['last_success_at']??''):'','ack_revision_id'=>(string)($publishDetail['revision_id']??''),
            'failed_channel'=>$visibleError['channel']??'','error_code'=>$visibleError['code']??'','support_id'=>$visibleError['support_id']??'',
            'channels'=>array_values(array_filter([$media,$publish,$avail],static fn(array $r):bool=>$r!==[])),
        ];
    }

    /**
     * Guest publication is critical-data first and media-degradable.
     * A missing image must never disable an otherwise valid menu/order revision.
     */
    public function syncGuestPublication(int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        $transportTimeoutSeconds=max(1,min(30,$transportTimeoutSeconds));
        $supportId=substr($this->obs->correlationId(),0,12);
        if(!$this->client->configured()){
            $this->record('guest_publish','disabled',null,null,['code'=>'public_not_configured','support_id'=>$supportId]);
            throw new PublicEdgeSyncException('public_not_configured','وب عمومی هنوز متصل نشده است.',409);
        }
        $guest=$this->builder->guestPublish();$results=[];
        $contentHash=(string)($guest['content_hash']??'');$revision=(string)($guest['revision_id']??'');

        // Publish first on resilient Public versions. Older strict Public versions may answer
        // missing_media; in that case media is uploaded and publication is retried once.
        $publish=$this->publishGuestRevision($guest,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
        $results['guest_publish']=$publish;
        if(!$publish['ok']&&$this->isRetryableTransportResult($publish)){
            $state=$this->guestPublicationState();
            return ['success'=>false,'degraded'=>false,'synced_at'=>gmdate('c'),'support_id'=>$supportId,'channels'=>$results,'state'=>$state];
        }

        $availability=$this->builder->availability();
        $availabilityVersion=(string)($availability['version']??PublicProjectionBuilder::hash($availability));
        $results['availability']=$this->sendChannelSafe('availability',$availabilityVersion,'/api/v1/local/guest/availability',$availability,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
        if(!$results['availability']['ok']&&$this->isRetryableTransportResult($results['availability'])){
            $state=$this->guestPublicationState();
            return ['success'=>false,'degraded'=>false,'synced_at'=>gmdate('c'),'support_id'=>$supportId,'channels'=>$results,'state'=>$state];
        }

        $results['guest_media']=$this->syncGuestMedia($guest,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
        if(!$results['guest_media']['ok']&&$this->isRetryableTransportResult($results['guest_media'])){
            $state=$this->guestPublicationState();
            return ['success'=>false,'degraded'=>false,'synced_at'=>gmdate('c'),'support_id'=>$supportId,'channels'=>$results,'state'=>$state];
        }
        if(empty($publish['ok'])&&(string)($publish['code']??'')==='missing_media'&&!empty($results['guest_media']['complete'])){
            $publish=$this->publishGuestRevision($guest,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
            $results['guest_publish']=$publish;
            if(!$publish['ok']&&$this->isRetryableTransportResult($publish)){
                $state=$this->guestPublicationState();
                return ['success'=>false,'degraded'=>false,'synced_at'=>gmdate('c'),'support_id'=>$supportId,'channels'=>$results,'state'=>$state];
            }
        }

        $results['heartbeat']=$this->sendHeartbeat($supportId,$transportTimeoutSeconds,$deadlineEpochMs);
        $state=$this->guestPublicationState();
        $this->obs->logEvent('info','public.guest_publish_completed',[
            'revision_id'=>$revision,'origin'=>$this->client->safeOrigin(),'state'=>$state['status'],
            'critical_ready'=>(bool)($state['critical_ready']??false),'media_ready'=>(bool)($state['media_ready']??false),
        ],$supportId);
        return [
            'success'=>(bool)($state['critical_ready']??false),
            'degraded'=>(bool)($state['critical_ready']??false)&&!(bool)($state['media_ready']??false),
            'synced_at'=>gmdate('c'),'support_id'=>$supportId,'channels'=>$results,'state'=>$state,
        ];
    }

    public function syncAll(int $transportTimeoutSeconds=10): array
    {
        $transportTimeoutSeconds=max(1,min(30,$transportTimeoutSeconds));
        $supportId=substr($this->obs->correlationId(),0,12);
        if(!$this->client->configured()){
            $this->record('all','disabled',null,null,['reason'=>'not_configured','support_id'=>$supportId]);
            throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز pair نشده است.',409);
        }
        $installationId=$this->client->installationId();$results=[];
        $installation=$this->builder->installation($installationId);
        $results['installation']=$this->sendChannelSafe('installation',PublicProjectionBuilder::hash($installation),'/api/v1/local/installation',$installation,$supportId,$transportTimeoutSeconds);
        if(!$results['installation']['ok']&&$this->isRetryableTransportResult($results['installation'])){
            $this->obs->logEvent('warning','public.sync_completed',['channels'=>['installation'],'failed_channels'=>['installation'],'critical_failed'=>['installation'],'degraded'=>false,'origin'=>$this->client->safeOrigin()],$supportId);
            return ['success'=>false,'degraded'=>false,'failed_channels'=>['installation'],'critical_failed_channels'=>['installation'],'synced_at'=>gmdate('c'),'support_id'=>$supportId,'channels'=>$results,'state'=>$this->snapshot(),'guest_publication'=>$this->guestPublicationState()];
        }
        $auth=$this->authProjectionPayload();
        $results['auth']=$this->sendChannelSafe('auth',PublicProjectionBuilder::hash($auth),'/api/v1/local/auth-projections',$auth,$supportId,$transportTimeoutSeconds);

        try{$guest=$this->syncGuestPublication($transportTimeoutSeconds);$results=array_merge($results,(array)$guest['channels']);}
        catch(Throwable $e){
            $results['guest_publish']=['ok'=>false,'code'=>$e instanceof PublicEdgeSyncException?$e->errorCode:'public_guest_internal','message'=>'انتشار منوی مهمان کامل نشد.'];
        }

        // Manual Full Sync uses the same durable per-model transport as automatic replication.
        // Seed authoritative invalidations, then drain each model independently; one slow model
        // cannot roll back unrelated models and no monolithic read-model transaction is created.
        $seeded=$this->seedProjectionInvalidations($this->builder->remoteModelKeys(),'manual.full_sync');
        $projection=$this->syncProjectionModelsV2(1000,12,$transportTimeoutSeconds);
        $results['read_models']=[
            'ok'=>(bool)($projection['success']??false),
            'code'=>(string)(($projection['success']??false)?'':'projection_model_sync_failed'),
            'protocol'=>(int)($projection['protocol']??2),'seeded'=>$seeded,'detail'=>$projection,
        ];
        $results['heartbeat']=$this->sendHeartbeat($supportId,$transportTimeoutSeconds);

        $failed=[];foreach($results as $channel=>$result){if(is_array($result)&&array_key_exists('ok',$result)&&!$result['ok'])$failed[]=(string)$channel;}
        $criticalFailed=array_values(array_diff($failed,['guest_media']));
        $guestState=$this->guestPublicationState();
        $success=$criticalFailed===[]&&(bool)($guestState['critical_ready']??false);
        if($success&&$this->authorityState!==null&&$this->authorityState->resyncRequired()){
            try{
                $activation=$this->client->activateAuthority($transportTimeoutSeconds);
                $results['authority_activate']=['ok'=>true,'detail'=>$activation['body']??[]];
                $this->authorityState->markActive($this->authorityState->epoch());
            }catch(PublicEdgeSyncException $e){
                $results['authority_activate']=['ok'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage()];
                $failed[]='authority_activate';$criticalFailed[]='authority_activate';$success=false;
            }
        }
        $degraded=$success&&($failed!==[]||!(bool)($guestState['media_ready']??true));
        $level=$success?'info':'warning';
        $this->obs->logEvent($level,'public.sync_completed',[
            'channels'=>array_keys($results),'failed_channels'=>$failed,'critical_failed'=>$criticalFailed,
            'degraded'=>$degraded,'origin'=>$this->client->safeOrigin(),
        ],$supportId);
        return [
            'success'=>$success,'degraded'=>$degraded,'failed_channels'=>$failed,'critical_failed_channels'=>$criticalFailed,
            'synced_at'=>gmdate('c'),'support_id'=>$supportId,'channels'=>$results,'state'=>$this->snapshot(),'guest_publication'=>$guestState,
        ];
    }


    /**
     * Runtime-owned projection sync: publish only channels whose exact Local source changed.
     * Manual Full Sync keeps using syncAll(); this path is intentionally change-driven so the
     * 30-second machine trigger does not replay 6-8 WAN requests while nothing changed.
     */
    public function syncAutomaticProjection(int $transportTimeoutSeconds=2,?int $deadlineEpochMs=null): array
    {
        $transportTimeoutSeconds=max(1,min(10,$transportTimeoutSeconds));
        $supportId=substr($this->obs->correlationId(),0,12);
        if(!$this->client->configured())throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز pair نشده است.',409);
        $results=[];$unreachable=false;$installationId=$this->client->installationId();

        $installation=$this->builder->installation($installationId);
        $results['installation']=$this->sendChannelIfChanged('installation',PublicProjectionBuilder::hash($installation),'/api/v1/local/installation',$installation,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
        if(!$results['installation']['ok']&&$this->isRetryableTransportResult($results['installation']))$unreachable=true;

        if(!$unreachable){
            if(!$this->deadlineAvailable($deadlineEpochMs,100))return $this->deadlineProjectionResult($results,$supportId);
            $auth=$this->authProjectionPayload();
            $results['auth']=$this->sendChannelIfChanged('auth',PublicProjectionBuilder::hash($auth),'/api/v1/local/auth-projections',$auth,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
            if(!$results['auth']['ok']&&$this->isRetryableTransportResult($results['auth']))$unreachable=true;
        }

        if(!$unreachable){
            $guestState=$this->guestPublicationState();
            if((bool)($guestState['critical_ready']??false)&&(bool)($guestState['media_ready']??false)){
                $results['guest_publish']=['ok'=>true,'skipped'=>true,'reason'=>'unchanged_ready'];
                $results['availability']=['ok'=>true,'skipped'=>true,'reason'=>'unchanged_ready'];
                $results['guest_media']=['ok'=>true,'complete'=>true,'skipped'=>true,'reason'=>'unchanged_ready'];
            }else{
                try{
                    if(!$this->deadlineAvailable($deadlineEpochMs,150))return $this->deadlineProjectionResult($results,$supportId);
                    $guest=$this->syncGuestPublication($transportTimeoutSeconds,$deadlineEpochMs);
                    $results=array_merge($results,(array)($guest['channels']??[]));
                    foreach((array)($guest['channels']??[]) as $row)if(is_array($row)&&!($row['ok']??true)&&$this->isRetryableTransportResult($row)){$unreachable=true;break;}
                }catch(PublicEdgeSyncException $e){
                    $results['guest_publish']=['ok'=>false,'code'=>$e->errorCode,'detail_code'=>$e->detailCode,'message'=>$e->getMessage(),'support_id'=>$supportId];
                    if(in_array($e->failureClass,['transport','overload'],true))$unreachable=true;
                }
            }
        }

        // H289: automatic read-model replication is owned exclusively by the durable
        // per-model outbox transport. Do not replay the monolithic /read-models payload here.
        $results['read_models']=['ok'=>true,'skipped'=>true,'reason'=>'durable_model_transport_v2'];

        $failed=[];foreach($results as $channel=>$row)if(is_array($row)&&array_key_exists('ok',$row)&&!$row['ok'])$failed[]=(string)$channel;
        return [
            'success'=>$failed===[]&&!$unreachable,'transport_unreachable'=>$unreachable,
            'failed_channels'=>$failed,'channels'=>$results,'support_id'=>$supportId,'synced_at'=>gmdate('c'),
        ];
    }

    /**
     * Drain Local transactional projection invalidations into Public read models.
     * Delivery is at-least-once; Public advances a monotonic per-installation cursor and
     * duplicate event batches are safe. A detected sequence gap is recovered by a signed
     * full read-model reset, never by guessing missing state.
     */
    public function syncProjectionEvents(int $limit=100,int $transportTimeoutSeconds=10): array
    {
        $limit=max(1,min(250,$limit));
        if(!$this->client->configured())return ['success'=>true,'configured'=>false,'supported'=>true,'pending'=>0,'delivered'=>0];
        // H242 migration is applied before runtime resumes. During staggered package rollout,
        // however, keep the method defensive so an older database does not break relay health.
        try{
            $rows=$this->pdo->query("SELECT sequence_id,event_type,aggregate_type,aggregate_id,model_keys,created_at,attempt_count FROM public_projection_outbox WHERE delivered_at IS NULL ORDER BY sequence_id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];
        }catch(Throwable $e){
            $this->obs->logEvent('warning','public.projection_event_outbox_unavailable',['exception'=>get_class($e)]);
            return ['success'=>true,'configured'=>true,'supported'=>false,'pending'=>0,'delivered'=>0,'reason'=>'outbox_unavailable'];
        }
        if($rows===[])return ['success'=>true,'configured'=>true,'supported'=>true,'pending'=>0,'delivered'=>0];
        $from=(int)$rows[0]['sequence_id'];$to=(int)$rows[count($rows)-1]['sequence_id'];$keys=[];$events=[];
        foreach($rows as $row){
            foreach(explode(',',(string)($row['model_keys']??'')) as $key){$key=trim($key);if($key!=='')$keys[$key]=true;}
            $events[]=[
                'sequence'=>(int)$row['sequence_id'],'event_type'=>(string)$row['event_type'],
                'aggregate_type'=>(string)$row['aggregate_type'],'aggregate_id'=>(string)$row['aggregate_id'],
                'model_keys'=>array_values(array_filter(array_map('trim',explode(',',(string)$row['model_keys'])))),
                'occurred_at'=>(string)$row['created_at'],
            ];
        }
        $modelKeys=array_keys($keys);sort($modelKeys,SORT_STRING);
        $models=$this->builder->remoteModelsFor($modelKeys);
        $attempt=$this->pdo->prepare('UPDATE public_projection_outbox SET attempt_count=attempt_count+1,last_attempt_at=NOW(6) WHERE delivered_at IS NULL AND sequence_id BETWEEN ? AND ?');
        $attempt->execute([$from,$to]);
        $payload=['format'=>'sokna-projection-events-v1','from_sequence'=>$from,'to_sequence'=>$to,'events'=>$events,'models'=>$models];
        $started=microtime(true);
        try{
            $response=$this->client->postRaw('/api/v1/local/read-model-events',$payload,$transportTimeoutSeconds);
        }catch(PublicEdgeSyncException $e){
            $this->obs->logEvent('warning','public.projection_event_sync_failed',['from_sequence'=>$from,'to_sequence'=>$to,'error_code'=>$e->errorCode,'http_status'=>$e->httpStatus]);
            return ['success'=>false,'configured'=>true,'supported'=>true,'pending'=>count($rows),'delivered'=>0,'error_code'=>$e->errorCode];
        }
        $status=(int)($response['status']??0);$body=is_array($response['body']??null)?$response['body']:[];
        if($status===404&&in_array((string)($body['error']??''),['route_not_found','unknown_route'],true)){
            // Exact-base Local may be installed before the matching Public delta. Preserve
            // outbox rows and let the old 30-second projection sync remain the compatibility path.
            return ['success'=>true,'configured'=>true,'supported'=>false,'pending'=>count($rows),'delivered'=>0,'reason'=>'public_route_not_available'];
        }
        if($status===409&&(string)($body['error']??'')==='projection_event_gap'){
            // ARCH-01: do not turn a gap into an unaudited full reset. Keep the durable outbox
            // pending and require explicit reconciliation instead of risking cursor/model rollback.
            $this->obs->logEvent('warning','public.projection_event_gap_requires_reconciliation',['from_sequence'=>$from,'to_sequence'=>$to,'ack_sequence'=>(int)($body['ack_sequence']??0)]);
            return ['success'=>false,'configured'=>true,'supported'=>true,'pending'=>count($rows),'delivered'=>0,'error_code'=>'projection_event_gap_requires_reconciliation','ack_sequence'=>(int)($body['ack_sequence']??0)];
        }
        if($status<200||$status>=300||($body['ok']??false)!==true){
            $code=(string)($body['error']??'projection_event_sync_failed');
            $this->obs->logEvent('warning','public.projection_event_sync_failed',['from_sequence'=>$from,'to_sequence'=>$to,'error_code'=>$code,'http_status'=>$status]);
            return ['success'=>false,'configured'=>true,'supported'=>true,'pending'=>count($rows),'delivered'=>0,'error_code'=>$code,'http_status'=>$status];
        }
        $ack=(int)($body['ack_sequence']??0);
        if($ack<$to){
            $this->obs->logEvent('error','public.projection_event_ack_invalid',['from_sequence'=>$from,'to_sequence'=>$to,'ack_sequence'=>$ack]);
            return ['success'=>false,'configured'=>true,'supported'=>true,'pending'=>count($rows),'delivered'=>0,'error_code'=>'projection_event_ack_invalid'];
        }
        $done=$this->pdo->prepare('UPDATE public_projection_outbox SET delivered_at=COALESCE(delivered_at,NOW(6)) WHERE delivered_at IS NULL AND sequence_id<=?');
        $done->execute([$ack]);$delivered=$done->rowCount();
        // Bounded forensic retention. Rows remain available long enough for support evidence,
        // while an always-on installation cannot grow the outbox forever.
        $pruneBefore=max(0,$ack-5000);
        if($pruneBefore>0){$prune=$this->pdo->prepare('DELETE FROM public_projection_outbox WHERE delivered_at IS NOT NULL AND sequence_id<?');$prune->execute([$pruneBefore]);}
        $out=['success'=>true,'configured'=>true,'supported'=>true,'pending'=>count($rows),'delivered'=>$delivered,'from_sequence'=>$from,'to_sequence'=>$to,'ack_sequence'=>$ack,'model_keys'=>$modelKeys,'latency_ms'=>(int)round((microtime(true)-$started)*1000),'gap_reset'=>(bool)($body['reset']??false)];
        $this->obs->logEvent('info','public.projection_event_sync_completed',$out);
        return $out;
    }

    /** @param list<string> $keys */
    public function seedProjectionInvalidations(array $keys,string $eventType='projection.manual'): int
    {
        $allowed=array_fill_keys($this->builder->remoteModelKeys(),true);$clean=[];
        foreach($keys as $key){$key=trim((string)$key);if($key!==''&&isset($allowed[$key]))$clean[$key]=true;}
        if($clean===[])return 0;$keys=array_keys($clean);sort($keys,SORT_STRING);
        $q=$this->pdo->prepare('INSERT INTO public_projection_outbox(event_type,aggregate_type,aggregate_id,model_keys) VALUES(?,?,?,?)');
        $q->execute([substr($eventType,0,96),'projection','*',implode(',',$keys)]);return (int)$this->pdo->lastInsertId();
    }

    /**
     * Cheap self-heal for missed/legacy triggers. It never builds a read model. If a model has
     * not been acknowledged recently and no pending invalidation already covers it, enqueue one
     * synthetic invalidation. This bounds staleness without restoring the old 30-second full build.
     */
    public function seedProjectionSafetyInvalidations(int $maxAgeSeconds=600,int $maxModels=3): array
    {
        $maxAgeSeconds=max(120,min(3600,$maxAgeSeconds));$maxModels=max(1,min(12,$maxModels));$now=time();$modelKeys=$this->builder->remoteModelKeys();$state=$this->projectionDeliveryState($modelKeys);$targets=$this->pendingProjectionTargets($modelKeys);$seeded=[];
        foreach($modelKeys as $key){
            $last=trim((string)($state[$key]['last_success_at']??''));$age=$last===''?PHP_INT_MAX:max(0,$now-(int)(\Sokna\Shared\IranTime::epoch($last,'Asia/Tehran')?:0));
            if($age<$maxAgeSeconds)continue;
            if((int)($targets[$key]??0)>(int)($state[$key]['ack_sequence']??0))continue;
            $this->seedProjectionInvalidations([$key],'projection.safety');$seeded[]=$key;if(count($seeded)>=$maxModels)break;
        }
        return ['seeded'=>$seeded,'count'=>count($seeded),'max_age_seconds'=>$maxAgeSeconds];
    }

    /**
     * Coalesced per-model projection transport v2. Local outbox rows are invalidations, not
     * deltas: all pending events for a model collapse into the latest authoritative snapshot.
     * Each model has its own monotonic ACK cursor, so a slow model never blocks unrelated state.
     */
    public function syncProjectionModelsV2(int $eventLimit=250,int $modelLimit=4,int $transportTimeoutSeconds=4,?int $deadlineEpochMs=null,?array $allowedKeys=null): array
    {
        $eventLimit=max(1,min(1000,$eventLimit));$modelLimit=max(1,min(12,$modelLimit));$transportTimeoutSeconds=max(1,min(15,$transportTimeoutSeconds));
        if(!$this->client->configured())return ['success'=>true,'configured'=>false,'pending_events'=>0,'attempted_models'=>0,'delivered_events'=>0];
        try{$rows=$this->pdo->query("SELECT sequence_id,model_keys FROM public_projection_outbox WHERE delivered_at IS NULL ORDER BY sequence_id LIMIT {$eventLimit}")->fetchAll(PDO::FETCH_ASSOC)?:[];}
        catch(Throwable $e){return ['success'=>false,'configured'=>true,'error_code'=>'projection_outbox_unavailable','exception'=>get_class($e)];}
        if($rows===[])return ['success'=>true,'configured'=>true,'pending_events'=>0,'attempted_models'=>0,'delivered_events'=>0,'protocol'=>2];

        // ARCH-03: model discovery must not depend on the oldest event prefix. A permanently
        // dirty reports/inventory row may stay at the head of the outbox without starving
        // operations/guest_operational invalidations that were inserted later.
        $targets=$this->pendingProjectionTargets($this->builder->remoteModelKeys());
        if($allowedKeys!==null)$targets=array_intersect_key($targets,array_fill_keys($allowedKeys,true));
        // A filtered lane must never retire events owned by another lane.
        if($targets===[]&&$allowedKeys!==null)return ['success'=>true,'configured'=>true,'protocol'=>2,'pending_events'=>count($rows),'attempted_models'=>0,'delivered_events'=>$this->markFullyAckedProjectionRows($eventLimit)];
        if($targets===[]){$ids=array_map(static fn(array $r):int=>(int)$r['sequence_id'],$rows);$this->markProjectionRowsDelivered($ids);return ['success'=>true,'configured'=>true,'pending_events'=>count($rows),'attempted_models'=>0,'delivered_events'=>count($ids),'protocol'=>2];}
        $state=$this->projectionDeliveryState(array_keys($targets));
        $priority=['guest_operational'=>0,'operations'=>1,'table_draft_context'=>2,'preparation'=>3,'notifications'=>4,'reports'=>5,'inventory'=>6,'inventory_cost'=>7,'operations_workspace'=>8,'subscribers'=>9,'deferred_context'=>10];
        $transportFailure=null;$dirty=[];foreach($targets as $key=>$seq)if((int)($state[$key]['ack_sequence']??0)<$seq)$dirty[$key]=$seq;
        uksort($dirty,static function(string $a,string $b) use ($priority):int{return ($priority[$a]??50)<=>($priority[$b]??50)?:strcmp($a,$b);});
        $selected=array_slice($dirty,0,$modelLimit,true);$attempted=[];$supported=true;$unreachable=false;
        foreach($selected as $key=>$seq){
            if(!$this->deadlineAvailable($deadlineEpochMs,125))break;
            $model=$this->preparedProjectionModel($key,(int)$seq);
            if(!is_array($model)){$this->recordProjectionModelFailure($key,'model_unavailable');$attempted[$key]=['ok'=>false,'error_code'=>'model_unavailable'];continue;}
            $payload=['format'=>'sokna-read-model-v2','up_to_sequence'=>$seq,'model'=>$model];
            $this->touchProjectionModelAttempt($key);
            try{$response=$this->client->postRaw('/api/v1/local/read-model-v2',$payload,$transportTimeoutSeconds,$deadlineEpochMs);}
            catch(PublicEdgeSyncException $e){$this->recordProjectionModelFailure($key,$e->errorCode);$attempted[$key]=['ok'=>false,'error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'retry_after_seconds'=>$e->retryAfterSeconds];if(in_array($e->failureClass,['transport','overload'],true)){$unreachable=true;$transportFailure=['error_code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'retry_after_seconds'=>$e->retryAfterSeconds,'http_status'=>$e->httpStatus];break;}continue;}
            $status=(int)($response['status']??0);$body=is_array($response['body']??null)?$response['body']:[];
            if($status===404&&in_array((string)($body['error']??''),['route_not_found','unknown_route'],true)){$supported=false;break;}
            // ARCH-01: never retry a same-generation sequence conflict with reset=true.
            // A restore must use a future explicit authority-epoch protocol; current transport fails closed.
            if($status<200||$status>=300||($body['ok']??false)!==true){$code=(string)($body['error']??'projection_model_sync_failed');$failureClass=(string)($response['failure_class']??'domain');$retryAfter=(int)($response['retry_after_seconds']??0);$this->recordProjectionModelFailure($key,$code);$attempted[$key]=['ok'=>false,'error_code'=>$code,'http_status'=>$status,'failure_class'=>$failureClass,'retry_after_seconds'=>$retryAfter];if(in_array($failureClass,['transport','overload'],true)){$unreachable=true;$transportFailure=['error_code'=>$code,'detail_code'=>'http_'.$status,'failure_class'=>$failureClass,'retry_after_seconds'=>$retryAfter,'http_status'=>$status];break;}continue;}
            $ack=(int)($body['ack_sequence']??0);if($ack<$seq){$this->recordProjectionModelFailure($key,'projection_model_ack_invalid');$attempted[$key]=['ok'=>false,'error_code'=>'projection_model_ack_invalid','ack_sequence'=>$ack];continue;}
            $version=(string)($model['source_version']??'');$this->recordProjectionModelSuccess($key,$ack,$version);$state[$key]=['ack_sequence'=>$ack,'source_version'=>$version];$attempted[$key]=['ok'=>true,'ack_sequence'=>$ack,'source_version'=>$version,'reset'=>(bool)($body['reset']??false)];
        }
        if(!$supported){
            $legacy=$this->syncProjectionEvents(min(100,$eventLimit),$transportTimeoutSeconds);
            if(($legacy['supported']??true)===false){
                // Compatibility with pre-v2 Public: a 404 from both durable endpoints
                // is NOT proof that read models were delivered. Send the signed full
                // snapshot through the supported v1 route and verify every model was
                // accepted. Keep outbox events pending: v1 has no per-model sequence ACK.
                try{
                    $models=$this->builder->remoteModels();
                    $response=$this->client->postRaw('/api/v1/local/read-models',['models'=>$models],$transportTimeoutSeconds);
                    $status=(int)($response['status']??0);
                    $body=is_array($response['body']??null)?$response['body']:[];
                    $accepted=(int)($body['synced']??0)+(int)($body['unchanged']??0);
                    $complete=$status>=200&&$status<300&&($body['ok']??false)===true&&$accepted===count($models)&&count($models)===count($this->builder->remoteModelKeys());
                    return ['success'=>$complete,'configured'=>true,'protocol'=>1,'fallback'=>true,
                        'reason'=>$complete?'verified_legacy_full_snapshot':'legacy_model_set_incomplete',
                        'submitted_models'=>count($models),'accepted_models'=>$accepted,'http_status'=>$status,
                        'pending_events'=>count($rows),'delivered_events'=>0,'legacy'=>$legacy];
                }catch(PublicEdgeSyncException $e){
                    return ['success'=>false,'configured'=>true,'protocol'=>1,'fallback'=>true,
                        'error_code'=>$e->errorCode,'http_status'=>$e->httpStatus,
                        'pending_events'=>count($rows),'delivered_events'=>0,'legacy'=>$legacy];
                }
            }
            return ['success'=>(bool)($legacy['success']??false),'configured'=>true,'protocol'=>1,'fallback'=>true,'pending_events'=>count($rows),'legacy'=>$legacy];
        }
        $state=$this->projectionDeliveryState($this->builder->remoteModelKeys());$deliver=[];
        foreach($rows as $row){$seq=(int)$row['sequence_id'];$ok=true;foreach(explode(',',(string)$row['model_keys']) as $key){$key=trim($key);if($key!==''&&(int)($state[$key]['ack_sequence']??0)<$seq){$ok=false;break;}}if($ok)$deliver[]=$seq;}
        $delivered=$this->markProjectionRowsDelivered($deliver);
        $failed=array_values(array_filter(array_keys($attempted),static fn(string $k):bool=>!($attempted[$k]['ok']??false)));
        return ['success'=>$failed===[]&&!$unreachable,'configured'=>true,'protocol'=>2,'pending_events'=>count($rows),'attempted_models'=>count($attempted),'models'=>$attempted,'failed_models'=>$failed,'transport_unreachable'=>$unreachable,'transport_failure'=>$transportFailure,'delivered_events'=>$delivered,'deadline_exhausted'=>!$this->deadlineAvailable($deadlineEpochMs,0),'deadline_remaining_ms'=>$this->deadlineRemainingMs($deadlineEpochMs)];
    }

    /** Independent Runtime lane: the same durable snapshots/ACKs, no command pickup. */
    public function syncLiveProjection(?int $deadlineEpochMs=null): array
    {
        $lock='sokna_live_projection';$q=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
        if((int)$q->fetchColumn()!==1)return ['success'=>true,'skipped'=>true,'reason'=>'live_projection_owner_busy'];
        try{return $this->syncProjectionModelsV2(250,4,3,$deadlineEpochMs,['operations','guest_operational','table_draft_context','preparation']);}
        finally{try{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}catch(Throwable){}}
    }

    /** A current independent worker suppresses only duplicate fast projection, never ACKs. */
    public function liveProjectionWorkerFresh(): bool
    {
        try{
            return $this->pdo->query("SELECT id FROM runtime_trigger_receipts WHERE trigger_key='public.live_projection_sync' AND state='completed' AND accepted_at>=DATE_SUB(CURRENT_TIMESTAMP(),INTERVAL 10 SECOND) ORDER BY accepted_at DESC LIMIT 1")->fetchColumn()!==false;
        }catch(Throwable){return false;}
    }


    /**
     * Read-your-writes fast path. Only one live staff model is piggy-backed on the next
     * realtime exchange and only when the durable projection outbox proves it is dirty.
     * Idle fast-lane cycles therefore add no model build and no extra WAN request.
     */
    /**
     * Build a bounded read-your-writes batch from durable invalidations. No WAN call occurs here.
     * $allowedKeys is ordered by caller importance so a Guest command can prioritize
     * guest_operational while a Staff draft can prioritize operations/table_draft_context.
     *
     * @param list<string> $allowedKeys
     */
    public function prepareRealtimeProjectionBatch(int $eventLimit=120,int $modelLimit=1,array $allowedKeys=['operations','guest_operational','table_draft_context','preparation']): array
    {
        $eventLimit=max(1,min(250,$eventLimit));$modelLimit=max(1,min(2,$modelLimit));
        if(!$this->client->configured())return ['items'=>[],'pending_events'=>0,'model_keys'=>[]];
        $known=array_fill_keys($this->builder->remoteModelKeys(),true);$priority=[];
        foreach(array_values(array_unique(array_map('strval',$allowedKeys))) as $i=>$key)if(isset($known[$key]))$priority[$key]=$i;
        if($priority===[])return ['items'=>[],'pending_events'=>0,'model_keys'=>[]];
        try{$rows=$this->pdo->query("SELECT sequence_id,model_keys FROM public_projection_outbox WHERE delivered_at IS NULL ORDER BY sequence_id LIMIT {$eventLimit}")->fetchAll(PDO::FETCH_ASSOC)?:[];}
        catch(Throwable){return ['items'=>[],'pending_events'=>0,'model_keys'=>[]];}
        if($rows===[])return ['items'=>[],'pending_events'=>0,'model_keys'=>[]];
        // ARCH-03: locate each allowed critical model across the complete pending outbox, not
        // only inside the oldest bounded prefix used for bookkeeping/cleanup.
        $targets=$this->pendingProjectionTargets(array_keys($priority));
        if($targets===[])return ['items'=>[],'pending_events'=>count($rows),'model_keys'=>[]];
        $state=$this->projectionDeliveryState(array_keys($targets));$dirty=[];
        foreach($targets as $key=>$seq)if((int)($state[$key]['ack_sequence']??0)<$seq)$dirty[$key]=$seq;
        uksort($dirty,static function(string $a,string $b) use($priority):int{return ($priority[$a]??50)<=>($priority[$b]??50)?:strcmp($a,$b);});
        $items=[];
        foreach(array_slice($dirty,0,$modelLimit,true) as $key=>$seq){$model=$this->preparedProjectionModel($key,(int)$seq);if(!is_array($model))continue;$this->touchProjectionModelAttempt($key);$items[]=['format'=>'sokna-read-model-v2','up_to_sequence'=>$seq,'model'=>$model];}
        return ['items'=>$items,'pending_events'=>count($rows),'model_keys'=>array_values(array_map(static fn(array $i):string=>(string)($i['model']['model_key']??''),$items))];
    }

    /** Pin the exact payload/hash per sequence across fast/periodic workers and lost replies. */
    private function preparedProjectionModel(string $key,int $sequence): ?array
    {
        if($sequence<1||!in_array($key,$this->builder->remoteModelKeys(),true))return null;
        $scope=hash('sha256',$this->client->installationId().'|'.($this->authorityState?->epoch()??1));
        $dir=$this->obs->runtimeDir().DIRECTORY_SEPARATOR.'public-projection-snapshots';
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Projection snapshot storage unavailable.');
        $path=$dir.DIRECTORY_SEPARATOR.$scope.'-'.$key.'.json';
        $lock=@fopen($path.'.lock','c');if(!is_resource($lock))throw new \RuntimeException('Projection snapshot lock unavailable.');
        try{
            // Never stall command processing behind a model being built by the periodic lane.
            if(!flock($lock,LOCK_EX|LOCK_NB))return null;
            $saved=$this->obs->readJsonFile($path);$savedSequence=(int)($saved['sequence']??0);
            if($savedSequence>$sequence)return null; // stale concurrent target; discover the latest next tick
            if($savedSequence===$sequence){
                $model=$saved['model']??null;
                if(!is_array($model)||(string)($model['model_key']??'')!==$key||!is_array($model['payload']??null)||!hash_equals(PublicProjectionBuilder::hash($model['payload']),(string)($model['source_version']??'')))throw new \RuntimeException('Projection snapshot integrity invalid.');
                return $model;
            }
            $models=$this->builder->remoteModelsFor([$key]);$model=$models[0]??null;
            if(!is_array($model))return null;
            $this->obs->atomicJsonWrite($path,['sequence'=>$sequence,'model'=>$model]);
            return $model;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /**
     * Capture the causal projection watermark for a committed Public mutation while still
     * inside the Local business transaction. The returned requirements are persisted with
     * the canonical realtime receipt/result, so retries reuse the exact same watermark.
     *
     * @param list<string> $modelKeys
     * @return list<array{model_key:string,min_sequence:int}>
     */
    public function captureVisibilityRequirements(array $modelKeys): array
    {
        $known=array_fill_keys($this->builder->remoteModelKeys(),true);$keys=[];
        foreach(array_values(array_unique(array_map('strval',$modelKeys))) as $key){$key=trim($key);if($key!==''&&isset($known[$key]))$keys[]=$key;}
        if($keys===[])return [];
        $targets=$this->pendingProjectionTargets($keys);$requirements=[];
        foreach($keys as $key){$seq=max(0,(int)($targets[$key]??0));if($seq>0)$requirements[]=['model_key'=>$key,'min_sequence'=>$seq];}
        return $requirements;
    }

    public function committedTableStates(array $payload,array $result,array $requirements): ?array
    {
        foreach($requirements as $r)if(($r['model_key']??'')==='operations')return $this->builder->committedTableStates($payload,$result,(int)($r['min_sequence']??0));
        return null;
    }

    public function applyRealtimeProjectionResults(array $batch,array $results): array
    {
        $items=is_array($batch['items']??null)?$batch['items']:[];$byKey=[];
        foreach($results as $result)if(is_array($result)){ $key=trim((string)($result['model_key']??''));if($key!=='')$byKey[$key]=$result; }
        $applied=[];$failed=[];
        foreach($items as $item){if(!is_array($item))continue;$model=is_array($item['model']??null)?$item['model']:[];$key=trim((string)($model['model_key']??''));$seq=max(0,(int)($item['up_to_sequence']??0));if($key===''||$seq<1)continue;$result=$byKey[$key]??null;if(!is_array($result))continue;
            $ok=($result['ok']??false)===true;$ack=max(0,(int)($result['ack_sequence']??0));
            if($ok&&$ack>=$seq){$version=(string)($model['source_version']??'');$this->recordProjectionModelSuccess($key,$ack,$version);$applied[$key]=['ack_sequence'=>$ack,'source_version'=>$version];}
            else{$code=trim((string)($result['error']??'projection_fastpath_failed'))?:'projection_fastpath_failed';$this->recordProjectionModelFailure($key,$code);$failed[$key]=$code;}
        }
        $delivered=$this->markFullyAckedProjectionRows(250);
        return ['applied'=>$applied,'failed'=>$failed,'delivered_events'=>$delivered];
    }

    private function markFullyAckedProjectionRows(int $limit=250): int
    {
        $limit=max(1,min(1000,$limit));try{$rows=$this->pdo->query("SELECT sequence_id,model_keys FROM public_projection_outbox WHERE delivered_at IS NULL ORDER BY sequence_id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return 0;}
        if($rows===[])return 0;$keys=[];foreach($rows as $row)foreach(explode(',',(string)$row['model_keys']) as $key){$key=trim($key);if($key!=='')$keys[$key]=true;}
        $state=$this->projectionDeliveryState(array_keys($keys));$deliver=[];
        foreach($rows as $row){$seq=(int)$row['sequence_id'];$ok=true;foreach(explode(',',(string)$row['model_keys']) as $key){$key=trim($key);if($key!==''&&(int)($state[$key]['ack_sequence']??0)<$seq){$ok=false;break;}}if($ok)$deliver[]=$seq;}
        return $this->markProjectionRowsDelivered($deliver);
    }


    /**
     * Return the newest pending invalidation sequence independently for each model key.
     * The number of queries is bounded by remote model count (currently small) and, unlike
     * prefix scanning, cannot starve a critical model behind unrelated older invalidations.
     *
     * @param list<string> $keys
     * @return array<string,int>
     */
    private function pendingProjectionTargets(array $keys): array
    {
        $keys=array_values(array_unique(array_filter(array_map(static fn($v):string=>trim((string)$v),$keys),static fn(string $v):bool=>$v!=='')));
        if($keys===[])return [];
        // H322/REV-21 steady state: O(number of requested models), independent of outbox backlog.
        // The migration backfills this monotonic watermark and an AFTER INSERT trigger advances it.
        try{
            $ph=implode(',',array_fill(0,count($keys),'?'));$q=$this->pdo->prepare("SELECT model_key,last_sequence FROM public_projection_model_targets WHERE model_key IN ($ph)");$q->execute($keys);$targets=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$key=(string)($row['model_key']??'');$seq=max(0,(int)($row['last_sequence']??0));if($key!==''&&$seq>0)$targets[$key]=$seq;}
            return $targets;
        }catch(Throwable){
            // Staggered pre-H322 database compatibility only. Once migration 0062 is applied,
            // production no longer performs this backlog-wide FIND_IN_SET aggregate scan.
            $parts=[];$params=[];
            foreach($keys as $i=>$key){$parts[]='MAX(CASE WHEN FIND_IN_SET(?,model_keys)>0 THEN sequence_id ELSE 0 END) AS m'.$i;$params[]=$key;}
            $q=$this->pdo->prepare('SELECT '.implode(',',$parts).' FROM public_projection_outbox WHERE delivered_at IS NULL');
            $q->execute($params);$row=$q->fetch(PDO::FETCH_NUM)?:[];$targets=[];
            foreach($keys as $i=>$key){$seq=(int)($row[$i]??0);if($seq>0)$targets[$key]=$seq;}
            return $targets;
        }
    }

    /** @param list<string> $keys @return array<string,array{ack_sequence:int,source_version:string,last_success_at:string}> */
    private function projectionDeliveryState(array $keys): array
    {
        if($keys===[])return [];$ph=implode(',',array_fill(0,count($keys),'?'));$q=$this->pdo->prepare("SELECT model_key,ack_sequence,source_version,last_success_at FROM public_projection_model_delivery WHERE model_key IN ($ph)");$q->execute($keys);$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$out[(string)$r['model_key']]=['ack_sequence'=>(int)$r['ack_sequence'],'source_version'=>(string)($r['source_version']??''),'last_success_at'=>(string)($r['last_success_at']??'')];return $out;
    }
    private function touchProjectionModelAttempt(string $key): void{$q=$this->pdo->prepare('INSERT INTO public_projection_model_delivery(model_key,last_attempt_at) VALUES(?,NOW(6)) ON DUPLICATE KEY UPDATE last_attempt_at=NOW(6)');$q->execute([$key]);}
    private function recordProjectionModelSuccess(string $key,int $seq,string $version): void{$q=$this->pdo->prepare("INSERT INTO public_projection_model_delivery(model_key,ack_sequence,source_version,last_attempt_at,last_success_at,last_error_code) VALUES(?,?,?,NOW(6),NOW(6),NULL) ON DUPLICATE KEY UPDATE ack_sequence=GREATEST(ack_sequence,VALUES(ack_sequence)),source_version=VALUES(source_version),last_attempt_at=NOW(6),last_success_at=NOW(6),last_error_code=NULL");$q->execute([$key,$seq,$version]);}
    private function recordProjectionModelFailure(string $key,string $code): void{$q=$this->pdo->prepare('INSERT INTO public_projection_model_delivery(model_key,last_attempt_at,last_error_code) VALUES(?,NOW(6),?) ON DUPLICATE KEY UPDATE last_attempt_at=NOW(6),last_error_code=VALUES(last_error_code)');$q->execute([$key,substr($code,0,80)]);}
    /** @param list<int> $ids */
    private function markProjectionRowsDelivered(array $ids): int
    {if($ids===[])return 0;$ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $v):bool=>$v>0)));if($ids===[])return 0;$ph=implode(',',array_fill(0,count($ids),'?'));$q=$this->pdo->prepare("UPDATE public_projection_outbox SET delivered_at=COALESCE(delivered_at,NOW(6)) WHERE delivered_at IS NULL AND sequence_id IN ($ph)");$q->execute($ids);$done=$q->rowCount();$max=max($ids);$pruneBefore=max(0,$max-5000);if($pruneBefore>0){$prune=$this->pdo->prepare('DELETE FROM public_projection_outbox WHERE delivered_at IS NOT NULL AND sequence_id<?');$prune->execute([$pruneBefore]);}return $done;}

    /** Transport heartbeat payload shared by exchange v2 and legacy heartbeat. */
    public function transportHeartbeatPayload(): array{return $this->heartbeatPayload();}

    /**
     * Heartbeat is piggy-backed on the one-second exchange, but only when the previous
     * liveness write is old enough. This keeps command pickup fast without turning the
     * Public heartbeat table into a one-write-per-second hot row.
     */
    public function transportHeartbeatPayloadIfDue(int $minimumAgeSeconds=15): array
    {
        $minimumAgeSeconds=max(5,min(60,$minimumAgeSeconds));
        try{
            $q=$this->pdo->query("SELECT last_success_at FROM public_sync_state WHERE channel='heartbeat' LIMIT 1");
            $last=trim((string)($q->fetchColumn()?:''));
            if($last!==''){
                $age=time()-(int)(\Sokna\Shared\IranTime::epoch($last,'UTC')?:0);
                if($age>=0&&$age<$minimumAgeSeconds)return [];
            }
        }catch(Throwable){}
        return $this->heartbeatPayload();
    }

    public function markHeartbeatExchangeSuccess(array $heartbeat,array $response=[]): void
    {
        if($heartbeat===[])return;
        $this->record('heartbeat','ok',PublicProjectionBuilder::hash($heartbeat),200,['transport_protocol'=>2,'exchange'=>true,'server_time'=>(string)($response['server_time']??'')]);
    }

    /**
     * Publish only the Local liveness heartbeat.
     * This is intentionally cheap enough for the 5-second relay cadence and
     * keeps live connectivity independent from the heavier 30-second projection sync.
     */
    public function syncHeartbeat(int $transportTimeoutSeconds=10): array
    {
        $supportId=substr($this->obs->correlationId(),0,12);
        if(!$this->client->configured())return ['success'=>false,'configured'=>false,'skipped'=>true,'support_id'=>$supportId];
        $result=$this->sendHeartbeat($supportId,$transportTimeoutSeconds);
        return ['success'=>(bool)($result['ok']??false),'configured'=>true,'support_id'=>$supportId,'channel'=>$result];
    }

    /**
     * Runtime 1.0.3 polls the reverse relay at one-second cadence for low command latency.
     * The liveness heartbeat does not need that cadence: keep it at the Local contract's
     * five-second interval so the fast relay lane does not create avoidable outbound HTTP
     * traffic and public_sync_state writes.
     */
    public function syncHeartbeatIfDue(int $minimumAgeSeconds=5,int $transportTimeoutSeconds=10): array
    {
        $minimumAgeSeconds=max(1,min(60,$minimumAgeSeconds));
        if(!$this->client->configured())return ['success'=>false,'configured'=>false,'skipped'=>true,'reason'=>'not_configured'];
        try{
            $q=$this->pdo->query("SELECT last_success_at FROM public_sync_state WHERE channel='heartbeat' LIMIT 1");
            $last=trim((string)($q->fetchColumn()?:''));
            if($last!==''){
                $age=time()-(int)(\Sokna\Shared\IranTime::epoch($last,'UTC')?:0);
                if($age>=0&&$age<$minimumAgeSeconds)return ['success'=>true,'configured'=>true,'skipped'=>true,'reason'=>'heartbeat_fresh','age_seconds'=>$age];
            }
        }catch(Throwable){
            // A missing/old sync-state table must not block the heartbeat itself.
        }
        return $this->syncHeartbeat($transportTimeoutSeconds);
    }

    /** Lane-scoped circuit breaker: realtime, projection and deferred failures never block each other. */
    public function transportLanePermit(string $lane): array
    {
        $lane=$this->normalizeTransportLane($lane);$channel='runtime_transport_'.$lane;
        try{
            $q=$this->pdo->prepare('SELECT status,detail_json FROM public_sync_state WHERE channel=? LIMIT 1');$q->execute([$channel]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||(string)($row['status']??'')!=='error')return ['active'=>false,'lane'=>$lane,'state'=>'closed'];
            $detail=json_decode((string)($row['detail_json']??''),true);if(!is_array($detail))$detail=[];
            $until=self::transportUtcTimestamp($detail['open_until']??null);
            if($until>time())return ['active'=>true,'lane'=>$lane,'state'=>'open','retry_after_seconds'=>max(1,$until-time()),'consecutive_failures'=>(int)($detail['consecutive_failures']??0),'failure_class'=>(string)($detail['failure_class']??'transport'),'error_code'=>(string)($detail['code']??'public_unreachable'),'detail_code'=>(string)($detail['detail_code']??'transport_failure'),'retry_budget_remaining'=>(int)($detail['retry_budget_remaining']??0)];
            // ARCH-06: exactly one process/HTTP request may probe a half-open lane. MySQL named
            // locks survive only for the owning DB connection and are released automatically on
            // connection loss, avoiding a stale application semaphore after a crash.
            $lock=$this->transportProbeLockName($lane);$l=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$l->execute([$lock]);$acquired=(int)($l->fetchColumn()?:0)===1;
            if(!$acquired)return ['active'=>true,'lane'=>$lane,'state'=>'half_open_probe_in_flight','retry_after_seconds'=>1,'consecutive_failures'=>(int)($detail['consecutive_failures']??0),'failure_class'=>(string)($detail['failure_class']??'transport')];
            return ['active'=>false,'lane'=>$lane,'state'=>'half_open','probe_acquired'=>true,'consecutive_failures'=>(int)($detail['consecutive_failures']??0),'failure_class'=>(string)($detail['failure_class']??'transport'),'retry_budget_remaining'=>(int)($detail['retry_budget_remaining']??0)];
        }catch(Throwable){return ['active'=>false,'lane'=>$lane,'state'=>'closed'];}
    }

    public function recordTransportLaneFailure(string $lane,PublicEdgeSyncException $e): void
    {
        $lane=$this->normalizeTransportLane($lane);$channel='runtime_transport_'.$lane;
        try{
            $class=$e->failureClass!==''?$e->failureClass:$this->classifyTransportFailure($e);
            if(!in_array($class,['transport','overload','authentication','compatibility'],true))return;
            $previous=0;$windowStarted=time();$attempts=0;
            try{$q=$this->pdo->prepare('SELECT detail_json FROM public_sync_state WHERE channel=? LIMIT 1');$q->execute([$channel]);$detail=json_decode((string)($q->fetchColumn()?:''),true);if(is_array($detail)){$previous=(int)($detail['consecutive_failures']??0);$parsed=self::transportUtcTimestamp($detail['retry_window_started_at']??null);if($parsed>0&&time()>=$parsed&&time()-$parsed<60){$windowStarted=$parsed;$attempts=(int)($detail['retry_attempts']??0);}}}catch(Throwable){}
            $failures=$previous+1;$attempts++;
            $maxAttempts=6;$remaining=max(0,$maxAttempts-$attempts);
            if(in_array($class,['authentication','compatibility'],true)){
                $base=$class==='authentication'?120:60;$backoff=$this->jitteredBackoffSeconds($lane,$failures,$base,$e->retryAfterSeconds,300);
            }else{
                $base=min(60,1*(2**min(5,max(0,$failures-1))));$backoff=$this->jitteredBackoffSeconds($lane,$failures,$base,$e->retryAfterSeconds,120);
                if($remaining===0)$backoff=max($backoff,max(1,60-(time()-$windowStarted)));
            }
            $openUntil=gmdate('Y-m-d H:i:s',time()+max(1,$backoff));
            $this->record($channel,'error',$this->localVersion,$e->httpStatus,[
                'code'=>$e->errorCode!==''?$e->errorCode:'public_sync_failed','detail_code'=>$e->detailCode!==''?$e->detailCode:'transport_failure','automatic'=>true,'lane'=>$lane,
                'failure_class'=>$class,'consecutive_failures'=>$failures,'backoff_seconds'=>$backoff,'retry_after_seconds'=>$e->retryAfterSeconds,
                'retry_window_started_at'=>gmdate('Y-m-d H:i:s',$windowStarted),'retry_attempts'=>$attempts,'retry_budget_max'=>$maxAttempts,'retry_budget_remaining'=>$remaining,'open_until'=>$openUntil,
            ]);
        }finally{$this->releaseTransportProbeLock($lane);}
    }

    public function clearTransportLaneFailure(string $lane): void
    {
        $lane=$this->normalizeTransportLane($lane);$channel='runtime_transport_'.$lane;
        try{$q=$this->pdo->prepare('SELECT status FROM public_sync_state WHERE channel=? LIMIT 1');$q->execute([$channel]);if((string)($q->fetchColumn()?:'')==='error')$this->record($channel,'ok',$this->localVersion,200,['code'=>'','automatic'=>true,'lane'=>$lane,'recovered'=>true,'consecutive_failures'=>0,'retry_attempts'=>0,'retry_budget_remaining'=>6]);}catch(Throwable){}finally{$this->releaseTransportProbeLock($lane);}
    }

    /** Persisted transport timestamps use gmdate, never relative or local wall-clock input. */
    private static function transportUtcTimestamp(mixed $value): int
    {
        if(!is_string($value))return 0;
        $text=trim($value);
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$text))return 0;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$text,new \DateTimeZone('UTC'));
        if($date===false||$date->format('Y-m-d H:i:s')!==$text)return 0;
        return $date->getTimestamp();
    }

    private function normalizeTransportLane(string $lane): string{return preg_replace('/[^a-z0-9_]+/i','_',strtolower(trim($lane)))?:'default';}
    private function transportProbeLockName(string $lane): string{return 'sokna_pub_probe_'.substr(hash('sha256',$lane),0,32);}
    private function releaseTransportProbeLock(string $lane): void{try{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$this->transportProbeLockName($lane)]);}catch(Throwable){}}
    private function classifyTransportFailure(PublicEdgeSyncException $e): string
    {
        if($e->errorCode==='public_unreachable')return 'transport';
        if(in_array($e->httpStatus,[429,502,503,504],true))return 'overload';
        if(in_array($e->httpStatus,[401,403],true))return 'authentication';
        if($e->httpStatus===426)return 'compatibility';
        return 'domain';
    }
    private function jitteredBackoffSeconds(string $lane,int $failures,int $base,int $retryAfter,int $cap): int
    {
        $base=max(1,min($cap,max($base,min($cap,$retryAfter))));
        $window=max(1,min(10,(int)ceil($base*0.25)));
        $jitter=random_int(0,$window);
        return min($cap,$base+$jitter);
    }

    /** Backward-compatible wrappers retained for older callers. */
    public function runtimeTransportBackoff(int $windowSeconds=15): array{return $this->transportLanePermit('legacy');}
    public function recordRuntimeTransportFailure(PublicEdgeSyncException $e): void{$this->recordTransportLaneFailure('legacy',$e);}
    public function clearRuntimeTransportFailure(): void{$this->clearTransportLaneFailure('legacy');}

    public function rotateEmergencyCode(): array
    {
        $plain=strtoupper(implode('-',str_split(bin2hex(random_bytes(12)),8)));$hash=password_hash($plain,PASSWORD_DEFAULT);$r=$this->client->post('/api/v1/local/emergency/access',['password_hash'=>$hash]);$this->record('emergency_access','ok',hash('sha256',$hash),(int)$r['status'],['rotated'=>true]);$this->obs->logEvent('warning','public.emergency_access_rotated',['origin'=>$this->client->safeOrigin()]);return ['emergency_code'=>$plain,'emergency_url'=>$this->client->publicBaseUrl().'/emergency.php'];
    }
    public function updateStatus(int $timeoutSeconds=10): array
    {
        $body=$this->client->post('/api/v1/local/update/status',[],$timeoutSeconds)['body'];
        $this->cacheUpdateControl($body);
        return $body;
    }

    public function supportDiagnostics(int $timeoutSeconds=10): array
    {
        $r=$this->client->diagnostics($timeoutSeconds);return is_array($r['body']??null)?(array)$r['body']:[];
    }

    /**
     * Stage a Public package. Routine v2 uses bounded resumable chunks; the
     * legacy one-shot Base64 path remains only for the one-time .35 bridge and
     * Emergency/full-snapshot fallback on older Public installations.
     */
    public function stageUpdate(string $zipPath): array
    {
        if(!is_file($zipPath))throw new PublicEdgeSyncException('public_package_missing','بسته Public پیدا نشد.',404);
        $size=(int)filesize($zipPath);if($size<=0||$size>67108864)throw new PublicEdgeSyncException('public_package_too_large','حجم بسته Public بیش از حد مجاز است.',413);
        $status=$this->updateStatus();
        if($this->routineUpdateSupported($status))return $this->stageRoutineUpdate($zipPath,$size);

        // Legacy bridge/fallback. Intentionally isolated so routine updates
        // never regress to loading a complete package into memory.
        $bytes=file_get_contents($zipPath);if(!is_string($bytes))throw new PublicEdgeSyncException('public_package_read_failed','خواندن بسته Public انجام نشد.',500);
        $r=$this->client->post('/api/v1/local/update/stage',['package_base64'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes)]);
        $body=(array)($r['body']??[]);$this->cacheUpdateControl($body);
        $this->obs->logEvent('warning','public.update_staged',['size_bytes'=>$size,'protocol'=>'legacy_full_v1','bridge_or_fallback'=>true]);return $body;
    }

    public function activateUpdate(): array
    {
        $status=$this->updateStatus();
        if(!$this->routineUpdateSupported($status)){
            $body=$this->client->post('/api/v1/local/update/activate',[])['body'];$this->cacheUpdateControl((array)$body);return $body;
        }
        $routine=is_array($status['routine_update']??null)?$status['routine_update']:[];$staged=is_array($routine['staged']??null)?$routine['staged']:[];$target=trim((string)($staged['version']??''));
        if($target===''){
            // A legacy bridge can still be staged on a v2-capable Public. If
            // routine has nothing staged, preserve the explicit legacy path.
            $legacy=is_array($status['update']??null)?$status['update']:[];if(is_array($legacy['staged']??null)){
                $body=$this->client->post('/api/v1/local/update/activate',[])['body'];$this->cacheUpdateControl((array)$body);return $body;
            }
            throw new PublicEdgeSyncException('public_nothing_staged','هیچ بسته Public برای فعال‌سازی آماده نیست.',409);
        }
        $activated=[];
        try{
            $activated=$this->client->post('/api/v1/local/update/v2/activate',[])['body'];
            // Pre-commit health proves target identity while Public is still pending. The target
            // routine updater itself owns schema migration atomically inside commitActivation().
            $health=$this->client->health();$body=is_array($health['body']??null)?$health['body']:[];
            if(($body['component']??'')!=='public-edge'||trim((string)($body['version']??''))!==$target||(int)($body['update_protocol']??0)!==2){
                throw new PublicEdgeSyncException('public_post_activate_identity_mismatch','نسخه فعال‌شده Public با بسته مورد انتظار تطابق ندارد.',502);
            }
            $pending=(array)($body['routine_update']??[]);if(empty($pending['pending_verification'])||trim((string)($pending['pending_version']??''))!==$target){
                throw new PublicEdgeSyncException('public_post_activate_verification_missing','Public وضعیت انتظار برای تأیید نسخه جدید را گزارش نکرد.',502);
            }
            $committed=$this->client->post('/api/v1/local/update/v2/commit',[])['body'];
            // Commit must not be considered successful until the new Public process reports an
            // exact schema/catalog match and no pending verification. This closes source/schema drift.
            $postHealth=$this->client->health();$post=is_array($postHealth['body']??null)?$postHealth['body']:[];$postRoutine=(array)($post['routine_update']??[]);
            if(($post['component']??'')!=='public-edge'||trim((string)($post['version']??''))!==$target||(int)($post['pending_migrations']??-1)!==0||(int)($post['catalog_migrations']??0)!==(int)($post['applied_migrations']??-1)||!empty($postRoutine['pending_verification'])||trim((string)($postRoutine['active_version']??''))!==$target){
                throw new PublicEdgeSyncException('public_post_commit_schema_mismatch','نسخه Public فعال شد اما تطابق کامل ساختار داده و نسخه تأیید نشد.',502);
            }
            // Keep passive Update/Connections surfaces aligned with the committed remote truth
            // without requiring another WAN probe on page load.
            $this->cacheUpdateControl($post);
            $this->obs->logEvent('warning','public.update_activated',['target_version'=>$target,'protocol'=>'routine_delta_v2','post_activate_verified'=>true,'schema_verified'=>true]);
            return $committed+['activation'=>$activated,'verified_health'=>$post,'precommit_health'=>$body];
        }catch(Throwable $e){
            try{$this->client->post('/api/v1/local/update/v2/rollback',[]);}catch(Throwable $rollbackError){
                $this->obs->logEvent('error','public.update_auto_rollback_failed',$this->updateFailureEvidence($target,$rollbackError));
            }
            $this->obs->logEvent('error','public.update_post_activate_failed',$this->updateFailureEvidence($target,$e));
            if($e instanceof PublicEdgeSyncException)throw $e;
            throw new PublicEdgeSyncException('public_post_activate_failed','بررسی نسخه جدید Public ناموفق بود و بازگشت خودکار درخواست شد.',502);
        }
    }

    private function updateFailureEvidence(string $target,Throwable $e): array
    {
        $out=['target_version'=>$target,'error'=>get_class($e)];
        if($e instanceof PublicEdgeSyncException){
            $out['error_code']=$e->errorCode;$out['detail_code']=$e->detailCode;$out['failure_class']=$e->failureClass;$out['http_status']=$e->httpStatus;
            if($e->context!==[])$out['context']=$e->context;
        }
        return $out;
    }

    /** Emergency/full-snapshot repair intentionally remains a legacy fallback. */
    public function repairUpdate(): array
    {
        $body=$this->client->post('/api/v1/local/update/repair',[])['body'];$this->cacheUpdateControl((array)$body);return $body;
    }

    public function rollbackUpdate(): array
    {
        $status=$this->updateStatus();$routine=is_array($status['routine_update']??null)?$status['routine_update']:[];
        if($this->routineUpdateSupported($status)&&(is_array($routine['pending_verification']??null)||trim((string)($routine['lkg_recovery_id']??''))!==''))return $this->client->post('/api/v1/local/update/v2/rollback',[])['body'];
        return $this->client->post('/api/v1/local/update/rollback',[])['body'];
    }

    private function routineUpdateSupported(array $status): bool{return !empty(((array)($status['protocols']??[]))['routine_delta_v2']);}

    private function stageRoutineUpdate(string $zipPath,int $size): array
    {
        $packageSha=hash_file('sha256',$zipPath);if(!is_string($packageSha))throw new PublicEdgeSyncException('public_package_read_failed','محاسبه هش بسته Public انجام نشد.',500);
        $start=$this->client->post('/api/v1/local/update/v2/upload/start',['sha256'=>$packageSha,'size_bytes'=>$size])['body'];
        $upload=(array)($start['upload']??[]);$uploadId=(string)($upload['upload_id']??'');$offset=max(0,(int)($upload['received_bytes']??0));if($uploadId===''||$offset>$size)throw new PublicEdgeSyncException('public_upload_state_invalid','وضعیت بارگذاری بسته Public معتبر نیست.',502);
        $fh=fopen($zipPath,'rb');if(!is_resource($fh))throw new PublicEdgeSyncException('public_package_read_failed','خواندن بسته Public انجام نشد.',500);
        try{
            if($offset>0&&fseek($fh,$offset)!==0)throw new PublicEdgeSyncException('public_package_seek_failed','ادامه بارگذاری بسته Public ممکن نشد.',500);
            $chunkSize=393216; // 384 KiB raw -> bounded JSON/Base64 request.
            while($offset<$size){$bytes=fread($fh,min($chunkSize,$size-$offset));if(!is_string($bytes)||$bytes==='')throw new PublicEdgeSyncException('public_package_read_failed','خواندن بخشی از بسته Public انجام نشد.',500);
                $r=$this->client->post('/api/v1/local/update/v2/upload/chunk',['upload_id'=>$uploadId,'offset'=>$offset,'chunk_base64'=>base64_encode($bytes),'chunk_sha256'=>hash('sha256',$bytes)])['body'];
                $server=(array)($r['upload']??[]);$next=(int)($server['received_bytes']??-1);if($next<$offset+strlen($bytes)||$next>$size)throw new PublicEdgeSyncException('public_upload_offset_invalid','Public Edge offset ادامه بارگذاری را نادرست گزارش کرد.',502);$offset=$next;
                if($offset<$size&&fseek($fh,$offset)!==0)throw new PublicEdgeSyncException('public_package_seek_failed','ادامه بارگذاری بسته Public ممکن نشد.',500);
            }
        }finally{fclose($fh);}
        $finish=$this->client->post('/api/v1/local/update/v2/upload/finish',['upload_id'=>$uploadId])['body'];
        $this->cacheUpdateControl((array)$finish);
        $this->obs->logEvent('warning','public.update_staged',['size_bytes'=>$size,'protocol'=>'routine_delta_v2','resumable'=>true,'package_sha256'=>$packageSha]);return $finish;
    }

    private function syncGuestMedia(array $guest,string $supportId,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        $manifest=is_array($guest['media_manifest']??null)?$guest['media_manifest']:[];
        $version=PublicProjectionBuilder::hash($manifest);
        if($manifest===[]){$detail=['ok'=>true,'complete'=>true,'uploaded'=>0,'manifest_count'=>0,'missing_count'=>0,'support_id'=>$supportId];$this->record('guest_media','ok',$version,200,$detail);return $detail;}
        try{
            $uploadManifest=$manifest;$remoteMissing=null;$statusSupported=false;
            $probe=$this->client->postRaw('/api/v1/local/guest/media/status',['media_manifest'=>$manifest],$transportTimeoutSeconds,$deadlineEpochMs);
            $probeBody=is_array($probe['body']??null)?$probe['body']:[];
            if((int)($probe['status']??0)>=200&&(int)($probe['status']??0)<300&&!empty($probeBody['ok'])){
                $statusSupported=true;$remoteMissing=[];
                foreach((array)($probeBody['missing']??[]) as $entry){if(is_array($entry)&&preg_match('/^[a-f0-9]{64}$/D',(string)($entry['sha256']??'')))$remoteMissing[(string)$entry['sha256']]=true;}
                if($remoteMissing===[]){$detail=['ok'=>true,'complete'=>true,'uploaded'=>0,'manifest_count'=>count($manifest),'missing_count'=>0,'deduplicated'=>count($manifest),'support_id'=>$supportId];$this->record('guest_media','ok',$version,200,$detail);return $detail;}
                $uploadManifest=array_filter($manifest,static fn($meta):bool=>is_array($meta)&&isset($remoteMissing[(string)($meta['sha256']??'')]));
            }elseif(!in_array((int)($probe['status']??0),[404,405],true)&&(string)($probeBody['error']??'')!=='route_not_found'){
                throw new PublicEdgeSyncException((string)($probeBody['error']??'media_status_failed'),'وضعیت تصاویر وب عمومی دریافت نشد.',(int)($probe['status']??502)?:502);
            }

            // Keep one manual/runtime iteration bounded. Later iterations resume only the hashes
            // still missing, preventing a large image library from blocking critical sync/ordering.
            $maxFiles=$statusSupported?8:PHP_INT_MAX;$maxBytes=$statusSupported?4*1024*1024:PHP_INT_MAX;$selected=[];$selectedBytes=0;$selectedFiles=0;
            foreach($uploadManifest as $source=>$meta){
                if(!is_array($meta))continue;$size=max(0,(int)($meta['size']??0));
                if($selectedFiles>0&&($selectedFiles>=$maxFiles||$selectedBytes+$size>$maxBytes))break;
                $selected[$source]=$meta;$selectedFiles++;$selectedBytes+=$size;
            }
            $payloads=$this->builder->guestMediaPayloads($selected);$uploaded=0;
            foreach($payloads as $payload){if(!$this->deadlineAvailable($deadlineEpochMs,100))break;$this->client->post('/api/v1/local/guest/media',$payload,$transportTimeoutSeconds,$deadlineEpochMs);$uploaded++;}
            $initialMissing=$statusSupported?count($remoteMissing??[]):count($uploadManifest);
            $remaining=max(0,$initialMissing-$uploaded);
            $complete=!$statusSupported?($uploaded===count($uploadManifest)):$remaining===0;
            $detail=['ok'=>true,'complete'=>$complete,'uploaded'=>$uploaded,'manifest_count'=>count($manifest),'missing_count'=>$remaining,'support_id'=>$supportId,'status_probe'=>$statusSupported];
            // Only mark the exact manifest version as delivered when Public has all replicas.
            $this->record('guest_media','ok',$complete?$version:null,200,$detail);
            return $detail;
        }catch(Throwable $e){
            $failure=$this->recordFailure('guest_media',$version,$e,$supportId);
            return ['ok'=>false,'complete'=>false,'code'=>$failure->errorCode,'message'=>$failure->getMessage(),'support_id'=>$supportId];
        }
    }

    private function publishGuestRevision(array $guest,string $supportId,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        $contentHash=(string)($guest['content_hash']??'');$revision=(string)($guest['revision_id']??'');
        try{
            $r=$this->client->post('/api/v1/local/guest/publish',$guest,$transportTimeoutSeconds,$deadlineEpochMs);$body=(array)$r['body'];
            if(!hash_equals($revision,(string)($body['revision_id']??''))||empty($body['active']))throw new PublicEdgeSyncException('guest_publish_ack_mismatch','وب عمومی تأیید نسخه منتشرشده را برنگرداند.',502);
            $detail=$body+['support_id'=>$supportId];$this->record('guest_publish','ok',$contentHash,(int)$r['status'],$detail);
            return ['ok'=>true]+$body;
        }catch(Throwable $e){$failure=$this->recordFailure('guest_publish',$contentHash,$e,$supportId);return ['ok'=>false,'code'=>$failure->errorCode,'message'=>$failure->getMessage(),'support_id'=>$supportId];}
    }

    private function sendHeartbeat(string $supportId,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        $heartbeat=$this->heartbeatPayload();
        return $this->sendChannelSafe('heartbeat',PublicProjectionBuilder::hash($heartbeat),'/api/v1/local/heartbeat',$heartbeat,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
    }

    private function sendChannelIfChanged(string $channel,string $version,string $path,array $payload,string $supportId,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        try{
            $q=$this->pdo->prepare('SELECT status,source_version FROM public_sync_state WHERE channel=? LIMIT 1');$q->execute([$channel]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($row)&&(string)($row['status']??'')==='ok'&&hash_equals($version,(string)($row['source_version']??''))){
                return ['ok'=>true,'skipped'=>true,'reason'=>'unchanged','source_version'=>$version];
            }
        }catch(Throwable){}
        return $this->sendChannelSafe($channel,$version,$path,$payload,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);
    }

    private function sendChannelSafe(string $channel,string $version,string $path,array $payload,string $supportId,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        try{return ['ok'=>true]+$this->sendChannel($channel,$version,$path,$payload,$supportId,$transportTimeoutSeconds,$deadlineEpochMs);}
        catch(PublicEdgeSyncException $e){return ['ok'=>false,'code'=>$e->errorCode,'detail_code'=>$e->detailCode,'failure_class'=>$e->failureClass,'retry_after_seconds'=>$e->retryAfterSeconds,'message'=>$e->getMessage(),'support_id'=>$supportId];}
    }

    private function sendChannel(string $channel,string $version,string $path,array $payload,string $supportId,int $transportTimeoutSeconds=10,?int $deadlineEpochMs=null): array
    {
        try{$r=$this->client->post($path,$payload,$transportTimeoutSeconds,$deadlineEpochMs);$body=(array)$r['body'];$this->record($channel,'ok',$version,(int)$r['status'],$body+['support_id'=>$supportId]);return $body;}
        catch(Throwable $e){throw $this->recordFailure($channel,$version,$e,$supportId);}
    }


    private function isRetryableTransportResult(array $result): bool
    {
        $class=(string)($result['failure_class']??'');
        if(in_array($class,['transport','overload'],true))return true;
        return (string)($result['code']??'')==='public_unreachable';
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

    private function deadlineProjectionResult(array $results,string $supportId): array
    {
        return ['success'=>false,'transport_unreachable'=>false,'deadline_exhausted'=>true,'failed_channels'=>[], 'channels'=>$results,'support_id'=>$supportId,'synced_at'=>gmdate('c')];
    }

    /** Persist Public update lifecycle truth locally without making passive diagnostics perform WAN I/O. */
    private function cacheUpdateControl(array $body): void
    {
        try{
            $path=$this->obs->runtimeDir().DIRECTORY_SEPARATOR.'public-update-control-cache.json';
            $existing=$this->obs->readJsonFile($path);$old=(array)($existing['status']??[]);
            $update=is_array($body['update']??null)?(array)$body['update']:(array)($old['update']??[]);
            $routine=is_array($body['routine_update']??null)?(array)$body['routine_update']:(array)($old['routine_update']??[]);
            $protocols=is_array($body['protocols']??null)?(array)$body['protocols']:(array)($old['protocols']??[]);
            $protocol=trim((string)($body['protocol']??''));$staged=is_array($body['staged']??null)?(array)$body['staged']:null;
            if($protocol==='routine_delta_v2')$protocols['routine_delta_v2']=true;
            if($protocol==='legacy_full_v1')$protocols['legacy_full_v1']=true;
            if($staged!==null){
                if($protocol==='routine_delta_v2')$routine['staged']=$staged;
                elseif($protocol==='legacy_full_v1')$update['staged']=$staged;
            }
            $safe=['update'=>$update,'routine_update'=>$routine,'protocols'=>$protocols];
            $this->obs->atomicJsonWrite($path,['format'=>'sokna-public-update-control-cache-v1','cached_at'=>gmdate('c'),'status'=>$this->obs->redact($safe)]);
        }catch(Throwable){}
    }

    private function recordFailure(string $channel,?string $version,Throwable $e,string $supportId): PublicEdgeSyncException
    {
        $known=$e instanceof PublicEdgeSyncException;$status=$known?$e->httpStatus:500;$code=$known?$e->errorCode:'public_'.$channel.'_internal';$detail=$known?$e->detailCode:'';
        $message=$this->channelFailureMessage($channel,$known?$e->getMessage():'');
        $detailPayload=['code'=>$code,'support_id'=>$supportId];if($detail!=='')$detailPayload['detail_code']=$detail;
        // Automatic heartbeat retries during an outage can happen every few seconds. Persist the
        // latest state every time, but do not grow the JSONL log with an identical line each tick.
        $emitLog=$channel!=='heartbeat'||$this->failureLogDue($channel,$code,$detail,60);
        $this->record($channel,'error',$version,$status,$detailPayload);
        if($emitLog){$log=['channel'=>$channel,'status'=>$status,'code'=>$code,'exception'=>get_class($e)];if($detail!=='')$log['detail_code']=$detail;$this->obs->logEvent('error','public.sync_failed',$log,$supportId);}
        return new PublicEdgeSyncException($code,$message,$status,$detail);
    }

    private function failureLogDue(string $channel,string $code,string $detail,int $windowSeconds=60): bool
    {
        $windowSeconds=max(5,min(300,$windowSeconds));
        try{
            $q=$this->pdo->prepare('SELECT status,detail_json,last_attempt_at FROM public_sync_state WHERE channel=? LIMIT 1');$q->execute([$channel]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||(string)($row['status']??'')!=='error')return true;
            $old=json_decode((string)($row['detail_json']??''),true);if(!is_array($old))$old=[];
            if((string)($old['code']??'')!==$code||(string)($old['detail_code']??'')!==$detail)return true;
            $at=(int)(strtotime((string)($row['last_attempt_at']??'').' UTC')?:0);if($at<=0)return true;
            return time()-$at>=$windowSeconds;
        }catch(Throwable){return true;}
    }

    private function channelFailureMessage(string $channel,string $fallback=''): string
    {
        return match($channel){
            'guest_media'=>'ارسال تصاویر منو به وب عمومی انجام نشد.',
            'guest_publish'=>'انتشار نسخه منو در وب عمومی انجام نشد.',
            'availability'=>'ارسال وضعیت موجودی منو به وب عمومی انجام نشد.',
            'installation'=>'ثبت مشخصات نصب در وب عمومی انجام نشد.',
            'auth'=>'همگام‌سازی دسترسی کارکنان با وب عمومی انجام نشد.',
            'read_models'=>'همگام‌سازی داده‌های راه‌دور انجام نشد.',
            'heartbeat'=>'ثبت وضعیت ارتباط وب عمومی انجام نشد.',
            default=>$fallback!==''?$fallback:'همگام‌سازی وب عمومی کامل نشد.',
        };
    }

    private function heartbeatPayload(): array
    {
        try{$runtime=RuntimeEvidence::heartbeatSnapshot($this->pdo);}catch(Throwable $e){$runtime=['status'=>'unavailable','last_seen_at'=>'','age_seconds'=>null,'recent_failed_count'=>0,'unresolved_failure'=>true];$this->obs->logEvent('warning','public.runtime_evidence_unavailable',['exception'=>get_class($e)]);}
        return ['local_version'=>$this->localVersion,'runtime_status'=>(string)($runtime['status']??'unavailable'),'telemetry'=>[
            'source'=>'local-web','synced_at'=>gmdate('c'),'runtime_last_seen_at'=>(string)($runtime['last_seen_at']??''),'runtime_age_seconds'=>$runtime['age_seconds']??null,
            'runtime_recent_failed_count'=>(int)($runtime['recent_failed_count']??0),'runtime_unresolved_failure'=>(bool)($runtime['unresolved_failure']??false),
            'projection_freshness'=>$this->projectionFreshnessTelemetry(),'lan_staff'=>$this->lanStaffTelemetry(),
        ]];
    }

    /**
     * ARCH-12: heartbeat carries model-specific replication intent, not model content.
     * Public can distinguish "unchanged but verified" from a genuinely dirty/stale model
     * without forcing identical snapshots to be resent merely to refresh last_sync_at.
     */
    private function projectionFreshnessTelemetry(): array
    {
        $keys=['operations','operations_workspace','preparation','inventory','inventory_cost','reports','subscribers','notifications','deferred_context','table_draft_context','guest_operational'];
        $complete=true;
        try{$targets=$this->pendingProjectionTargets($keys);}catch(Throwable){$targets=[];$complete=false;}
        try{$delivered=$this->projectionDeliveryState($keys);}catch(Throwable){$delivered=[];}
        $models=[];
        foreach($keys as $key){
            $target=max(0,(int)($targets[$key]??0));$ack=max(0,(int)($delivered[$key]['ack_sequence']??0));
            $models[$key]=['pending_target_sequence'=>$target,'last_acked_sequence'=>$ack,'dirty'=>$target>$ack];
        }
        return ['format'=>'sokna-projection-freshness-v1','checked_at'=>gmdate('c'),'complete'=>$complete,'models'=>$models];
    }

    private function authProjectionPayload(): array
    {
        $rows=$this->builder->authProjections();$lan=$this->lanStaffTelemetry();
        $enabled=($lan['enabled']??false)===true;$base=$enabled?rtrim(trim((string)($lan['base_url']??'')),'/'):'';
        if($base!==''){
            foreach($rows as &$row){
                if(!is_array($row))continue;$projection=trim((string)($row['projection_id']??''));
                $credential=$this->client->mintLanStaffToken($projection,43200);
                $row['lan_base_url']=$base;$row['lan_token']=(string)($credential['token']??'');$row['lan_token_expires_at']=(string)($credential['expires_at']??'');
            }
            unset($row);
        }
        return ['projections'=>$rows,'lan_staff'=>['enabled'=>$enabled,'base_url'=>$base]];
    }

    private function lanStaffTelemetry(): array
    {
        // H341-P3: config.php is the only product authority for Staff LAN routing.
        // Legacy lan-adaptive.json is intentionally ignored so a stale runtime file cannot
        // silently re-enable split-DNS/LAN behavior after an origin migration.
        $enabled=$this->config!==null&&(bool)$this->config->get('lan_staff.enabled',false);
        $base=$this->config!==null?rtrim(trim($this->config->string('lan_staff.base_url','')),'/'):'';
        if(!$enabled)return ['enabled'=>false,'configured'=>$base!=='' ,'source'=>'config','policy'=>'lan_preferred_public_fallback'];
        if($base==='')return ['enabled'=>false,'configured'=>false,'state_error'=>true,'error_code'=>'lan_endpoint_missing','source'=>'config'];
        $u=parse_url($base);
        if(!is_array($u)||strtolower((string)($u['scheme']??''))!=='https'||empty($u['host'])||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment']))
            return ['enabled'=>false,'configured'=>true,'state_error'=>true,'error_code'=>'lan_endpoint_invalid','source'=>'config'];
        $publicOrigin=$this->normalizedOrigin($this->client->safeOrigin());$lanOrigin=$this->normalizedOrigin($base);
        if($publicOrigin!==''&&$lanOrigin!==''&&hash_equals($publicOrigin,$lanOrigin))
            return ['enabled'=>false,'configured'=>true,'state_error'=>true,'error_code'=>'lan_endpoint_same_as_public','source'=>'config'];
        return ['enabled'=>true,'configured'=>true,'base_url'=>$base,'source'=>'config','policy'=>'lan_preferred_public_fallback','guest_policy'=>'public_first'];
    }

    private function normalizedOrigin(string $value): string
    {
        $p=parse_url(trim($value));if(!is_array($p))return '';$scheme=strtolower((string)($p['scheme']??''));$host=strtolower(trim((string)($p['host']??''),'[]'));if($scheme===''||$host==='')return '';$portNumber=isset($p['port'])?(int)$p['port']:null;$port=$portNumber!==null&&!(($scheme==='https'&&$portNumber===443)||($scheme==='http'&&$portNumber===80))?':'.$portNumber:'';$authority=str_contains($host,':')?'['.$host.']':$host;return $scheme.'://'.$authority.$port;
    }

    private function currentGuestError(array $by,array $expected,bool $includeMedia=false): ?array
    {
        $requirements=['guest_publish'=>$expected['content_hash'],'availability'=>$expected['availability_version']];
        if($includeMedia)$requirements=['guest_media'=>$expected['media_version']]+$requirements;
        foreach($requirements as $channel=>$version){$row=$by[$channel]??[];if((string)($row['status']??'')!=='error'||!hash_equals((string)$version,(string)($row['source_version']??'')))continue;$detail=is_array($row['detail']??null)?$row['detail']:[];return ['channel'=>$channel,'code'=>(string)($detail['code']??'public_sync_failed'),'support_id'=>(string)($detail['support_id']??''),'message'=>$this->channelFailureMessage($channel)];}
        return null;
    }

    private function currentMediaError(array $media,string $expectedVersion): ?array
    {
        if((string)($media['status']??'')!=='error'||!hash_equals($expectedVersion,(string)($media['source_version']??'')))return null;
        $detail=is_array($media['detail']??null)?$media['detail']:[];
        return ['channel'=>'guest_media','code'=>(string)($detail['code']??'public_sync_failed'),'support_id'=>(string)($detail['support_id']??''),'message'=>$this->channelFailureMessage('guest_media')];
    }

    private static function faNumber(int $value): string
    {
        return strtr((string)$value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    private function record(string $channel,string $status,?string $version,?int $http,array $detail): void
    {
        // source_version is CHAR(64). Long build labels are diagnostic metadata,
        // not a reason to lose the channel's recovery/failure write in strict SQL.
        if($version!==null&&strlen($version)>64){$detail['source_version_label']=$version;$version=hash('sha256',$version);}
        $q=$this->pdo->prepare('INSERT INTO public_sync_state(channel,source_version,status,last_http_status,detail_json,last_attempt_at,last_success_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),CASE WHEN ?=\'ok\' THEN UTC_TIMESTAMP() ELSE NULL END) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),status=VALUES(status),last_http_status=VALUES(last_http_status),detail_json=VALUES(detail_json),last_attempt_at=UTC_TIMESTAMP(),last_success_at=CASE WHEN VALUES(status)=\'ok\' THEN UTC_TIMESTAMP() ELSE last_success_at END');
        $q->execute([$channel,$version,$status,$http,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status]);
    }
}
