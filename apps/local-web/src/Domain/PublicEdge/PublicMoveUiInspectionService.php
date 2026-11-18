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
    private readonly ?Closure $targetHealth;
    private readonly ?Closure $targetSetupStatus;

    public function __construct(
        private readonly string $currentBaseUrl,
        private readonly string $authorityPhase,
        callable $sameInstancePreflight,
        ?callable $targetHealth = null,
        ?callable $targetSetupStatus = null,
        private readonly string $localInstallationId = ''
    ) {
        $this->sameInstancePreflight=Closure::fromCallable($sameInstancePreflight);
        $this->targetHealth=$targetHealth===null?null:Closure::fromCallable($targetHealth);
        $this->targetSetupStatus=$targetSetupStatus===null?null:Closure::fromCallable($targetSetupStatus);
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
            // A failed same-instance probe does not imply that the new target
            // is healthy, installed, or belongs to this Local. Verify the target
            // independently before allowing even an owner-consent popup.
            return $this->inspectIndependentTarget($base,$e->errorCode);
        } catch (Throwable) {
            return $this->inspectIndependentTarget($base,'same_instance_probe_unavailable');
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

    /**
     * Only chooses the next UI step. No owner grant, secret, Local config,
     * pairing or Public state can be changed by this read-only classifier.
     */
    private function inspectIndependentTarget(array $base,string $probeFailure): array
    {
        // These errors already prove that SAME-instance deployment artifacts
        // are incomplete. Do not bypass a broken PWA/binding via owner consent.
        if (in_array($probeFailure,[
            'public_origin_target_pwa_incomplete',
            'public_origin_target_health_mismatch',
            'public_origin_target_binding_mismatch',
            'deployment_target_manifest_identity_mismatch',
            'deployment_target_manifest_scope_mismatch'
        ],true)) {
            return self::finish($base,'target_deployment_incomplete','blocked',
                'استقرار مقصد اجزای ضروری یا هویت یکسان را تأیید نکرد؛ ابتدا استقرار را اصلاح کنید.');
        }
        if ($this->targetHealth===null || $this->targetSetupStatus===null) {
            return self::finish($base,'target_verification_not_configured','blocked',
                'پیش‌بررسی مستقل مقصد آماده نیست؛ هیچ درخواست انتقالی آغاز نشد.');
        }
        $target=(string)$base['target_base_url'];
        try {
            $health=($this->targetHealth)($target);
        } catch (Throwable) {
            return self::finish($base,'target_health_unreachable','blocked',
                'سلامت مقصد از طریق HTTPS بررسی نشد؛ آدرس، گواهی TLS و دسترسی سرور را بررسی کنید.');
        }
        if (!is_array($health) ||
            ($health['ok']??null)!==true ||
            ($health['component']??null)!=='public-edge' ||
            ($health['database']??null)!=='ready' ||
            !array_key_exists('pending_migrations',$health) ||
            (int)$health['pending_migrations']!==0 ||
            !is_string($health['version']??null) ||
            trim($health['version'])==='') {
            return self::finish($base,'target_health_not_ready','blocked',
                'Public مقصد سالم یا آماده نیست؛ دیتابیس، نسخه و مهاجرت‌های معلق را بررسی کنید.');
        }
        try {
            $setup=($this->targetSetupStatus)($target);
        } catch (Throwable) {
            return self::finish($base,'target_setup_unavailable','blocked',
                'وضعیت نصب مقصد قابل‌خواندن نیست؛ تشخیص نصب تازه یا موجود انجام نشد.');
        }
        if (!is_array($setup) ||
            !is_bool($setup['installed']??null) ||
            !is_bool($setup['paired']??null)) {
            return self::finish($base,'target_setup_invalid','blocked',
                'پاسخ شناسایی نصب مقصد ناقص است؛ انتقال شروع نشد.');
        }
        if ($setup['installed']!==true) {
            return self::finish($base,'target_not_installed','blocked',
                'ابتدا نصب استاندارد وب عمومی مقصد را تکمیل کنید.');
        }
        if (PublicMoveDecisionEngine::canonicalUrl((string)($setup['base_url']??''))!==$target) {
            return self::finish($base,'target_base_url_mismatch','blocked',
                'آدرس پایه گزارش‌شده توسط مقصد با آدرس درخواستی یکسان نیست.');
        }
        if ($setup['paired']===true) {
            $remoteId=trim((string)($setup['installation_id']??''));
            if ($remoteId==='' || $this->localInstallationId==='') {
                return self::finish($base,'target_paired_identity_unknown','blocked',
                    'مالکیت اتصال موجود مقصد روشن نیست؛ برای جلوگیری از جابه‌جایی اشتباه متوقف شد.');
            }
            if (!hash_equals($this->localInstallationId,$remoteId)) {
                return self::finish($base,'target_paired_to_other_local','blocked',
                    'این وب عمومی به Local دیگری متصل است؛ انتقال مالکیت جداگانه لازم است.');
            }
            return self::finish($base,'same_local_existing_public','awaiting_owner',
                'مقصد وب عمومی موجود همین Local است؛ تأیید مستقل مالک مقصد لازم است.');
        }
        return self::finish($base,'fresh_unpaired_public','awaiting_owner',
            'مقصد نصب‌شده و هنوز جفت نشده است؛ مالک همان وب عمومی باید اتصال را تأیید کند.');
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
