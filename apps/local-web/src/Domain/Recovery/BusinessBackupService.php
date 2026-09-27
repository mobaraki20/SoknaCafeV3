<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Recovery;

use PDO;
use Sokna\Local\Core\Observability;
use Throwable;

final class BusinessBackupService
{
    private const MAGIC="SOKNA-SKB1\n";
    private const FORMAT='sokna-business-backup-v1';
    private const CHUNK=1048576;
    private const EXCLUDED_TABLES=[
        'schema_migrations','schema_migration_statements',
        'runtime_trigger_receipts',
        'print_agents','print_destinations','print_jobs','print_attempts','print_claim_requests','print_claim_reconciliations',
    ];
    private const EMPTY_TARGET_MARKERS=['users','orders','inventory_items','expenses','settlement_records','subscriber_ledger','accommodation_transfers'];
    private const EXCLUDED_MACHINE_IDENTITY=['runtime_machine_secret','print_agent_identity','tls_private_key'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Observability $observability,
        private readonly string $sourceInstallationId,
    ) {}

    public function createEncrypted(string $destination,string $passphrase,int $actorUserId=0): array
    {
        self::requireSodium();
        self::validatePassphrase($passphrase);
        $destination=$this->safeDestination($destination);
        $tmp=$this->tempPath('business-backup','.ndjson.gz');
        try{
            $manifest=$this->writeSnapshot($tmp,$actorUserId);
            $this->encryptFile($tmp,$destination,$passphrase);
            @chmod($destination,0600);
            return [
                'format'=>'sokna-secure-business-backup-v1','path'=>$destination,
                'sha256'=>hash_file('sha256',$destination),'size'=>(int)filesize($destination),
                'source_installation_id'=>$manifest['source_installation_id'],
                'excluded_machine_identity'=>$manifest['excluded_machine_identity'],
                'schema_versions'=>$manifest['schema_versions'],'created_at'=>$manifest['created_at'],
            ];
        }finally{ @unlink($tmp); }
    }

    public function restoreEncryptedToEmptyTarget(string $source,string $passphrase,int $actorUserId=0): array
    {
        self::requireSodium();
        self::validatePassphrase($passphrase);
        if(!is_file($source))throw new RecoveryException('backup_missing','فایل پشتیبان پیدا نشد.',404);
        $tmp=$this->tempPath('business-restore','.ndjson.gz');
        try{
            $this->decryptFile($source,$tmp,$passphrase);
            return $this->restoreSnapshot($tmp,$actorUserId);
        }finally{ @unlink($tmp); }
    }

    public function inspectEncrypted(string $source,string $passphrase): array
    {
        self::requireSodium();self::validatePassphrase($passphrase);
        $tmp=$this->tempPath('business-inspect','.ndjson.gz');
        try{$this->decryptFile($source,$tmp,$passphrase);return $this->readManifest($tmp);}finally{@unlink($tmp);}
    }

    private function writeSnapshot(string $path,int $actorUserId): array
    {
        $tables=$this->businessTables();
        $versions=array_map('strval',$this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        $manifest=[
            'format'=>self::FORMAT,'schema_version'=>1,'created_at'=>gmdate('c'),
            'source_installation_id'=>$this->sourceInstallationId!==''?$this->sourceInstallationId:'unknown-installation',
            'schema_versions'=>$versions,'tables'=>$tables,
            'excluded_tables'=>self::EXCLUDED_TABLES,'excluded_machine_identity'=>self::EXCLUDED_MACHINE_IDENTITY,
        ];
        $gz=@gzopen($path,'wb9');if($gz===false)throw new RecoveryException('backup_write_failed','فایل موقت پشتیبان قابل ایجاد نیست.',500);
        $counts=[];$hashes=[];
        try{
            $this->gzLine($gz,['kind'=>'manifest','value'=>$manifest]);
            foreach($tables as $table){
                $columns=$this->columns($table);$counts[$table]=0;$ctx=hash_init('sha256');
                $this->gzLine($gz,['kind'=>'table','name'=>$table,'columns'=>$columns]);
                $stmt=$this->pdo->query('SELECT * FROM `'.str_replace('`','``',$table).'` ORDER BY '.$this->stableOrder($table,$columns));
                while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
                    if($table==='settlement_records'&&array_key_exists('final_print_job_id',$row))$row['final_print_job_id']=null;
                    $payload=['kind'=>'row','table'=>$table,'value'=>$row];$json=self::json($payload);
                    hash_update($ctx,$json."\n");$this->gzRawLine($gz,$json);$counts[$table]++;
                }
                $hashes[$table]=hash_final($ctx);
            }
            $this->gzLine($gz,['kind'=>'footer','table_counts'=>$counts,'table_hashes'=>$hashes]);
        }finally{gzclose($gz);}
        $this->observability->logEvent('info','recovery.backup_created',[
            'actor_user_id'=>$actorUserId,'table_count'=>count($tables),'row_count'=>array_sum($counts),
            'excluded_machine_identity'=>self::EXCLUDED_MACHINE_IDENTITY,
        ]);
        return $manifest;
    }

