<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Realtime;

use PDO;
use Sokna\PublicEdge\Connectivity\ConnectivityService;
use Sokna\PublicEdge\Remote\RemoteReadModelService;
use Throwable;

final class RealtimeService
{
    private const TERMINAL = ['committed', 'rejected', 'expired', 'cancelled', 'unknown_review'];
    private const KINDS = [
        'guest_order.submit', 'guest_order.quote', 'guest_order.list', 'guest_order.status', 'guest_table.context',
        'waiter_call.create', 'waiter_call.status', 'waiter_call.cancel',
        'order.edit', 'order.cancel', 'order.staff_status',
        'hall.acceptance_update','hall.waiter_status', 'hall.table_move', 'hall.order_reprint', 'hall.order_item_adjust',
        'settlement.commit', 'settlement.late_catalog', 'settlement.late_commit', 'settlement.review', 'settlement.discount_preview', 'settlement.discount', 'settlement.receipt_reprint', 'settlement.followup_detach', 'settlement.followup_destination', 'preparation.mutate',
        'table_draft.get', 'table_draft.create', 'table_draft.edit', 'table_draft.finalize', 'table_draft.finalize_atomic', 'table_draft.cancel',
        'notification.push.subscribe', 'notification.push.unsubscribe', 'notification.preferences.save',
    ];
    private const GUEST_KINDS = [
        'guest_order.submit', 'guest_order.quote', 'guest_order.list', 'guest_order.status', 'guest_table.context',
        'waiter_call.create', 'waiter_call.status', 'waiter_call.cancel',
        'order.edit', 'order.cancel',
    ];
    private const CAPABILITIES = [
        'guest_order.submit' => 'guest.order.submit',
        'guest_order.quote' => 'guest.order.submit',
        'guest_order.list' => 'guest.order.submit',
        'guest_order.status' => 'guest.order.submit',
        'guest_table.context' => 'guest.order.submit',
        'waiter_call.create' => 'guest.waiter_call.create',
        'waiter_call.status' => 'guest.waiter_call.create',
        'waiter_call.cancel' => 'guest.waiter_call.create',
        'order.edit' => 'orders.mutate',
        'order.cancel' => 'orders.mutate',
        'order.staff_status' => 'orders.mutate',
        'hall.acceptance_update' => 'shift_supervision',
        'hall.waiter_status' => 'orders.mutate',
        'hall.table_move' => 'orders.mutate',
        'hall.order_reprint' => 'orders.mutate',
        'hall.order_item_adjust' => 'orders.mutate',
        'settlement.commit' => 'finance.settle',
        'settlement.late_catalog' => 'finance.settle',
        'settlement.late_commit' => 'finance.settle',
        'settlement.review' => 'finance.settle',
        'settlement.discount_preview' => 'finance.settle',
        'settlement.discount' => 'finance.settle',
        'settlement.receipt_reprint' => 'finance.settle',
        'settlement.followup_detach' => 'finance.settle',
        'settlement.followup_destination' => 'finance.settle',
        'preparation.mutate' => 'preparation.mutate',
        'table_draft.get' => 'orders.table_draft',
        'table_draft.create' => 'orders.table_draft',
        'table_draft.edit' => 'orders.table_draft',
        'table_draft.finalize' => 'orders.table_draft',
        'table_draft.finalize_atomic' => 'orders.table_draft',
        'table_draft.cancel' => 'orders.table_draft',
        'notification.push.subscribe' => 'notifications.push',
        'notification.push.unsubscribe' => 'notifications.push',
        'notification.preferences.save' => 'notifications.push',
    ];

    public function __construct(private readonly PDO $pdo, private readonly ConnectivityService $connectivity, private readonly RemoteReadModelService $readModels)
    {
    }

    /** ARCH-12 stable opaque trace shared with Local receipt/logging. */
    public static function traceId(string $installationId,string $requestId): string
    {
        return 'skt1-'.substr(hash('sha256',trim($installationId)."\0".trim($requestId)),0,32);
    }

    public function enqueue(array $session, array $envelope): array
    {
        $installationId = trim((string)($session['installation_id'] ?? ''));
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $capabilities = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if ($installationId === '' || $projectionId === '') {
            return $this->error(401, 'unauthorized');
        }

        $envelope['actor_projection_id'] = $projectionId;
        unset($envelope['session_id']);
        $validation = $this->validateEnvelope($envelope);
        if ($validation['errors'] !== []) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_envelope', 'fields' => $validation['errors']]];
        }

        $kind = (string)$envelope['kind'];
        if (in_array($kind, self::GUEST_KINDS, true)) {
            return $this->error(403, 'guest_route_required');
        }
        $flags = $this->installationFlags($installationId);
        if ($flags === null || !$flags['active'] || !$flags['remote_enabled']) {
            return $this->error(409, 'remote_disabled');
        }
        $requiredCapability = self::CAPABILITIES[$kind] ?? '';
        if (!in_array('*', $capabilities, true) && !in_array($requiredCapability, $capabilities, true)) {
            return $this->error(403, 'forbidden');
        }
        if (str_starts_with($kind, 'table_draft.') || str_starts_with($kind, 'notification.') || str_starts_with($kind, 'settlement.') || str_starts_with($kind, 'hall.') || $kind==='preparation.mutate' || $kind==='order.staff_status') {
            $connectivity = $this->connectivity->status($installationId, 45);
            if (($connectivity['local_fresh'] ?? false) !== true) {
                return ['status' => 503, 'body' => ['ok' => false, 'error' => 'local_unavailable', 'connectivity' => $connectivity]];
            }
        }

