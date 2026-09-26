<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class RealtimeHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function enqueue(string $sessionToken, string $rawBody): array
    {
        $session = $this->core->publicSessions()->resolve($sessionToken);
        if (!is_array($session)) return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        return $this->core->realtime()->enqueue($session, $body);
    }

    public function result(string $sessionToken, string $requestId): array
    {
        $session = $this->core->publicSessions()->resolve($sessionToken);
        if (!is_array($session)) return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        return $this->core->realtime()->result($session, $requestId);
    }

    public function claim(
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
        return $this->core->realtime()->claim(
            (string)$verification['installation_id'],
            (int)($body['lease_seconds'] ?? 20),
        );
    }

    public function ack(
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
        return $this->core->realtime()->ack((string)$verification['installation_id'], $body);
    }
}
