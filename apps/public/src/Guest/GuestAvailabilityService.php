<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

use PDO;
use Sokna\PublicEdge\Core\CanonicalJson;

final class GuestAvailabilityService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sync(string $installationId, array $body): array
    {
        $installationId = trim($installationId);
        $generatedAt = trim((string)($body['generated_at'] ?? ''));
        $version = strtolower(trim((string)($body['version'] ?? '')));
        $items = $body['items'] ?? null;
        $acceptance = $body['order_acceptance'] ?? null;
        $generatedTs = strtotime($generatedAt);

        if ($installationId === ''
            || !is_array($items)
            || !is_array($acceptance)
            || !preg_match('/^[a-f0-9]{64}$/', $version)
            || $generatedTs === false) {
            return $this->error(400, 'invalid_availability_payload');
        }

        $canonical = $body;
        unset($canonical['version']);
        $expected = CanonicalJson::sha256($canonical);
        if (!hash_equals($version, $expected)) {
            return $this->error(422, 'availability_integrity_failed');
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO guest_availability_state(installation_id,version,payload_json,generated_at,last_sync_at) '
            . 'VALUES(?,?,?,?,UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE version=VALUES(version),payload_json=VALUES(payload_json),'
            . 'generated_at=VALUES(generated_at),last_sync_at=UTC_TIMESTAMP()'
        );
        $stmt->execute([$installationId, $version, $json, gmdate('Y-m-d H:i:s', $generatedTs)]);

        return ['status' => 200, 'body' => ['ok' => true, 'version' => $version]];
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
