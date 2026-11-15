<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Security;

use RuntimeException;
use InvalidArgumentException;

/**
 * Installation-scoped Ed25519 key material under protected storage OUTSIDE webroot.
 * Key creation must be called only after independently verified owner setup.
 * This helper never provisions owner access or exposes a public HTTP endpoint.
 */
final class PublicMoveInstanceKeyStore
{
    public function __construct(private readonly string $storageRoot, private readonly string $documentRoot) {}

    /** Explicit owner-authorized provisioning. Never silently rotates an existing key. */
    public function provision(string $publicInstanceId, bool $ownerAuthorized): string
    {
        if (!$ownerAuthorized) throw new RuntimeException('move_owner_authorization_required');
        $file = $this->keyFile($publicInstanceId, true);
        if (is_file($file)) return $this->readFile($file);
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        // 'x' is create-only; simultaneous attempts never overwrite an existing key.
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            if (is_file($file)) return $this->readFile($file);
            throw new RuntimeException('move_key_create_failed');
        }
        try {
            if (!@chmod($file, 0600) && PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('move_key_permissions_failed');
            $bytes = fwrite($handle, base64_encode($secret) . "\n");
            if ($bytes !== 89 || !fflush($handle)) throw new RuntimeException('move_key_write_failed');
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($file);
            throw $e;
        }
        fclose($handle);
        return $secret;
    }

    /** Key retrieval is for authenticated server-side signing only. */
    public function load(string $publicInstanceId): string
    {
        return $this->readFile($this->keyFile($publicInstanceId, false));
    }

    private function keyFile(string $instanceId, bool $create): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{5,127}$/D', $instanceId)) {
            throw new InvalidArgumentException('invalid_public_instance_id');
        }
        $doc = realpath($this->documentRoot);
        $storage = realpath($this->storageRoot);
        if ($doc === false || $storage === false || !is_dir($storage) || is_link($this->storageRoot)) {
            throw new RuntimeException('move_storage_unavailable');
        }
        $doc = rtrim(str_replace('\\', '/', $doc), '/');
        $storage = rtrim(str_replace('\\', '/', $storage), '/');
        if ($storage === $doc || str_starts_with($storage . '/', $doc . '/')) {
            throw new RuntimeException('move_key_storage_inside_webroot');
        }
        $dir = $storage . '/move-identity-keys';
        if (!is_dir($dir) && $create && !@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new RuntimeException('move_key_directory_create_failed');
        }
        if (!is_dir($dir) || is_link($dir)) throw new RuntimeException('move_key_directory_invalid');
        $file = $dir . '/' . hash('sha256', $instanceId) . '.key';
        if (is_link($file)) throw new RuntimeException('move_key_symlink_rejected');
        return $file;
    }

    private function readFile(string $file): string
    {
        if (!is_file($file) || is_link($file)) throw new RuntimeException('move_key_missing_or_unsafe');
        if (PHP_OS_FAMILY !== 'Windows' && ((fileperms($file) & 077) !== 0)) {
            throw new RuntimeException('move_key_permissions_unsafe');
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || strlen($raw) !== 89 || !str_ends_with($raw, "\n")) {
            throw new RuntimeException('move_key_corrupt');
        }
        $key = base64_decode(substr($raw, 0, -1), true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('move_key_corrupt');
        }
        return $key;
    }
}
