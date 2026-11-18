<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Recovery;

use Closure;
use PDO;
use Sokna\Local\Core\Observability;

/**
 * R284: an irreversible, offline SOURCE write fence with authenticated handoff.
 *
 * This does NOT claim that the Public host or physical Windows printer has been
 * cut over. Never use a handoff receipt to activate a second live Local without
 * separately verified Public authority and PrintAgent pairing.
 */
final class LocalMachineTransferFence
{
    public const MARKER='lifecycle/local/offline-machine-fenced.json';
    public const RECEIPT_FORMAT='sokna-local-machine-offline-fence-v1';
    private readonly Closure $workersStopped;

    /** $workersStopped must independently verify Apache, Runtime, PrintAgent
     * have stopped (Windows services or an isolated disposable test harness).
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Observability $obs,
        private readonly string $dataRoot,
        private readonly string $installationId,
        callable $workersStopped
    ) { $this->workersStopped=Closure::fromCallable($workersStopped); }

    /** @return array{source_fenced:bool,receipt:array} */
    public function seal(
        string $backupFile,string $mediaArchive,
        string $backupPassphrase,string $mediaPassphrase,
        string $handoffSecret,string $receiptFile,
    ):array {
        $id=trim($this->installationId);
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{11,127}$/D',$id))
            throw new RecoveryException('cutover_source_identity_invalid','شناسه نصب محلی معتبر نیست.',409);
        if(strlen($handoffSecret)<32)
            throw new RecoveryException('cutover_secret_weak','کلید تحویل باید دست‌کم ۳۲ نویسه باشد.',422);
        if(!($this->workersStopped)())
            throw new RecoveryException('cutover_workers_running',
                'تا توقف سرویس وب و PrintAgent و Runtime، قفل انتقال مجاز نیست.',409);
        if(!is_dir($this->dataRoot)||is_link($this->dataRoot))
            throw new RecoveryException('cutover_data_directory_invalid','مسیر خصوصی اطلاعات معتبر نیست.',409);
        foreach([$backupFile,$mediaArchive,$receiptFile] as $p){
            if($p===''||is_file($p)||is_link($p)||!is_dir(dirname($p)))
                throw new RecoveryException('cutover_output_conflict',
                    'خروجی انتقال باید در مسیر معتبر و جدید ساخته شود.',409);
        }
        if(count(array_unique([$backupFile,$mediaArchive,$receiptFile]))!==3)
            throw new RecoveryException('cutover_output_conflict','خروجی‌های انتقال نباید یک مسیر باشند.',409);

        // Source preflight BEFORE fencing. Never lose a print job that has been
        // accepted, started, failed, unknown or waiting for a manual decision.
        $unresolved=$this->pdo->query(
            "SELECT status,COUNT(*) AS n FROM print_jobs
             WHERE status NOT IN ('submitted','cancelled')
             GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC);
        if($unresolved!==[])
            throw new RecoveryException('cutover_unresolved_print_jobs',
                'فیش‌های چاپ‌نشده یا دارای وضعیت نامشخص باید قبل از انتقال بررسی شوند.',409,
                ['statuses'=>array_column($unresolved,'status')]);

        $marker=$this->dataRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,self::MARKER);
        $dir=dirname($marker);
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))
            throw new RecoveryException('cutover_marker_dir_missing','مسیر قفل انتقال آماده نیست.',500);
        if(is_link($marker))
            throw new RecoveryException('cutover_marker_symlink','مسیر قفل انتقال معتبر نیست.',409);
        if(is_file($marker))
            throw new RecoveryException('cutover_already_fenced',
                'این دستگاه قبلاً قفل انتقال شده است؛ برای جلوگیری از تولید دو نسخهٔ متفاوت، عملیات تازه مسدود شد.',409);

        // x mode is an exclusive, persistent, atomic CREATE. Any power loss
        // after this line fails CLOSED on the old HTTP and LAN guest gateways.
        $f=@fopen($marker,'xb');
        if($f===false)
            throw new RecoveryException('cutover_marker_create_failed','قفل انتقال ایجاد نشد.',500);
        $nonce=bin2hex(random_bytes(16));
        $blocked=[
            'format'=>'sokna-old-local-source-fenced-v1',
            'source_installation_id'=>$id,
            'nonce'=>$nonce,'created_at'=>gmdate('c'),
            'phase'=>'blocked_before_business_snapshot','operator_unseal_allowed'=>false,
        ];
        try{
            $bytes=self::json($blocked);
            if(fwrite($f,$bytes)!==strlen($bytes))
                throw new RecoveryException('cutover_marker_write_failed','ثبت قفل انتقال کامل نشد.',500);
            fflush($f);if(function_exists('fsync'))fsync($f);
        }finally{fclose($f);@chmod($marker,0600);}
        // From here onward never automatically remove source fence, including
        // if the encrypted source archive or media validation fails.
        if(!($this->workersStopped)())
            throw new RecoveryException('cutover_workers_started_after_fence',
                'سرویس قدیمی در حین تهیه بکاپ دوباره فعال شده؛ انتقال متوقف شد.',409);

        $backup=new BusinessBackupService($this->pdo,$this->obs,$id);
        $b=$backup->createEncrypted($backupFile,$backupPassphrase,0);
        $businessSha=(string)$b['sha256'];
        $media=new PrivateMediaTransferService();
        $m=$media->export(
            $this->dataRoot.DIRECTORY_SEPARATOR.'guest-media',
            $mediaArchive,$mediaPassphrase,$id,$businessSha
        );
        if(!($this->workersStopped)())
            throw new RecoveryException('cutover_workers_resumed','سرویس روی دستگاه قدیمی دوباره فعال شد.',409);
        $inspection=$backup->inspectEncrypted($backupFile,$backupPassphrase);
        if(($inspection['source_installation_id']??'')!==$id ||
            ($inspection['snapshot_consistency']??'')!=='innodb-repeatable-read-single-transaction-v1')
            throw new RecoveryException('cutover_snapshot_invalid','پشتیبان تجاری با مبدأ مطابقت ندارد.',409);
        $mediaInspection=$media->inspect($mediaArchive,$mediaPassphrase,$id,$businessSha);
        if((int)$mediaInspection['media_files']!==(int)$m['media_files'])
            throw new RecoveryException('cutover_media_file_count_changed','تعداد تصاویر در انتقال ناسازگار است.',409);

        $receipt=[
            'format'=>self::RECEIPT_FORMAT,'source_installation_id'=>$id,
            'operation_nonce'=>$nonce,
            'business_backup_sha256'=>$businessSha,
            'media_archive_sha256'=>(string)$m['sha256'],
            'media_files'=>(int)$m['media_files'],
            'schema_versions'=>(array)($inspection['schema_versions']??[]),
            'created_at'=>gmdate('c'),'source_http_fenced'=>true,
            'source_windows_workers_stopped'=>true,
            'source_public_fenced_remotely'=>false,
            'exclusive_public_authority_transferred'=>false,
            'target_business_activated'=>false,
            'target_physical_printer_tested'=>false,
        ];
        $signed=$receipt;
        $signed['mac']=hash_hmac('sha256',self::json($receipt),$handoffSecret);
        $handle=@fopen($receiptFile,'xb');
        if($handle===false)
            throw new RecoveryException('cutover_receipt_create_failed','رسید انتقال ساخته نشد.',500);
        try{
            $bytes=self::json($signed);
            if(fwrite($handle,$bytes)!==strlen($bytes))
                throw new RecoveryException('cutover_receipt_write_failed','رسید انتقال کامل نشد.',500);
            fflush($handle);if(function_exists('fsync'))fsync($handle);
        }finally{fclose($handle);@chmod($receiptFile,0600);}
        $this->obs->logEvent('warning','recovery.offline_source_fenced',[
            'source_installation_id'=>$id,'operation_nonce'=>$nonce,
            'business_backup_sha256'=>$businessSha,
            'source_public_fenced_remotely'=>false,
        ]);
        return [
            'source_fenced'=>true,'receipt'=>$signed,
            'source_public_owner_transfer_required'=>true,
            'new_local_activation_authorized'=>false,
        ];
    }

    /**
     * Verify the original source identity and *actual encrypted bytes*.
     * No network or source machine needed; this is NOT Public owner approval.
     */
    public static function verify(
        string $receiptFile,string $handoffSecret,
        string $businessFile,string $mediaArchive,
        string $expectedOldInstallationId
    ):array {
        if(strlen($handoffSecret)<32)
            throw new RecoveryException('cutover_secret_weak','کلید تحویل معتبر نیست.',422);
        if(!is_file($receiptFile)||is_link($receiptFile))
            throw new RecoveryException('cutover_receipt_missing','رسید انتقال وجود ندارد.',404);
        $raw=file_get_contents($receiptFile);
        if(!is_string($raw)||strlen($raw)>16384)
            throw new RecoveryException('cutover_receipt_invalid','رسید انتقال معتبر نیست.',422);
        $doc=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($doc)||!is_string($doc['mac']??null)||
            !preg_match('/^[a-f0-9]{64}$/D',$doc['mac']))
            throw new RecoveryException('cutover_receipt_invalid','امضای رسید انتقال معتبر نیست.',422);
        $claimedMac=$doc['mac'];unset($doc['mac']);
        if(!hash_equals(hash_hmac('sha256',self::json($doc),$handoffSecret),$claimedMac))
            throw new RecoveryException('cutover_receipt_bad_signature','رسید انتقال تأیید نشد.',409);
        if(($doc['format']??null)!==self::RECEIPT_FORMAT||
            ($doc['source_installation_id']??null)!==$expectedOldInstallationId||
            ($doc['source_http_fenced']??null)!==true||
            ($doc['exclusive_public_authority_transferred']??null)!==false)
            throw new RecoveryException('cutover_receipt_wrong_source','رسید انتقال با مبدأ مورد انتظار یکسان نیست.',409);
        foreach(['business_backup_sha256'=>$businessFile,'media_archive_sha256'=>$mediaArchive] as $key=>$path){
            if(!is_file($path)||is_link($path)||
                !is_string($doc[$key]??null)||
                !hash_equals($doc[$key],hash_file('sha256',$path)))
                throw new RecoveryException('cutover_archive_hash_mismatch',
                    'فایل‌های انتقال با رسید امضا‌شده یکسان نیستند.',409,['type'=>$key]);
        }
        return $doc+['source_receipt_verified'=>true,
            'local_stage_only'=>true,'public_owner_transfer_still_required'=>true];
    }

    public static function isFenced(string $dataRoot): bool
    {
        return $dataRoot!=='' &&
            (file_exists(rtrim($dataRoot,"\\/").DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,self::MARKER))||
             is_link(rtrim($dataRoot,"\\/").DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,self::MARKER)));
    }

    private static function json(array $doc):string
    {
        return json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|
            JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)."\n";
    }
}
