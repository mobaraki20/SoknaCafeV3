<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Update;

final class UpdateMigrationPolicy
{
    public const POLICY='forward-compatible-v1';

    /** @return array<string,string> version => sha256 */
    public static function catalogFromManifest(array $manifest): array
    {
        $database=(array)($manifest['database']??[]);
        if((string)($database['policy']??'')!==self::POLICY){
            throw new LocalUpdateException('database_policy_missing','سیاست سازگاری دیتابیس در بسته به‌روزرسانی معتبر نیست.',422);
        }
        $rows=(array)($database['migrations']??[]);$catalog=[];
        foreach($rows as $row){
            if(!is_array($row))throw new LocalUpdateException('database_contract_invalid','فهرست migrationهای بسته معتبر نیست.',422);
            $version=trim((string)($row['version']??''));$hash=strtolower(trim((string)($row['sha256']??'')));
            if(!preg_match('/^\d{4}_[a-z0-9_]+$/D',$version)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||isset($catalog[$version])){
                throw new LocalUpdateException('database_contract_invalid','فهرست migrationهای بسته معتبر نیست.',422);
            }
            $catalog[$version]=$hash;
        }
        ksort($catalog,SORT_STRING);return $catalog;
    }

    /** @param list<array<string,mixed>> $files */
    public static function assertManifestMatchesFiles(array $manifest,array $files): array
    {
        $catalog=self::catalogFromManifest($manifest);$fromFiles=[];
        foreach($files as $entry){
            if(!is_array($entry))continue;$path=str_replace('\\','/',(string)($entry['path']??''));
            if(!preg_match('#^database/migrations/(\d{4}_[a-z0-9_]+)\.sql$#D',$path,$m))continue;
            $hash=strtolower((string)($entry['sha256']??''));
            if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new LocalUpdateException('database_contract_invalid','هش migration در manifest معتبر نیست.',422);
            $fromFiles[$m[1]]=$hash;
        }
        ksort($fromFiles,SORT_STRING);
        if($catalog!==$fromFiles)throw new LocalUpdateException('database_catalog_mismatch','فهرست migrationهای بسته با فایل‌های آن یکسان نیست.',422);
        return $catalog;
    }

    /**
     * A direct upgrade is allowed when the installed migration history is an
     * unchanged subset of the incoming package catalog. New migrations may be
     * appended; applied history may never disappear or be rewritten.
     * @param list<string> $applied
     * @param array<string,string> $incoming
     * @return array{applied_count:int,incoming_count:int,pending_count:int}
     */
    public static function assertInstalledLineage(array $applied,array $incoming,string $installedMigrationDir): array
    {
        foreach($applied as $version){
            $version=(string)$version;
            if(!isset($incoming[$version]))throw new LocalUpdateException('schema_downgrade_unsupported','این بسته از ساختار دیتابیس نصب فعلی قدیمی‌تر است و مستقیم قابل نصب نیست.',409,['migration'=>$version]);
            $path=rtrim($installedMigrationDir,'/\\').DIRECTORY_SEPARATOR.$version.'.sql';
            if(!is_file($path))throw new LocalUpdateException('migration_history_unverifiable','فایل migration مربوط به نصب فعلی پیدا نشد؛ برای جلوگیری از تغییر تاریخچه، نصب متوقف شد.',409,['migration'=>$version]);
            $hash=strtolower((string)hash_file('sha256',$path));
            if(!hash_equals($incoming[$version],$hash))throw new LocalUpdateException('migration_history_changed','تاریخچه migrationهای این نصب با بسته جدید یکسان نیست؛ نصب مستقیم مجاز نیست.',409,['migration'=>$version]);
        }
        return ['applied_count'=>count($applied),'incoming_count'=>count($incoming),'pending_count'=>max(0,count($incoming)-count($applied))];
    }
}
