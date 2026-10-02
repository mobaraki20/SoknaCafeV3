<?php
declare(strict_types=1);

namespace Sokna\Local\UI;

final class FontRuntime
{
    private const MIN_BYTES = 80000;
    private const MAX_BYTES = 180000;
    private const RETRY_SECONDS = 21600;
    private const VERSION = '33.003';
    private const SHA256 = '4e3fa217d38fdafc1fea4414ceb58ca5e662cf0ab5fa735a8c8c20e8b42cad92';
    private const URLS = [
        'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2',
        'https://raw.githubusercontent.com/rastikerdar/vazirmatn/v33.003/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2',
    ];

    public static function version(): string
    {
        return self::VERSION;
    }

    public static function fontPath(string $packageRoot): string
    {
        return rtrim($packageRoot, "\\/") . DIRECTORY_SEPARATOR . 'SetupCache' . DIRECTORY_SEPARATOR . 'ui-fonts' . DIRECTORY_SEPARATOR . 'Vazirmatn-Variable.woff2';
    }

    public static function valid(string $path): bool
    {
        if (!is_file($path)) return false;
        $size = @filesize($path);
        if (!is_int($size) || $size < self::MIN_BYTES || $size > self::MAX_BYTES) return false;
        $handle = @fopen($path, 'rb');
        if ($handle === false) return false;
        $magic = fread($handle, 4);
        fclose($handle);
        if ($magic !== 'wOF2') return false;
        $hash = @hash_file('sha256', $path);
        return is_string($hash) && hash_equals(self::SHA256, $hash);
    }

    public static function ensure(string $packageRoot, bool $force = false): bool
    {
        $target = self::fontPath($packageRoot);
        if (self::valid($target)) return true;

        $dir = dirname($target);
        $status = $dir . DIRECTORY_SEPARATOR . 'font-install-status.json';
        if (!$force && is_file($status) && (time() - (int)@filemtime($status)) < self::RETRY_SECONDS) return false;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;

        $ok = false;
        foreach (self::URLS as $url) {
            $data = self::fetch($url);
            if ($data === false) continue;
            $temp = $target . '.tmp-' . bin2hex(random_bytes(5));
            $written = @file_put_contents($temp, $data, LOCK_EX);
            if ($written === strlen($data) && self::valid($temp)) {
                if (is_file($target)) @unlink($target);
                if (@rename($temp, $target)) {
                    @chmod($target, 0644);
                    $ok = true;
                    break;
                }
            }
            @unlink($temp);
        }

        @file_put_contents($status, json_encode([
            'ok' => $ok,
            'checked_at' => gmdate('c'),
            'source' => 'vazirmatn-v'.self::VERSION,
            'sha256' => self::SHA256,
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
        return $ok;
    }

    private static function fetch(string $url): string|false
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'SOKNA Local Web font runtime',
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $context);
        if (!is_string($data)) return false;
        $size = strlen($data);
        if ($size < self::MIN_BYTES || $size > self::MAX_BYTES || substr($data, 0, 4) !== 'wOF2') return false;
        return hash_equals(self::SHA256, hash('sha256', $data)) ? $data : false;
    }
}