        $requestId = (string)$envelope['request_id'];
        $traceId = self::traceId($installationId,$requestId);
        $canonical = self::canonicalJson($envelope);
        $requestHash = hash('sha256', $canonical);
        $expiresAt = gmdate('Y-m-d H:i:s', (int)$validation['expires_ts']);

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                'SELECT request_hash,state,result_json,error_code FROM realtime_requests ' .
                'WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $existingStmt->execute([$installationId, $requestId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
                    $this->pdo->rollBack();
                    return $this->error(409, 'request_id_conflict');
                }
                $this->pdo->commit();
                return ['status' => 200, 'body' => [
                    'ok' => true,
                    'state' => (string)$existing['state'],
                    'trace_id' => $traceId,
                    'deduplicated' => true,
                    'result' => self::decodeObject($existing['result_json'] ?? null),
                    'error_code' => (string)($existing['error_code'] ?? ''),
                ]];
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO realtime_requests(installation_id,authority_epoch,request_id,request_hash,kind,actor_projection_id,envelope_json,state,expires_at) ' .
                'VALUES(?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute([$installationId, $this->currentAuthorityEpoch($installationId), $requestId, $requestHash, $kind, $projectionId, $canonical, 'queued', $expiresAt]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 202, 'body' => ['ok' => true, 'state' => 'queued', 'trace_id'=>$traceId, 'deduplicated' => false]];
    }

    /**
     * Guest compatibility uses the same authoritative Realtime queue but preserves
     * dev.39 retry identity: timestamps may change while kind+actor+payload stay stable.
     */
    public function enqueueGuest(string $installationId, array $envelope): array
    {
        $installationId = trim($installationId);
        if ($installationId === '') return $this->error(401, 'unauthorized');

        unset($envelope['session_id']);
        $validation = $this->validateEnvelope($envelope);
        if ($validation['errors'] !== []) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_envelope', 'fields' => $validation['errors']]];
        }

        $kind = (string)$envelope['kind'];
        if (!in_array($kind, self::GUEST_KINDS, true)) {
            return $this->error(403, 'forbidden');
        }

        $flags = $this->installationFlags($installationId);
        if ($flags === null || !$flags['active'] || !$flags['remote_enabled']) {
            return $this->error(409, 'remote_disabled');
        }

        $requestId = (string)$envelope['request_id'];
        $traceId = self::traceId($installationId,$requestId);
        $projectionId = (string)$envelope['actor_projection_id'];
        $canonical = self::canonicalJson($envelope);
        $requestHash = hash('sha256', $canonical);
        $expiresAt = gmdate('Y-m-d H:i:s', (int)$validation['expires_ts']);
        $logical = [
            'kind' => $kind,
            'actor_projection_id' => $projectionId,
            'payload' => (array)$envelope['payload'],
        ];
        $logicalHash = hash('sha256', self::canonicalJson($logical));

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                'SELECT state,result_json,error_code,envelope_json FROM realtime_requests ' .
                'WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $existingStmt->execute([$installationId, $requestId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                $oldEnvelope = self::decodeObject($existing['envelope_json'] ?? null);
                $oldLogical = is_array($oldEnvelope) ? [
                    'kind' => (string)($oldEnvelope['kind'] ?? ''),
                    'actor_projection_id' => (string)($oldEnvelope['actor_projection_id'] ?? ''),
                    'payload' => is_array($oldEnvelope['payload'] ?? null) ? $oldEnvelope['payload'] : [],
                ] : [];
                $oldLogicalHash = hash('sha256', self::canonicalJson($oldLogical));
                if (!hash_equals($oldLogicalHash, $logicalHash)) {
                    $this->pdo->rollBack();
                    return $this->error(409, 'request_id_conflict');
                }
                $this->pdo->commit();
                return ['status' => 200, 'body' => [
                    'ok' => true,
                    'state' => (string)$existing['state'],
                    'trace_id' => $traceId,
                    'deduplicated' => true,
                    'result' => self::decodeObject($existing['result_json'] ?? null),
                    'error_code' => (string)($existing['error_code'] ?? ''),
                ]];
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO realtime_requests(installation_id,authority_epoch,request_id,request_hash,kind,actor_projection_id,envelope_json,state,expires_at) ' .
                'VALUES(?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute([$installationId, $this->currentAuthorityEpoch($installationId), $requestId, $requestHash, $kind, $projectionId, $canonical, 'queued', $expiresAt]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 202, 'body' => ['ok' => true, 'state' => 'queued', 'trace_id'=>$traceId, 'deduplicated' => false]];
    }

    /**
     * Internal Local-owned no-op probe. It uses the exact realtime queue/claim/ACK
     * transport without requiring a staff session or mutating business data.
     */
    public function enqueueDiagnosticProbe(string $installationId,string $requestId,string $correlationId): array
    {
        $installationId=trim($installationId);$requestId=trim($requestId);$correlationId=trim($correlationId);
        if($installationId===''||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/D',$requestId))return $this->error(400,'invalid_probe_request_id');
        if($correlationId===''||strlen($correlationId)>128)return $this->error(400,'invalid_probe_correlation_id');
        $flags=$this->installationFlags($installationId);
        if($flags===null||!$flags['active']||!$flags['remote_enabled'])return $this->error(409,'remote_disabled');

        $created=gmdate('c');$expires=gmdate('c',time()+120);
        $envelope=[
            'request_id'=>$requestId,
            'kind'=>'diagnostics.ping',
            'actor_projection_id'=>'diagnostics:local',
            'created_at'=>$created,
            'expires_at'=>$expires,
            'payload'=>['probe_id'=>$requestId,'correlation_id'=>$correlationId,'public_created_at'=>$created],
        ];
        $canonical=self::canonicalJson($envelope);$requestHash=hash('sha256',$canonical);$expiresAt=gmdate('Y-m-d H:i:s',time()+120);

        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT request_hash,state FROM realtime_requests WHERE installation_id=? AND request_id=? FOR UPDATE');
            $q->execute([$installationId,$requestId]);$existing=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)){
                if(!hash_equals((string)$existing['request_hash'],$requestHash)){ $this->pdo->rollBack(); return $this->error(409,'request_id_conflict'); }
                $this->pdo->commit();
                return ['status'=>200,'body'=>['ok'=>true,'request_id'=>$requestId,'state'=>(string)$existing['state'],'deduplicated'=>true]];
            }
            $q=$this->pdo->prepare(
                "INSERT INTO realtime_requests(installation_id,authority_epoch,request_id,request_hash,kind,actor_projection_id,envelope_json,state,expires_at) VALUES(?,?,?,?,?,?,?,'queued',?)"
            );
            $q->execute([$installationId,$this->currentAuthorityEpoch($installationId),$requestId,$requestHash,'diagnostics.ping','diagnostics:local',$canonical,$expiresAt]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return ['status'=>202,'body'=>['ok'=>true,'request_id'=>$requestId,'state'=>'queued','deduplicated'=>false]];
    }

    public function diagnosticProbeResult(string $installationId,string $requestId): array
    {
        $installationId=trim($installationId);$requestId=trim($requestId);
        if($installationId===''||$requestId==='')return $this->error(400,'probe_request_id_required');
        $q=$this->pdo->prepare(
            "SELECT request_id,state,result_json,error_code,created_at,claimed_at,updated_at FROM realtime_requests " .
            "WHERE installation_id=? AND request_id=? AND kind='diagnostics.ping' LIMIT 1"
        );
        $q->execute([$installationId,$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return $this->error(404,'probe_not_found');
        return ['status'=>200,'body'=>[
            'ok'=>true,'request_id'=>(string)$row['request_id'],'state'=>(string)$row['state'],
            'terminal'=>self::isTerminal((string)$row['state']),
            'result'=>self::decodeObject($row['result_json']??null)??[],
            'error_code'=>(string)($row['error_code']??''),
            'created_at'=>(string)($row['created_at']??''),'claimed_at'=>(string)($row['claimed_at']??''),'updated_at'=>(string)($row['updated_at']??''),
        ]];
    }

    /** Operational queue/roundtrip evidence safe for diagnostics surfaces. */
    public function diagnostics(string $installationId): array
    {
        $installationId=trim($installationId);
        $summary=[
            'queued'=>0,'queued_total'=>0,'expired_due'=>0,'claimed'=>0,'claimed_stale'=>0,
            'oldest_queued_at'=>'','oldest_expired_due_at'=>'','last_claimed_at'=>'','last_terminal_at'=>'',
            'enqueued_15m'=>0,'enqueued_60m'=>0,'queue_by_kind'=>[],'pending_samples'=>[],'recent_terminal'=>[],'last_failure'=>null,'last_probe'=>null,'last_terminal_probe'=>null,
        ];
        if($installationId==='')return $summary;

        // Core queue counters and round-trip proof are independent evidence. A failure in an
        // optional drill-down query must never erase a valid diagnostics.ping receipt and make
        // the Public dashboard fall back to heartbeat-only after a successful full probe.
        try{
            $q=$this->pdo->prepare(
                "SELECT " .
                "SUM(CASE WHEN state='queued' THEN 1 ELSE 0 END) queued_total," .
                "SUM(CASE WHEN state='queued' AND expires_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END) queued_count," .
                "SUM(CASE WHEN state='queued' AND expires_at<=UTC_TIMESTAMP() THEN 1 ELSE 0 END) expired_due_count," .
                "SUM(CASE WHEN state='claimed' AND (lease_expires_at IS NULL OR lease_expires_at>=UTC_TIMESTAMP()) THEN 1 ELSE 0 END) claimed_count," .
                "SUM(CASE WHEN state='claimed' AND lease_expires_at<UTC_TIMESTAMP() THEN 1 ELSE 0 END) claimed_stale_count," .
                "MIN(CASE WHEN state='queued' AND expires_at>UTC_TIMESTAMP() THEN created_at ELSE NULL END) oldest_queued_at," .
                "MIN(CASE WHEN state='queued' AND expires_at<=UTC_TIMESTAMP() THEN created_at ELSE NULL END) oldest_expired_due_at," .
                "MAX(claimed_at) last_claimed_at," .
                "MAX(CASE WHEN state IN ('committed','rejected','expired','cancelled','unknown_review') THEN updated_at ELSE NULL END) last_terminal_at," .
                "SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) enqueued_15m," .
                "SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE) THEN 1 ELSE 0 END) enqueued_60m " .
                "FROM realtime_requests WHERE installation_id=?"
            );
            $q->execute([$installationId]);$r=$q->fetch(PDO::FETCH_ASSOC)?:[];
            $summary['queued']=max(0,(int)($r['queued_count']??0));
            $summary['queued_total']=max(0,(int)($r['queued_total']??0));
            $summary['expired_due']=max(0,(int)($r['expired_due_count']??0));
            $summary['claimed']=max(0,(int)($r['claimed_count']??0));
            $summary['claimed_stale']=max(0,(int)($r['claimed_stale_count']??0));
            $summary['oldest_queued_at']=(string)($r['oldest_queued_at']??'');
            $summary['oldest_expired_due_at']=(string)($r['oldest_expired_due_at']??'');
            $summary['last_claimed_at']=(string)($r['last_claimed_at']??'');
            $summary['last_terminal_at']=(string)($r['last_terminal_at']??'');
            $summary['enqueued_15m']=max(0,(int)($r['enqueued_15m']??0));
            $summary['enqueued_60m']=max(0,(int)($r['enqueued_60m']??0));
        }catch(Throwable $e){
            $summary['error']='relay_diagnostics_unavailable';
            $summary['exception_class']=get_class($e);
        }

        // Fetch the round-trip proof before optional detail queries. Public staff connectivity
        // depends on this row, so it must survive an unrelated diagnostic breakdown failure.
        try{
            $q=$this->pdo->prepare(
                "SELECT request_id,state,error_code,created_at,claimed_at,updated_at,result_json FROM realtime_requests " .
                "WHERE installation_id=? AND kind='diagnostics.ping' ORDER BY id DESC LIMIT 1"
            );
            $q->execute([$installationId]);$probe=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($probe)){
                $summary['last_probe']=[
                    'request_id'=>(string)$probe['request_id'],'state'=>(string)$probe['state'],'error_code'=>(string)($probe['error_code']??''),
                    'created_at'=>(string)($probe['created_at']??''),'claimed_at'=>(string)($probe['claimed_at']??''),'updated_at'=>(string)($probe['updated_at']??''),
                    'result'=>self::decodeObject($probe['result_json']??null)??[],
                ];
            }
            $qt=$this->pdo->prepare(
                "SELECT request_id,state,error_code,created_at,claimed_at,updated_at,result_json FROM realtime_requests " .
                "WHERE installation_id=? AND kind='diagnostics.ping' AND state IN ('committed','rejected','expired','cancelled','unknown_review') ORDER BY id DESC LIMIT 1"
            );
            $qt->execute([$installationId]);$terminalProbe=$qt->fetch(PDO::FETCH_ASSOC);
            if(is_array($terminalProbe)){
                $summary['last_terminal_probe']=[
                    'request_id'=>(string)$terminalProbe['request_id'],'state'=>(string)$terminalProbe['state'],'error_code'=>(string)($terminalProbe['error_code']??''),
                    'created_at'=>(string)($terminalProbe['created_at']??''),'claimed_at'=>(string)($terminalProbe['claimed_at']??''),'updated_at'=>(string)($terminalProbe['updated_at']??''),
                    'result'=>self::decodeObject($terminalProbe['result_json']??null)??[],
                ];
            }
        }catch(Throwable $e){
            $summary['probe_error']='relay_probe_diagnostics_unavailable';
            $summary['probe_exception_class']=get_class($e);
        }

        // Drill-down data is best-effort only. Keep it isolated from core connectivity evidence.
        try{
            $q=$this->pdo->prepare(
                "SELECT kind," .
                "SUM(CASE WHEN state='queued' AND expires_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END) queued_count," .
                "SUM(CASE WHEN state='queued' AND expires_at<=UTC_TIMESTAMP() THEN 1 ELSE 0 END) expired_due_count," .
                "SUM(CASE WHEN state='claimed' AND (lease_expires_at IS NULL OR lease_expires_at>=UTC_TIMESTAMP()) THEN 1 ELSE 0 END) claimed_count," .
                "SUM(CASE WHEN state='claimed' AND lease_expires_at<UTC_TIMESTAMP() THEN 1 ELSE 0 END) claimed_stale_count," .
                "SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) enqueued_15m," .
                "SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE) THEN 1 ELSE 0 END) enqueued_60m," .
                "MIN(CASE WHEN state='queued' AND expires_at>UTC_TIMESTAMP() THEN created_at ELSE NULL END) oldest_queued_at," .
                "MAX(created_at) newest_created_at " .
                "FROM realtime_requests WHERE installation_id=? AND (" .
                "state IN ('queued','claimed') OR created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE)) " .
                "GROUP BY kind ORDER BY " .
                "(SUM(CASE WHEN state='queued' AND expires_at>UTC_TIMESTAMP() THEN 1 ELSE 0 END)+" .
                "SUM(CASE WHEN state='claimed' THEN 1 ELSE 0 END)+" .
                "SUM(CASE WHEN state='queued' AND expires_at<=UTC_TIMESTAMP() THEN 1 ELSE 0 END)) DESC,kind ASC LIMIT 32"
            );
            $q->execute([$installationId]);$kinds=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
            foreach($kinds as $row)$summary['queue_by_kind'][]=[
                'kind'=>(string)($row['kind']??''),'queued'=>max(0,(int)($row['queued_count']??0)),
                'expired_due'=>max(0,(int)($row['expired_due_count']??0)),'claimed'=>max(0,(int)($row['claimed_count']??0)),
                'claimed_stale'=>max(0,(int)($row['claimed_stale_count']??0)),'enqueued_15m'=>max(0,(int)($row['enqueued_15m']??0)),
                'enqueued_60m'=>max(0,(int)($row['enqueued_60m']??0)),'oldest_queued_at'=>(string)($row['oldest_queued_at']??''),
                'newest_created_at'=>(string)($row['newest_created_at']??''),
            ];
        }catch(Throwable $e){
            $summary['detail_error']='relay_kind_diagnostics_unavailable';
            $summary['detail_exception_class']=get_class($e);
        }

        try{
            $q=$this->pdo->prepare(
                "SELECT request_id,kind,state,error_code,created_at,claimed_at,updated_at FROM realtime_requests " .
                "WHERE installation_id=? AND state IN ('committed','rejected','expired','cancelled','unknown_review') ORDER BY id DESC LIMIT 16"
            );
            $q->execute([$installationId]);$terminal=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
            foreach($terminal as $row){
                $entry=[
                    'request_id'=>(string)($row['request_id']??''),'kind'=>(string)($row['kind']??''),'state'=>(string)($row['state']??''),
                    'error_code'=>(string)($row['error_code']??''),'created_at'=>(string)($row['created_at']??''),'claimed_at'=>(string)($row['claimed_at']??''),
                    'updated_at'=>(string)($row['updated_at']??''),
                ];
                $summary['recent_terminal'][]=$entry;
            }
            if($summary['recent_terminal']!==[]){$latest=$summary['recent_terminal'][0];if((string)($latest['state']??'')!=='committed'||(string)($latest['error_code']??'')!=='')$summary['last_failure']=$latest;}
        }catch(Throwable $e){
            $summary['terminal_error']='relay_terminal_diagnostics_unavailable';
            $summary['terminal_exception_class']=get_class($e);
        }

        try{
            $q=$this->pdo->prepare(
                "SELECT request_id,kind,state,error_code,created_at,claimed_at,updated_at,expires_at,lease_expires_at FROM realtime_requests " .
                "WHERE installation_id=? AND (state='queued' OR state='claimed') ORDER BY id ASC LIMIT 20"
            );
            $q->execute([$installationId]);$pending=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
            foreach($pending as $row)$summary['pending_samples'][]=[
                'request_id'=>(string)($row['request_id']??''),'kind'=>(string)($row['kind']??''),'state'=>(string)($row['state']??''),
                'error_code'=>(string)($row['error_code']??''),'created_at'=>(string)($row['created_at']??''),'claimed_at'=>(string)($row['claimed_at']??''),
                'updated_at'=>(string)($row['updated_at']??''),'expires_at'=>(string)($row['expires_at']??''),'lease_expires_at'=>(string)($row['lease_expires_at']??''),
            ];
        }catch(Throwable $e){
            $summary['pending_error']='relay_pending_diagnostics_unavailable';
            $summary['pending_exception_class']=get_class($e);
        }
        return $summary;
    }

    /**
     * Transport v2 exchange: one signed Local->Public round-trip carries liveness,
     * idempotent ACK retries and a bounded batch of newly claimed commands.
     * Old claim/ack endpoints remain supported for staggered upgrades.
     */
    public function exchange(string $installationId,array $body): array
    {
        $installationId=trim($installationId);
        $flags=$this->installationFlags($installationId);
        if($flags===null||!$flags['active']||!$flags['remote_enabled'])return $this->error(409,'remote_disabled');
        if((int)($body['protocol']??0)!==2)return $this->error(400,'unsupported_transport_protocol');
        // ARCH-05: Local supplies the remaining exchange budget. Use Public monotonic elapsed
        // time rather than trusting cross-host wall-clock equality. Work already completed in
        // this exchange remains durable; once the budget is low, leave the rest for next tick.
        $budgetMs=max(100,min(5000,(int)($body['budget_ms']??3000)));
        $exchangeStarted=microtime(true);$exchangeDeadline=$exchangeStarted+($budgetMs/1000);$deadlineExhausted=false;
        $budgetAvailable=static function(int $reserveMs=0) use($exchangeDeadline):bool{return (microtime(true)+max(0,$reserveMs)/1000)<$exchangeDeadline;};

        $heartbeat=is_array($body['heartbeat']??null)?$body['heartbeat']:[];
        if($heartbeat!==[]){
            $this->connectivity->heartbeat(
                $installationId,
                (string)($heartbeat['local_version']??''),
                (string)($heartbeat['runtime_status']??''),
                is_array($heartbeat['telemetry']??null)?$heartbeat['telemetry']:[],
            );
        }

        // Read-your-writes fast path: Local may piggy-back one/two monotonic model snapshots
        // in the same signed exchange that carries terminal ACKs. Projection failure is isolated
        // from command ACK/claim so a read-model defect can never strand a business command.
        $ackResults=[];$acks=is_array($body['acks']??null)?array_slice($body['acks'],0,25):[];
        foreach($acks as $ack){
            if(!$budgetAvailable(50)){$deadlineExhausted=true;break;}
            if(!is_array($ack))continue;
            $requestId=trim((string)($ack['request_id']??''));
            $result=$this->ack($installationId,$ack);$status=(int)($result['status']??500);$rb=is_array($result['body']??null)?$result['body']:[];
            $ackResults[]=[
                'request_id'=>$requestId,
                'ok'=>$status>=200&&$status<300&&($rb['ok']??false)===true,
                'state'=>(string)($rb['state']??''),
                'trace_id'=>(string)($rb['trace_id']??self::traceId($installationId,$requestId)),
                'error'=>(string)($rb['error']??''),
                'deduplicated'=>(bool)($rb['deduplicated']??false),
            ];
        }

        // ARCH-08: ambiguous ACK outcomes are not success. Local may keep canonical
        // evidence and ask for the current Public row until terminal identity is proven.
        $ackReconcileResults=[];$ackReconcile=is_array($body['ack_reconcile']??null)?array_slice($body['ack_reconcile'],0,25):[];
        foreach($ackReconcile as $probe){
            if(!$budgetAvailable(40)){$deadlineExhausted=true;break;}
            if(!is_array($probe))continue;$requestId=trim((string)($probe['request_id']??''));if($requestId==='')continue;
            $ackReconcileResults[]=$this->ackReconciliationStatus($installationId,$requestId);
        }

        $maxRequests=max(0,min(10,(int)($body['max_requests']??4)));
        $projectionResults=[];$projectionModels=$maxRequests>0?[]:
            (is_array($body['projection_models']??null)?array_slice($body['projection_models'],0,2):[]);
        foreach($projectionModels as $projection){
            if(!$budgetAvailable(75)){$deadlineExhausted=true;break;}
            if(!is_array($projection))continue;
            try{$applied=$this->readModels->syncModelV2($installationId,$projection);$status=(int)($applied['status']??500);$rb=is_array($applied['body']??null)?$applied['body']:[];}
            catch(Throwable){$status=500;$rb=['ok'=>false,'error'=>'projection_fastpath_exception'];}
            $projectionResults[]=[
                'model_key'=>(string)($rb['model_key']??(($projection['model']['model_key']??''))),
                'ok'=>$status>=200&&$status<300&&($rb['ok']??false)===true,
                'ack_sequence'=>(int)($rb['ack_sequence']??0),
                'error'=>(string)($rb['error']??''),
                'duplicate'=>(bool)($rb['duplicate']??false),
            ];
        }


        $leaseSeconds=max(5,min(60,(int)($body['lease_seconds']??30)));
        $requests=[];
        for($i=0;$i<$maxRequests;$i++){
            if(!$budgetAvailable(75)){$deadlineExhausted=true;break;}
            $claimed=$this->claim($installationId,$leaseSeconds,0);
            $status=(int)($claimed['status']??500);$rb=is_array($claimed['body']??null)?$claimed['body']:[];
            if($status===404&&(string)($rb['error']??'')==='empty_queue')break;
            if($status<200||$status>=300||($rb['ok']??false)!==true){
                return ['status'=>$status?:502,'body'=>['ok'=>false,'error'=>(string)($rb['error']??'transport_exchange_claim_failed'),'ack_results'=>$ackResults]];
            }
            $requests[]=[
                'request'=>is_array($rb['request']??null)?$rb['request']:[],
                'trace_id'=>(string)($rb['trace_id']??''),
                'lease_token'=>(string)($rb['lease_token']??''),
                'lease_seconds'=>(int)($rb['lease_seconds']??$leaseSeconds),
            ];
        }
        return ['status'=>200,'body'=>[
            'ok'=>true,'protocol'=>2,'server_time'=>gmdate('c'),
            'capabilities'=>['transport_protocol'=>2,'projection_models'=>2,'atomic_table_draft_finalize'=>true,'guest_async_submit'=>true,'trace_contract'=>'sokna-trace-v1','freshness_contract'=>'projection-heartbeat-v1'],
            'projection_results'=>$projectionResults,'ack_results'=>$ackResults,'ack_reconcile_results'=>$ackReconcileResults,'requests'=>$requests,
            'deadline_exhausted'=>$deadlineExhausted,'budget_ms'=>$budgetMs,'elapsed_ms'=>max(0,(int)round((microtime(true)-$exchangeStarted)*1000)),
        ]];
    }

    public function claim(string $installationId, int $leaseSeconds = 20, int $waitMilliseconds = 0): array
    {
        $installationId = trim($installationId);
        $leaseSeconds = min(60, max(5, $leaseSeconds));
        // H241: keep the current Runtime compatible while removing most of the idle 1s pickup gap.
        // The wait is deliberately bounded below the Runtime trigger cadence / Local HTTP timeout.
        $waitMilliseconds = min(900, max(0, $waitMilliseconds));
        $flags = $this->installationFlags($installationId);
        if ($flags === null || !$flags['active'] || !$flags['remote_enabled']) {
            return $this->error(409, 'remote_disabled');
        }

        $startedAt = microtime(true);
        $deadline = $startedAt + ($waitMilliseconds / 1000);
        $expireDue = true;
        do {
            $claimed = $this->claimOnce($installationId, $leaseSeconds, $expireDue);
            $expireDue = false;
            if ($claimed !== null) {
                $claimed['body']['waited_ms'] = max(0, (int)round((microtime(true) - $startedAt) * 1000));
                return $claimed;
            }
            if ($waitMilliseconds <= 0 || microtime(true) >= $deadline) break;
            // Never sleep while a DB transaction/row lock is held.
            $remainingUs = max(0, (int)(($deadline - microtime(true)) * 1_000_000));
            if ($remainingUs <= 0) break;
            usleep(min(50_000, $remainingUs));
        } while (true);

        return $this->error(404, 'empty_queue');
    }

    /** @return array{status:int,body:array<string,mixed>}|null */
    private function claimOnce(string $installationId, int $leaseSeconds, bool $expireDue): ?array
    {
        $this->pdo->beginTransaction();
        try {
            if ($expireDue) {
                $expire = $this->pdo->prepare(
                    "UPDATE realtime_requests SET state='expired',lease_token_hash=NULL,lease_expires_at=NULL " .
                    "WHERE installation_id=? AND authority_epoch=? AND state='queued' AND expires_at<=UTC_TIMESTAMP()"
                );
                $epoch=$this->currentAuthorityEpoch($installationId);$expire->execute([$installationId,$epoch]);
            }

            // A stale claim is reconciliation work even after the command deadline. Reclaiming
            // the SAME request_id is safe: Local receipt dedupe returns an already-terminal result,
            // while a never-executed expired envelope is rejected by Local's deadline gate without
            // running business logic. This prevents claimed+expired rows from becoming unclaimable.
            $select = $this->pdo->prepare(
                "SELECT id,envelope_json FROM realtime_requests WHERE installation_id=? AND authority_epoch=? AND " .
                "((state='queued' AND expires_at>UTC_TIMESTAMP()) OR (state='claimed' AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP()))) " .
                'ORDER BY id ASC LIMIT 1 FOR UPDATE'
            );
            $select->execute([$installationId,$epoch??$this->currentAuthorityEpoch($installationId)]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }

            $leaseToken = bin2hex(random_bytes(24));
            $update = $this->pdo->prepare(
                "UPDATE realtime_requests SET state='claimed',lease_token_hash=?," .
                'lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),claimed_at=UTC_TIMESTAMP() WHERE id=?'
            );
            $update->execute([hash('sha256', $leaseToken), $leaseSeconds, (int)$row['id']]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        $request=self::decodeObject($row['envelope_json'] ?? null)??[];
        $traceId=self::traceId($installationId,(string)($request['request_id']??''));
        return ['status' => 200, 'body' => [
            'ok' => true,
            'request' => $request,
            'trace_id'=>$traceId,
            'lease_token' => $leaseToken,
            'lease_seconds' => $leaseSeconds,
        ]];
    }

    public function ack(string $installationId, array $ack): array
    {
        $requestId = trim((string)($ack['request_id'] ?? ''));
        $leaseToken = (string)($ack['lease_token'] ?? '');
        $state = (string)($ack['state'] ?? '');
        if ($requestId === '' || $leaseToken === '' || !self::isTerminal($state)) {
            return $this->error(400, 'invalid_ack');
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT state,lease_token_hash,result_json,error_code FROM realtime_requests WHERE installation_id=? AND request_id=? FOR UPDATE'
            );
            $select->execute([$installationId, $requestId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->rollBack();
                return $this->error(404, 'not_found');
            }
            if (self::isTerminal((string)$row['state'])) {
                $storedResult=self::decodeObject($row['result_json']??null)??[];
                $incomingResult=is_array($ack['result']??null)?$ack['result']:[];
                $same=(string)$row['state']===$state
                    &&(string)($row['error_code']??'')===(string)($ack['error_code']??'')
                    &&self::canonicalJson($storedResult)===self::canonicalJson($incomingResult);
                $this->pdo->commit();
                if(!$same)return ['status'=>409,'body'=>['ok'=>false,'error'=>'terminal_conflict','state'=>(string)$row['state']]];
                return ['status' => 200, 'body' => ['ok' => true, 'state' => (string)$row['state'], 'trace_id'=>self::traceId($installationId,$requestId), 'deduplicated' => true]];
            }
            $storedHash = (string)($row['lease_token_hash'] ?? '');
            if ((string)$row['state'] !== 'claimed' || $storedHash === '' || !hash_equals($storedHash, hash('sha256', $leaseToken))) {
                $this->pdo->rollBack();
                return $this->error(409, 'lease_conflict');
            }

            $result = is_array($ack['result'] ?? null) ? $ack['result'] : [];
            $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($resultJson)) $resultJson = '{}';
            $update = $this->pdo->prepare(
                'UPDATE realtime_requests SET state=?,result_json=?,error_code=?,lease_token_hash=NULL,lease_expires_at=NULL ' .
                'WHERE installation_id=? AND request_id=?'
            );
            $update->execute([$state, $resultJson, (string)($ack['error_code'] ?? ''), $installationId, $requestId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return ['status' => 200, 'body' => ['ok' => true, 'state' => $state, 'trace_id'=>self::traceId($installationId,$requestId), 'deduplicated' => false]];
    }

    /** @return array<string,mixed> */
    private function ackReconciliationStatus(string $installationId,string $requestId): array
    {
        $stmt=$this->pdo->prepare('SELECT state,result_json,error_code FROM realtime_requests WHERE installation_id=? AND request_id=? LIMIT 1');
        $stmt->execute([$installationId,$requestId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return ['request_id'=>$requestId,'trace_id'=>self::traceId($installationId,$requestId),'exists'=>false,'terminal'=>false,'state'=>'','result'=>[],'error_code'=>''];
        $state=(string)($row['state']??'');
        return [
            'request_id'=>$requestId,'trace_id'=>self::traceId($installationId,$requestId),'exists'=>true,'terminal'=>self::isTerminal($state),'state'=>$state,
            'result'=>self::decodeObject($row['result_json']??null)??[],'error_code'=>(string)($row['error_code']??''),
        ];
    }

    public function result(array $session, string $requestId): array
    {
        $requestId = trim($requestId);
        if ($requestId === '') return $this->error(400, 'request_id_required');
        $installationId = trim((string)($session['installation_id'] ?? ''));
        $projectionId = trim((string)($session['projection_id'] ?? ''));
        $capabilities = is_array($session['capabilities'] ?? null) ? array_map('strval', $session['capabilities']) : [];
        if ($installationId === '' || $projectionId === '') return $this->error(401, 'unauthorized');

        $stmt = $this->pdo->prepare(
            'SELECT actor_projection_id,state,result_json,error_code,created_at,claimed_at,updated_at,expires_at FROM realtime_requests ' .
            'WHERE installation_id=? AND request_id=? LIMIT 1'
        );
        $stmt->execute([$installationId, $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return $this->error(404, 'not_found');
        if (!in_array('*', $capabilities, true) && !hash_equals($projectionId, (string)$row['actor_projection_id'])) {
            return $this->error(403, 'forbidden');
        }

        // The claim worker normally expires never-claimed requests. If the worker is down,
        // GET /result is still reachable: allow the owner to settle an elapsed *queued* intent.
        // Guard the update by state AND expires_at so a simultaneous Local claim always wins.
        // Never change claimed, committed, or unknown_review here: a Local effect may exist.
        if ((string)$row['state']==='queued' && strtotime((string)($row['expires_at']??''))<=time()) {
            $expire=$this->pdo->prepare(
                "UPDATE realtime_requests SET state='expired',error_code='command_expired_before_claim'," .
                "lease_token_hash=NULL,lease_expires_at=NULL,updated_at=UTC_TIMESTAMP() " .
                "WHERE installation_id=? AND request_id=? AND state='queued' AND expires_at<=UTC_TIMESTAMP()"
            );
            $expire->execute([$installationId,$requestId]);
            if($expire->rowCount()>0){
                $row['state']='expired';$row['error_code']='command_expired_before_claim';$row['updated_at']=gmdate('Y-m-d H:i:s');
            }else{
                // A concurrent claim/ACK changed the row; read its actual result, not a guess.
                $stmt->execute([$installationId,$requestId]);$current=$stmt->fetch(PDO::FETCH_ASSOC);
                if(is_array($current))$row=$current;
            }
        }

        $result=self::decodeObject($row['result_json'] ?? null)??[];$state=(string)$row['state'];$terminal=self::isTerminal($state);
        $requirements=is_array($result['affected_model_requirements']??null)?$result['affected_model_requirements']:[];
        $visibility=$state==='committed'?$this->readModels->visibilityStatus($installationId,$requirements):['state'=>'not_required','visible'=>true,'requirements'=>[],'pending_models'=>[]];
        $traceId=self::traceId($installationId,$requestId);$localTrace=is_array($result['_transport_trace']??null)?$result['_transport_trace']:[];
        $checkedAt=gmdate('c');
        $trace=[
            'trace_id'=>$traceId,'public_enqueued_at'=>(string)($row['created_at']??''),'public_claimed_at'=>(string)($row['claimed_at']??''),
            'local_finished_at'=>(string)($localTrace['local_finished_at']??''),'visibility_requirements_captured_at'=>(string)($localTrace['visibility_requirements_captured_at']??''),
            'public_terminal_at'=>$terminal?(string)($row['updated_at']??''):'','visibility_checked_at'=>$checkedAt,'visibility_state'=>(string)($visibility['state']??'not_required'),
        ];
        return ['status' => 200, 'body' => [
            'ok' => true,
            'state' => $state,
            'terminal' => $terminal,
            'business_terminal' => $terminal,
            'trace_id'=>$traceId,
            'trace'=>$trace,
            'result' => $result,
            'visibility' => $visibility,
            'error_code' => (string)($row['error_code'] ?? ''),
            'updated_at' => (string)$row['updated_at'],
        ]];
    }

    private function installationFlags(string $installationId): ?array
    {
        if ($installationId === '') return null;
        $stmt = $this->pdo->prepare(
            'SELECT active,remote_enabled,order_intake_enabled FROM installations WHERE installation_id=? LIMIT 1'
        );
        $stmt->execute([$installationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return [
            'active' => (bool)$row['active'],
            'remote_enabled' => (bool)$row['remote_enabled'],
            'order_intake_enabled' => (bool)$row['order_intake_enabled'],
        ];
    }

    private function validateEnvelope(array $envelope): array
    {
        $errors = [];
        $requestId = trim((string)($envelope['request_id'] ?? ''));
        if ($requestId === '' || strlen($requestId) > 96 || preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1) $errors[] = 'request_id';
        $kind = trim((string)($envelope['kind'] ?? ''));
        if (!in_array($kind, self::KINDS, true)) $errors[] = 'kind';
        $createdTs = \Sokna\Shared\IranTime::wireEpoch((string)($envelope['created_at']??'')) ?: 0;
        $expiresTs = \Sokna\Shared\IranTime::wireEpoch((string)($envelope['expires_at']??'')) ?: 0;
        if ($createdTs <= 0) $errors[] = 'created_at';
        if ($expiresTs <= 0 || ($createdTs > 0 && $expiresTs <= $createdTs)) $errors[] = 'expires_at';
        if (!isset($envelope['payload']) || !is_array($envelope['payload'])) $errors[] = 'payload';
        if (isset($envelope['expected_version']) && !is_int($envelope['expected_version']) && !is_string($envelope['expected_version'])) $errors[] = 'expected_version';
        if (isset($envelope['expected_state']) && !is_string($envelope['expected_state'])) $errors[] = 'expected_state';
        if (trim((string)($envelope['actor_projection_id'] ?? '')) === '') $errors[] = 'actor_projection_id';
        return ['errors' => $errors, 'expires_ts' => $expiresTs];
    }

    private static function canonicalJson(array $value): string
    {
        $json = json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) throw new \RuntimeException('Realtime canonical JSON encoding failed.');
        return $json;
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([self::class, 'normalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::normalize($child);
        return $value;
    }

    private static function decodeObject(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }


    private function currentAuthorityEpoch(string $installationId): int
    {
        try{$q=$this->pdo->prepare('SELECT authority_epoch FROM installations WHERE installation_id=? LIMIT 1');$q->execute([$installationId]);return max(1,(int)($q->fetchColumn()?:1));}catch(Throwable){return 1;}
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
