<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use Closure;
use Throwable;

/**
 * R258 QA only: read-only entry point for the small-cafe move screen.
 *
 * The sole proof callback MUST be a trusted server-side signed deployment
 * binding preflight (currently PublicOriginRebindService::preflight).
 * An error or a mismatching Public installation is NEVER promoted to consent.
 * This class has no credential, config, ledger or cutover write operations.
 */
final class PublicMoveUiInspectionService
{
    private readonly Closure $sameInstancePreflight;

    public function __construct(
        private readonly string $currentBaseUrl,
        private readonly string $authorityPhase,
        callable $sameInstancePreflight
    ) {
        $this->sameInstancePreflight=Closure::fromCallable($sameInstancePreflight);
    }

    /** @return array<string,mixed> */
    public function inspect(string $requestedTarget): array
    {
        $base=PublicMoveDecisionEngine::classify(
            $this->currentBaseUrl,$requestedTarget,$this->authorityPhase
        );
        // Validation, unchanged URL, and authority recovery all short-circuit
        // BEFORE any network request. Never accept browser "verified" evidence.
        if (($base['reason_code']??'')!=='health_not_checked') return $base;
        if (!in_array($this->authorityPhase,['active','resync_required'],true)) {
            return self::finish($base,'local_recovery_required','blocked',
                'اتصال فعلی ابتدا باید از وضعیت بازیابی خارج شود؛ انتقال آغاز نشده است.');
        }
        if (($base['current_base_url']??null)===null) {
            return self::finish($base,'connection_not_configured','blocked',
                'ابتدا اتصال فعلی وب عمومی باید روشن و تأیید شود.');
        }
        try {
            $proof=($this->sameInstancePreflight)((string)$base['target_base_url']);
        } catch (PublicEdgeSyncException $e) {
            // Different/new Public cannot answer a SAME-instance shared-secret
            // probe. Treat the outcome only as "needs independent OWNER proof".
            // It is never evidence of ownership and never permits cutover.
            return self::finish($base,'target_requires_owner','awaiting_owner',
                'اگر مقصد نصب تازه است، مالک همان وب عمومی باید اتصال را تأیید کند.');
        } catch (Throwable) {
            return self::finish($base,'target_requires_owner','awaiting_owner',
                'برای اتصال به نصب تازه، تأیید مالک مقصد لازم است.');
        }
        // Only signed, server-generated SAME-instance preflight evidence can
        // reach this point. Never trust a JSON body or a frontend flag as proof.
        if (!is_array($proof) ||
            ($proof['ok']??false)!==true ||
            ($proof['target_health_verified']??false)!==true ||
            !is_string($proof['public_instance_id']??null) ||
            trim($proof['public_instance_id'])==='' ||
            !is_string($proof['current_base_url']??null) ||
            !is_string($proof['target_base_url']??null) ||
            !hash_equals((string)$base['current_base_url'],$proof['current_base_url']) ||
            !hash_equals((string)$base['target_base_url'],$proof['target_base_url'])) {
            return self::finish($base,'signed_preflight_incomplete','blocked',
                'شواهد تأیید هویت این مقصد کافی نیست؛ انتقال شروع نشده است.');
        }
        return self::finish($base,'same_instance_verified','ready_for_review',
            'مقصد همان وب عمومی فعلی است و پیش‌بررسی فنی تأیید شد. تغییر آدرس هنوز انجام نشده است.');
    }

    /** Every response remains read-only; no transfer or remote intake toggles. */
    private static function finish(array $base,string $reason,string $route,string $message): array
    {
        $base['reason_code']=$reason;
        $base['route']=$route;
        $base['state']=$route==='ready_for_review'?'awaiting_confirmation':'needs_attention';
        $base['next_step']=match($route) {
            'ready_for_review'=>'separate_verified_commit_required',
            'awaiting_owner'=>'independent_owner_consent_required',
            default=>'none'
        };
        $base['message']=$message;
        $base['read_only']=true;
        $base['writes_performed']=false;
        $base['safe_to_cutover']=false;
        $base['local_connection_preserved']=true;
        return $base;
    }
}
