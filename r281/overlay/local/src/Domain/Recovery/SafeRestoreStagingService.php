<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Recovery;

use Closure;
use PDO;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\PublicEdge\PublicAuthorityStateService;
use Throwable;

/**
 * R281: business restore into a NEW, schema-only database.
 *
 * Never removes or edits an existing database, the old PC, web config,
 * installation binding, printer identities, or Public authority. A successful
 * stage is NOT a cutover; host activation remains a separate owner-approved
 * and journaled operation (not implemented by this service).
 *
 * This component is intentionally kept in an isolated QA overlay until the
 * native installer, runtime and Public authority gates are accepted.
 */
final class SafeRestoreStagingService
{
    private readonly Closure $connectDatabase;

    /** @param callable(string):PDO $connectDatabase */
    public function __construct(
        private readonly PDO $control,
        callable $connectDatabase,
        private readonly Observability $observability,
    ) {
        $this->connectDatabase=Closure::fromCallable($connectDatabase);
    }

    public function stage(
        string $installedSchemaDatabase,
        string $newStagingDatabase,
        string $encryptedBackupPath,
        string $passphrase,
        string $newLocalInstallationId
    ): array {
        $source=self::validateName($installedSchemaDatabase);
        $target=self::validateName($newStagingDatabase);
        if($source===$target)throw new RecoveryException('restore_staging_is_source',
            'پایگاه دادهٔ آماده‌سازی باید کاملاً جدا از نصب موجود باشد.',409);
        if(!str_starts_with($target,'sokna_restore_'))
            throw new RecoveryException('restore_staging_name_invalid',
                'نام پایگاه دادهٔ موقت بازیابی باید در فضای اختصاصی باشد.',422);
        $newId=trim($newLocalInstallationId);
        if(strlen($newId)<12||strlen($newId)>128)
            throw new RecoveryException('restore_target_identity_invalid',
                'شناسهٔ نصب جدید معتبر نیست.',422);
        if(!is_file($encryptedBackupPath))
            throw new RecoveryException('backup_missing','فایل پشتیبان پیدا نشد.',404);
        if($this->databaseExists($target))
            throw new RecoveryException('restore_staging_already_exists',
                'دیتابیس مقصد از قبل وجود دارد؛ برای جلوگیری از دست‌کاری، عملیات متوقف شد.',409);
        if(!$this->databaseExists($source))
            throw new RecoveryException('restore_source_schema_missing',
                'دیتابیس نصب مرجع پیدا نشد.',404);

        // Observe the source schema before ANY CREATE. No production table
        // contents are copied; only the SQL definitions and migration ledger.
        $tables=$this->tableDefinitions($source);
        foreach(['settings','schema_migrations','users','orders','inventory_items','public_authority_state'] as $required)
            if(!isset($tables[$required]))
                throw new RecoveryException('restore_source_schema_incomplete',
                    'ساختار دیتابیس نصب مرجع کامل نیست.',409,['table'=>$required]);

        $sourceConnection=($this->connectDatabase)($source);
        if(!$sourceConnection instanceof PDO)
            throw new RecoveryException('restore_source_connection_invalid',
                'اتصال ساختار نصب مرجع معتبر نیست.',500);
        $sourceVersions=$sourceConnection
            ->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_COLUMN);
        if(!is_array($sourceVersions)||count($sourceVersions)<1)
            throw new RecoveryException('restore_source_migrations_empty',
                'تاریخچه نسخه‌های دیتابیس موجود نیست.',409);
        // Derive all tables, never use a fixed catalogue of 82 migration files.
        // Separate tenant installations can have different supported versions.
        $this->control->exec('CREATE DATABASE '.self::quote($target).
            ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        // Any exception leaves only the NEW isolated staging database behind;
        // never DROP an unknown schema and never switch an operator's config.
        $staging=null;
        try{
            $this->control->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach($tables as $table=>$ddl)
                    $this->control->exec($this->targetDdl($ddl,$table,$target));
            }finally {
                $this->control->exec('SET FOREIGN_KEY_CHECKS=1');
            }
            $staging=($this->connectDatabase)($target);
            if(!$staging instanceof PDO)
                throw new RecoveryException('restore_target_connection_invalid',
                    'اتصال دیتابیس آماده‌سازی معتبر نیست.',500);
            foreach(['schema_migrations','schema_migration_statements'] as $ledgerTable){
                if(!isset($tables[$ledgerTable]))continue;
                $sql='INSERT INTO '.self::quote($target).'.'.self::quote($ledgerTable).
                    ' SELECT * FROM '.self::quote($source).'.'.self::quote($ledgerTable);
                $this->control->exec($sql);
            }
            $actualVersions=$staging->query('SELECT version FROM schema_migrations ORDER BY version')
                ->fetchAll(PDO::FETCH_COLUMN);
            if($actualVersions!==$sourceVersions)
                throw new RecoveryException('restore_staging_schema_mismatch',
                    'فهرست نسخه‌های پایگاه دادهٔ آماده‌سازی با نصب مرجع یکسان نیست.',409);
            $authority=new PublicAuthorityStateService($staging,$newId);
            $backup=new BusinessBackupService($staging,$this->observability,$newId,
                $authority,null); // No remote contact or Public fencing.
            $result=$backup->restoreEncryptedToEmptyTarget(
                $encryptedBackupPath,$passphrase,0);
            if(($result['restored']??null)!==true)
                throw new RecoveryException('restore_staging_incomplete',
                    'بازیابی در دیتابیس موقت کامل نشد.',500);
            $archivedSourceId=trim((string)($result['source_installation_id']??''));
            if($archivedSourceId===''||$archivedSourceId==='unknown-installation')
                throw new RecoveryException('restore_source_identity_missing',
                    'هویت مبدا پشتیبان مشخص نیست؛ اتصال خودکار مجاز نیست.',409);

            // The original business backup includes the old installation.id
            // setting. Replace ONLY this setting IN STAGING so a future new
            // machine never silently claims the old PC's installation ID.
            $staging->beginTransaction();
            try{
                $row=$staging->query("SELECT setting_value FROM settings WHERE setting_key='installation.id' LIMIT 1")
                    ->fetchColumn();
                if(!is_string($row)||$row!==$archivedSourceId)
                    throw new RecoveryException('restore_source_identity_mismatch',
                        'شناسهٔ نصب ثبت‌شده با شناسهٔ پشتیبان یکسان نیست.',409);
                $stmt=$staging->prepare("UPDATE settings SET setting_value=? WHERE setting_key='installation.id'");
                $stmt->execute([$newId]);
                if($stmt->rowCount()!==1)
                    throw new RecoveryException('restore_target_identity_not_written',
                        'شناسهٔ مستقل نصب جدید ذخیره نشد.',500);
                $staging->commit();
            }catch(Throwable $e){
                if($staging->inTransaction())$staging->rollBack();
                throw $e;
            }

            if($authority->phase()!=='reenroll_required')
                throw new RecoveryException('restore_staging_authority_not_fenced',
                    'وب عمومی پس از بازیابی نیازمند تأیید مجدد نیست.',500);
            foreach(['print_agents','print_destinations','print_jobs','print_attempts'] as $machineTable){
                if(!isset($tables[$machineTable]))continue;
                if((int)$staging->query('SELECT COUNT(*) FROM '.self::quote($machineTable))
                    ->fetchColumn()!==0)
                    throw new RecoveryException('restore_staging_machine_identity_cloned',
                        'هویت چاپ یا صف چاپ نباید منتقل شود.',500,['table'=>$machineTable]);
            }
            $this->observability->logEvent('warning','recovery.staged_without_activation',[
                'source_installation_id'=>$archivedSourceId,
                'target_installation_id'=>$newId,
                'target_database'=>$target,
                'rows'=>(int)($result['row_count']??0),
                'activation_authorized'=>false,
            ]);
            return [
                'ok'=>true,'stage_only'=>true,'staged_database'=>$target,
                'source_installation_id'=>$archivedSourceId,
                'new_installation_id'=>$newId,
                'schema_version_count'=>count($sourceVersions),
                'business_rows_restored'=>(int)($result['row_count']??0),
                'new_machine_identity_reprovision_required'=>true,
                'public_reenrollment_required'=>true,
                'old_machine_fencing_verified'=>false,
                'physical_print_verified'=>false,
                'activation_authorized'=>false,
                'operator_install_allowed'=>false,
                'next_action'=>'independent_native_and_public_cutover_qualification',
            ];
        }catch(Throwable $e){
            $this->observability->logEvent('error','recovery.staging_failed_no_cutover',[
                'stage_database'=>$target,'phase'=>'stage_only',
                'error_code'=>$e instanceof RecoveryException?$e->errorCode:'restore_stage_failed',
                'operator_activation_allowed'=>false,
            ]);
            throw $e;
        }
    }

    /** @return array<string,string> */
    private function tableDefinitions(string $source): array
    {
        $sql='SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES '.
            'WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME';
        $query=$this->control->prepare($sql);$query->execute([$source]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows)throw new RecoveryException('restore_source_schema_empty',
            'ساختار مرجع نصب خالی است.',409);
        $out=[];
        foreach($rows as $row){
            $table=(string)$row['TABLE_NAME'];
            if((string)($row['TABLE_TYPE']??'')!=='BASE TABLE' ||
               strtolower((string)($row['ENGINE']??''))!=='innodb')
                throw new RecoveryException('restore_source_nontransactional_table',
                    'ساختار دارای جدول غیرتراکنشی یا نمای پشتیبانی‌نشده است.',409,
                    ['table'=>$table]);
            $query=$this->control->query(
                'SHOW CREATE TABLE '.self::quote($source).'.'.self::quote($table));
            $details=$query->fetch(PDO::FETCH_ASSOC);
            $ddl=(string)($details['Create Table']??'');
            if($ddl==='')
                throw new RecoveryException('restore_schema_ddl_missing',
                    'ساختار کامل جدول پیدا نشد.',500,['table'=>$table]);
            $out[$table]=$ddl;
        }
        return $out;
    }

    private function targetDdl(string $ddl,string $table,string $target): string
    {
        $from='CREATE TABLE '.self::quote($table);
        if(!str_starts_with($ddl,$from))
            throw new RecoveryException('restore_schema_ddl_invalid',
                'ساختار جدول قابل بازتولید نیست.',500,['table'=>$table]);
        return 'CREATE TABLE '.self::quote($target).'.'.self::quote($table).
            substr($ddl,strlen($from));
    }

    private function databaseExists(string $name): bool
    {
        $query=$this->control->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=? LIMIT 1');
        $query->execute([$name]);return $query->fetchColumn()!==false;
    }
    private static function validateName(string $name): string
    {
        if(!preg_match('/^[a-z][a-z0-9_]{4,62}$/D',$name))
            throw new RecoveryException('restore_db_name_invalid',
                'نام دیتابیس معتبر نیست.',422);
        return $name;
    }
    private static function quote(string $name): string
    {
        return '`'.str_replace('`','``',$name).'`';
    }
}
