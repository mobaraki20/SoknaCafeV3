<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use InvalidArgumentException;
use Sokna\PublicEdge\Auth\PublicSessionStore;

/**
 * Protected bridge between the actual PublicSessionStore and consent policy.
 * No trust in browser-supplied role/installation/owner-grant fields.
 * Does NOT expose an HTTP endpoint or publish a Local owner grant.
 * A freshly installed Public with no admin projection requires setup bootstrap.
 */
final class PublicMoveOwnerSessionBridge
{
    public function __construct(
        private readonly PublicSessionStore $sessions,
        private readonly PublicMoveInstanceKeyStore $keys,
        private readonly string $trustedBaseUrl,
        private readonly string $trustedPublicInstanceId,
        private readonly string $serverOnlyCsrfKey,
    ) {
        if (strlen($serverOnlyCsrfKey) < 32 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D', $trustedPublicInstanceId)) {
            throw new InvalidArgumentException('invalid_public_owner_bridge_config');
        }
    }

    /** Only the real authenticated and currently active administrator can obtain CSRF. */
    public function issueCsrf(string $sessionCookieToken, array $challenge, int $now): string
    {
        $csrf = PublicMoveOwnerConsentGuard::csrf($challenge, $sessionCookieToken, $this->serverOnlyCsrfKey);
        PublicMoveOwnerConsentGuard::authorize(
            $challenge,
            $this->sessions->resolve($sessionCookieToken),
            $sessionCookieToken,
            $csrf,
            $this->expectedOrigin(),
            $this->trustedBaseUrl,
            $this->serverOnlyCsrfKey,
            $now,
        );
        return $csrf;
    }

    /**
     * One signed attestation for a validated POST in an existing paired Public.
     * The Local backend still MUST independently validate the signed owner grant,
     * consume its durable nonce and perform its own cutover checks.
     */
    public function attest(
        string $sessionCookieToken,
        array $challenge,
        string $csrf,
        string $requestOrigin,
        string $fetchSite,
        int $now,
    ): array {
        $eligibility = PublicMoveOwnerConsentGuard::authorize(
            $challenge,
            $this->sessions->resolve($sessionCookieToken),
            $sessionCookieToken,
            $csrf,
            $requestOrigin,
            $this->trustedBaseUrl,
            $this->serverOnlyCsrfKey,
            $now,
            $fetchSite,
        );
        $secret = $this->keys->load($this->trustedPublicInstanceId);
        $envelope = PublicMoveAttestationIssuer::issue(
            $challenge, $this->trustedBaseUrl, $this->trustedPublicInstanceId, $secret, $now,
        );
        return [
            'attestation' => $envelope,
            'local_installation_id' => $eligibility['local_installation_id'],
            'operation_id' => $eligibility['operation_id'],
            'owner_session_authenticated' => true,
            'owner_grant_issued' => false,
            'nonce_claimed' => false,
            'safe_to_cutover' => false,
        ];
    }

    private function expectedOrigin(): string
    {
        $p = parse_url($this->trustedBaseUrl);
        if (!is_array($p) || ($p['scheme'] ?? null) !== 'https' || !isset($p['host'])) {
            throw new InvalidArgumentException('invalid_trusted_public_base_url');
        }
        return 'https://'.$p['host'].(isset($p['port']) && (int)$p['port'] !== 443 ? ':'.$p['port'] : '');
    }
}
