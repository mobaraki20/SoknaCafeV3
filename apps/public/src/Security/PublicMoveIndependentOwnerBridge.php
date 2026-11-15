<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use InvalidArgumentException;

/**
 * R255: consent bridge for a FRESH, INDEPENDENT Public instance without any
 * Local staff projections. The real Public owner session belongs to the Public
 * INSTANCE, NOT to the not-yet-paired Local installation.
 *
 * Security boundary: HTTPS+Origin+Fetch-Site+immutable challenge+session CSRF.
 * This is a read-only attestation prerequisite. It never manufactures a trusted
 * LOCAL owner grant, never claims a Local nonce and never authorizes cutover.
 */
final class PublicMoveIndependentOwnerBridge
{
    public function __construct(
        private readonly PublicMoveOwnerBootstrapService $owners,
        private readonly PublicMoveInstanceKeyStore $keys,
        private readonly string $trustedBaseUrl,
        private readonly string $trustedPublicInstanceId,
        private readonly string $serverOnlyCsrfKey,
    ) {
        if (strlen($serverOnlyCsrfKey)<32 ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D',$trustedPublicInstanceId)) {
            throw new InvalidArgumentException('invalid_independent_owner_bridge_config');
        }
        $this->expectedOrigin();
    }

    public function issueCsrf(string $ownerSessionToken, array $challenge,int $now): string
    {
        $csrf=PublicMoveOwnerConsentGuard::csrf($challenge,$ownerSessionToken,$this->serverOnlyCsrfKey);
        $this->authorize($ownerSessionToken,$challenge,$csrf,$this->expectedOrigin(),'same-origin',$now);
        return $csrf;
    }

    /** @return array<string,mixed> */
    public function attest(
        string $ownerSessionToken,
        array $challenge,
        string $csrf,
        string $requestOrigin,
        string $fetchSite,
        int $now,
    ): array {
        $this->authorize($ownerSessionToken,$challenge,$csrf,$requestOrigin,$fetchSite,$now);
        $privateKey=$this->keys->load($this->trustedPublicInstanceId);
        $envelope=PublicMoveAttestationIssuer::issue(
            $challenge,$this->trustedBaseUrl,$this->trustedPublicInstanceId,$privateKey,$now
        );
        return [
            'attestation'=>$envelope,
            'owner_session_authenticated'=>true,
            'owner_public_instance_id'=>$this->trustedPublicInstanceId,
            'local_installation_id'=>$challenge['local_installation_id'],
            'operation_id'=>$challenge['operation_id'],
            'owner_grant_issued'=>false,
            'nonce_claimed'=>false,
            'safe_to_cutover'=>false,
        ];
    }

    private function authorize(
        string $token,array $challenge,string $csrf,string $origin,string $fetchSite,int $now
    ): void {
        if ($now<=0 || !is_int($challenge['issued_at']??null) ||
            !is_int($challenge['expires_at']??null) ||
            $now < $challenge['issued_at']-30 || $now >= $challenge['expires_at']) {
            throw new InvalidArgumentException('owner_challenge_expired');
        }
        if (!is_string($challenge['target_base_url']??null) ||
            !hash_equals($this->trustedBaseUrl,$challenge['target_base_url'])) {
            throw new InvalidArgumentException('owner_destination_mismatch');
        }
        $session=$this->owners->resolve($token,$now);
        if (!is_array($session) || ($session['role']??null)!=='owner' ||
            !hash_equals($this->trustedPublicInstanceId,(string)($session['public_instance_id']??''))) {
            throw new InvalidArgumentException('destination_owner_session_required');
        }
        if ($fetchSite!=='same-origin' || !hash_equals($this->expectedOrigin(),$origin)) {
            throw new InvalidArgumentException('owner_cross_origin_rejected');
        }
        $expected=PublicMoveOwnerConsentGuard::csrf($challenge,$token,$this->serverOnlyCsrfKey);
        if (strlen($expected)!==strlen($csrf) || !hash_equals($expected,$csrf)) {
            throw new InvalidArgumentException('owner_csrf_rejected');
        }
    }

    private function expectedOrigin(): string
    {
        $parts=parse_url($this->trustedBaseUrl);
        if (!is_array($parts) || ($parts['scheme']??null)!=='https' ||
            !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('invalid_trusted_public_url');
        }
        $host=strtolower((string)$parts['host']);
        if ($host===''||filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false ||
            filter_var($host,FILTER_VALIDATE_IP)!==false) {
            throw new InvalidArgumentException('invalid_trusted_public_host');
        }
        $port=isset($parts['port']) && (int)$parts['port']!==443 ? ':'.(int)$parts['port'] : '';
        return 'https://'.$host.$port;
    }
}
