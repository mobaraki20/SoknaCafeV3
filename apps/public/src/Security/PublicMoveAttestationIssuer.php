<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use InvalidArgumentException;

/**
 * R252: pure signing primitive. An authenticated, owner-controlled Public
 * endpoint must provision the installation key and guard access before use.
 * This class itself installs no endpoint, accepts no guest request, and changes
 * no database/host state. The signed statement is not permission to cut over.
 */
final class PublicMoveAttestationIssuer
{
    private const CONTRACT = 'sokna-public-move-attestation-v1';
    private const CHALLENGE_KEYS = ['contract', 'operation_id', 'local_installation_id', 'source_public_instance_id', 'source_base_url', 'target_base_url', 'nonce', 'issued_at', 'expires_at'];

    /** @param array<string,mixed> $challenge @return array<string,mixed> */
    public static function issue(array $challenge, string $destinationBaseUrl, string $destinationInstanceId, string $privateKey, int $now): array
    {
        if (array_keys($challenge) !== self::CHALLENGE_KEYS || $challenge['contract'] !== self::CONTRACT ||
            !is_string($challenge['target_base_url']) || !hash_equals($challenge['target_base_url'], $destinationBaseUrl) ||
            !is_int($challenge['issued_at']) || !is_int($challenge['expires_at']) ||
            !is_string($challenge['nonce']) || !preg_match('/^[0-9a-f]{48}$/D', $challenge['nonce']) ||
            $challenge['issued_at'] > $now + 30 || $challenge['expires_at'] <= $now ||
            $challenge['expires_at'] - $challenge['issued_at'] < 30 ||
            $challenge['expires_at'] - $challenge['issued_at'] > 300) {
            throw new InvalidArgumentException('invalid_or_expired_move_challenge');
        }
        foreach (['operation_id', 'local_installation_id', 'source_public_instance_id'] as $field) {
            $id = $challenge[$field];
            if ($field === 'source_public_instance_id' && $id === '') continue;
            self::validId($id);
        }
        self::validId($destinationInstanceId);
        if (strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new InvalidArgumentException('invalid_target_private_key');
        $payload = $challenge;
        $payload['target_instance_id'] = $destinationInstanceId;
        $payload['public_key_b64'] = base64_encode(sodium_crypto_sign_publickey_from_secretkey($privateKey));
        $payload['attested_at'] = $now;
        $bytes = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return ['payload' => $payload, 'signature_b64' => base64_encode(sodium_crypto_sign_detached($bytes, $privateKey))];
    }

    private static function validId(mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D', $id)) throw new InvalidArgumentException('invalid_installation_identity');
    }
}
