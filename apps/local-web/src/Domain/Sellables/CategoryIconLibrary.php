<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Sellables;

use RuntimeException;

final class CategoryIconLibrary
{
    private ?array $document = null;

    public function __construct(private readonly string $path) {}

    /** @return list<array{label:string,icons:list<array{key:string,label:string,keywords:list<string>}>}> */
    public function groups(): array
    {
        $doc = $this->document();
        $groups = [];
        foreach ((array)($doc['groups'] ?? []) as $rawGroup) {
            if (!is_array($rawGroup)) continue;
            $label = trim((string)($rawGroup['label'] ?? ''));
            if ($label === '') continue;
            $icons = [];
            foreach ((array)($rawGroup['icons'] ?? []) as $rawIcon) {
                if (!is_array($rawIcon)) continue;
                $key = trim((string)($rawIcon['key'] ?? ''));
                $name = trim((string)($rawIcon['label'] ?? ''));
                if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/D', $key) || $name === '') continue;
                $keywords = [];
                foreach ((array)($rawIcon['keywords'] ?? []) as $keyword) {
                    $keyword = trim((string)$keyword);
                    if ($keyword !== '') $keywords[] = $keyword;
                }
                $icons[] = ['key'=>$key,'label'=>$name,'keywords'=>array_values(array_unique($keywords))];
            }
            if ($icons !== []) $groups[] = ['label'=>$label,'icons'=>$icons];
        }
        if ($groups === []) throw new RuntimeException('Category icon library is empty or invalid.');
        return $groups;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->groups() as $group) foreach ($group['icons'] as $icon) $keys[] = $icon['key'];
        return array_values(array_unique($keys));
    }

    public function isAllowed(?string $key): bool
    {
        $key = trim((string)$key);
        return $key !== '' && in_array($key, $this->keys(), true);
    }

    public function resolve(?string $explicitKey, string $name): string
    {
        $explicitKey = trim((string)$explicitKey);
        if ($this->isAllowed($explicitKey)) return $explicitKey;
        $normalized = self::normalize($name);
        foreach ($this->groups() as $group) {
            foreach ($group['icons'] as $icon) {
                foreach ($icon['keywords'] as $keyword) {
                    $needle = self::normalize($keyword);
                    if ($needle !== '' && str_contains($normalized, $needle)) return $icon['key'];
                }
            }
        }
        return 'list';
    }

    private function document(): array
    {
        if ($this->document !== null) return $this->document;
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        if (!is_string($raw)) throw new RuntimeException('Category icon library file is missing.');
        $doc = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || ($doc['format'] ?? '') !== 'sokna-category-icon-library-v1') {
            throw new RuntimeException('Category icon library contract is invalid.');
        }
        return $this->document = $doc;
    }

    private static function normalize(string $value): string
    {
        $value = str_replace(['ي','ك','‌','-','_','/'], ['ی','ک',' ',' ',' ',' '], trim($value));
        return preg_replace('/\s+/u', ' ', $value) ?: $value;
    }
}
