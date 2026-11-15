<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use InvalidArgumentException;

/**
 * Server-only MAC seal for a previously authenticated destination-owner grant.
 * Key must come from Local's protected credential store, never a Public reply.
 * This does NOT authenticate the target owner or create a browser endpoint.
 */
final class PublicMoveApprovalMac
{
    private const FIELDS = [
        'provenance', 'status', 'approval_receipt_id', 'expires_at',
        'public_key_b64', 'target_instance_id', 'target_base_url',
        'local_installation_id', 'operation_id', 'nonce',
    ];

    /** @param array<string,mixed> $authenticatedGrant */
    public static function seal(array $authenticatedGrant, string $localOnlyKey): array
    {
        if (strlen($localOnlyKey) < 32 || array_keys($authenticatedGrant) !== self::FIELDS) {
            throw new InvalidArgumentException('invalid_local_approval_material');
        }
        $value = self::canonical($authenticatedGrant);
        $authenticatedGrant['authorization_mac_b64'] = base64_encode(hash_hmac('sha256', $value, $localOnlyKey, true));
        return $authenticatedGrant;
    }

    /** @param array<string,mixed> $grant */
    public static function verify(array $grant, string $localOnlyKey): bool
    {
        if (strlen($localOnlyKey) < 32 || array_keys($grant) !== [...self::FIELDS, 'authorization_mac_b64']) return false;
        $mac = $grant['authorization_mac_b64'];
        if (!is_string($mac)) return false;
        $decoded = base64_decode($mac, true);
        if (!is_string($decoded) || strlen($decoded) !== 32 || base64_encode($decoded) !== $mac) return false;
        unset($grant['authorization_mac_b64']);
        try {
            $wanted = hash_hmac('sha256', self::canonical($grant), $localOnlyKey, true);
        } catch (\Throwable) {
            return false;
        }
        return hash_equals($wanted, $decoded);
    }

    private static function canonical(array $grant): string
    {
        if (array_keys($grant) !== self::FIELDS) throw new InvalidArgumentException('invalid_owner_grant_wire_shape');
        return json_encode($grant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
