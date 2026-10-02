<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use Sokna\Local\Core\Config;
use Throwable;

final class PublicReenrollmentService
{
    public function __construct(
        private readonly Config $config,
        private readonly PublicEdgeSyncClient $client,
        private readonly string $configPath,
    ) {
    }

    public function initialPair(string $baseUrl, string $code, string $displayName = '', int $actorId = 0): array
    {
        $code = trim($code);
        if ($code === '') throw new PublicEdgeSyncException('pairing_code_required', 'کد اتصال لازم است.', 422);

        $canonicalBase = $this->requiredCanonicalBase($baseUrl);
        $secret = bin2hex(random_bytes(32));
        $staged = $this->stagePublicConfig($canonicalBase, $secret);
        try {
            $response = $this->client->initialPair($canonicalBase, $code, $secret, $displayName);
            $this->commitPublicConfig($staged);
        } catch (Throwable $error) {
            @unlink($staged);
            throw $error;
        }

        return [
            'ok' => true,
            'public' => $response['body'],
            'reload_required' => true,
            'actor_user_id' => $actorId,
        ];
    }

    public function complete(string $baseUrl, string $code, string $displayName = '', int $actorId = 0): array
    {
        $code = trim($code);
        if ($code === '') throw new PublicEdgeSyncException('enrollment_code_required', 'کد re-enrollment لازم است.', 422);

        $canonicalBase = $this->requiredCanonicalBase($baseUrl);
        $secret = bin2hex(random_bytes(32));
        $staged = $this->stagePublicConfig($canonicalBase, $secret);
        try {
            $response = $this->client->reenroll($canonicalBase, $code, $secret, $displayName);
            $this->commitPublicConfig($staged);
        } catch (Throwable $error) {
            @unlink($staged);
            throw $error;
        }

        return [
            'ok' => true,
            'public' => $response['body'],
            'reload_required' => true,
            'actor_user_id' => $actorId,
        ];
    }

    private function requiredCanonicalBase(string $baseUrl): string
    {
        $canonical = $this->client->canonicalBaseUrl($baseUrl);
        if ($canonical === '') {
            throw new PublicEdgeSyncException('public_url_invalid', 'نشانی Public Edge معتبر نیست.', 422);
        }
        return $canonical;
    }

    /**
     * Build the replacement config before touching Public Edge. This catches invalid/unwritable
     * local configuration first, reducing the chance of a remote pair succeeding while Local
     * cannot persist its half of the shared secret.
     */
    private function stagePublicConfig(string $baseUrl, string $secret): string
    {
        $raw = is_file($this->configPath) ? require $this->configPath : [];
        if (!is_array($raw)) {
            throw new PublicEdgeSyncException('config_unavailable', 'تنظیمات سامانه قابل به‌روزرسانی نیست.', 500);
        }
        $raw['public'] = is_array($raw['public'] ?? null) ? $raw['public'] : [];
        $raw['public']['base_url'] = $baseUrl;
        $raw['public']['shared_secret'] = $secret;

        $directory = dirname($this->configPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new PublicEdgeSyncException('config_write_failed', 'مسیر تنظیمات سامانه قابل نوشتن نیست.', 500);
        }

        $staged = $this->configPath . '.pairing-' . bin2hex(random_bytes(6)) . '.tmp';
        $php = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($raw, true) . ";\n";
        if (file_put_contents($staged, $php, LOCK_EX) === false) {
            @unlink($staged);
            throw new PublicEdgeSyncException('config_write_failed', 'آماده‌سازی تنظیمات اتصال کامل نشد.', 500);
        }
        @chmod($staged, 0600);
        return $staged;
    }

    private function commitPublicConfig(string $staged): void
    {
        if (!is_file($staged)) {
            throw new PublicEdgeSyncException('config_write_failed', 'فایل موقت تنظیمات اتصال پیدا نشد.', 500);
        }

        // POSIX rename is atomic and replaces the destination. Windows PHP may not replace an
        // existing destination, so keep a rollback copy for that path.
        if (@rename($staged, $this->configPath)) {
            @chmod($this->configPath, 0600);
            return;
        }

        $backup = $this->configPath . '.pairing-backup-' . bin2hex(random_bytes(4));
        $hadOriginal = is_file($this->configPath);
        if ($hadOriginal && !@rename($this->configPath, $backup)) {
            @unlink($staged);
            throw new PublicEdgeSyncException('config_write_failed', 'قفل جایگزینی تنظیمات اتصال باز نشد.', 500);
        }
        try {
            if (!@rename($staged, $this->configPath)) {
                if ($hadOriginal) @rename($backup, $this->configPath);
                throw new PublicEdgeSyncException('config_write_failed', 'ثبت اتصال وب عمومی کامل نشد.', 500);
            }
            @chmod($this->configPath, 0600);
            if ($hadOriginal) @unlink($backup);
        } catch (Throwable $error) {
            @unlink($staged);
            throw $error;
        }
    }
}
