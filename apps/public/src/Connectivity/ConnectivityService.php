<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Connectivity;

use PDO;

final class ConnectivityService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function heartbeat(
        string $installationId,
        string $localVersion,
        string $runtimeStatus,
        array $telemetry = [],
    ): void {
        $telemetryJson = json_encode(
            $telemetry,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO installation_heartbeats(installation_id,local_version,runtime_status,telemetry_json,last_seen_at) ' .
            'VALUES(?,?,?,?,UTC_TIMESTAMP()) ' .
            'ON DUPLICATE KEY UPDATE local_version=VALUES(local_version),runtime_status=VALUES(runtime_status),' .
            'telemetry_json=VALUES(telemetry_json),last_seen_at=UTC_TIMESTAMP()'
        );
        $stmt->execute([
            trim($installationId),
            trim($localVersion),
            trim($runtimeStatus),
            $telemetryJson,
        ]);
    }

    public function status(string $installationId, int $freshSeconds = 45): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.active,i.remote_enabled,h.local_version,h.runtime_status,h.telemetry_json,h.last_seen_at ' .
            'FROM installations i LEFT JOIN installation_heartbeats h ON h.installation_id=i.installation_id ' .
            'WHERE i.installation_id=? LIMIT 1'
        );
        $stmt->execute([trim($installationId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'known' => false,
                'active' => false,
                'remote_enabled' => false,
                'local_fresh' => false,
                'last_seen_at' => '',
                'local_version' => '',
                'runtime_status' => '',
                'telemetry' => [],
            ];
        }

        $lastSeen = (string)($row['last_seen_at'] ?? '');
        $lastSeenEpoch = $lastSeen !== '' ? (strtotime($lastSeen . ' UTC') ?: 0) : 0;
        $fresh = max(10, $freshSeconds);
        $telemetry = json_decode((string)($row['telemetry_json'] ?? '[]'), true);

        return [
            'known' => true,
            'active' => (bool)($row['active'] ?? false),
            'remote_enabled' => (bool)($row['remote_enabled'] ?? false),
            'local_fresh' => $lastSeenEpoch >= time() - $fresh,
            'last_seen_at' => $lastSeen,
            'local_version' => (string)($row['local_version'] ?? ''),
            'runtime_status' => (string)($row['runtime_status'] ?? ''),
            'telemetry' => is_array($telemetry) ? $telemetry : [],
        ];
    }
}
