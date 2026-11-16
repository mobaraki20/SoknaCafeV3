<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Sokna\PublicEdge\Auth\PublicSessionStore;

/**
 * R257 upgrade-only owner activation.
 *
 * A previously installed Public is NOT "fresh". There is no anonymous
 * initial-claim endpoint. Its existing currently authenticated Staff admin
 * may initialize the new independent Public owner only when EXACTLY ONE
 * active Local installation exists on this Public database.
 *
 * The activated owner belongs to the Public instance in install.lock.
 * No pairing secret, Local installation identity or order state is changed.
 * Fail closed for zero or multiple active installations: a later explicit
 * authenticated site-owner recovery path must handle those cases.
 */
final class PublicMoveExistingOwnerActivation
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicSessionStore $sessions,
        private readonly PublicMoveOwnerBootstrapService $owners,
        private readonly string $trustedPublicInstanceId,
        private readonly string $trustedBaseUrl,
        private readonly string $serverCsrfKey,
    ) {
        if (strlen($serverCsrfKey) < 32 ||
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D', $trustedPublicInstanceId)) {
            throw new InvalidArgumentException('invalid_upgrade_owner_configuration');
        }
        if (!preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]+)?(?:/[^?#]*)?$#D', $trustedBaseUrl)) {
            throw new InvalidArgumentException('invalid_upgrade_public_base_url');
        }
    }

    /** No mutation. Only verified PublicSessionStore data is trusted. */
    public function preflight(string $staffToken, string $origin, string $fetchSite, int $now): array
    {
        $session = $this->staffAdmin($staffToken, $origin, $fetchSite, $now);
        if ($this->owners->initialized($this->trustedPublicInstanceId)) {
            return ['status' => 'owner_already_ready', 'owner_initialized' => true,
                'requires_action' => false, 'safe_to_cutover' => false];
        }
        return ['status' => 'owner_setup_available', 'owner_initialized' => false,
            'requires_action' => true, 'expires_at' => $now + 300,
            'csrf' => $this->csrf($staffToken, (string)$session['installation_id'], intdiv($now, 300)),
            'safe_to_cutover' => false];
    }

    public function activate(
        string $staffToken, string $origin, string $fetchSite, string $csrf,
        string $newOwnerPassword, int $now
    ): array {
        $session = $this->staffAdmin($staffToken, $origin, $fetchSite, $now);
        $expected = $this->csrf($staffToken, (string)$session['installation_id'], intdiv($now, 300));
        $previous = $this->csrf($staffToken, (string)$session['installation_id'], intdiv($now, 300)-1);
        if ($csrf === '' || !(hash_equals($expected, $csrf) || hash_equals($previous, $csrf))) {
            throw new RuntimeException('upgrade_owner_csrf_rejected');
        }
        // Validate user input BEFORE staging a short-lived claim, so a typo
        // cannot strand the installer in a pending-claim state.
        if (strlen($newOwnerPassword)<12 || strlen($newOwnerPassword)>256) {
            throw new InvalidArgumentException('invalid_owner_password_length');
        }
        if ($this->owners->initialized($this->trustedPublicInstanceId)) {
            throw new RuntimeException('upgrade_owner_already_exists');
        }
        // A trusted authenticated admin may call these internals; there is no
        // public "stage claim" route or unguarded client-supplied installation ID.
        $claim = $this->owners->stageClaim($this->trustedPublicInstanceId, $now, 180);
        $proof = $this->owners->initialize(
            $this->trustedPublicInstanceId, $claim['setup_token'], $newOwnerPassword, $now
        );
        if (($proof['owner_created'] ?? null) !== true) {
            throw new RuntimeException('upgrade_owner_activation_failed');
        }
        return ['status' => 'owner_initialized', 'owner_initialized' => true,
            'login_required' => true, 'safe_to_cutover' => false,
            'local_binding_changed' => false, 'order_intake_changed' => false];
    }

    private function staffAdmin(string $staffToken, string $origin, string $fetchSite, int $now): array
    {
        if ($now <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $staffToken) ||
            $fetchSite !== 'same-origin' || !hash_equals($this->origin(), $origin)) {
            throw new RuntimeException('upgrade_owner_staff_authentication_required');
        }
        $session = $this->sessions->resolve($staffToken);
        if (!is_array($session) || ($session['role'] ?? '') !== 'admin' ||
            !is_string($session['installation_id'] ?? null)) {
            throw new RuntimeException('upgrade_owner_staff_admin_required');
        }
        // Public can store several Local installations. Never allow an admin
        // of just one tenant to claim the *entire* Public hosting instance.
        $rows = $this->pdo->query(
            'SELECT installation_id FROM installations WHERE active=1'
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1 || !hash_equals((string)$rows[0], $session['installation_id'])) {
            throw new RuntimeException('upgrade_owner_site_scope_ambiguous');
        }
        return $session;
    }

    private function csrf(string $sessionToken, string $staffInstallation, int $bucket): string
    {
        return rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            implode("\n", ['sokna-public-upgrade-owner-v1', $this->trustedPublicInstanceId,
                $staffInstallation, $sessionToken, (string)$bucket]),
            $this->serverCsrfKey, true,
        )), '+/', '-_'), '=');
    }

    private function origin(): string
    {
        $p = parse_url($this->trustedBaseUrl);
        if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https' ||
            empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            throw new InvalidArgumentException('upgrade_owner_base_invalid');
        }
        return 'https://'.strtolower($p['host']).
            (isset($p['port']) && (int)$p['port'] !== 443 ? ':'.(int)$p['port'] : '');
    }
}
