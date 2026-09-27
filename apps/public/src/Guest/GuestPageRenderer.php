<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

final class GuestPageRenderer
{
    public function __construct(private readonly GuestRuntimeService $runtime)
    {
    }

    /**
     * Render the Public-owned Guest surface from immutable publish + availability projections.
     * No Local business rule is reimplemented here: write eligibility comes only from actionState()
     * and projected acceptance/session state.
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function render(string $installationId, array $query = [], array $endpoints = []): array
    {
        $installationId = trim($installationId);
        $bundle = $this->runtime->bundle($installationId);
        $snapshot = is_array($bundle['snapshot'] ?? null) ? $bundle['snapshot'] : [];
        if ($snapshot === []) {
            return $this->statePage(
                503,
                'منوی عمومی هنوز منتشر نشده',
                'لطفاً کمی بعد دوباره امتحان کنید.',
                false,
                $endpoints,
            );
        }

        $tableToken = trim((string)($query['table'] ?? ''));
        $table = $tableToken !== '' ? $this->findTable($snapshot, $tableToken) : null;
        if ($tableToken !== '' && $table === null) {
            return $this->statePage(
                404,
                'این QR معتبر نیست',
                'این QR ممکن است قدیمی یا مربوط به میزی غیرفعال باشد. QR موجود روی میز را دوباره اسکن کنید یا از همکاران کافه کمک بگیرید.',
                true,
                $endpoints,
            );
        }

        $actionState = $this->runtime->actionState($bundle);
        $availability = is_array($bundle['availability'] ?? null) ? $bundle['availability'] : [];
        $catalog = $this->catalog($snapshot, trim((string)($query['menu'] ?? '')));
        $categories = $this->categoriesWithItems($catalog, $availability);
        $menus = $this->menus($snapshot);
        $selectedMenuKey = (string)($catalog['menu_key'] ?? '');
        $acceptance = $this->orderAcceptance($availability);
        $session = $table !== null ? $this->projectedSession($availability, (int)($table['id'] ?? 0)) : null;
        $sessionsEnabled = !empty($snapshot['features']['table_sessions_enabled']);
        $sessionAllowsOrder = !$sessionsEnabled || ($session !== null && (string)($session['status'] ?? '') === 'active');
        $canOrder = $table !== null
            && ($actionState['enabled'] ?? false) === true
            && $acceptance
            && $sessionAllowsOrder;
        $waiterEnabled = $table !== null
            && ($actionState['enabled'] ?? false) === true
            && !empty($availability['waiter_enabled_table']);

        $displayName = trim((string)($snapshot['cafe_name'] ?? $snapshot['name'] ?? $bundle['display_name'] ?? 'سکنا'));
        if ($displayName === '') $displayName = 'سکنا';
        $cssUrl = $this->safeAssetUrl((string)($endpoints['css'] ?? '/assets/scds/guest.css'));
        $jsUrl = $this->safeAssetUrl((string)($endpoints['js'] ?? '/assets/scds/guest.js'));
        $orderEndpoint = $this->safeAssetUrl((string)($endpoints['create_order'] ?? ''));
        $waiterEndpoint = $this->safeAssetUrl((string)($endpoints['waiter_call'] ?? ''));
        $mediaBase = $this->safeAssetUrl((string)($endpoints['media_base'] ?? ''));
        $manifest = is_array($bundle['media_manifest'] ?? null) ? $bundle['media_manifest'] : [];
        $presentation = is_array($snapshot['presentation'] ?? null) ? $snapshot['presentation'] : [];
        $copy = is_array($presentation['copy'] ?? null) ? $presentation['copy'] : [];
        $themeCssUrl = $this->safeAssetUrl((string)($endpoints['theme_css'] ?? ''));
        $marketing = is_array($snapshot['marketing'] ?? null) ? $snapshot['marketing'] : [];

        $html = '<!doctype html><html lang="fa" dir="rtl"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . ($tableToken !== '' ? '<meta name="robots" content="noindex,nofollow,noarchive">' : '')
            . '<title>' . self::e($displayName) . ' | منو</title>'
            . '<link rel="stylesheet" href="' . self::e($cssUrl) . '">'
            . ($themeCssUrl !== '' ? '<link rel="stylesheet" href="' . self::e($themeCssUrl) . '">' : '')
            . '<script defer src="' . self::e($jsUrl) . '"></script>'
            . '</head><body class="sg-body">'
            . '<div class="sg-app" data-sg-app'
            . ' data-installation-id="' . self::e($installationId) . '"'
            . ' data-table-token="' . self::e($tableToken) . '"'
            . ' data-order-endpoint="' . self::e($orderEndpoint) . '"'
            . ' data-waiter-endpoint="' . self::e($waiterEndpoint) . '">';

        $html .= '<header class="sg-header"><div class="sg-brand">'
            . '<span class="sg-brand-mark" aria-hidden="true">س</span>'
            . '<div><strong>' . self::e($displayName) . '</strong><span>' . self::e($this->copy($copy,'menu_subtitle','منوی عمومی')) . '</span></div></div>';
        if ($table !== null) {
            $tableName = trim((string)($table['name'] ?? ''));
            $html .= '<span class="sg-table-badge">' . self::e($tableName !== '' ? $tableName : 'میز') . '</span>';
        }
        $html .= '</header>';

        if (($actionState['enabled'] ?? false) === true) {
            $html .= '<div class="sg-action-state is-ready" role="status"><strong>' . self::e($this->copy($copy,'ready_title','ارتباط زنده برقرار است')) . '</strong><span>' . self::e($this->copy($copy,'ready_body','وضعیت سفارش‌گیری از کافه به‌روز است.')) . '</span></div>';
        } else {
            $html .= '<div class="sg-action-state is-degraded" role="status"><strong>' . self::e($this->copy($copy,'degraded_title','منو در حالت فقط‌خواندنی است')) . '</strong><span>' . self::e($this->copy($copy,'degraded_body','ارتباط زنده با کافه موقتاً در دسترس نیست؛ مشاهده منو ادامه دارد.')) . '</span></div>';
        }

        $html .= '<main class="sg-main">';
        $campaigns = is_array($marketing['campaigns'] ?? null) ? $marketing['campaigns'] : [];
        $events = is_array($marketing['events'] ?? null) ? $marketing['events'] : [];
        if ($campaigns !== [] || $events !== []) {
            $html .= '<section class="sg-marketing" aria-label="خبر و رویداد">';
            foreach ($campaigns as $campaign) {
                if (!is_array($campaign)) continue;
                $headline = trim((string)($campaign['headline'] ?? ''));
                if ($headline === '') continue;
                $html .= '<article class="sg-promo-card"><span>خبر سکنا</span><h2>' . self::e($headline) . '</h2>';
                $body = trim((string)($campaign['body'] ?? ''));
                if ($body !== '') $html .= '<p>' . self::e($body) . '</p>';
                $html .= '</article>';
            }
            foreach ($events as $event) {
                if (!is_array($event)) continue;
                $title = trim((string)($event['title'] ?? ''));
                if ($title === '') continue;
                $html .= '<article class="sg-promo-card sg-event-card"><span>رویداد</span><h2>' . self::e($title) . '</h2>';
                $desc = trim((string)($event['description'] ?? ''));
                if ($desc !== '') $html .= '<p>' . self::e($desc) . '</p>';
                $meta = array_filter([trim((string)($event['starts_at'] ?? '')), trim((string)($event['location_label'] ?? ''))], static fn(string $v): bool => $v !== '');
                if ($meta !== []) $html .= '<small>' . self::e(implode(' · ', $meta)) . '</small>';
                $html .= '</article>';
            }
            $html .= '</section>';
        }
        $html .= '<section class="sg-search" aria-label="جست‌وجوی منو">'
            . '<label for="sgMenuSearch">' . self::e($this->copy($copy,'search_label','جست‌وجوی منو')) . '</label>'
            . '<input id="sgMenuSearch" data-sg-search type="search" inputmode="search" autocomplete="off" placeholder="' . self::e($this->copy($copy,'search_placeholder','نام نوشیدنی یا غذا را بنویسید')) . '">'
            . '</section>';

        if (count($menus) > 1) {
            $html .= '<nav class="sg-menu-switcher" aria-label="انتخاب منو">';
            foreach ($menus as $menu) {
                $key = (string)($menu['menu_key'] ?? '');
                if ($key === '') continue;
                $params = ['menu' => $key];
                if ($tableToken !== '') $params['table'] = $tableToken;
                $href = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                $selected = hash_equals($selectedMenuKey, $key);
                $html .= '<a class="sg-menu-link' . ($selected ? ' is-active' : '') . '" href="' . self::e($href) . '"'
                    . ($selected ? ' aria-current="page"' : '') . '>' . self::e((string)($menu['name'] ?? 'منو')) . '</a>';
            }
            $html .= '</nav>';
        }

        if ($categories === []) {
            $html .= '<section class="sg-empty" role="status"><h1>' . self::e($this->copy($copy,'empty_title','منو هنوز آیتمی ندارد')) . '</h1><p>' . self::e($this->copy($copy,'empty_body','لطفاً کمی بعد دوباره بررسی کنید.')) . '</p></section>';
        } else {
            $html .= '<nav class="sg-category-nav" aria-label="دسته‌بندی‌های منو">';
            foreach ($categories as $category) {
                $id = (int)($category['id'] ?? 0);
                $html .= '<a href="#sg-category-' . $id . '">' . self::e((string)($category['name'] ?? 'دسته')) . '</a>';
            }
            $html .= '</nav><div class="sg-catalog" data-sg-catalog>';

            foreach ($categories as $category) {
                $categoryId = (int)($category['id'] ?? 0);
                $html .= '<section class="sg-category" id="sg-category-' . $categoryId . '"><header><h2>'
                    . self::e((string)($category['name'] ?? 'دسته')) . '</h2><span>'
                    . self::faDigits((string)count((array)($category['items'] ?? []))) . ' انتخاب</span></header>';
                foreach ((array)($category['items'] ?? []) as $item) {
                    if (!is_array($item)) continue;
                    $id = (int)($item['id'] ?? 0);
                    $name = trim((string)($item['name'] ?? ''));
                    $description = trim((string)($item['description'] ?? ''));
                    if ($description !== '' && self::lower($description) === self::lower($name)) $description = '';
                    $price = max(0, (int)($item['price'] ?? 0));
                    $available = !empty($item['available']);
                    $search = trim($name . ' ' . (string)($category['name'] ?? '') . ' ' . $description);
                    $source = (string)($item['image_path'] ?? '');
                    $image = $this->mediaUrl($installationId, $source, $manifest, $mediaBase);
                    $imageAlt = $this->mediaAlt($source, $manifest, $name);

                    $html .= '<article class="sg-item' . (!$available ? ' is-unavailable' : '') . '" data-sg-item data-item-id="' . $id . '" data-search="' . self::e(self::lower($search)) . '">';
                    if ($image !== '') {
                        $html .= '<img class="sg-item-media" src="' . self::e($image) . '" loading="lazy" decoding="async" alt="' . self::e($imageAlt) . '">';
                    } else {
                        $html .= '<div class="sg-item-media sg-item-placeholder" aria-hidden="true">س</div>';
                    }
                    $html .= '<div class="sg-item-copy"><div><h3>' . self::e($name !== '' ? $name : 'آیتم منو') . '</h3>';
                    if ($description !== '') $html .= '<p>' . self::e($description) . '</p>';
                    $html .= '</div><div class="sg-item-meta"><strong>' . self::money($price) . '</strong>';

                    if ($canOrder && $available) {
                        $html .= '<div class="sg-quantity" aria-label="تعداد ' . self::e($name) . '">'
                            . '<button type="button" data-sg-qty-dec="' . $id . '" aria-label="کم کردن">−</button>'
                            . '<output data-sg-qty-value="' . $id . '">۰</output>'
                            . '<button type="button" data-sg-qty-inc="' . $id . '" data-item-price="' . $price . '" data-item-name="' . self::e($name) . '" aria-label="افزودن">+</button>'
                            . '</div>';
                    } else {
                        $status = !$available ? $this->copy($copy,'unavailable','ناموجود') : ($table === null ? $this->copy($copy,'scan_table','برای سفارش QR میز را اسکن کنید') : $this->copy($copy,'ordering_disabled','سفارش‌گیری موقتاً غیرفعال است'));
                        $html .= '<span class="sg-item-state">' . self::e($status) . '</span>';
                    }
                    $html .= '</div></div></article>';
                }
                $html .= '</section>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="sg-no-results" data-sg-no-results hidden>' . self::e($this->copy($copy,'no_results','نتیجه‌ای پیدا نشد.')) . '</div></main>';

        if ($canOrder) {
            $endpointReady = $orderEndpoint !== '';
            $html .= '<aside class="sg-basket" aria-label="سبد سفارش" data-sg-basket>'
                . '<div><span>' . self::e($this->copy($copy,'basket_total','جمع سفارش')) . '</span><strong data-sg-basket-total>۰ تومان</strong></div>'
                . '<button type="button" data-sg-order-submit' . ($endpointReady ? '' : ' disabled') . '>' . self::e($this->copy($copy,'submit_order','ثبت سفارش')) . '</button>'
                . '<p class="sg-inline-message" data-sg-order-message role="status" aria-live="polite"></p>'
                . '</aside>';
        }
        if ($waiterEnabled) {
            $html .= '<button class="sg-waiter" type="button" data-sg-waiter' . ($waiterEndpoint !== '' ? '' : ' disabled') . '>' . self::e($this->copy($copy,'call_waiter','فراخوان گارسون')) . '</button>';
        }

        $html .= '</div></body></html>';

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ],
            'body' => $html,
        ];
    }

    private function menus(array $snapshot): array
    {
        $menus = is_array($snapshot['menus'] ?? null) ? $snapshot['menus'] : [];
        if ($menus !== []) return array_values(array_filter($menus, 'is_array'));
        $catalogs = is_array($snapshot['catalogs'] ?? null) ? $snapshot['catalogs'] : [];
        $result = [];
        foreach ($catalogs as $key => $catalog) {
            if (!is_array($catalog)) continue;
            $menu = is_array($catalog['menu'] ?? null) ? $catalog['menu'] : [];
            $result[] = [
                'menu_key' => (string)($menu['menu_key'] ?? $key),
                'name' => (string)($menu['name'] ?? 'منو'),
                'sort_order' => (int)($menu['sort_order'] ?? 0),
            ];
        }
        return $result;
    }

    private function catalog(array $snapshot, string $requested): array
    {
        $catalogs = is_array($snapshot['catalogs'] ?? null) ? $snapshot['catalogs'] : [];
        if ($catalogs !== []) {
            $menus = $this->menus($snapshot);
            $selected = $menus[0] ?? [];
            if ($requested !== '') {
                foreach ($menus as $menu) {
                    if ((string)($menu['menu_key'] ?? '') === $requested) {
                        $selected = $menu;
                        break;
                    }
                }
            }
            $key = (string)($selected['menu_key'] ?? array_key_first($catalogs) ?? '');
            $catalog = is_array($catalogs[$key] ?? null) ? $catalogs[$key] : [];
            $catalog['menu_key'] = $key;
            return $catalog;
        }

        return [
            'menu_key' => 'main',
            'categories' => is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [],
            'items' => is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [],
        ];
    }

    private function categoriesWithItems(array $catalog, array $availability): array
    {
        $availabilityItems = is_array($availability['items'] ?? null) ? $availability['items'] : [];
        $categories = [];
        foreach ((array)($catalog['categories'] ?? []) as $raw) {
            if (!is_array($raw)) continue;
            $id = (int)($raw['id'] ?? 0);
            $raw['id'] = $id;
            $raw['items'] = [];
            $categories[$id] = $raw;
        }

        foreach ((array)($catalog['items'] ?? []) as $raw) {
            if (!is_array($raw)) continue;
            $id = (int)($raw['id'] ?? 0);
            $categoryId = (int)($raw['category_id'] ?? 0);
            $live = is_array($availabilityItems[(string)$id] ?? null) ? $availabilityItems[(string)$id] : [];
            if (array_key_exists('available', $live)) $raw['available'] = (bool)$live['available'];
            $raw['id'] = $id;
            $raw['category_id'] = $categoryId;
            if (!isset($categories[$categoryId])) {
                $categories[$categoryId] = ['id' => $categoryId, 'name' => (string)($raw['category_name'] ?? 'سایر'), 'items' => []];
            }
            $categories[$categoryId]['items'][] = $raw;
        }

        return array_values($categories);
    }

    private function orderAcceptance(array $availability): bool
    {
        $acceptance = $availability['order_acceptance'] ?? null;
        if (is_bool($acceptance)) return $acceptance;
        if (!is_array($acceptance)) return false;
        if (array_key_exists('cafe', $acceptance)) return (bool)$acceptance['cafe'];
        if (array_key_exists('enabled', $acceptance)) return (bool)$acceptance['enabled'];
        return false;
    }

    private function projectedSession(array $availability, int $tableId): ?array
    {
        $tables = is_array($availability['tables'] ?? null) ? $availability['tables'] : [];
        $row = is_array($tables[(string)$tableId] ?? null) ? $tables[(string)$tableId] : [];
        $session = $row['session'] ?? null;
        return is_array($session) ? $session : null;
    }

    private function findTable(array $snapshot, string $token): ?array
    {
        foreach ((array)($snapshot['tables'] ?? []) as $table) {
            if (is_array($table) && isset($table['token']) && hash_equals((string)$table['token'], $token)) return $table;
        }
        return null;
    }

    private function mediaAlt(string $source,array $manifest,string $fallback): string
    {
        $meta=is_array($manifest[$source]??null)?$manifest[$source]:[];
        $alt=trim((string)($meta['alt_text']??''));
        if($alt===''||str_contains($alt,'<')||str_contains($alt,'>'))return $fallback;
        return function_exists('mb_substr')?mb_substr($alt,0,180,'UTF-8'):substr($alt,0,180);
    }

    private function mediaUrl(string $installationId, string $source, array $manifest, string $mediaBase): string
    {
        if ($source === '' || $mediaBase === '') return '';
        $meta = is_array($manifest[$source] ?? null) ? $manifest[$source] : [];
        $sha = strtolower((string)($meta['sha256'] ?? ''));
        $ext = strtolower((string)($meta['extension'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1 || preg_match('/^(jpg|png|webp|gif)$/', $ext) !== 1) return '';
        return rtrim($mediaBase, '/') . '/' . rawurlencode($installationId) . '/' . $sha . '.' . $ext;
    }

    private function statePage(int $status, string $title, string $message, bool $noIndex, array $endpoints): array
    {
        $cssUrl = $this->safeAssetUrl((string)($endpoints['css'] ?? '/assets/scds/guest.css'));
        $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . ($noIndex ? '<meta name="robots" content="noindex,nofollow,noarchive">' : '')
            . '<title>' . self::e($title) . ' | سکنا</title>'
            . '<link rel="stylesheet" href="' . self::e($cssUrl) . '"></head><body class="sg-body">'
            . '<main class="sg-system-state"><span class="sg-system-code">' . self::faDigits((string)$status) . '</span>'
            . '<h1>' . self::e($title) . '</h1><p>' . self::e($message) . '</p></main></body></html>';
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
            ],
            'body' => $html,
        ];
    }

    private function copy(array $copy,string $key,string $fallback,int $max=220): string
    {
        $value=trim((string)($copy[$key]??''));
        if($value===''||str_contains($value,'<')||str_contains($value,'>'))return $fallback;
        return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);
    }

    private function safeAssetUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (str_starts_with($value, '/') || preg_match('#^https://#i', $value) === 1) return $value;
        return '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function money(int $amount): string
    {
        return self::faDigits(number_format($amount)) . ' تومان';
    }

    private static function faDigits(string $value): string
    {
        return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }
}
