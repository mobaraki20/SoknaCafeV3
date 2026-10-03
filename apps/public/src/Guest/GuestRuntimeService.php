<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

use PDO;

final class GuestRuntimeService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function bundle(string $installationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.installation_id,i.display_name,i.active,i.remote_enabled,i.order_intake_enabled,'
            . 'r.revision_id,r.snapshot_json,r.media_manifest_json,r.generated_at,'
            . 'a.payload_json AS availability_json,a.last_sync_at,'
            . 'h.last_seen_at AS heartbeat_at '
            . 'FROM installations i '
            . 'LEFT JOIN guest_active_revisions ar ON ar.installation_id=i.installation_id '
            . 'LEFT JOIN guest_publish_revisions r ON r.installation_id=ar.installation_id AND r.revision_id=ar.revision_id '
            . 'LEFT JOIN guest_availability_state a ON a.installation_id=i.installation_id '
            . 'LEFT JOIN installation_heartbeats h ON h.installation_id=i.installation_id '
            . 'WHERE i.installation_id=? LIMIT 1'
        );
        $stmt->execute([trim($installationId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !(bool)$row['active']) return [];

        $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
        $manifest = json_decode((string)($row['media_manifest_json'] ?? ''), true);
        $availability = json_decode((string)($row['availability_json'] ?? ''), true);
        $row['snapshot'] = is_array($snapshot) ? $snapshot : [];
        $row['media_manifest'] = is_array($manifest) ? $manifest : [];
        $row['availability'] = is_array($availability) ? $availability : [];
        unset($row['snapshot_json'], $row['media_manifest_json'], $row['availability_json']);
        return $row;
    }

    public function actionState(array $bundle, int $freshSeconds = 15): array
    {
        $fresh = max(5, $freshSeconds);
        $now = time();
        $heartbeat = strtotime((string)($bundle['heartbeat_at'] ?? '')) ?: 0;
        $availabilityAt = strtotime((string)($bundle['last_sync_at'] ?? '')) ?: 0;
        $localFresh = $heartbeat >= $now - $fresh;
        $availabilityFresh = $availabilityAt >= $now - $fresh;
        $enabled = (bool)($bundle['remote_enabled'] ?? false)
            && (bool)($bundle['order_intake_enabled'] ?? false)
            && $localFresh
            && $availabilityFresh;

        return [
            'enabled' => $enabled,
            'local_fresh' => $localFresh,
            'availability_fresh' => $availabilityFresh,
            'heartbeat_at' => (string)($bundle['heartbeat_at'] ?? ''),
            'last_sync_at' => (string)($bundle['last_sync_at'] ?? ''),
        ];
    }
}
