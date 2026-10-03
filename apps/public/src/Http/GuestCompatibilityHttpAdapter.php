<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class GuestCompatibilityHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function createOrder(string $installationId, string $rawBody): array
    {
        return $this->dispatch($installationId, $rawBody, 'createOrder');
    }

    public function guestOrders(string $installationId, string $rawBody): array
    {
        return $this->dispatch($installationId, $rawBody, 'guestOrders');
    }

    public function orderQuote(string $installationId, string $rawBody): array
    {
        return $this->dispatch($installationId, $rawBody, 'orderQuote');
    }

    public function orderStatus(string $installationId, string $rawBody): array
    {
        return $this->dispatch($installationId, $rawBody, 'orderStatus');
    }

    public function tableContext(string $installationId, string $rawBody): array
    {
        return $this->dispatch($installationId, $rawBody, 'tableContext');
    }

    public function waiterCall(string $installationId, string $rawBody): array
    {
        return $this->dispatch($installationId, $rawBody, 'waiterCall');
    }

    public function metric(string $installationId, string $rawBody): array
    {
        $decoded = $this->decode($rawBody);
        if (isset($decoded['response'])) return $decoded['response'];
        return $this->core->guestCompatibility()->metric();
    }

    private function dispatch(string $installationId, string $rawBody, string $method): array
    {
        $decoded = $this->decode($rawBody);
        if (isset($decoded['response'])) return $decoded['response'];
        return $this->core->guestCompatibility()->{$method}(trim($installationId), $decoded['payload']);
    }

    private function decode(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['response' => ['status' => 400, 'body' => ['success' => false, 'code' => 'invalid_json']]];
        }
        return ['payload' => $payload];
    }
}
