<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Health;

use PDO;
use Sokna\PublicEdge\Core\SafeErrors;
use Throwable;

final class PublicHealthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function status(?string $correlationId = null): array
    {
        try {
            $probe = $this->pdo->query('SELECT 1')->fetchColumn();
            if ((int)$probe !== 1) return SafeErrors::response(503, 'public_unavailable', $correlationId);
            $count = (int)$this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            return [
                'status' => 200,
                'body' => [
                    'ok' => true,
                    'component' => 'public-edge',
                    'database' => 'ready',
                    'applied_migrations' => $count,
                    'correlation_id' => SafeErrors::correlationId($correlationId),
                ],
            ];
        } catch (Throwable $error) {
            $safe = SafeErrors::response(503, 'public_unavailable', $correlationId);
            $safe['body']['component'] = 'public-edge';
            $safe['body']['database'] = 'unavailable';
            return $safe;
        }
    }
}
