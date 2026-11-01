<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Remote;

use PDO;
use Sokna\PublicEdge\Connectivity\ConnectivityService;
use Sokna\PublicEdge\Core\CanonicalJson;
use Throwable;

final class RemoteReadModelService
{
    private const MODEL_CAPABILITIES = [
        'operations' => 'operations.read',
        'operations_workspace' => 'operations.read',
        'preparation' => 'preparation.read',
        'inventory' => 'inventory.read',
        'inventory_cost' => 'inventory.cost.read',
        'reports' => 'reports.read',
        'subscribers' => 'subscriber.read',
        'notifications' => 'notifications.read',
        'deferred_context' => 'deferred.context',
        'table_draft_context' => 'orders.table_draft',
    ];

    /** Internal-only projections accepted from signed Local sync but never exposed by Staff read API. */
    private const INTERNAL_MODEL_KEYS = ['guest_operational'];

    /** A09: bounded long-poll policy. Real host capacity still requires load UAT. */
    public const WATCH_MAX_WAIT_MS = 9000;
    public const WATCH_RECONNECT_MIN_MS = 100;
    public const WATCH_RECONNECT_MAX_MS = 250;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ConnectivityService $connectivity,
    ) {
    }

    public function sync(string $installationId, array $body): array
    {
        $installationId=trim($installationId);$models=$body['models']??null;
        if($installationId===''||!is_array($models))return $this->error(400,'invalid_read_model_payload');
        $this->pdo->beginTransaction();
        try{
            // Unsequenced v1 snapshots cannot prove freshness once a sequenced v2
            // model has been committed. Refuse instead of silently regressing it.
            $fence=$this->legacyModelV2Fence($installationId,$models,null);
            if($fence!==null){$this->pdo->rollBack();return $fence;}
            $stats=$this->syncModelsInTransaction($installationId,$models);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return ['status'=>200,'body'=>['ok'=>true]+$stats];
    }

    /**
     * Apply a signed monotonic Local projection event batch and the exact read-model snapshots
     * produced after those mutations. The cursor and model writes commit atomically on Public.
     * Delivery is at-least-once: duplicate/overlapping batches are safe; forward gaps fail closed.
     */
    public function syncEventBatch(string $installationId,array $body): array
    {
        $installationId=trim($installationId);
        if($installationId===''||(string)($body['format']??'')!=='sokna-projection-events-v1')return $this->error(400,'invalid_projection_event_payload');
        $reset=($body['reset']??false)===true;$to=max(0,(int)($body['to_sequence']??0));$from=max(0,(int)($body['from_sequence']??0));
        $events=is_array($body['events']??null)?$body['events']:[];$models=is_array($body['models']??null)?$body['models']:[];
        if($to<1||(!$reset&&($from<1||$from>$to)))return $this->error(422,'invalid_projection_event_range');
        if(!$reset){
            $expected=$from;
            foreach($events as $event){
                if(!is_array($event)||(int)($event['sequence']??0)!==$expected)return $this->error(422,'projection_event_sequence_invalid');
                $expected++;
            }
            if($expected-1!==$to)return $this->error(422,'projection_event_sequence_incomplete');
        }
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO remote_projection_cursors(installation_id,authority_epoch,last_sequence) VALUES(?,?,0) ON DUPLICATE KEY UPDATE authority_epoch=VALUES(authority_epoch)')->execute([$installationId,$this->currentAuthorityEpoch($installationId)]);
            $cursorStmt=$this->pdo->prepare('SELECT last_sequence FROM remote_projection_cursors WHERE installation_id=? FOR UPDATE');$cursorStmt->execute([$installationId]);$cursor=(int)$cursorStmt->fetchColumn();
            // ARCH-01: a full reset must never be an implicit escape hatch around monotonicity.
            // Explicit restore/authority generation is not part of the current protocol, so fail closed.
            if($reset){
                $this->pdo->rollBack();
                return ['status'=>409,'body'=>['ok'=>false,'error'=>'projection_event_reset_requires_epoch','ack_sequence'=>$cursor,'received_to'=>$to]];
            }
            if($from>$cursor+1){
                $this->pdo->rollBack();
                return ['status'=>409,'body'=>['ok'=>false,'error'=>'projection_event_gap','expected_sequence'=>$cursor+1,'received_from'=>$from,'ack_sequence'=>$cursor]];
            }
            // At-least-once duplicates must NEVER replay model bytes. Legacy v1
            // used to overwrite a newer v2 projection before returning duplicate=true.
            if($to<=$cursor){
                $this->pdo->commit();
                return ['status'=>200,'body'=>['ok'=>true,'ack_sequence'=>$cursor,'previous_sequence'=>$cursor,'reset'=>false,'duplicate'=>true,'synced'=>0,'unchanged'=>0,'invalid'=>0]];
            }
            $fence=$this->legacyModelV2Fence($installationId,$models,$to);
            if($fence!==null){$this->pdo->rollBack();return $fence;}
            $stats=$this->syncModelsInTransaction($installationId,$models);
            if((int)($stats['invalid']??0)>0){$this->pdo->rollBack();return $this->error(422,'projection_event_model_invalid');}
            foreach($events as $event){
                if(!is_array($event))continue;$seq=(int)($event['sequence']??0);if($seq<1||$seq>$to)continue;
                $keys=is_array($event['model_keys']??null)?array_values(array_filter(array_map('strval',$event['model_keys']))):[];
                $occurred=trim((string)($event['occurred_at']??''));$ts=$occurred!==''?\Sokna\Shared\IranTime::epoch($occurred,'UTC'):false;
                $insert=$this->pdo->prepare('INSERT IGNORE INTO remote_projection_events(installation_id,authority_epoch,sequence_id,event_type,aggregate_type,aggregate_id,model_keys,occurred_at) VALUES(?,?,?,?,?,?,?,?)');
                $insert->execute([$installationId,$this->currentAuthorityEpoch($installationId),$seq,substr((string)($event['event_type']??''),0,96),substr((string)($event['aggregate_type']??''),0,48),substr((string)($event['aggregate_id']??''),0,96),substr(implode(',',$keys),0,255),$ts===false?null:gmdate('Y-m-d H:i:s',$ts)]);
            }
            $ack=$reset?$to:max($cursor,$to);
            $update=$this->pdo->prepare('UPDATE remote_projection_cursors SET last_sequence=? WHERE installation_id=?');$update->execute([$ack,$installationId]);
            $pruneBefore=max(0,$ack-5000);if($pruneBefore>0){$prune=$this->pdo->prepare('DELETE FROM remote_projection_events WHERE installation_id=? AND sequence_id<?');$prune->execute([$installationId,$pruneBefore]);}
            $this->pdo->commit();
            return ['status'=>200,'body'=>['ok'=>true,'ack_sequence'=>$ack,'previous_sequence'=>$cursor,'reset'=>$reset,'duplicate'=>!$reset&&$to<=$cursor]+$stats];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /**
     * Legacy v1 has no monotonic model generation of its own. If the v2 owner
     * already committed a snapshot at or after this legacy sequence, refuse
     * the conflicting write while holding the same v2 cursor row locks.
     * In the unsequenced v1 endpoint, any positive v2 cursor is authoritative.
     * @param array<int,mixed> $models
     * @return array<string,mixed>|null
     */
    private function legacyModelV2Fence(string $installationId,array $models,?int $incomingSequence): ?array
    {
        $keys=[];
        foreach($models as $item){
            if(!is_array($item))continue;
            $key=trim((string)($item['model_key']??''));
            if($key!==''&&$this->isSyncModelKey($key))$keys[$key]=true;
        }
        if($keys===[])return null;
        $names=array_keys($keys);
        $placeholders=implode(',',array_fill(0,count($names),'?'));
        $statement=$this->pdo->prepare("SELECT model_key,last_sequence FROM remote_projection_model_cursors WHERE installation_id=? AND model_key IN ($placeholders) FOR UPDATE");
        $statement->execute(array_merge([$installationId],$names));
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $at=max(0,(int)($row['last_sequence']??0));
            if($at>0&&($incomingSequence===null||$at>=$incomingSequence)){
                return ['status'=>409,'body'=>[
                    'ok'=>false,'error'=>'legacy_projection_behind_v2',
                    'model_key'=>(string)($row['model_key']??''),
                    'v2_sequence'=>$at,
                    'received_sequence'=>$incomingSequence,
                ]];
            }
        }
        return null;
    }

    /** @return array{synced:int,unchanged:int,invalid:int} */
    private function syncModelsInTransaction(string $installationId,array $models,array $preparedModels=[]): array
    {
        $synced=0;$unchanged=0;$invalid=0;
        foreach($models as $model){
            if(!is_array($model)){$invalid++;continue;}
            $format=trim((string)($model['format']??''));$modelKey=trim((string)($model['model_key']??''));$sourceVersion=strtolower(trim((string)($model['source_version']??'')));$generatedAt=trim((string)($model['generated_at']??''));$payload=$model['payload']??null;$generatedTs=strtotime($generatedAt);
            if($format!=='sokna-remote-read-v1'||!$this->isSyncModelKey($modelKey)||!preg_match('/^[a-f0-9]{64}$/',$sourceVersion)||!is_array($payload)||$generatedTs===false){$invalid++;continue;}
            // V2 snapshots were verified and encoded before starting the transaction.
            // Legacy callers retain their original validation and serialization behavior.
            $prepared=is_array($preparedModels[$modelKey]??null)?$preparedModels[$modelKey]:null;
            if($prepared===null||!hash_equals((string)($prepared['source_version']??''),$sourceVersion)){
                $expected=CanonicalJson::sha256($payload);if(!hash_equals($expected,$sourceVersion)){$invalid++;continue;}
                $payloadJson=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            }else{
                $payloadJson=(string)$prepared['payload_json'];
                $generatedTs=(int)$prepared['generated_ts'];
            }
            $check=$this->pdo->prepare('SELECT source_version FROM remote_read_models WHERE installation_id=? AND model_key=? FOR UPDATE');$check->execute([$installationId,$modelKey]);$oldVersion=$check->fetchColumn();
            if($oldVersion!==false&&hash_equals((string)$oldVersion,$sourceVersion)){$this->pdo->prepare('UPDATE remote_read_models SET last_sync_at=UTC_TIMESTAMP() WHERE installation_id=? AND model_key=?')->execute([$installationId,$modelKey]);$unchanged++;continue;}
            $stmt=$this->pdo->prepare('INSERT INTO remote_read_models(installation_id,model_key,source_version,payload_json,generated_at,last_sync_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),payload_json=VALUES(payload_json),generated_at=VALUES(generated_at),last_sync_at=UTC_TIMESTAMP()');
            $stmt->execute([$installationId,$modelKey,$sourceVersion,$payloadJson,gmdate('Y-m-d H:i:s',$generatedTs)]);$synced++;
        }
        return ['synced'=>$synced,'unchanged'=>$unchanged,'invalid'=>$invalid];
    }

    /**
     * Transport v2 full-snapshot upsert for one model key. A snapshot may safely skip
     * intermediate Local events because the model payload is authoritative state, not a delta.
     * Monotonic per-model cursors prevent stale retries from overwriting newer snapshots.
     */
    public function syncModelV2(string $installationId,array $body): array
    {
        $installationId=trim($installationId);
        if($installationId===''||(string)($body['format']??'')!=='sokna-read-model-v2')return $this->error(400,'invalid_read_model_v2_payload');
        // Optional transport representation only. The signed outer JSON is verified
        // before this method; the canonical uncompressed content hash is checked below.
        if(array_key_exists('model_encoding',$body)){
            if($body['model_encoding']!=='gzip-base64-v1'||!function_exists('gzdecode')||isset($body['model'])||!is_string($body['model_gzip']??null))
                return $this->error(422,'projection_encoding_invalid');
            $packed=base64_decode($body['model_gzip'],true);
            if($packed===false||strlen($packed)>2097152)return $this->error(422,'projection_encoding_invalid');
            $decoded=@gzdecode($packed,2097153);
            if(!is_string($decoded)||strlen($decoded)>2097152)return $this->error(413,'projection_decoded_too_large');
            try{$model=json_decode($decoded,true,512,JSON_THROW_ON_ERROR);}
            catch(Throwable){return $this->error(422,'projection_encoding_invalid');}
            if(!is_array($model))return $this->error(422,'projection_encoding_invalid');
            $body['model']=$model;
        }
        $sequence=max(0,(int)($body['up_to_sequence']??0));$reset=($body['reset']??false)===true;
        $model=is_array($body['model']??null)?$body['model']:[];
        $modelKey=trim((string)($model['model_key']??''));$sourceVersion=strtolower(trim((string)($model['source_version']??'')));
        if($sequence<1||!$this->isSyncModelKey($modelKey)||!preg_match('/^[a-f0-9]{64}$/D',$sourceVersion))return $this->error(422,'invalid_read_model_v2_model');

        // Verify the complete signed model payload and serialize it BEFORE acquiring
        // any DB cursor/model locks. An invalid duplicate must not receive an ACK.
        $preflightStarted=microtime(true);
        try{
            $generatedAt=trim((string)($model['generated_at']??''));
            $generatedTs=strtotime($generatedAt);
            $modelPayload=$model['payload']??null;
            if((string)($model['format']??'')!=='sokna-remote-read-v1'||!is_array($modelPayload)||$generatedTs===false){
                return $this->error(422,'projection_model_invalid');
            }
            if(!hash_equals($sourceVersion,CanonicalJson::sha256($modelPayload))){
                return $this->error(422,'projection_model_invalid');
            }
            $preparedJson=json_encode($modelPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }catch(Throwable){return $this->error(422,'projection_model_invalid');}
        // Short, content-free timing phases let us distinguish CPU preflight from
        // Public DB lock contention, payload write, or commit. The signed HTTP
        // adapter exposes timing counters only to the authenticated Local sender.
        $phase=['model_preflight_ms'=>max(0,(int)round((microtime(true)-$preflightStarted)*1000))];$last=microtime(true);
        $mark=static function(string $name) use (&$phase,&$last):void {
            $now=microtime(true);$phase[$name]=max(0,(int)round(($now-$last)*1000));$last=$now;
        };
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO remote_projection_model_cursors(installation_id,authority_epoch,model_key,last_sequence,source_version) VALUES(?,?,?,0,NULL) ON DUPLICATE KEY UPDATE authority_epoch=VALUES(authority_epoch)')->execute([$installationId,$this->currentAuthorityEpoch($installationId),$modelKey]);
            $mark('cursor_insert_ms');
            $cursorStmt=$this->pdo->prepare('SELECT last_sequence,source_version FROM remote_projection_model_cursors WHERE installation_id=? AND model_key=? FOR UPDATE');
            $cursorStmt->execute([$installationId,$modelKey]);$cursorRow=$cursorStmt->fetch(PDO::FETCH_ASSOC)?:[];$cursor=(int)($cursorRow['last_sequence']??0);
            $mark('cursor_lock_ms');
            $currentStmt=$this->pdo->prepare('SELECT source_version FROM remote_read_models WHERE installation_id=? AND model_key=? LIMIT 1');$currentStmt->execute([$installationId,$modelKey]);$current=(string)($currentStmt->fetchColumn()?:'');
            $mark('model_read_ms');
            $decision=self::projectionV2Decision($cursor,$current,$sequence,$sourceVersion,$reset);
            if($decision['action']==='duplicate'){
                $this->pdo->commit();$mark('commit_ms');
                return ['status'=>200,'body'=>['ok'=>true,'model_key'=>$modelKey,'ack_sequence'=>$cursor,'source_version'=>$sourceVersion,'duplicate'=>true,'reset'=>false,'storage_timing'=>$phase]];
            }
            if($decision['action']==='reject'){
                $this->pdo->rollBack();$mark('rollback_ms');
                return ['status'=>409,'body'=>['ok'=>false,'error'=>$decision['error'],'model_key'=>$modelKey,'ack_sequence'=>$cursor,'received_sequence'=>$sequence,'storage_timing'=>$phase]];
            }
            $stats=$this->syncModelsInTransaction($installationId,[$model],[$modelKey=>[
                'source_version'=>$sourceVersion,'generated_ts'=>$generatedTs,'payload_json'=>$preparedJson,
            ]]);
            $mark('model_apply_ms');
            if((int)($stats['invalid']??0)>0){$this->pdo->rollBack();$mark('rollback_ms');return ['status'=>422,'body'=>['ok'=>false,'error'=>'projection_model_invalid','storage_timing'=>$phase]];}
            $update=$this->pdo->prepare('UPDATE remote_projection_model_cursors SET last_sequence=?,source_version=? WHERE installation_id=? AND model_key=?');
            $update->execute([$sequence,$sourceVersion,$installationId,$modelKey]);
            $mark('cursor_update_ms');
            $this->pdo->commit();$mark('commit_ms');
            return ['status'=>200,'body'=>['ok'=>true,'model_key'=>$modelKey,'ack_sequence'=>$sequence,'source_version'=>$sourceVersion,'duplicate'=>false,'reset'=>$reset,'storage_timing'=>$phase]+$stats];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /**
     * ARCH-01 monotonicity gate. The same pure decision is used by production and the
     * source qualification test so stale/reordered snapshots cannot bypass the rule.
     * @return array{action:string,error:string}
     */
    private static function projectionV2Decision(int $cursor,string $current,int $sequence,string $sourceVersion,bool $reset): array
    {
        // Legacy reset had no authority generation/epoch and could regress cursor + payload.
        // Fail closed until an explicit signed restore-generation protocol is introduced.
        if($reset)return ['action'=>'reject','error'=>'projection_model_reset_requires_epoch'];
        if($sequence<$cursor)return ['action'=>'reject','error'=>'projection_model_sequence_conflict'];
        if($sequence===$cursor){
            if($current!==''&&hash_equals($current,$sourceVersion))return ['action'=>'duplicate','error'=>''];
            return ['action'=>'reject','error'=>'projection_model_sequence_conflict'];
        }
        return ['action'=>'apply','error'=>''];
    }

    /**
     * Server-side read for bounded internal projections such as Guest operational state.
     * There is intentionally no HTTP adapter route for this method.
     */
    public function readInternal(string $installationId,string $modelKey,int $staleSeconds=90): array
    {
        $installationId=trim($installationId);$modelKey=trim($modelKey);
        if($installationId===''||!in_array($modelKey,self::INTERNAL_MODEL_KEYS,true))return $this->error(404,'model_unavailable');
        $stmt=$this->pdo->prepare('SELECT source_version,payload_json,generated_at,last_sync_at FROM remote_read_models WHERE installation_id=? AND model_key=? LIMIT 1');
        $stmt->execute([$installationId,$modelKey]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return $this->error(404,'model_unavailable');
        $payload=json_decode((string)($row['payload_json']??''),true);
        // An invalid internal Guest projection is not an authoritative empty table.
        // Match the fail-closed behavior of the Staff read API: do not misreport
        // missing orders/waiter calls because the stored JSON is corrupted.
        if(!is_array($payload))return $this->error(502,'projection_payload_invalid');
        $connectivity=$this->connectivity->status($installationId,45);
        $freshness=$this->freshnessForModel($installationId,$modelKey,(string)($row['last_sync_at']??''),$connectivity,$staleSeconds);
        return ['status'=>200,'body'=>[
            'ok'=>true,'model_key'=>$modelKey,'source_version'=>(string)$row['source_version'],
            'generated_at'=>(string)$row['generated_at'],'last_sync_at'=>(string)$row['last_sync_at'],
            'stale'=>(bool)$freshness['stale'],'freshness'=>$freshness,'connectivity'=>$connectivity,'payload'=>$payload,
        ]];
    }

    /**
     * Resolve a command's causal read-after-write requirements against Public model cursors.
     * Business commit remains independent from projection delivery; this method only describes
     * whether the Public view has reached the minimum Local sequences captured at commit time.
     *
     * @param list<array<string,mixed>> $requirements
     * @return array<string,mixed>
     */
    public function visibilityStatus(string $installationId,array $requirements): array
    {
        $installationId=trim($installationId);$normalized=[];
        foreach($requirements as $item){
            if(!is_array($item))continue;$key=trim((string)($item['model_key']??''));$seq=max(0,(int)($item['min_sequence']??0));
            if($key===''||$seq<1||!$this->isSyncModelKey($key))continue;
            $normalized[$key]=max($seq,(int)($normalized[$key]??0));
        }
        if($normalized===[])return ['state'=>'not_required','visible'=>true,'requirements'=>[],'pending_models'=>[]];
        $keys=array_keys($normalized);$ph=implode(',',array_fill(0,count($keys),'?'));$cursors=[];
        $q=$this->pdo->prepare("SELECT model_key,last_sequence FROM remote_projection_model_cursors WHERE installation_id=? AND model_key IN ($ph)");
        $q->execute(array_merge([$installationId],$keys));
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$cursors[(string)$row['model_key']]=max(0,(int)$row['last_sequence']);
        return self::visibilityDecision($normalized,$cursors);
    }

    /**
     * @param array<string,int> $requirements
     * @param array<string,int> $cursors
     * @return array<string,mixed>
     */
    public static function visibilityDecision(array $requirements,array $cursors): array
    {
        $models=[];$pending=[];ksort($requirements,SORT_STRING);
        foreach($requirements as $key=>$required){
            $required=max(1,(int)$required);$current=max(0,(int)($cursors[$key]??0));$ok=$current>=$required;
            $models[]=['model_key'=>(string)$key,'required_sequence'=>$required,'current_sequence'=>$current,'satisfied'=>$ok];
            if(!$ok)$pending[]=(string)$key;
        }
        return ['state'=>$pending===[]?'visible':'pending','visible'=>$pending===[],'requirements'=>$models,'pending_models'=>$pending];
    }


    private function currentAuthorityEpoch(string $installationId): int
    {
        try{$q=$this->pdo->prepare('SELECT authority_epoch FROM installations WHERE installation_id=? LIMIT 1');$q->execute([$installationId]);return max(1,(int)($q->fetchColumn()?:1));}catch(Throwable){return 1;}
    }

    private function isSyncModelKey(string $modelKey): bool
    {
        return array_key_exists($modelKey,self::MODEL_CAPABILITIES)||in_array($modelKey,self::INTERNAL_MODEL_KEYS,true);
    }

    public function read(array $session, string $modelKey, int $staleSeconds = 90): array
    {
        $modelKey = trim($modelKey);
        $required = self::MODEL_CAPABILITIES[$modelKey] ?? null;
        if ($required === null) return $this->error(404, 'unknown_model');
        if (!$this->hasCapability($session, $required)) return $this->error(403, 'forbidden');

        $installationId = trim((string)($session['installation_id'] ?? ''));
        if ($installationId === '') return $this->error(401, 'unauthorized');

        $stmt = $this->pdo->prepare(
            'SELECT source_version,payload_json,generated_at,last_sync_at '
            . 'FROM remote_read_models WHERE installation_id=? AND model_key=? LIMIT 1'
        );
        $stmt->execute([$installationId, $modelKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $this->error(404, 'model_unavailable');

        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        // A corrupt/truncated projection must not silently become a successful empty report.
        // Keep the underlying stored bytes private; the client only sees a stable error code.
        if (!is_array($payload)) return $this->error(502, 'projection_payload_invalid');
        if ($modelKey === 'operations') $payload = $this->filterOperations($payload, $session);
        if ($modelKey === 'operations_workspace') $payload = $this->filterOperationsWorkspace($payload, $session);
        if ($modelKey === 'preparation') $payload = $this->filterPreparation($payload, $session);
        if ($modelKey === 'deferred_context') $payload = $this->filterDeferredContext($payload, $session);
        if ($modelKey === 'notifications') $payload = $this->filterNotifications($payload, $session);

        $connectivity = $this->connectivity->status($installationId, 45);
        $freshness=$this->freshnessForModel($installationId,$modelKey,(string)($row['last_sync_at']??''),$connectivity,$staleSeconds);

        return [
            'status' => 200,
            'body' => [
                'ok' => true,
                'model_key' => $modelKey,
                'source_version' => (string)$row['source_version'],
                'generated_at' => (string)$row['generated_at'],
                'last_sync_at' => (string)$row['last_sync_at'],
                'stale' => (bool)$freshness['stale'],
                'freshness'=>$freshness,
                'connectivity' => $connectivity,
                'payload' => $payload,
            ],
        ];
    }

    /**
     * Long-poll only against Public read-model state. No request in this path reaches Local.
     * The cursor includes projection versions + connectivity freshness so browsers wake on
     * either business changes or degraded/recovered connectivity.
     */
    public function watch(array $session,array $modelKeys,string $cursor='',int $waitMs=10000): array
    {
        $installationId=trim((string)($session['installation_id']??''));
        if($installationId==='')return $this->error(401,'unauthorized');
        $keys=[];
        foreach($modelKeys as $key){
            $key=trim((string)$key);$required=self::MODEL_CAPABILITIES[$key]??null;
            if($required!==null&&$this->hasCapability($session,$required))$keys[$key]=true;
        }
        if($keys===[])return $this->error(403,'forbidden');
        $keys=array_keys($keys);sort($keys,SORT_STRING);
        $waitMs=max(0,min(self::WATCH_MAX_WAIT_MS,$waitMs));$started=microtime(true);$deadline=$started+($waitMs/1000);$polls=0;
        do{
            $state=$this->watchState($installationId,$keys,false);$polls++;
            $initial=$cursor==='';$changed=$initial||!hash_equals($cursor,(string)$state['cursor']);$timedOut=microtime(true)>=$deadline;
            if($changed||$timedOut){
                $elapsed=(int)round((microtime(true)-$started)*1000);$retry=self::watchReconnectDelayMs($changed,$initial);
                return ['status'=>200,'body'=>['ok'=>true,'changed'=>$changed,'watch'=>['poll_count'=>$polls,'elapsed_ms'=>$elapsed,'requested_wait_ms'=>$waitMs,'retry_after_ms'=>$retry,'reason'=>$initial?'initial':($changed?'changed':'timeout')]]+$state];
            }
            $this->watchSleep($polls,$deadline);
        }while(true);
    }

    public function watchInternal(string $installationId,array $modelKeys,string $cursor='',int $waitMs=10000): array
    {
        $installationId=trim($installationId);if($installationId==='')return $this->error(400,'invalid_installation');
        $keys=[];foreach($modelKeys as $key){$key=trim((string)$key);if(in_array($key,self::INTERNAL_MODEL_KEYS,true))$keys[$key]=true;}
        if($keys===[])return $this->error(404,'model_unavailable');$keys=array_keys($keys);sort($keys,SORT_STRING);
        $waitMs=max(0,min(self::WATCH_MAX_WAIT_MS,$waitMs));$started=microtime(true);$deadline=$started+($waitMs/1000);$polls=0;
        do{
            $state=$this->watchState($installationId,$keys,true);$polls++;
            $initial=$cursor==='';$changed=$initial||!hash_equals($cursor,(string)$state['cursor']);$timedOut=microtime(true)>=$deadline;
            if($changed||$timedOut){
                $elapsed=(int)round((microtime(true)-$started)*1000);$retry=self::watchReconnectDelayMs($changed,$initial);
                return ['status'=>200,'body'=>['ok'=>true,'changed'=>$changed,'watch'=>['poll_count'=>$polls,'elapsed_ms'=>$elapsed,'requested_wait_ms'=>$waitMs,'retry_after_ms'=>$retry,'reason'=>$initial?'initial':($changed?'changed':'timeout')]]+$state];
            }
            $this->watchSleep($polls,$deadline);
        }while(true);
    }

    private function watchState(string $installationId,array $keys,bool $internal): array
    {
        $versions=[];$lastSync='';$freshness=[];$vector=[];$stale=false;$rows=[];$cursors=[];
        $placeholders=implode(',',array_fill(0,count($keys),'?'));$params=array_merge($keys,[$installationId]);$connectivity=[
            'known'=>false,'active'=>false,'remote_enabled'=>false,'local_fresh'=>false,'last_seen_at'=>'','local_version'=>'','runtime_status'=>'','telemetry'=>[],
        ];
        try{
            // REV-21/H322: one indexed snapshot query per long-poll iteration instead of
            // connectivity + read-model + cursor round trips. The installation row keeps the
            // result non-empty even when a requested model has not been projected yet.
            $sql="SELECT i.active installation_active,i.remote_enabled installation_remote_enabled,h.local_version,h.runtime_status,h.telemetry_json,h.last_seen_at,"
                ."m.model_key,m.source_version,m.last_sync_at,COALESCE(c.last_sequence,0) last_sequence "
                ."FROM installations i LEFT JOIN installation_heartbeats h ON h.installation_id=i.installation_id "
                ."LEFT JOIN remote_read_models m ON m.installation_id=i.installation_id AND m.model_key IN ($placeholders) "
                ."LEFT JOIN remote_projection_model_cursors c ON c.installation_id=i.installation_id AND c.model_key=m.model_key "
                ."WHERE i.installation_id=?";
            $q=$this->pdo->prepare($sql);$q->execute($params);$result=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
            if($result!==[]){
                $head=$result[0];$lastSeen=(string)($head['last_seen_at']??'');$lastSeenEpoch=$lastSeen!==''?(\Sokna\Shared\IranTime::epoch($lastSeen,'UTC')?:0):0;$telemetry=json_decode((string)($head['telemetry_json']??'[]'),true);
                $connectivity=['known'=>true,'active'=>(bool)($head['installation_active']??false),'remote_enabled'=>(bool)($head['installation_remote_enabled']??false),'local_fresh'=>$lastSeenEpoch>=time()-45,'last_seen_at'=>$lastSeen,'local_version'=>(string)($head['local_version']??''),'runtime_status'=>(string)($head['runtime_status']??''),'telemetry'=>is_array($telemetry)?$telemetry:[]];
                foreach($result as $row){$key=trim((string)($row['model_key']??''));if($key==='')continue;$rows[$key]=$row;$cursors[$key]=max(0,(int)($row['last_sequence']??0));}
            }
        }catch(Throwable){
            // Compatibility fallback for a staggered schema rollout; this is not the steady-state
            // capacity path and remains intentionally conservative.
            $connectivity=$this->connectivity->status($installationId,45);
            $legacyParams=array_merge([$installationId],$keys);
            try{$q=$this->pdo->prepare("SELECT model_key,source_version,last_sync_at FROM remote_read_models WHERE installation_id=? AND model_key IN ($placeholders)");$q->execute($legacyParams);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$rows[(string)$row['model_key']]=$row;}catch(Throwable){}
            try{$q=$this->pdo->prepare("SELECT model_key,last_sequence FROM remote_projection_model_cursors WHERE installation_id=? AND model_key IN ($placeholders)");$q->execute($legacyParams);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$cursors[(string)$row['model_key']]=max(0,(int)($row['last_sequence']??0));}catch(Throwable){}
        }
        $telemetry=is_array($connectivity['telemetry']??null)?$connectivity['telemetry']:[];$projection=is_array($telemetry['projection_freshness']??null)?$telemetry['projection_freshness']:[];
        $models=(string)($projection['format']??'')==='sokna-projection-freshness-v1'&&($projection['complete']??false)===true&&is_array($projection['models']??null)?$projection['models']:[];
        foreach($keys as $key){
            $row=is_array($rows[$key]??null)?$rows[$key]:null;$versions[$key]=$row?(string)($row['source_version']??''):'';$sync=$row?(string)($row['last_sync_at']??''):'';if($sync>$lastSync)$lastSync=$sync;
            $modelCursor=max(0,(int)($cursors[$key]??0));$model=is_array($models[$key]??null)?$models[$key]:null;$lastSyncEpoch=\Sokna\Shared\IranTime::epoch($sync,'UTC')?:0;
            $f=$row?self::freshnessDecision(($connectivity['local_fresh']??false)===true,$model,$modelCursor,$lastSyncEpoch,90):['stale'=>true,'state'=>'stale','reason'=>'model_unavailable','mode'=>'missing','public_cursor_sequence'=>$modelCursor,'local_target_sequence'=>0];
            $f['checked_at']=(string)($connectivity['last_seen_at']??'');$f['replication_checked_at']=(string)($projection['checked_at']??$connectivity['last_seen_at']??'');$f['content_last_sync_at']=$sync;$freshness[$key]=$f;$stale=$stale||($f['stale']??true)===true;
            $vector[$key]=['stale'=>(bool)($f['stale']??true),'mode'=>(string)($f['mode']??''),'cursor'=>(int)($f['public_cursor_sequence']??0),'target'=>(int)($f['local_target_sequence']??0)];
        }
        // Do not hash heartbeat timestamp: a healthy unchanged model must not wake browsers on every heartbeat.
        $cursor=hash('sha256',CanonicalJson::encode(['versions'=>$versions,'freshness'=>$vector]));
        return ['cursor'=>$cursor,'versions'=>$versions,'last_sync_at'=>$lastSync,'stale'=>$stale,'freshness'=>$freshness,'connectivity'=>['local_fresh'=>(bool)($connectivity['local_fresh']??false),'last_seen_at'=>(string)($connectivity['last_seen_at']??'')]];
    }

    private function watchSleep(int $poll,float $deadline): void
    {
        $remaining=max(0.0,$deadline-microtime(true));if($remaining<=0)return;
        $base=self::watchPollDelayMicros($poll,0);
        // Small per-request jitter prevents synchronized browsers from repeatedly hitting the DB
        // on the same millisecond while keeping the deterministic base policy testable.
        $jitter=random_int(0,min(50000,max(0,intdiv($base,8))));
        usleep((int)min((float)($base+$jitter),$remaining*1000000));
    }

    /** Pure A09 policy hook used by production and capacity qualification. */
    public static function watchPollDelayMicros(int $poll,int $jitterMicros=0): int
    {
        $poll=max(1,$poll);$base=$poll<=2?250000:500000;
        return $base+max(0,min(50000,$jitterMicros));
    }

    /** Reconnect gap applies only after an unchanged long-poll timeout, never after a change. */
    public static function watchReconnectDelayMs(bool $changed,bool $initial,?int $jitterMs=null): int
    {
        if($changed||$initial)return 0;
        $span=self::WATCH_RECONNECT_MAX_MS-self::WATCH_RECONNECT_MIN_MS;
        $jitter=$jitterMs??random_int(0,$span);
        return self::WATCH_RECONNECT_MIN_MS+max(0,min($span,$jitter));
    }

    /**
     * Pure ARCH-12 freshness decision used by qualification tests and production reads.
     * When v1 projection telemetry exists, old content timestamps do not make an unchanged
     * model stale; only heartbeat loss or a Local target sequence ahead of Public does.
     * Older Local versions fall back to the conservative last_sync_at rule.
     * @param null|array<string,mixed> $projectionModel
     * @return array<string,mixed>
     */
    public static function freshnessDecision(bool $heartbeatFresh,?array $projectionModel,int $publicCursor,int $lastSyncEpoch,int $staleSeconds,int $nowEpoch=0): array
    {
        $now=$nowEpoch>0?$nowEpoch:time();$publicCursor=max(0,$publicCursor);$staleSeconds=max(5,$staleSeconds);
        if(!$heartbeatFresh)return ['stale'=>true,'state'=>'stale','reason'=>'local_heartbeat_stale','mode'=>'heartbeat','public_cursor_sequence'=>$publicCursor,'local_target_sequence'=>0];
        if(is_array($projectionModel)){
            $target=max(0,(int)($projectionModel['pending_target_sequence']??0));
            $stale=$target>$publicCursor;
            return ['stale'=>$stale,'state'=>$stale?'stale':'fresh','reason'=>$stale?'replication_lag':'replication_verified','mode'=>'projection_cursor_v1','public_cursor_sequence'=>$publicCursor,'local_target_sequence'=>$target,'local_last_acked_sequence'=>max(0,(int)($projectionModel['last_acked_sequence']??0))];
        }
        $stale=$lastSyncEpoch<=0||$lastSyncEpoch<$now-$staleSeconds;
        return ['stale'=>$stale,'state'=>$stale?'stale':'fresh','reason'=>$stale?'legacy_sync_age':'legacy_sync_recent','mode'=>'legacy_last_sync','public_cursor_sequence'=>$publicCursor,'local_target_sequence'=>0];
    }

    /** @return array<string,mixed> */
    private function freshnessForModel(string $installationId,string $modelKey,string $lastSyncAt,array $connectivity,int $staleSeconds): array
    {
        $cursor=0;try{$q=$this->pdo->prepare('SELECT last_sequence FROM remote_projection_model_cursors WHERE installation_id=? AND model_key=? LIMIT 1');$q->execute([$installationId,$modelKey]);$cursor=max(0,(int)($q->fetchColumn()?:0));}catch(Throwable){}
        $telemetry=is_array($connectivity['telemetry']??null)?$connectivity['telemetry']:[];$projection=is_array($telemetry['projection_freshness']??null)?$telemetry['projection_freshness']:[];
        $models=(string)($projection['format']??'')==='sokna-projection-freshness-v1'&&($projection['complete']??false)===true&&is_array($projection['models']??null)?$projection['models']:[];
        $model=is_array($models[$modelKey]??null)?$models[$modelKey]:null;$lastSyncEpoch=\Sokna\Shared\IranTime::epoch($lastSyncAt,'UTC')?:0;
        $decision=self::freshnessDecision(($connectivity['local_fresh']??false)===true,$model,$cursor,$lastSyncEpoch,max(30,$staleSeconds));
        $decision['checked_at']=(string)($connectivity['last_seen_at']??'');$decision['replication_checked_at']=(string)($projection['checked_at']??$connectivity['last_seen_at']??'');$decision['content_last_sync_at']=$lastSyncAt;
        return $decision;
    }

    private function hasCapability(array $session, string $capability): bool
    {
        $caps = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if (in_array('*', $caps, true) || in_array($capability, $caps, true)) return true;
        return $capability === 'preparation.read' && in_array('preparation.monitor', $caps, true);
    }


    private function filterOperations(array $payload,array $session): array
    {
        if(!$this->hasCapability($session,'finance.settle'))unset($payload['settlement_accounts']);
        return $payload;
    }

    private function filterOperationsWorkspace(array $payload,array $session): array
    {
        $caps=is_array($session['capabilities']??null)?array_values(array_map('strval',$session['capabilities'])):[];
        if(!in_array('*',$caps,true))$payload['expenses']=null;
        return $payload;
    }

    private function filterPreparation(array $payload, array $session): array
    {
        $caps = is_array($session['capabilities'] ?? null) ? array_values(array_map('strval', $session['capabilities'])) : [];
        $all = in_array('*', $caps, true);
        $monitor = !$all && in_array('preparation.monitor', $caps, true);
        $canMutate = !$all && in_array('preparation.mutate', $caps, true);
        $assigned = is_array($session['preparation_areas'] ?? null)
            ? array_values(array_intersect(['kitchen','bar'], array_map('strval', $session['preparation_areas'])))
            : [];
        $visible = ($all || $monitor) ? ['kitchen','bar'] : $assigned;
        $actionable = $canMutate ? $assigned : [];

        if (array_key_exists('orders', $payload)) {
            $orders=[];
            foreach (is_array($payload['orders'] ?? null) ? $payload['orders'] : [] as $order) {
                if(!is_array($order))continue;
                $areas=[];
                foreach(is_array($order['areas']??null)?$order['areas']:[] as $area){
                    if(!is_array($area))continue;
                    $key=(string)($area['area']??'');
                    if(!in_array($key,$visible,true))continue;
                    $area['actionable']=in_array($key,$actionable,true);
                    $areas[]=$area;
                }
                if($areas===[])continue;
                $order['areas']=$areas;$orders[]=$order;
            }
            $payload['orders']=$orders;
            $payload['permissions']=[
                'preparation_visible'=>$visible!==[],
                'preparation_actionable'=>$actionable!==[],
                'monitor_only'=>$monitor || ($visible!==[] && $actionable===[]),
                'visible_preparation_areas'=>$visible,
                'actionable_preparation_areas'=>$actionable,
            ];
            return $payload;
        }

        // Backward-compatible fallback for older Local projections during rollout.
        if ($all || $monitor) return $payload + ['permissions'=>[
            'preparation_visible'=>true,'preparation_actionable'=>false,
            'monitor_only'=>true,'visible_preparation_areas'=>['kitchen','bar'],
            'actionable_preparation_areas'=>[],
        ]];
        $allow = static fn(array $row): bool => in_array((string)($row['area'] ?? $row['area_key'] ?? ''), $visible, true);
        $payload['tasks'] = array_values(array_filter(
            is_array($payload['tasks'] ?? null) ? $payload['tasks'] : [],
            static fn(mixed $row): bool => is_array($row) && $allow($row),
        ));
        $payload['adjustments'] = array_values(array_filter(
            is_array($payload['adjustments'] ?? null) ? $payload['adjustments'] : [],
            static fn(mixed $row): bool => is_array($row) && $allow($row),
        ));
        $payload['permissions']=[
            'preparation_visible'=>$visible!==[],'preparation_actionable'=>$actionable!==[],
            'monitor_only'=>$visible!==[] && $actionable===[],'visible_preparation_areas'=>$visible,
            'actionable_preparation_areas'=>$actionable,
        ];
        return $payload;
    }

    private function filterDeferredContext(array $payload, array $session): array
    {
        $caps = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if (in_array('*', $caps, true)) return $payload;
        $has = static fn(string $cap): bool => in_array($cap, $caps, true);

        if (!$has('supply.manage.defer')) $payload['purchase_units'] = [];
        if (!$has('supply.need.defer') && !$has('supply.manage.defer')) $payload['supply_groups'] = [];
        if (!$has('supply.need.defer')
            && !$has('supply.manage.defer')
            && !$has('inventory.waste.defer')
            && !$has('inventory.count_draft.defer')) {
            $payload['inventory_items'] = [];
        }
        if (!$has('inventory.count_draft.defer')) $payload['count_drafts'] = [];
        if (!$has('subscriber.payment.defer')) $payload['subscribers'] = [];
        if (!$has('expense.create.defer')) $payload['expense_categories'] = [];
        return $payload;
    }

    private function filterNotifications(array $payload, array $session): array
    {
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $rows = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $payload['items'] = array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row) && hash_equals($projectionId, (string)($row['projection_id'] ?? ''))));
        foreach ($payload['items'] as &$row) unset($row['projection_id']);
        unset($row);
        $push = is_array($payload['push'] ?? null) ? $payload['push'] : [];
        $subscriptions = is_array($push['subscriptions'] ?? null) ? $push['subscriptions'] : [];
        $push['subscriptions'] = array_values(array_filter($subscriptions, static fn(mixed $row): bool => is_array($row) && hash_equals($projectionId, (string)($row['projection_id'] ?? ''))));
        foreach ($push['subscriptions'] as &$row) unset($row['projection_id']);
        unset($row);
        $preferences = is_array($push['preferences'] ?? null) ? $push['preferences'] : [];
        $match = null;
        foreach ($preferences as $row) if (is_array($row) && hash_equals($projectionId, (string)($row['projection_id'] ?? ''))) { $match = $row; break; }
        if (is_array($match)) unset($match['projection_id']);
        $push['preferences'] = $match;
        $payload['push'] = $push;
        return $payload;
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
