<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

/**
 * R251 phase A: side-effect-free decision engine for a small cafe's Public move.
 * It consumes ONLY evidence already vetted by the caller. It performs no HTTP,
 * file, secret, database, or lifecycle writes, and NEVER grants cutover.
 *
 * A changed URL must not be interpreted as an independent installation.
 * An existing signed rebind proof must not be reused for another instance.
 */
final class PublicMoveDecisionEngine
{
    private const KNOWN_PHASES = ['active', 'resync_required', 'reenroll_required'];

    /** @return array<string,mixed> */
    public static function classify(
        string $currentUrl,
        string $targetUrl,
        string $localAuthorityPhase,
        array $verifiedEvidence = [],
    ): array {
        $base = [
            'contract' => 'sokna-public-move-discovery-v1',
            'read_only' => true,
            'writes_performed' => false,
            'safe_to_cutover' => false,
            'local_connection_preserved' => true,
            'target_base_url' => null,
            'current_base_url' => null,
            'route' => 'blocked',
            'state' => 'needs_attention',
            'next_step' => 'none',
            'message' => '',
        ];
        $current = self::canonicalUrl($currentUrl);
        $target = self::canonicalUrl($targetUrl);
        if ($target === null) {
            return self::finish($base, 'invalid_target', 'blocked', 'none', 'آدرس وب عمومی جدید معتبر نیست. آدرس کامل HTTPS را وارد کنید.');
        }
        $base['target_base_url'] = $target;
        $base['current_base_url'] = $current;
        if (!in_array($localAuthorityPhase, self::KNOWN_PHASES, true)) {
            return self::finish($base, 'local_state_unknown', 'blocked', 'review_local_state', 'وضعیت اتصال فعلی روشن نیست؛ انتقال شروع نشده است.');
        }
        if ($current !== null && hash_equals($current, $target)) {
            return self::finish($base, 'already_selected', 'same_destination', 'none', 'همین آدرس قبلاً ثبت شده است. انتقال جدید لازم نیست.');
        }
        if ($current === null) {
            $base['current_base_url'] = null;
        }
        $health = $verifiedEvidence['target_health'] ?? null;
        if (!is_array($health)) {
            return self::finish($base, 'health_not_checked', 'awaiting_health', 'check_target_health', 'آدرس ثبت شد؛ هنوز سلامت وب عمومی جدید بررسی نشده است.');
        }
        if (($health['ok'] ?? null) !== true || ($health['component'] ?? null) !== 'public-edge'
            || ($health['database'] ?? null) !== 'ready' || (int)($health['pending_migrations'] ?? -1) !== 0
            || !is_string($health['version'] ?? null) || trim($health['version']) === '') {
            return self::finish($base, 'target_not_ready', 'blocked', 'fix_target', 'وب عمومی جدید هنوز آماده نیست؛ اتصال فعلی تغییری نکرده است.');
        }
        // Health confirms an app is running, NOT what installation owns it.
        $base['target_version'] = (string)$health['version'];
        $proof = $verifiedEvidence['identity_proof'] ?? null;
        if (!is_array($proof) || ($proof['verified'] ?? null) !== true) {
            return self::finish($base, 'identity_not_proven', 'awaiting_identity', 'prove_destination_identity', 'وب عمومی در دسترس است؛ هنوز هویت نصب و مالکیت مقصد تأیید نشده است.');
        }
        $sourceId = trim((string)($proof['source_instance_id'] ?? ''));
        $targetId = trim((string)($proof['target_instance_id'] ?? ''));
        if ($targetId === '' || $sourceId === '' || strlen($sourceId) > 128 || strlen($targetId) > 128) {
            return self::finish($base, 'identity_incomplete', 'blocked', 'prove_destination_identity', 'شناسایی نصب‌های مبدأ و مقصد کامل نشده است.');
        }
        if (hash_equals($sourceId, $targetId)) {
            if ($localAuthorityPhase !== 'active') {
                return self::finish($base, 'same_instance_recovery_pending', 'recovery_required', 'resolve_authority_state', 'این همان نصب قبلی است، اما اتصال فعلی ابتدا باید ایمن بازیابی شود.');
            }
            return self::finish($base, 'same_instance', 'same_instance_rebind', 'preflight_same_instance', 'این همان وب عمومی با آدرس جدید است؛ مسیر تغییر آدرس مناسب است.');
        }
        // A different installation needs its own explicit, short-lived approval.
        if (($proof['destination_ownership_approved'] ?? null) !== true) {
            return self::finish($base, 'ownership_not_approved', 'independent_destination', 'approve_ownership', 'این یک نصب مستقل است؛ دسترسی به وب عمومی جدید را تأیید کنید.');
        }
        return self::finish($base, 'independent_verified', 'independent_destination', 'plan_safe_handoff', 'مقصد مستقل شناسایی شد؛ اکنون بررسی اطلاعات و آماده‌سازی انتقال لازم است.');
    }

    public static function canonicalUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return null;
        try { $p = parse_url($url); } catch (\ValueError) { return null; }
        if (!is_array($p) || array_key_exists('user',$p) || array_key_exists('pass',$p)
            || array_key_exists('query',$p) || array_key_exists('fragment',$p)) return null;
        if (strtolower((string)($p['scheme'] ?? '')) !== 'https') return null;
        $host = strtolower(trim((string)($p['host'] ?? '')));
        if ($host === '' || str_ends_with($host, '.') || filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) === false) return null;
        if (filter_var($host,FILTER_VALIDATE_IP) !== false || $host === 'localhost' || str_ends_with($host,'.localhost')
            || str_ends_with($host,'.local') || str_ends_with($host,'.internal')) return null;
        if (isset($p['port']) && ((int)$p['port'] < 1 || (int)$p['port'] > 65535)) return null;
        $rawPath = (string)($p['path'] ?? '');
        $decoded = rawurldecode($rawPath);
        if (str_contains($decoded, '\\') || str_contains($decoded, "\0") || preg_match('/[\x00-\x1f\x7f]/', $decoded)) return null;
        foreach (explode('/', $decoded) as $segment) if ($segment === '.' || $segment === '..') return null;
        $path = rtrim($rawPath, '/');
        $port = isset($p['port']) && (int)$p['port'] !== 443 ? ':'.(int)$p['port'] : '';
        return 'https://'.$host.$port.$path;
    }

    private static function finish(array $base,string $code,string $route,string $nextStep,string $message): array
    {
        $base['reason_code'] = $code;
        $base['route'] = $route;
        $base['state'] = in_array($route,['blocked','recovery_required'],true)?'needs_attention':'checking';
        if ($route === 'same_destination') $base['state'] = 'no_change';
        $base['next_step'] = $nextStep;
        $base['message'] = $message;
        return $base;
    }
}
