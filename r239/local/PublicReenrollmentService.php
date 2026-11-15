<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\PublicEdge;

use Sokna\Local\Core\Config;
use Throwable;

final class PublicReenrollmentService
{
    private readonly Config $config;
    private readonly PublicEdgeSyncClient $client;
    private readonly ?PublicAuthorityStateService $authorityState;
    private readonly string $configPath;

    /** Accept both the pre-authority and current constructor ABI during Web self-update. */
    public function __construct(
        Config $config,
        PublicEdgeSyncClient $client,
        PublicAuthorityStateService|string $authorityOrConfigPath,
        ?string $configPath=null,
    ) {
        $this->config=$config;$this->client=$client;
        if(is_string($authorityOrConfigPath)){
            $this->authorityState=null;$this->configPath=$authorityOrConfigPath;
        }else{
            $this->authorityState=$authorityOrConfigPath;$this->configPath=(string)$configPath;
        }
    }

    public function initialPair(string $baseUrl, string $code, string $displayName = '', int $actorId = 0): array
    {
        // A one-time setup code must never silently overwrite an existing Local pairing.
        if ($this->client->configured()) {
            throw new PublicEdgeSyncException('public_already_paired', 'این لوکال قبلاً به پابلیک وصل شده است. برای نصب تازه پابلیک از «بازیابی اتصال پابلیک» استفاده کنید؛ ثبت کد اولیه مجاز نیست.', 409);
        }
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

    /** Read-only, administrator-only guidance. Never includes a secret or an enrollment code. */
    public function replacementPreflight(): array
    {
        if (!$this->client->configured() || $this->authorityState === null) {
            throw new PublicEdgeSyncException('public_replacement_unavailable', 'ابتدا باید اتصال فعلی لوکال و نسخه پشتیبان آن معتبر باشند.', 409);
        }
        $state = $this->authorityState->status();
        $phase = (string)($state['phase'] ?? '');
        if (!in_array($phase, ['active', 'reenroll_required'], true)) {
            throw new PublicEdgeSyncException('public_replacement_busy', 'بازیابی قبلی هنوز در جریان است. وضعیت همگام‌سازی را بررسی کنید.', 409);
        }
        if (!is_dir(dirname($this->configPath)) || !is_writable(dirname($this->configPath))) {
            throw new PublicEdgeSyncException('config_write_failed', 'تنظیمات لوکال قابل نوشتن نیست. بازیابی شروع نشد.', 503);
        }
        $pending = is_file($this->pendingJournalPath());
        $epoch = $this->authorityState->epoch();
        if ($epoch >= 2147483600) throw new PublicEdgeSyncException('authority_epoch_limit', 'نسل اعتبار به محدودیت ایمنی رسیده است.', 409);
        return [
            'ok' => true, 'mode' => 'fresh_public_replacement',
            'installation_id' => $this->client->installationId(),
            'base_url' => $this->client->publicBaseUrl(),
            'current_epoch' => $epoch, 'minimum_next_epoch' => $epoch + 1,
            'phase' => $phase,
            'pending_recovery' => $pending,
            'next_action' => $pending ? 'resume_pending_recovery' : 'create_new_recovery_code',
            'recovery_console_url' => rtrim($this->client->publicBaseUrl(), '/') . '/authority-recovery.php',
            'note' => 'ابتدا در کنسول بازیابی پابلیک کد با نسل حداقل موردنیاز بسازید؛ سپس در همین صفحه بازیابی را تأیید کنید.',
        ];
    }

    /** One explicit recovery path; not a URL rebind, not initial Pair, not device takeover. */
    public function recoverReplacement(
        string $baseUrl, string $code, string $displayName,
        int $actorId, string $confirmation, int $expectedEpoch
    ): array {
        if ($confirmation !== 'REPLACE_PUBLIC') {
            throw new PublicEdgeSyncException('public_replacement_confirmation_required', 'برای بازیابی اتصال، تأیید صریح لازم است.', 422);
        }
        if (is_file($this->pendingJournalPath())) {
            throw new PublicEdgeSyncException('public_recovery_pending_resume_required', 'یک بازیابی نیمه‌تمام ثبت شده است؛ ابتدا همان عملیات را به‌صورت امن ادامه دهید، نه اینکه کد تازه بسازید.', 409);
        }
        $pre = $this->replacementPreflight();
        if ($expectedEpoch !== (int)$pre['current_epoch']) {
            throw new PublicEdgeSyncException('public_replacement_stale_preflight', 'نسل اتصال پس از پیش‌بررسی تغییر کرده است. دوباره بررسی کنید.', 409);
        }
        $requested = $this->requiredCanonicalBase($baseUrl);
        if (!hash_equals((string)$pre['base_url'], $requested)) {
            throw new PublicEdgeSyncException('public_replacement_url_changed', 'برای تغییر دامنه یا مسیر از فرایند جداگانه استفاده کنید؛ در بازیابی نصب تازه، نشانی باید با تنظیم فعلی یکسان باشد.', 409);
        }
        if (trim($code) === '') {
            throw new PublicEdgeSyncException('enrollment_code_required', 'کد بازیابی یک‌بارمصرف لازم است.', 422);
        }
        if ($this->authorityState->phase() === 'active') {
            // Fail closed immediately; normal write/publish must not continue during recovery.
            $this->authorityState->markRestoreRequired('fresh_public_replacement');
        }
        return $this->complete($requested, $code, $displayName, $actorId);
    }

    public function complete(string $baseUrl, string $code, string $displayName = '', int $actorId = 0): array
    {
        return $this->withRecoveryLock(function() use ($baseUrl,$code,$displayName,$actorId): array {
        $code = trim($code);
        if ($code === '') throw new PublicEdgeSyncException('enrollment_code_required', 'کد بازیابی لازم است.', 422);
        if ($this->authorityState === null || !$this->authorityState->reenrollmentRequired()) {
            throw new PublicEdgeSyncException('public_reenroll_requires_recovery', 'ابتدا پیش‌بررسی و تأیید بازیابی اتصال پابلیک را انجام دهید.', 409);
        }
        if (is_file($this->pendingJournalPath())) {
            throw new PublicEdgeSyncException('public_recovery_pending_resume_required', 'بازیابی قبلی نیمه‌تمام است؛ آن را ادامه دهید.', 409);
        }
        $canonicalBase = $this->requiredCanonicalBase($baseUrl);
        $oldEpoch = $this->authorityState->epoch();
        $secret = bin2hex(random_bytes(32));
        $staged = $this->stagePublicConfig($canonicalBase, $secret);
        // Durable marker before the irreversible remote one-time-code operation.
        // Contains NO SECRET. The staged config is chmod 0600 in the protected config directory.
        try {
            $this->createPendingJournal($staged, $canonicalBase, $oldEpoch);
        } catch (Throwable $error) {
            @unlink($staged);
            throw $error;
        }
        try {
            $response = $this->client->reenrollAuthority($canonicalBase, $code, $secret, $displayName);
            $newEpoch=(int)($response['body']['authority_epoch']??0);
            $newIdentity=(string)($response['body']['installation_id']??'');
            if ($newEpoch <= $oldEpoch || !hash_equals($this->client->installationId(), $newIdentity)) {
                throw new PublicEdgeSyncException('public_replacement_epoch_rejected', 'پاسخ پابلیک نسل جدید یا شناسه نصب معتبر ندارد؛ اتصال فعال نشد.', 409);
            }
            // A signed, read-only proof with the proposed NEW secret is required.
            // Remote HTTP 200 alone cannot turn a pending recovery into a working pairing.
            $this->client->probeProposedAuthority($canonicalBase, $secret, $newEpoch);
            $this->finalizePendingConfig($staged, $oldEpoch, $newEpoch);
            return [
                'ok' => true, 'public' => $response['body'],
                'reload_required' => true, 'actor_user_id' => $actorId,
            ];
        } catch (Throwable $error) {
            // NEVER unlink the staged secret or journal after contacting Public. The remote
            // operation may have committed even if the reply was lost. Recovery resumes
            // using a signed proof, not a second one-time-code submission.
            throw $error;
        }
        });
    }

    /** Retry only a journaled operation; cannot create a new pairing or rotate again. */
    public function resumePendingRecovery(int $actorId = 0): array
    {
        return $this->withRecoveryLock(function() use ($actorId): array {
        if ($this->authorityState === null || !$this->client->configured()) {
            throw new PublicEdgeSyncException('public_recovery_unavailable','بازیابی قبلی قابل بررسی نیست.',409);
        }
        $pending = $this->loadPendingJournal();
        $expected = (int)$pending['expected_epoch'];
        $current = $this->authorityState->epoch();
        if ($current < $expected || $current > $expected+16) {
            throw new PublicEdgeSyncException('public_recovery_epoch_changed','نسل اعتبار از زمان شروع بازیابی تغییر کرده است؛ بررسی دستی لازم است.',409);
        }
        $stage = dirname($this->configPath).'/'.$pending['stage_file'];
        $candidate = is_file($stage) ? $stage : $this->configPath;
        if (!is_file($candidate) || !hash_equals((string)$pending['stage_sha256'],(string)hash_file('sha256',$candidate))) {
            throw new PublicEdgeSyncException('public_recovery_staged_config_missing','فایل محافظت‌شده بازیابی تغییر کرده یا در دسترس نیست؛ به کد جدید متوسل نشوید.',409);
        }
        $raw = require $candidate;
        if (!is_array($raw) || !is_array($raw['public']??null)) {
            throw new PublicEdgeSyncException('public_recovery_config_invalid','تنظیمات بازیابی معتبر نیست.',409);
        }
        $url = $this->requiredCanonicalBase((string)($raw['public']['base_url']??''));
        if (!hash_equals((string)$pending['base_url'],$url)
            || !hash_equals((string)$pending['installation_id'],$this->client->installationId())) {
            throw new PublicEdgeSyncException('public_recovery_identity_changed','هویت اتصال بازیابی با پیش‌بررسی متفاوت است.',409);
        }
        $secret = (string)($raw['public']['shared_secret']??'');
        if (strlen($secret)<32)throw new PublicEdgeSyncException('public_recovery_secret_missing','راز محافظت‌شده بازیابی معتبر نیست.',409);
        // If a remote commit succeeded but its response was lost, the signed proof
        // identifies the exact new epoch without consuming a second recovery code.
        $matched=0;
        for($epoch=max($expected+1,$current);$epoch<=$expected+16;$epoch++){
            try {
                $this->client->probeProposedAuthority($url,$secret,$epoch);
                $matched=$epoch;break;
            } catch (PublicEdgeSyncException $error) {
                if (!in_array($error->errorCode,['authority_epoch_mismatch','bad_signature','authority_reenrollment_required','public_recovery_proof_invalid'],true)) {
                    throw $error;
                }
            }
        }
        if($matched===0)throw new PublicEdgeSyncException('public_recovery_remote_unverified','پابلیک هنوز استفاده از راز و نسل جدید را تأیید نکرده است؛ بازیابی امن متوقف ماند.',409);
        $this->finalizePendingConfig($stage,$expected,$matched);
        return ['ok'=>true,'authority_epoch'=>$matched,'reload_required'=>true,'actor_user_id'=>$actorId,
            'note'=>'اعتبار با اثبات امضاشده تطبیق شد؛ همگام‌سازی مجدد هنوز لازم است.'];
        });
    }

    private function withRecoveryLock(callable $work): array
    {
        $lock=$this->configPath.'.public-recovery.lock';
        $fp=@fopen($lock,'c');
        if(!is_resource($fp))throw new PublicEdgeSyncException('public_recovery_lock_unavailable','قفل ایمنی بازیابی در دسترس نیست.',503);
        @chmod($lock,0600);
        if(!flock($fp,LOCK_EX|LOCK_NB)){
            fclose($fp);
            throw new PublicEdgeSyncException('public_recovery_busy','عملیات بازیابی دیگری در جریان است.',409);
        }
        try{return $work();}
        finally{flock($fp,LOCK_UN);fclose($fp);}
    }

    private function pendingJournalPath(): string { return $this->configPath.'.public-recovery-pending.json'; }

    private function createPendingJournal(string $staged,string $baseUrl,int $oldEpoch): void
    {
        $journal = $this->pendingJournalPath();
        if (is_file($journal))throw new PublicEdgeSyncException('public_recovery_pending_resume_required','بازیابی قبلی هنوز تعیین تکلیف نشده است.',409);
        $data=[
            'schema'=>'sokna-public-recovery-journal-v1',
            'stage_file'=>basename($staged),'stage_sha256'=>hash_file('sha256',$staged),
            'base_url'=>$baseUrl,'installation_id'=>$this->client->installationId(),
            'expected_epoch'=>$oldEpoch,'started_at'=>gmdate('c'),
        ];
        $tmp=$journal.'.tmp-'.bin2hex(random_bytes(6));
        $encoded=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$encoded,LOCK_EX)===false){@unlink($tmp);throw new PublicEdgeSyncException('public_recovery_journal_failed','ثبت وضعیت بازیابی ممکن نیست.',503);}
        @chmod($tmp,0600);
        if(!@rename($tmp,$journal)){
            @unlink($tmp);
            throw new PublicEdgeSyncException('public_recovery_journal_failed','ثبت وضعیت بازیابی ممکن نیست.',503);
        }
        @chmod($journal,0600);
    }

    private function loadPendingJournal(): array
    {
        $raw=@file_get_contents($this->pendingJournalPath());
        $data=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($data)||($data['schema']??'')!=='sokna-public-recovery-journal-v1'
            || !preg_match('/^config\.php\.pairing-[a-f0-9]{12}\.tmp$/D',(string)($data['stage_file']??''))
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($data['stage_sha256']??''))) {
            throw new PublicEdgeSyncException('public_recovery_journal_invalid','وضعیت بازیابی قبلی معتبر نیست؛ اتصال تغییر نکرد.',409);
        }
        return $data;
    }

    private function finalizePendingConfig(string $staged,int $expectedEpoch,int $targetEpoch): void
    {
        $currentEpoch=$this->authorityState?->epoch()??0;
        if ($targetEpoch <= $expectedEpoch || $currentEpoch < $expectedEpoch || $currentEpoch > $targetEpoch) {
            throw new PublicEdgeSyncException('public_recovery_epoch_conflict','نسل اعتبار هنگام ثبت بازیابی تغییر کرده است.',409);
        }
        if(is_file($staged))$this->commitPublicConfig($staged);
        if($this->authorityState->epoch() < $targetEpoch)$this->authorityState->markReenrolled($targetEpoch);
        if($this->authorityState->epoch()!==$targetEpoch || !$this->authorityState->resyncRequired()) {
            throw new PublicEdgeSyncException('public_recovery_state_incomplete','تنظیمات نوشته شدند اما حالت بازیابی هنوز تأیید نشده است.',409);
        }
        @unlink($this->pendingJournalPath());
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