    private function restoreSnapshot(string $path,int $actorUserId): array
    {
        $this->assertEmptyTarget();
        $targetVersions=array_map('strval',$this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        $gz=@gzopen($path,'rb');if($gz===false)throw new RecoveryException('backup_read_failed','محتوای پشتیبان قابل خواندن نیست.',422);
        $manifestLine=$this->readGzJsonLine($gz);$manifest=is_array($manifestLine['value']??null)?$manifestLine['value']:[];
        if(($manifestLine['kind']??'')!=='manifest'||($manifest['format']??'')!==self::FORMAT||($manifest['schema_version']??0)!==1){gzclose($gz);throw new RecoveryException('backup_contract','فرمت پشتیبان پشتیبانی نمی‌شود.',422);}
        $sourceVersions=array_map('strval',(array)($manifest['schema_versions']??[]));
        foreach($sourceVersions as $version)if(!in_array($version,$targetVersions,true)){gzclose($gz);throw new RecoveryException('schema_incompatible','نسخه دیتابیس مقصد از پشتیبان قدیمی‌تر است.',409,['missing_version'=>$version]);}
        $tables=array_values(array_map('strval',(array)($manifest['tables']??[])));
        $allowed=array_flip($this->businessTables());
        foreach($tables as $table)if(!isset($allowed[$table])){gzclose($gz);throw new RecoveryException('table_not_allowed','پشتیبان شامل جدول غیرمجاز است.',422,['table'=>$table]);}
        $restoreTables=$tables;

        $counts=[];$hashCtx=[];$current='';$footer=null;
        $this->pdo->beginTransaction();
        try{
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach(array_reverse($restoreTables) as $table)$this->pdo->exec('DELETE FROM `'.str_replace('`','``',$table).'`');
            while(!gzeof($gz)){
                $raw=gzgets($gz);if($raw===false)break;$trim=trim($raw);if($trim==='')continue;
                $entry=json_decode($trim,true,64,JSON_THROW_ON_ERROR);$kind=(string)($entry['kind']??'');
                if($kind==='table'){
                    $current=(string)($entry['name']??'');if(!in_array($current,$tables,true))throw new RecoveryException('backup_contract','جدول پشتیبان معتبر نیست.',422);
                    $counts[$current]=0;$hashCtx[$current]=hash_init('sha256');continue;
                }
                if($kind==='row'){
                    $table=(string)($entry['table']??'');$row=$entry['value']??null;
                    if($table===''||$table!==$current||!is_array($row))throw new RecoveryException('backup_contract','ترتیب رکوردهای پشتیبان معتبر نیست.',422);
                    hash_update($hashCtx[$table],$trim."\n");$this->insertRow($table,$row);$counts[$table]++;continue;
                }
                if($kind==='footer'){$footer=$entry;break;}
                throw new RecoveryException('backup_contract','رکورد ناشناخته در پشتیبان وجود دارد.',422);
            }
            if(!is_array($footer))throw new RecoveryException('backup_truncated','پشتیبان ناقص است.',422);
            foreach($tables as $table){
                $expectedCount=(int)(($footer['table_counts']??[])[$table]??-1);
                $expectedHash=(string)(($footer['table_hashes']??[])[$table]??'');
                $actualHash=hash_final($hashCtx[$table]??hash_init('sha256'));
                if(($counts[$table]??0)!==$expectedCount||!preg_match('/^[a-f0-9]{64}$/',$expectedHash)||!hash_equals($expectedHash,$actualHash))
                    throw new RecoveryException('backup_integrity','یکپارچگی داده پشتیبان تأیید نشد.',422,['table'=>$table]);
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            $this->pdo->commit();
        }catch(Throwable $e){
            try{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}catch(Throwable){}
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }finally{gzclose($gz);}
        $this->observability->logEvent('warning','recovery.business_restored',[
            'actor_user_id'=>$actorUserId,'source_installation_id'=>$manifest['source_installation_id']??'',
            'table_count'=>count($restoreTables),'row_count'=>array_sum(array_intersect_key($counts,array_flip($restoreTables))),'machine_identity_reprovision_required'=>true,
        ]);
        return [
            'restored'=>true,'source_installation_id'=>(string)($manifest['source_installation_id']??''),
            'table_count'=>count($restoreTables),'row_count'=>array_sum(array_intersect_key($counts,array_flip($restoreTables))),
            'excluded_machine_identity'=>(array)($manifest['excluded_machine_identity']??[]),
            'machine_identity_reprovision_required'=>true,
        ];
    }

    private function businessTables(): array
    {
        $tables=array_map('strval',$this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $exclude=array_flip(self::EXCLUDED_TABLES);
        $out=[];foreach($tables as $table)if(!isset($exclude[$table]))$out[]=$table;
        sort($out,SORT_STRING);return $out;
    }

    private function columns(string $table): array
    {
        $rows=$this->pdo->query('SHOW COLUMNS FROM `'.str_replace('`','``',$table).'`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r):string=>(string)$r['Field'],$rows);
    }

    private function stableOrder(string $table,array $columns): string
    {
        $pk=$this->pdo->query('SHOW KEYS FROM `'.str_replace('`','``',$table).'` WHERE Key_name=\'PRIMARY\'')->fetchAll(PDO::FETCH_ASSOC);
        usort($pk,static fn(array $a,array $b):int=>(int)($a['Seq_in_index']??0)<=>(int)($b['Seq_in_index']??0));
        $order=[];foreach($pk as $row)$order[]='`'.str_replace('`','``',(string)$row['Column_name']).'`';
        if(!$order&&$columns)$order[]='`'.str_replace('`','``',$columns[0]).'`';
        return $order?implode(',',$order):'1';
    }

    private function insertRow(string $table,array $row): void
    {
        if(!$row)return;$cols=array_keys($row);$quoted=array_map(static fn(string $c):string=>'`'.str_replace('`','``',$c).'`',$cols);
        $stmt=$this->pdo->prepare('INSERT INTO `'.str_replace('`','``',$table).'`('.implode(',',$quoted).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');
        $stmt->execute(array_values($row));
    }

    private function assertEmptyTarget(): void
    {
        foreach(self::EMPTY_TARGET_MARKERS as $table){
            if(!$this->tableExists($table))continue;
            if((int)$this->pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()>0)
                throw new RecoveryException('target_not_empty','بازیابی فقط روی نصب خالی مجاز است.',409,['table'=>$table]);
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt=$this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$stmt->execute([$table]);return $stmt->fetchColumn()!==false;
    }

    private function readManifest(string $path): array
    {
        $gz=@gzopen($path,'rb');if($gz===false)throw new RecoveryException('backup_read_failed','محتوای پشتیبان قابل خواندن نیست.',422);
        try{$line=$this->readGzJsonLine($gz);}finally{gzclose($gz);}
        $manifest=is_array($line['value']??null)?$line['value']:[];
        if(($line['kind']??'')!=='manifest'||($manifest['format']??'')!==self::FORMAT)throw new RecoveryException('backup_contract','فرمت پشتیبان پشتیبانی نمی‌شود.',422);
        return $manifest;
    }

    private function encryptFile(string $source,string $destination,string $passphrase): void
    {
        $salt=random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);$ops=SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;$mem=SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
        $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,$passphrase,$salt,$ops,$mem,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);sodium_memzero($key);
        $meta=self::json(['format'=>'sokna-skb1','kdf'=>'argon2id13','salt'=>base64_encode($salt),'opslimit'=>$ops,'memlimit'=>$mem]);
        $in=fopen($source,'rb');$out=fopen($destination,'wb');if(!$in||!$out)throw new RecoveryException('backup_write_failed','فایل پشتیبان قابل ایجاد نیست.',500);
        try{
            fwrite($out,self::MAGIC);fwrite($out,strlen($meta)."\n");fwrite($out,$meta);fwrite($out,$header);
            $remaining=(int)filesize($source);
            while($remaining>0){$plain=fread($in,min(self::CHUNK,$remaining));if($plain===false||$plain==='')throw new RecoveryException('backup_read_failed','فایل موقت قابل خواندن نیست.',500);$remaining-=strlen($plain);$tag=$remaining===0?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;$cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$plain,'',$tag);fwrite($out,pack('N',strlen($cipher)));fwrite($out,$cipher);}
        }finally{fclose($in);fclose($out);}
    }

    private function decryptFile(string $source,string $destination,string $passphrase): void
    {
        $in=fopen($source,'rb');if(!$in)throw new RecoveryException('backup_missing','فایل پشتیبان قابل خواندن نیست.',404);
        $out=null;
        try{
            if(fread($in,strlen(self::MAGIC))!==self::MAGIC)throw new RecoveryException('backup_contract','فرمت فایل پشتیبان معتبر نیست.',422);
            $lenLine=fgets($in);$len=(int)trim((string)$lenLine);if($len<20||$len>4096)throw new RecoveryException('backup_contract','هدر فایل پشتیبان معتبر نیست.',422);
            $meta=json_decode((string)fread($in,$len),true,32,JSON_THROW_ON_ERROR);$salt=base64_decode((string)($meta['salt']??''),true);
            if(($meta['format']??'')!=='sokna-skb1'||($meta['kdf']??'')!=='argon2id13'||!is_string($salt)||strlen($salt)!==SODIUM_CRYPTO_PWHASH_SALTBYTES)throw new RecoveryException('backup_contract','هدر امنیتی پشتیبان معتبر نیست.',422);
            $header=fread($in,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);if(strlen($header)!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)throw new RecoveryException('backup_truncated','پشتیبان ناقص است.',422);
            $ops=(int)($meta['opslimit']??0);$mem=(int)($meta['memlimit']??0);if($ops<1||$mem<8192)throw new RecoveryException('backup_contract','پارامتر امنیتی پشتیبان معتبر نیست.',422);
            $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,$passphrase,$salt,$ops,$mem,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
            $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);sodium_memzero($key);$out=fopen($destination,'wb');if(!$out)throw new RecoveryException('backup_write_failed','فایل موقت بازیابی قابل ایجاد نیست.',500);
            $final=false;
            while(!feof($in)){$sizeRaw=fread($in,4);if($sizeRaw==='')break;if(strlen($sizeRaw)!==4)throw new RecoveryException('backup_truncated','پشتیبان ناقص است.',422);$size=unpack('N',$sizeRaw)[1];if($size<1||$size>self::CHUNK+256)throw new RecoveryException('backup_contract','اندازه فریم پشتیبان معتبر نیست.',422);$cipher='';while(strlen($cipher)<$size){$part=fread($in,$size-strlen($cipher));if($part===false||$part==='')throw new RecoveryException('backup_truncated','پشتیبان ناقص است.',422);$cipher.=$part;}$pulled=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$cipher);if($pulled===false)throw new RecoveryException('backup_auth_failed','رمز نادرست است یا فایل پشتیبان دست‌کاری شده است.',422);[$plain,$tag]=$pulled;if($final)throw new RecoveryException('backup_trailing_data','پس از فریم پایانی داده اضافی وجود دارد.',422);fwrite($out,$plain);if($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL)$final=true;}
            if(!$final)throw new RecoveryException('backup_truncated','فریم پایانی پشتیبان وجود ندارد.',422);
        }catch(RecoveryException $e){if($out)fclose($out);@unlink($destination);throw $e;}catch(Throwable $e){if($out)fclose($out);@unlink($destination);throw new RecoveryException('backup_auth_failed','رمز نادرست است یا فایل پشتیبان معتبر نیست.',422);}
        finally{if(is_resource($in))fclose($in);if(is_resource($out))fclose($out);}
    }

    private function safeDestination(string $path): string
    {
        $path=trim($path);if($path===''||str_contains($path,"\0"))throw new RecoveryException('invalid_path','مسیر فایل پشتیبان معتبر نیست.',422);
        $dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RecoveryException('backup_write_failed','پوشه پشتیبان قابل ایجاد نیست.',500);
        return $path;
    }

    private function tempPath(string $prefix,string $suffix): string
    {
        $dir=$this->observability->dataRoot().DIRECTORY_SEPARATOR.'recovery-tmp';if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RecoveryException('temp_unavailable','فضای موقت بازیابی آماده نیست.',500);
        return $dir.DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(8)).$suffix;
    }

    private function readGzJsonLine($gz): array
    {
        $raw=gzgets($gz);if($raw===false)throw new RecoveryException('backup_truncated','پشتیبان ناقص است.',422);$decoded=json_decode(trim($raw),true,64,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new RecoveryException('backup_contract','رکورد پشتیبان معتبر نیست.',422);return $decoded;
    }
    private function gzLine($gz,array $value): void{$this->gzRawLine($gz,self::json($value));}
    private function gzRawLine($gz,string $json): void{if(gzwrite($gz,$json."\n")===false)throw new RecoveryException('backup_write_failed','نوشتن پشتیبان کامل نشد.',500);}
    private static function json(array $value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
    private static function validatePassphrase(string $passphrase): void{if(strlen($passphrase)<12||strlen($passphrase)>1024)throw new RecoveryException('weak_passphrase','رمز بازیابی باید حداقل ۱۲ کاراکتر باشد.',422);}
    private static function requireSodium(): void{if(!function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push'))throw new RecoveryException('sodium_unavailable','رمزنگاری امن پشتیبان روی این PHP فعال نیست.',500);}
}
