<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use InvalidArgumentException;

/**
 * A fail-closed authorization boundary for a destination Public administrator.
 * $resolvedSession MUST come from server-side PublicSessionStore::resolve(),
 * never a browser-supplied JSON object. This is a pure guard, not an endpoint,
 * and does not create approval records, enrol Local, or enable order intake.
 *
 * A new unpaired Public with no projected administrator cannot pass this gate;
 * an independent owner setup/login flow must be provided before production use.
 */
final class PublicMoveOwnerConsentGuard
{
    private const CHALLENGE_FIELDS = [
        'contract','operation_id','local_installation_id','source_public_instance_id',
        'source_base_url','target_base_url','nonce','issued_at','expires_at',
    ];

    /** The token is scoped to one session and one immutable challenge. */
    public static function csrf(array $challenge, string $sessionToken, string $serverOnlyKey): string
    {
        self::requireChallenge($challenge);
        if (!self::validSessionToken($sessionToken) || strlen($serverOnlyKey) < 32) {
            throw new InvalidArgumentException('consent_csrf_material_invalid');
        }
        return rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            "sokna-public-move-owner-consent-v1\n" . $sessionToken . "\n" . self::json($challenge),
            $serverOnlyKey,
            true,
        )), '+/', '-_'), '=');
    }

    /**
     * @param array<string,mixed>|null $resolvedSession A verified server-side authenticated session
     * @param array<string,mixed> $challenge A Local-issued, still-unclaimed operation challenge
     * @return array<string,mixed> Eligibility ONLY; does not grant cutover.
     */
    public static function authorize(
        array $challenge,
        ?array $resolvedSession,
        string $sessionToken,
        string $csrf,
        string $origin,
        string $expectedPublicBase,
        string $serverOnlyKey,
        int $now,
        string $fetchSite = 'same-origin',
    ): array {
        self::requireChallenge($challenge);
        if ($now <= 0 || $now < (int)$challenge['issued_at'] - 30 || $now >= (int)$challenge['expires_at']) {
            throw new InvalidArgumentException('consent_challenge_expired');
        }
        $base = self::canonicalUrl($expectedPublicBase);
        if ($base === null || !hash_equals($base, (string)$challenge['target_base_url'])) {
            throw new InvalidArgumentException('consent_destination_mismatch');
        }
        if (!is_array($resolvedSession) ||
            !is_string($resolvedSession['installation_id'] ?? null) ||
            !hash_equals($resolvedSession['installation_id'], (string)$challenge['local_installation_id']) ||
            ($resolvedSession['role'] ?? null) !== 'admin' ||
            !is_string($resolvedSession['projection_id'] ?? null) ||
            $resolvedSession['projection_id'] === '') {
            throw new InvalidArgumentException('consent_admin_session_required');
        }
        if ($fetchSite !== 'same-origin') {
            throw new InvalidArgumentException('consent_same_origin_request_required');
        }
        if (!self::matchOrigin($origin, $base)) {
            throw new InvalidArgumentException('consent_origin_rejected');
        }
        $expected = self::csrf($challenge, $sessionToken, $serverOnlyKey);
        if (strlen($csrf) !== strlen($expected) || !hash_equals($expected, $csrf)) {
            throw new InvalidArgumentException('consent_csrf_rejected');
        }
        return [
            'owner_session_authenticated' => true,
            'admin_projection_id' => $resolvedSession['projection_id'],
            'local_installation_id' => $challenge['local_installation_id'],
            'operation_id' => $challenge['operation_id'],
            'target_base_url' => $base,
            'authorization_scope' => 'destination_owner_preflight',
            'approval_record_created' => false,
            'nonce_claimed' => false,
            'safe_to_cutover' => false,
        ];
    }

    private static function requireChallenge(array $c): void
    {
        if (array_keys($c) !== self::CHALLENGE_FIELDS ||
            ($c['contract'] ?? null) !== 'sokna-public-move-attestation-v1' ||
            !is_string($c['local_installation_id'] ?? null) ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D', $c['local_installation_id']) ||
            !is_string($c['operation_id'] ?? null) ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D', $c['operation_id']) ||
            !is_string($c['nonce'] ?? null) ||
            !preg_match('/^[a-f0-9]{48}$/D', $c['nonce']) ||
            !is_int($c['issued_at'] ?? null) ||
            !is_int($c['expires_at'] ?? null) ||
            $c['expires_at'] - $c['issued_at'] > 300 ||
            $c['expires_at'] - $c['issued_at'] < 30 ||
            self::canonicalUrl((string)($c['target_base_url'] ?? '')) !== $c['target_base_url'] ||
            !is_string($c['source_base_url'] ?? null) ||
            ($c['source_base_url'] !== '' && self::canonicalUrl($c['source_base_url']) !== $c['source_base_url'])) {
            throw new InvalidArgumentException('consent_challenge_invalid');
        }
    }

    /** Deliberately self-contained: Public cannot depend on Local Web classes at runtime. */
    private static function canonicalUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > 2048 || trim($url) !== $url || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
        try { $p = parse_url($url); } catch (\ValueError) { return null; }
        if (!is_array($p) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return null;
        if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return null;
        $host = strtolower((string)($p['host'] ?? ''));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false ||
            filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost' ||
            str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) return null;
        $rawPath = (string)($p['path'] ?? '');
        if (str_contains($rawPath, '\\') || preg_match('/[\x00-\x1f\x7f]/', rawurldecode($rawPath))) return null;
        foreach (explode('/', rawurldecode($rawPath)) as $part) if ($part === '.' || $part === '..') return null;
        $port = isset($p['port']) && (int)$p['port'] !== 443 ? ':' . (int)$p['port'] : '';
        return 'https://' . $host . $port . rtrim($rawPath, '/');
    }

    private static function matchOrigin(string $origin, string $targetBase): bool
    {
        $o = parse_url(trim($origin));
        $t = parse_url($targetBase);
        if (!is_array($o) || !is_array($t) ||
            isset($o['user']) || isset($o['pass']) || isset($o['path']) ||
            isset($o['query']) || isset($o['fragment'])) return false;
        return strtolower((string)($o['scheme'] ?? '')) === 'https' &&
            strtolower((string)($o['host'] ?? '')) === strtolower((string)($t['host'] ?? '')) &&
            (int)($o['port'] ?? 443) === (int)($t['port'] ?? 443);
    }

    private static function validSessionToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private static function json(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
