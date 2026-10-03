<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class GuestSyncHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function media(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawBody,
    ): array {
        $verified = $this->verifiedJson($installationId, $method, $path, $timestamp, $nonce, $signature, $rawBody);
        if (($verified['ok'] ?? false) !== true) return $verified['response'];
        return $this->core->guestMedia()->put((string)$verified['installation_id'], (array)$verified['payload']);
    }

    public function publish(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawBody,
    ): array {
        $verified = $this->verifiedJson($installationId, $method, $path, $timestamp, $nonce, $signature, $rawBody);
        if (($verified['ok'] ?? false) !== true) return $verified['response'];
        return $this->core->guestPublish()->publish((string)$verified['installation_id'], (array)$verified['payload']);
    }

    public function availability(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawBody,
    ): array {
        $verified = $this->verifiedJson($installationId, $method, $path, $timestamp, $nonce, $signature, $rawBody);
        if (($verified['ok'] ?? false) !== true) return $verified['response'];
        return $this->core->guestAvailability()->sync((string)$verified['installation_id'], (array)$verified['payload']);
    }

    private function verifiedJson(
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
                'ok' => false,
                'response' => [
                    'status' => (int)$verification['status'],
                    'body' => ['ok' => false, 'error' => (string)$verification['error']],
                ],
            ];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [
                'ok' => false,
                'response' => ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']],
            ];
        }

        return [
            'ok' => true,
            'installation_id' => (string)$verification['installation_id'],
            'payload' => $payload,
        ];
    }
}
