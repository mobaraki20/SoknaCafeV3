<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use InvalidArgumentException;

/**
 * R252: pure, read-only owner-bound cryptographic evidence verification.
 * This does not fetch a URL, accept browser-origin approval, claim nonces,
 * persist credentials, connect installations or authorize a cutover.
 *
 * The $ownerGrant MUST be loaded from an authenticated, durable, trusted
 * approval ledger on Local; it MUST NOT be parsed from a remote response.
 */
final class PublicMoveIdentityVerifier
{
    public const CONTRACT = 'sokna-public-move-attestation-v1';
    private const CHALLENGE_KEYS = ['contract', 'operation_id', 'local_installation_id', 'source_public_instance_id', 'source_base_url', 'target_base_url', 'nonce', 'issued_at', 'expires_at'];
    private const ATTESTATION_KEYS = ['contract', 'operation_id', 'local_installation_id', 'source_public_instance_id', 'source_base_url', 'target_base_url', 'nonce', 'issued_at', 'expires_at', 'target_instance_id', 'public_key_b64', 'attested_at'];
    private const MAX_TTL = 300;

    /** @return array<string,mixed> */
    public static function challenge(string $operationId, string $localId, string $sourceInstanceId, string $sourceBaseUrl, string $targetBaseUrl, int $now, int $ttl = 180): array
    {
        self::validId($operationId, 'operation_id');
        self::validId($localId, 'local_installation_id');
        if ($sourceInstanceId !== '') self::validId($sourceInstanceId, 'source_public_instance_id');
        $source = $sourceBaseUrl === '' ? '' : PublicMoveDecisionEngine::canonicalUrl($sourceBaseUrl);
        $target = PublicMoveDecisionEngine::canonicalUrl($targetBaseUrl);
        if ($source === null || $target === null || ($source !== '' && hash_equals($source, $target))) {
            throw new InvalidArgumentException('invalid_source_or_target');
        }
        if ($now <= 0 || $ttl < 30 || $ttl > self::MAX_TTL) throw new InvalidArgumentException('invalid_challenge_time');
        return [
            'contract' => self::CONTRACT,
            'operation_id' => $operationId,
            'local_installation_id' => $localId,
            'source_public_instance_id' => $sourceInstanceId,
            'source_base_url' => $source,
            'target_base_url' => $target,
            'nonce' => bin2hex(random_bytes(24)),
            'issued_at' => $now,
            'expires_at' => $now + $ttl,
        ];
    }

