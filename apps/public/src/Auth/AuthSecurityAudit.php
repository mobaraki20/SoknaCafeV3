<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Auth;

use PDO;

final class AuthSecurityAudit
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(
        string $installationId,
        ?string $projectionId,
        string $eventKey,
        string $accountKey,
        string $originKey,
        string $correlationId = '',
        array $metadata = [],
    ): void {
        $metadataJson = $metadata === [] ? null : json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_security_audit(installation_id,projection_id,event_key,account_key,origin_key,correlation_id,metadata_json) VALUES(?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $installationId,
            $projectionId !== null && trim($projectionId) !== '' ? trim($projectionId) : null,
            trim($eventKey),
            $accountKey,
            $originKey,
            trim($correlationId) !== '' ? trim($correlationId) : null,
            $metadataJson,
        ]);
    }
}
