<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\GuestContent;

use RuntimeException;

final class ThemePackageManager
{
    private const COLOR_TOKENS = [
        'canvas','surface','surface_soft','text','muted','border','primary','primary_contrast',
        'focus','ready_bg','ready_text','degraded_bg','degraded_text',
    ];
    private const RADIUS_TOKENS = ['radius_md','radius_lg'];

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<array<string,mixed>> */
    public function packages(): array
    {
        if (!is_dir($this->root)) return [];
        $entries = scandir($this->root) ?: [];
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $dir = $this->root . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($dir)) continue;
            try {
                $out[] = $this->loadDir($dir);
            } catch (RuntimeException) {
                // An invalid package never enters the manager catalog.
            }
        }
        usort($out, static fn(array $a,array $b): int => strcmp((string)$a['theme_key'], (string)$b['theme_key']));
        return $out;
    }

    /** @return array<string,mixed> */
    public function get(string $themeKey): array
    {
        $themeKey = trim($themeKey);
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $themeKey)) throw new RuntimeException('invalid_theme_key');
        $dir = $this->root . DIRECTORY_SEPARATOR . $themeKey;
        $package = $this->loadDir($dir);
        if (!hash_equals((string)$package['theme_key'], $themeKey)) throw new RuntimeException('theme_key_directory_mismatch');
        return $package;
    }

    /** @return array{theme_key:string,name:string,version:string,layout:string,fingerprint:string,tokens:array<string,string>,editable_tokens:list<string>} */
    public function resolve(string $themeKey, array $settings = []): array
    {
        $package = $this->get($themeKey);
        $tokens = (array)$package['tokens'];
        $editable = array_values(array_map('strval', (array)$package['editable_tokens']));
        foreach ($settings as $key => $value) {
            $key = (string)$key;
            if (!in_array($key, $editable, true)) throw new RuntimeException('theme_setting_not_editable:' . $key);
            $tokens[$key] = $this->validateToken($key, (string)$value);
        }
        foreach ($tokens as $key => $value) $tokens[(string)$key] = $this->validateToken((string)$key, (string)$value);
        ksort($tokens);
        return [
            'theme_key' => (string)$package['theme_key'],
            'name' => (string)$package['name'],
            'version' => (string)$package['version'],
            'layout' => (string)$package['layout'],
            'fingerprint' => (string)$package['fingerprint'],
            'tokens' => $tokens,
            'editable_tokens' => $editable,
        ];
    }

    /** @return array<string,mixed> */
    private function loadDir(string $dir): array
    {
        if (!is_dir($dir)) throw new RuntimeException('theme_package_missing');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $files[] = $rel;
            if ($rel !== 'theme.json') throw new RuntimeException('theme_package_forbidden_file:' . $rel);
        }
        sort($files);
        if ($files !== ['theme.json']) throw new RuntimeException('theme_package_contract');
        $raw = file_get_contents($dir . DIRECTORY_SEPARATOR . 'theme.json');
        if (!is_string($raw) || strlen($raw) > 65536) throw new RuntimeException('theme_manifest_unreadable');
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) throw new RuntimeException('theme_manifest_invalid_json');
        if (($manifest['format'] ?? '') !== 'sokna-guest-theme-v1') throw new RuntimeException('theme_format');
        $key = trim((string)($manifest['theme_key'] ?? ''));
        $name = trim((string)($manifest['name'] ?? ''));
        $version = trim((string)($manifest['version'] ?? ''));
        $layout = trim((string)($manifest['layout'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $key)) throw new RuntimeException('theme_key');
        if ($name === '' || $this->length($name) > 80) throw new RuntimeException('theme_name');
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) throw new RuntimeException('theme_version');
        if (!in_array($layout, ['cards'], true)) throw new RuntimeException('theme_layout');
        $tokens = is_array($manifest['tokens'] ?? null) ? $manifest['tokens'] : [];
        foreach (array_merge(self::COLOR_TOKENS, self::RADIUS_TOKENS) as $required) {
            if (!array_key_exists($required, $tokens)) throw new RuntimeException('theme_token_missing:' . $required);
        }
        foreach ($tokens as $token => $value) $tokens[(string)$token] = $this->validateToken((string)$token, (string)$value);
        $editable = array_values(array_unique(array_map('strval', is_array($manifest['editable_tokens'] ?? null) ? $manifest['editable_tokens'] : [])));
        foreach ($editable as $token) if (!array_key_exists($token, $tokens)) throw new RuntimeException('theme_editable_unknown:' . $token);
        $canonical = [
            'format' => 'sokna-guest-theme-v1',
            'theme_key' => $key,
            'name' => $name,
            'version' => $version,
            'description' => trim((string)($manifest['description'] ?? '')),
            'layout' => $layout,
            'tokens' => $tokens,
            'editable_tokens' => $editable,
        ];
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $canonical['fingerprint'] = hash('sha256', $encoded);
        return $canonical;
    }

    private function length(string $value): int
    {
        if(function_exists('mb_strlen'))return mb_strlen($value,'UTF-8');
        $ok=preg_match_all('/./us',$value,$m);
        return $ok===false?strlen($value):$ok;
    }

    private function validateToken(string $key, string $value): string
    {
        $value = trim($value);
        if (in_array($key, self::COLOR_TOKENS, true)) {
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/D', $value)) throw new RuntimeException('theme_color_invalid:' . $key);
            return strtoupper($value);
        }
        if (in_array($key, self::RADIUS_TOKENS, true)) {
            if (!preg_match('/^(?:[8-9]|[1-3]\d|40)px$/D', $value)) throw new RuntimeException('theme_radius_invalid:' . $key);
            return strtolower($value);
        }
        throw new RuntimeException('theme_token_unknown:' . $key);
    }
}