    /**
     * Successful result is proof only of the already owner-approved target
     * public key responding to the challenge. It is NEVER cutover permission.
     *
     * @param array<string,mixed> $challenge
     * @param array<string,mixed> $envelope
     * @param array<string,mixed> $ownerGrant trusted server-side record
     * @return array<string,mixed>
     */
    public static function verify(array $challenge, array $envelope, array $ownerGrant, int $now): array
    {
        self::requireShape($challenge, self::CHALLENGE_KEYS);
        if ($challenge['contract'] !== self::CONTRACT) throw new InvalidArgumentException('wrong_challenge_contract');
        foreach (['operation_id', 'local_installation_id'] as $field) self::validId($challenge[$field], $field);
        if ($challenge['source_public_instance_id'] !== '') self::validId($challenge['source_public_instance_id'], 'source_public_instance_id');
        if (!is_string($challenge['nonce']) || !preg_match('/^[0-9a-f]{48}$/D', $challenge['nonce'])) throw new InvalidArgumentException('invalid_challenge_nonce');
        if (!is_int($challenge['issued_at']) || !is_int($challenge['expires_at']) ||
            $challenge['issued_at'] > $now + 30 || $challenge['expires_at'] <= $now ||
            $challenge['expires_at'] - $challenge['issued_at'] < 30 ||
            $challenge['expires_at'] - $challenge['issued_at'] > self::MAX_TTL) {
            throw new InvalidArgumentException('expired_or_invalid_challenge');
        }
        $source = $challenge['source_base_url'];
        $target = $challenge['target_base_url'];
        if (!is_string($source) || !is_string($target) ||
            ($source !== '' && PublicMoveDecisionEngine::canonicalUrl($source) !== $source) ||
            PublicMoveDecisionEngine::canonicalUrl($target) !== $target ||
            ($source !== '' && hash_equals($source, $target))) throw new InvalidArgumentException('challenge_url_invalid');

        if (array_keys($envelope) !== ['payload', 'signature_b64'] || !is_array($envelope['payload']) || !is_string($envelope['signature_b64'])) {
            throw new InvalidArgumentException('invalid_attestation_envelope');
        }
        $payload = $envelope['payload'];
        self::requireShape($payload, self::ATTESTATION_KEYS);
        foreach (self::CHALLENGE_KEYS as $key) {
            if ($payload[$key] !== $challenge[$key]) throw new InvalidArgumentException('attestation_binding_mismatch');
        }
        self::validId($payload['target_instance_id'], 'target_instance_id');
        if (!is_int($payload['attested_at']) || $payload['attested_at'] < $challenge['issued_at'] - 30 ||
            $payload['attested_at'] > $now + 30 || $payload['attested_at'] >= $challenge['expires_at']) {
            throw new InvalidArgumentException('attestation_time_invalid');
        }
        $key = self::decodeKey($payload['public_key_b64'], SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'invalid_public_key');
        $signature = self::decodeKey($envelope['signature_b64'], SODIUM_CRYPTO_SIGN_BYTES, 'invalid_signature');
        if (!sodium_crypto_sign_verify_detached($signature, self::canonical($payload), $key)) {
            throw new InvalidArgumentException('signature_not_verified');
        }
        // An attacker can sign their own URL. This trusted grant is the essential
        // external proof that an authenticated operator controls the TARGET.
        if (($ownerGrant['provenance'] ?? null) !== 'authenticated_target_admin_session' ||
            ($ownerGrant['status'] ?? null) !== 'approved' ||
            !is_string($ownerGrant['approval_receipt_id'] ?? null) ||
            strlen($ownerGrant['approval_receipt_id']) < 12 ||
            !is_int($ownerGrant['expires_at'] ?? null) || $ownerGrant['expires_at'] <= $now ||
            $ownerGrant['expires_at'] > $challenge['expires_at'] ||
            !is_string($ownerGrant['public_key_b64'] ?? null) ||
            !hash_equals($ownerGrant['public_key_b64'], $payload['public_key_b64']) ||
            !is_string($ownerGrant['target_instance_id'] ?? null) ||
            !hash_equals($ownerGrant['target_instance_id'], $payload['target_instance_id']) ||
            !is_string($ownerGrant['target_base_url'] ?? null) ||
            !hash_equals($ownerGrant['target_base_url'], $target) ||
            !is_string($ownerGrant['local_installation_id'] ?? null) ||
            !hash_equals($ownerGrant['local_installation_id'], $challenge['local_installation_id']) ||
            !is_string($ownerGrant['operation_id'] ?? null) ||
            !hash_equals($ownerGrant['operation_id'], $challenge['operation_id']) ||
            !is_string($ownerGrant['nonce'] ?? null) ||
            !hash_equals($ownerGrant['nonce'], $challenge['nonce'])) {
            throw new InvalidArgumentException('trusted_owner_approval_required');
        }
        return [
            'verified' => true,
            'destination_ownership_approved' => true,
            'source_instance_id' => $challenge['source_public_instance_id'],
            'target_instance_id' => $payload['target_instance_id'],
            'target_base_url' => $target,
            'local_installation_id' => $challenge['local_installation_id'],
            'operation_id' => $challenge['operation_id'],
            'approval_receipt_id' => $ownerGrant['approval_receipt_id'],
            'safe_to_cutover' => false,
            'nonce_claim_required' => true,
            'read_only' => true,
        ];
    }

    /** Strict canonical serializer for signed fields, with fixed wire order. */
    public static function canonical(array $payload): string
    {
        self::requireShape($payload, self::ATTESTATION_KEYS);
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $expected */
    private static function requireShape(array $value, array $expected): void
    {
        if (array_keys($value) !== $expected) throw new InvalidArgumentException('invalid_wire_shape');
    }

    private static function validId(mixed $id, string $field): void
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D', $id)) {
            throw new InvalidArgumentException('invalid_' . $field);
        }
    }

    private static function decodeKey(mixed $input, int $expectedSize, string $error): string
    {
        if (!is_string($input)) throw new InvalidArgumentException($error);
        $binary = base64_decode($input, true);
        if ($binary === false || strlen($binary) !== $expectedSize || base64_encode($binary) !== $input) {
            throw new InvalidArgumentException($error);
        }
        return $binary;
    }
}
