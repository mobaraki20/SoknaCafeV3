<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class ConnectivityHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function heartbeat(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawBody,
    ): array {
        $verification = $this->core->signedLocalRequests()->verify(
            $installationId,
            $method,
            $path,
            $timestamp,
            $nonce,
            $rawBody,
            $signature,
        );
        if (($verification['ok'] ?? false) !== true) {
            return ['status' => (int)$verification['status'], 'body' => ['ok' => false, 'error' => (string)$verification['error']]];
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];

        $telemetry = is_array($body['telemetry'] ?? null) ? $body['telemetry'] : [];
        $this->core->connectivity()->heartbeat(
            (string)$verification['installation_id'],
            (string)($body['local_version'] ?? ''),
            (string)($body['runtime_status'] ?? ''),
            $telemetry,
        );
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function status(string $installationId, int $freshSeconds = 45): array
    {
        $status = $this->core->connectivity()->status($installationId, $freshSeconds);
        return ['status' => ($status['known'] ?? false) ? 200 : 404, 'body' => ['ok' => (bool)($status['known'] ?? false)] + $status];
    }
}
