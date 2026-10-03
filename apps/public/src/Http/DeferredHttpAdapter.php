<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class DeferredHttpAdapter
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
        return $this->core->deferred()->enqueue($session, $body);
    }

    public function list(string $sessionToken, int $limit = 100): array
    {
        $session = $this->core->publicSessions()->resolve($sessionToken);
        if (!is_array($session)) return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        return $this->core->deferred()->list($session, $limit);
    }

    public function result(string $sessionToken, string $requestId): array
    {
        $session = $this->core->publicSessions()->resolve($sessionToken);
        if (!is_array($session)) return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        return $this->core->deferred()->result($session, $requestId);
    }

    public function claim(string $installationId, string $method, string $path, string $timestamp, string $nonce, string $signature, string $rawBody): array
    {
        $verification = $this->verifyLocal($installationId, $method, $path, $timestamp, $nonce, $signature, $rawBody);
        if (($verification['ok'] ?? false) !== true) return $verification['response'];
        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        return $this->core->deferred()->claim((string)$verification['installation_id'], (int)($body['lease_seconds'] ?? 30));
    }

    public function ack(string $installationId, string $method, string $path, string $timestamp, string $nonce, string $signature, string $rawBody): array
    {
        $verification = $this->verifyLocal($installationId, $method, $path, $timestamp, $nonce, $signature, $rawBody);
        if (($verification['ok'] ?? false) !== true) return $verification['response'];
        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        return $this->core->deferred()->ack((string)$verification['installation_id'], $body);
    }

    public function reconcile(string $installationId, string $method, string $path, string $timestamp, string $nonce, string $signature, string $rawBody): array
    {
        $verification = $this->verifyLocal($installationId, $method, $path, $timestamp, $nonce, $signature, $rawBody);
        if (($verification['ok'] ?? false) !== true) return $verification['response'];
        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        return $this->core->deferred()->reconcile((string)$verification['installation_id'], $body);
    }

    public function periodStatus(string $installationId, string $method, string $path, string $timestamp, string $nonce, string $signature, string $rawBody): array
    {
        $verification = $this->verifyLocal($installationId, $method, $path, $timestamp, $nonce, $signature, $rawBody);
        if (($verification['ok'] ?? false) !== true) return $verification['response'];
        $body = json_decode($rawBody, true);
        if (!is_array($body)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        return $this->core->deferred()->periodStatus(
            (string)$verification['installation_id'],
            (string)($body['from_date'] ?? ''),
            (string)($body['to_date'] ?? ''),
        );
    }

    private function verifyLocal(string $installationId, string $method, string $path, string $timestamp, string $nonce, string $signature, string $rawBody): array
    {
        $verification = $this->core->signedLocalRequests()->verify($installationId, $method, $path, $timestamp, $nonce, $rawBody, $signature);
        if (($verification['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'response' => ['status' => (int)$verification['status'], 'body' => ['ok' => false, 'error' => (string)$verification['error']]],
            ];
        }
        return ['ok' => true, 'installation_id' => (string)$verification['installation_id']];
    }
}
