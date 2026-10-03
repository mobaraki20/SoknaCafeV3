<?php
declare(strict_types=1);

namespace Sokna\Local\Core;

use RuntimeException;

final class Session
{
    public const DEFAULT_LIFETIME = 43200;

    public static function start(Config $config, Observability $observability, ?string $cookiePath = null, ?bool $secure = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $sessionDir = $observability->dataRoot() . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($sessionDir) && !@mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
            throw new RuntimeException('Local session storage could not be created.');
        }
        @chmod($sessionDir, 0700);
        if (!is_writable($sessionDir)) throw new RuntimeException('Local session storage is not writable.');

        session_save_path($sessionDir);
        session_name($config->string('app.session_name', 'SOKNA_CAFE_SID'));

        $lifetime = max(300, (int)$config->get('app.session_lifetime', self::DEFAULT_LIFETIME));
        ini_set('session.gc_maxlifetime', (string)$lifetime);

        if ($secure === null) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
        }

        if ($cookiePath === null) {
            $cookiePath = self::cookiePathForRequest();
        } else {
            $cookiePath = '/' . trim($cookiePath, '/');
            if ($cookiePath !== '/') $cookiePath .= '/';
        }

        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => $secure,
            'cookie_samesite' => 'Lax',
            'cookie_path' => $cookiePath,
            'cookie_lifetime' => $lifetime,
            'gc_maxlifetime' => $lifetime,
            'use_strict_mode' => true,
            'use_only_cookies' => true,
        ]);
    }

    public static function cookiePathForRequest(
        ?string $scriptName = null,
        ?string $scriptFilename = null,
        ?string $documentRoot = null,
        ?string $appRoot = null,
    ): string {
        $scriptName = str_replace('\\', '/', $scriptName ?? (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $scriptName = '/' . ltrim((string)(parse_url($scriptName, PHP_URL_PATH) ?? $scriptName), '/');
        $scriptFilename = str_replace('\\', '/', $scriptFilename ?? (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $documentRoot = str_replace('\\', '/', $documentRoot ?? (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $appRoot = str_replace('\\', '/', $appRoot ?? dirname(__DIR__, 2));

        $normalizeFs = static function (string $path): string {
            if ($path === '') return '';
            $real = realpath($path);
            $path = str_replace('\\', '/', $real !== false ? $real : $path);
            return rtrim($path, '/');
        };

        $appFs = $normalizeFs($appRoot);
        $docFs = $normalizeFs($documentRoot);
        $scriptFs = $normalizeFs($scriptFilename);
        $mount = '';

        if ($appFs !== '' && $docFs !== '' && ($appFs === $docFs || str_starts_with($appFs . '/', $docFs . '/'))) {
            $relative = ltrim(substr($appFs, strlen($docFs)), '/');
            $mount = $relative === '' ? '' : '/' . trim($relative, '/');
        } elseif ($appFs !== '' && $scriptFs !== '' && ($scriptFs === $appFs || str_starts_with($scriptFs . '/', $appFs . '/'))) {
            $relativeFile = ltrim(substr($scriptFs, strlen($appFs)), '/');
            if ($relativeFile !== '') {
                $suffix = '/' . $relativeFile;
                if (str_ends_with($scriptName, $suffix)) {
                    $candidate = substr($scriptName, 0, -strlen($suffix));
                    $mount = $candidate === '' || $candidate === '/' ? '' : '/' . trim($candidate, '/');
                }
            }
        } else {
            $base = basename($scriptName);
            if (in_array($base, ['index.php', 'install.php', 'login.php'], true)) {
                $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
                if ($dir !== '.' && $dir !== '/') $mount = '/' . trim($dir, '/');
            }
        }

        return $mount === '' ? '/' : $mount . '/';
    }

    public static function refreshCookie(int $lifetime = self::DEFAULT_LIFETIME): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !ini_get('session.use_cookies')) return;
        if (PHP_SAPI === 'cli') return;

        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + max(300, $lifetime),
            'path' => (string)($params['path'] ?? '/'),
            'domain' => (string)($params['domain'] ?? ''),
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => (string)($params['samesite'] ?? 'Lax'),
        ]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) return;

        if (ini_get('session.use_cookies') && PHP_SAPI !== 'cli') {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, [
                'path' => (string)($params['path'] ?? '/'),
                'domain' => (string)($params['domain'] ?? ''),
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => (string)($params['samesite'] ?? 'Lax'),
            ]);
        }
        session_destroy();
    }
}
