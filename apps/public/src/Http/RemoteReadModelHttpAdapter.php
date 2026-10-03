<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class RemoteReadModelHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function sync(
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
            return [
                'status' => (int)$verification['status'],
                'body' => ['ok' => false, 'error' => (string)$verification['error']],
            ];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        }

        return $this->core->remoteReadModels()->sync(
            (string)$verification['installation_id'],
            $payload,
        );
    }

    public function read(string $sessionToken, string $modelKey): array
    {
        $session = $this->core->publicSessions()->resolve($sessionToken);
        if (!is_array($session)) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        }
        return $this->core->remoteReadModels()->read($session, $modelKey);
    }
}
