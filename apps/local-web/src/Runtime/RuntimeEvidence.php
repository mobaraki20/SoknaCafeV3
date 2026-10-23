<?php
declare(strict_types=1);

namespace Sokna\Local\Runtime;

use PDO;
use Throwable;

/**
 * Canonical evidence projection for the Windows Runtime as observed by Local Web.
 * A Windows service being installed/running is not treated as proof that scheduled work reaches Local.
 */
final class RuntimeEvidence
{
    public const FRESH_SECONDS = 180;
    public const SCHEDULER_FRESH_SECONDS = 30;
    public const EXECUTION_STALL_SECONDS = 120;

    /** Indexed liveness evidence for the transport heartbeat; no all-history GROUP BY. */
    public static function heartbeatSnapshot(PDO $pdo, ?int $nowEpoch=null): array
    {
        try{
            $latest=$pdo->query('SELECT runtime_instance_id,accepted_at FROM runtime_trigger_receipts ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if(!is_array($latest))return self::fromRows([],$nowEpoch);
            $id=(string)$latest['runtime_instance_id'];$row=['runtime_instance_id'=>$id,'last_seen'=>(string)$latest['accepted_at']];
            foreach(['accepted'=>'last_pending_at','failed'=>'last_failed_at','completed'=>'last_completed_at'] as $state=>$key){
                $q=$pdo->prepare('SELECT accepted_at FROM runtime_trigger_receipts WHERE runtime_instance_id=? AND state=? ORDER BY accepted_at DESC LIMIT 1');$q->execute([$id,$state]);$row[$key]=(string)($q->fetchColumn()?:'');
            }
            $q=$pdo->prepare("SELECT COUNT(*) FROM runtime_trigger_receipts WHERE runtime_instance_id=? AND state='failed' AND accepted_at>=DATE_SUB(CURRENT_TIMESTAMP(),INTERVAL 15 MINUTE)");$q->execute([$id]);$row['recent_failed_count']=(int)$q->fetchColumn();
            $q=$pdo->prepare("SELECT COUNT(*) FROM runtime_trigger_receipts WHERE runtime_instance_id=? AND state='accepted'");$q->execute([$id]);$row['pending_count']=(int)$q->fetchColumn();
            return self::fromRows([$row],$nowEpoch);
        }catch(Throwable){return ['status'=>'unavailable','last_seen_at'=>'','age_seconds'=>null,'recent_failed_count'=>0,'unresolved_failure'=>true];}
    }

    public static function snapshot(PDO $pdo, ?int $nowEpoch = null): array
    {
        try {
            $rows = $pdo->query(
                "SELECT runtime_instance_id," .
                "MAX(accepted_at) last_seen," .
                "SUM(CASE WHEN state='failed' AND accepted_at>=DATE_SUB(CURRENT_TIMESTAMP(),INTERVAL 15 MINUTE) THEN 1 ELSE 0 END) recent_failed_count," .
                "SUM(CASE WHEN state='accepted' THEN 1 ELSE 0 END) pending_count," .
                "COUNT(*) receipt_count," .
                "MAX(CASE WHEN state='accepted' THEN accepted_at ELSE NULL END) last_pending_at," .
                "MAX(CASE WHEN state='failed' THEN accepted_at ELSE NULL END) last_failed_at," .
                "MAX(CASE WHEN state='completed' THEN accepted_at ELSE NULL END) last_completed_at " .
                "FROM runtime_trigger_receipts GROUP BY runtime_instance_id ORDER BY last_seen DESC LIMIT 10"
            )->fetchAll(PDO::FETCH_ASSOC);
            return self::fromRows(is_array($rows) ? $rows : [], $nowEpoch);
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'fresh_seconds' => self::FRESH_SECONDS,
                'instances' => [],
                'latest_instance_id' => '',
                'last_seen_at' => '',
                'age_seconds' => null,
                'recent_failed_count' => 0,
                'unresolved_failure' => false,
            ];
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function fromRows(array $rows, ?int $nowEpoch = null): array
    {
        $now = $nowEpoch ?? time();
        $instances = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $lastSeen = trim((string)($row['last_seen'] ?? ''));
            $lastFailed = trim((string)($row['last_failed_at'] ?? ''));
            $lastCompleted = trim((string)($row['last_completed_at'] ?? ''));
            $lastPending = trim((string)($row['last_pending_at'] ?? ''));
            $age = self::age($lastSeen, $now);
            $pendingAge = self::age($lastPending, $now);
            $pendingCount = max(0, (int)($row['pending_count'] ?? 0));
            $failedEpoch = self::epoch($lastFailed);
            $completedEpoch = self::epoch($lastCompleted);
            $unresolved = $failedEpoch > 0 && ($completedEpoch <= 0 || $failedEpoch > $completedEpoch);
            $executionState = $unresolved ? 'failed' : ($pendingCount > 0 ? (($pendingAge !== null && $pendingAge > self::EXECUTION_STALL_SECONDS) ? 'stalled' : 'running') : ($lastCompleted !== '' ? 'completed' : 'not_seen'));
            $instances[] = [
                'runtime_instance_id' => trim((string)($row['runtime_instance_id'] ?? '')),
                'last_seen' => $lastSeen,
                'age_seconds' => $age,
                'recent_failed_count' => max(0, (int)($row['recent_failed_count'] ?? 0)),
                'pending_count' => $pendingCount,
                'receipt_count' => max(0, (int)($row['receipt_count'] ?? 0)),
                'last_pending_at' => $lastPending,
                'pending_age_seconds' => $pendingAge,
                'last_failed_at' => $lastFailed,
                'last_completed_at' => $lastCompleted,
                'execution_state' => $executionState,
                'unresolved_failure' => $unresolved,
            ];
        }

        $latest = $instances[0] ?? null;
        if ($latest === null) {
            $status = 'not_seen';
        } elseif ($latest['age_seconds'] === null || $latest['age_seconds'] > self::FRESH_SECONDS) {
            $status = 'observed_stale';
        } elseif ($latest['unresolved_failure'] || in_array((string)($latest['execution_state'] ?? ''), ['failed','stalled'], true)) {
            $status = 'degraded';
        } else {
            $status = 'observed_recently';
        }

        return [
            'status' => $status,
            'fresh_seconds' => self::FRESH_SECONDS,
            'instances' => $instances,
            'latest_instance_id' => (string)($latest['runtime_instance_id'] ?? ''),
            'last_seen_at' => (string)($latest['last_seen'] ?? ''),
            'age_seconds' => $latest['age_seconds'] ?? null,
            'recent_failed_count' => (int)($latest['recent_failed_count'] ?? 0),
            'pending_count' => (int)($latest['pending_count'] ?? 0),
            'last_pending_at' => (string)($latest['last_pending_at'] ?? ''),
            'pending_age_seconds' => $latest['pending_age_seconds'] ?? null,
            'execution_state' => (string)($latest['execution_state'] ?? 'not_seen'),
            'execution_stall_seconds' => self::EXECUTION_STALL_SECONDS,
            'unresolved_failure' => (bool)($latest['unresolved_failure'] ?? false),
            'last_failed_at' => (string)($latest['last_failed_at'] ?? ''),
            'last_completed_at' => (string)($latest['last_completed_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $probe */
    public static function combine(array $receipt, array $probe, ?int $nowEpoch = null): array
    {
        $now = $nowEpoch ?? time();
        $health = is_array($probe['health'] ?? null) ? $probe['health'] : null;
        $scheduler = is_array($health['scheduler'] ?? null) ? $health['scheduler'] : [];
        $cycleAt = trim((string)($scheduler['last_cycle_at'] ?? ''));
        $cycleAge = self::ageIso($cycleAt, $now);
        $schedulerHealthy = ($scheduler['healthy'] ?? false) === true;
        $schedulerFresh = $cycleAge !== null && $cycleAge <= self::SCHEDULER_FRESH_SECONDS;
        $liveInstance = trim((string)($health['instance_id'] ?? ''));
        $receiptInstance = trim((string)($receipt['latest_instance_id'] ?? ''));
        $instanceMatch = $liveInstance !== '' && $receiptInstance !== '' ? hash_equals($receiptInstance, $liveInstance) : null;
        $probeStatus = (string)($probe['status'] ?? 'not_configured');
        $serviceStatus = (string)($health['status'] ?? '');
        $receiptStatus = (string)($receipt['status'] ?? 'unavailable');

        if ($probeStatus !== 'ok') {
            $status = in_array($receiptStatus, ['observed_recently','degraded'], true) ? 'degraded' : 'unavailable';
        } elseif ($serviceStatus === 'starting') {
            $status = 'starting';
        } elseif (in_array($serviceStatus, ['degraded','maintenance_paused','failed'], true)) {
            $status = 'degraded';
        } elseif ($serviceStatus !== 'running' || !$schedulerHealthy || !$schedulerFresh) {
            $status = 'degraded';
        } elseif ($receiptStatus === 'degraded') {
            $status = 'degraded';
        } elseif ($receiptStatus === 'observed_stale') {
            $status = 'observed_stale';
        } elseif ($receiptStatus === 'observed_recently' && $instanceMatch !== false) {
            $status = 'observed_recently';
        } else {
            $status = 'degraded';
        }

        return array_merge($receipt, [
            'status' => $status,
            'receipt_status' => $receiptStatus,
            'probe' => [
                'status' => $probeStatus,
                'http_status' => (int)($probe['http_status'] ?? 0),
                'error_code' => (string)($probe['error_code'] ?? ''),
            ],
            'service_health' => $health,
            'runtime_version' => (string)($health['runtime_version'] ?? ''),
            'runtime_instance_id' => $liveInstance,
            'scheduler' => [
                'healthy' => $schedulerHealthy,
                'last_cycle_at' => $cycleAt,
                'cycle_age_seconds' => $cycleAge,
                'fresh' => $schedulerFresh,
                'last_error_code' => (string)($scheduler['last_error_code'] ?? ''),
            ],
            'execution' => [
                'state' => (string)($receipt['execution_state'] ?? 'not_seen'),
                'pending_count' => (int)($receipt['pending_count'] ?? 0),
                'last_pending_at' => (string)($receipt['last_pending_at'] ?? ''),
                'pending_age_seconds' => $receipt['pending_age_seconds'] ?? null,
                'last_completed_at' => (string)($receipt['last_completed_at'] ?? ''),
                'last_failed_at' => (string)($receipt['last_failed_at'] ?? ''),
                'recent_failed_count' => (int)($receipt['recent_failed_count'] ?? 0),
                'stall_seconds' => self::EXECUTION_STALL_SECONDS,
            ],
            'supervised_components' => is_array($health['supervised_components'] ?? null) ? $health['supervised_components'] : [],
            'instance_match' => $instanceMatch,
            'scheduler_fresh_seconds' => self::SCHEDULER_FRESH_SECONDS,
        ]);
    }

    private static function ageIso(string $value, int $now): ?int
    {
        if ($value === '') return null;
        $epoch = strtotime($value);
        return $epoch === false ? null : max(0, $now - $epoch);
    }

    private static function age(string $value, int $now): ?int
    {
        $epoch = self::epoch($value);
        return $epoch <= 0 ? null : max(0, $now - $epoch);
    }

    private static function epoch(string $value): int
    {
        if ($value === '') return 0;
        // runtime_trigger_receipts.accepted_at is DATETIME DEFAULT CURRENT_TIMESTAMP.
        // Local Database::connect() sets the MariaDB session time_zone to PHP's
        // app.timezone offset. Bare receipt timestamps are therefore LOCAL,
        // unlike ISO-8601 UTC scheduler timestamps. Never append ' UTC' here.
        $epoch = strtotime($value);
        return $epoch === false ? 0 : $epoch;
    }
}
