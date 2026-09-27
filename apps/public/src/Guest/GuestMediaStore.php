<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

final class GuestMediaStore
{
    private const MAX_BYTES = 8 * 1024 * 1024;
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];

    public function __construct(private readonly string $storageRoot)
    {
    }

    public function put(string $installationId, array $body): array
    {
        $sha = strtolower(trim((string)($body['sha256'] ?? '')));
        $mime = trim((string)($body['mime'] ?? ''));
        $extension = strtolower(trim((string)($body['extension'] ?? '')));
        $declaredSize = (int)($body['size'] ?? 0);
        $encoded = (string)($body['content_base64'] ?? '');

        if (!preg_match('/^[a-f0-9]{64}$/', $sha)
            || !isset(self::ALLOWED[$mime])
            || self::ALLOWED[$mime] !== $extension
            || $declaredSize < 1
            || $declaredSize > self::MAX_BYTES) {
            return $this->error(400, 'invalid_media_metadata');
        }

        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || strlen($bytes) !== $declaredSize || !hash_equals($sha, hash('sha256', $bytes))) {
            return $this->error(422, 'media_integrity_failed');
        }

        $dir = $this->installationDir($installationId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return $this->error(503, 'media_storage_unavailable');
        }

        $path = $dir . DIRECTORY_SEPARATOR . $sha . '.' . $extension;
        if (is_file($path)) {
            $existing = hash_file('sha256', $path);
            if (is_string($existing) && hash_equals($sha, $existing)) {
                return ['status' => 200, 'body' => ['ok' => true, 'sha256' => $sha, 'deduplicated' => true]];
            }
            return $this->error(409, 'media_hash_collision');
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        $written = file_put_contents($tmp, $bytes, LOCK_EX);
        if ($written !== strlen($bytes) || !rename($tmp, $path)) {
            @unlink($tmp);
            return $this->error(503, 'media_write_failed');
        }

        return ['status' => 201, 'body' => ['ok' => true, 'sha256' => $sha, 'deduplicated' => false]];
    }

    /** @return array{path:string,mime:string}|null */
    public function publicFile(string $installationId, string $sha, string $extension): ?array
    {
        $installationId = trim($installationId);
        $sha = strtolower(trim($sha));
        $extension = strtolower(trim($extension));
        if ($installationId === '' || preg_match('/^[A-Za-z0-9._-]{1,96}$/D', $installationId) !== 1) return null;
        if (preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1 || preg_match('/^(jpg|png|webp|gif|svg)$/D', $extension) !== 1) return null;
        $mime = array_search($extension, self::ALLOWED, true);
        if (!is_string($mime)) return null;
        $path = $this->installationDir($installationId) . DIRECTORY_SEPARATOR . $sha . '.' . $extension;
        if (!is_file($path) || !is_readable($path)) return null;
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($sha, $actual)) return null;
        return ['path' => $path, 'mime' => $mime];
    }

    public function verifyManifest(string $installationId, array $manifest): array
    {
        foreach ($manifest as $source => $meta) {
            if (!is_array($meta)) {
                return ['ok' => false, 'status' => 400, 'error' => 'invalid_media_manifest'];
            }
            $sha = strtolower(trim((string)($meta['sha256'] ?? '')));
            $extension = strtolower(trim((string)($meta['extension'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $sha) || !preg_match('/^(jpg|png|webp|gif|svg)$/', $extension)) {
                return ['ok' => false, 'status' => 400, 'error' => 'invalid_media_manifest'];
            }
            $path = $this->installationDir($installationId) . DIRECTORY_SEPARATOR . $sha . '.' . $extension;
            $existing = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($existing) || !hash_equals($sha, $existing)) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'missing_media',
                    'source' => (string)$source,
                    'sha256' => $sha,
                ];
            }
        }
        return ['ok' => true, 'status' => 200];
    }

    private function installationDir(string $installationId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', trim($installationId));
        return rtrim($this->storageRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'guest-media'
            . DIRECTORY_SEPARATOR . ($safe === '' ? '_' : $safe);
    }

    private function error(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    }
}
