<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class AuthHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function projectionSync(
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
        $rows = is_array($body['projections'] ?? null) ? $body['projections'] : [];
        $count = $this->core->authProjections()->sync((string)$verification['installation_id'], $rows);
        return ['status' => 200, 'body' => ['ok' => true, 'synced' => $count]];
    }

    public function login(string $rawBody, string $origin = '', string $correlationId = ''): array
    {
        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        $result = $this->core->loginService()->login(
            (string)($body['installation_id'] ?? ''),
            (string)($body['username'] ?? ''),
            (string)($body['password'] ?? ''),
            $origin,
            $correlationId,
        );
        $status = (int)($result['status'] ?? 500);
        unset($result['status']);
        return ['status' => $status, 'body' => $result];
    }
}
