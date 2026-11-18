<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Recovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;
use RuntimeException;
use Throwable;

/**
 * R283: authenticated private guest-media vault transfer.
 * Export/restore only: NEVER alters current guest-media, config.php, Local
 * installation, Public authority, Maintenance, print agent or active database.
 * Call only AFTER business orders are frozen on the source machine. Paired
 * media archive must match the exact encrypted business backup SHA-256.
 */
final class PrivateMediaTransferService
{
    private const MAGIC="SOKNA-MEDIA-SECURE-V1\n";
    private const CHUNK=1048576;
    private const MAX_FILE_BYTES=1073741824;
    private const MAX_TOTAL_BYTES=32212254720;
    private const MAX_FILES=50000;

    /** Creates a separate encrypted archive. Does not change any source file. */
    public function export(string $mediaRoot,string $output,string $password,string $installationId,string $businessBackupSha): array
    {
        self::requireCrypto($password);
        self::validateSha($businessBackupSha);
        $installationId=self::validateInstallationId($installationId);
        if(!is_dir($mediaRoot)||is_link($mediaRoot))
            throw new RecoveryException('media_source_missing','پوشه خصوصی تصاویر موجود نیست.',409);
        if(is_file($output)||is_link($output))
            throw new RecoveryException('media_export_already_exists','فایل مقصد از قبل وجود دارد.',409);
        $files=$this->collect($mediaRoot);
        $manifest=['format'=>'sokna-private-media-v1','source_installation_id'=>$installationId,
            'business_backup_sha256'=>$businessBackupSha,
            'files'=>$files,'total_bytes'=>array_sum(array_column($files,'size')),
            'created_at'=>gmdate('c')];
        $zip=self::temporary('sokna-media-export','.zip');
        $cipher=$output.'.partial-'.bin2hex(random_bytes(8));
        try{
            $z=new ZipArchive();
            if($z->open($zip,ZipArchive::OVERWRITE|ZipArchive::CREATE)!==true)
                throw new RecoveryException('media_zip_create_failed','آرشیو تصاویر ساخته نشد.',500);
            $z->addFromString('manifest.json',self::json($manifest));
            foreach($files as $f) {
                if(!$z->addFile(rtrim($mediaRoot,"\\/").DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$f['path']),
                    'vault/'.$f['path']))
                    throw new RecoveryException('media_zip_add_failed','تصاویر قابل بسته‌بندی نیستند.',500);
            }
            if(!$z->close())throw new RecoveryException('media_zip_close_failed','آرشیو تصاویر کامل نشد.',500);
            // Fail closed on source edits during ZIP construction.
            if($this->collect($mediaRoot)!==$files)
                throw new RecoveryException('media_source_changed_during_export',
                    'تصاویر حین انتقال تغییر کرده‌اند؛ ابتدا ثبت و ویرایش را متوقف کنید.',409);
            $this->verifyPlainZip($zip,$installationId,$businessBackupSha);
            $this->encrypt($zip,$cipher,$password);
            if(!@rename($cipher,$output))
                throw new RecoveryException('media_export_commit_failed','بستن آرشیو رمزگذاری‌شده انجام نشد.',500);
            @chmod($output,0600);
            return ['format'=>'sokna-media-secure-v1','file'=>$output,
                'sha256'=>hash_file('sha256',$output),'media_files'=>count($files),
                'total_bytes'=>$manifest['total_bytes'],
                'paired_business_backup_sha256'=>$businessBackupSha,'stage_only'=>true];
        }finally{ @unlink($zip);@unlink($cipher); }
    }

    /**
     * Decrypt, validate every ZIP member and stage to a NEW private directory.
     * Never merges into app.data_dir/guest-media or any active site content.
     */
    public function importStage(string $archive,string $dataDir,string $password,
        string $expectedSourceId,string $businessBackupSha): array
    {
        self::requireCrypto($password);self::validateSha($businessBackupSha);
        $expectedSourceId=self::validateInstallationId($expectedSourceId);
        if(!is_file($archive)||is_link($archive))
            throw new RecoveryException('media_archive_missing','فایل رسانه‌ای رمزگذاری‌شده پیدا نشد.',404);
        if(!is_dir($dataDir)||is_link($dataDir))
            throw new RecoveryException('media_target_root_invalid','پوشه خصوصی مقصد معتبر نیست.',409);
        $zip=self::temporary('sokna-media-inspect','.zip');
        $partial='';$stage='';
        try{
            $this->decrypt($archive,$zip,$password);
            $manifest=$this->verifyPlainZip($zip,$expectedSourceId,$businessBackupSha);
            $id=bin2hex(random_bytes(12));
            $partial=rtrim($dataDir,"\\/").DIRECTORY_SEPARATOR.'.media-stage-'.$id.'.partial';
            $stage=rtrim($dataDir,"\\/").DIRECTORY_SEPARATOR.'media-stage-'.$id;
            if(file_exists($partial)||file_exists($stage)||is_link($partial)||is_link($stage))
                throw new RecoveryException('media_target_conflict','مسیر مقصد از قبل وجود دارد.',409);
            if(!@mkdir($partial,0700))
                throw new RecoveryException('media_stage_create_failed','مسیر امن رسانه‌ها ساخته نشد.',500);
            $z=new ZipArchive();
            if($z->open($zip)!==true)throw new RecoveryException('media_zip_invalid','آرشیو رسانه‌ای معتبر نیست.',422);
            try{
                foreach($manifest['files'] as $f){
                    $relative=(string)$f['path'];
                    self::validateRelative($relative);
                    $destination=$partial.DIRECTORY_SEPARATOR.'guest-media'.DIRECTORY_SEPARATOR.
                        str_replace('/',DIRECTORY_SEPARATOR,$relative);
                    $folder=dirname($destination);
                    if(!is_dir($folder)&&!@mkdir($folder,0700,true))
                        throw new RecoveryException('media_stage_directory_failed','مسیر رسانه‌ای آماده نشد.',500);
                    if(is_link($folder)||is_link($destination)||file_exists($destination))
                        throw new RecoveryException('media_target_conflict','فایل رسانه‌ای از قبل وجود دارد.',409);
                    $input=$z->getStream('vault/'.$relative);
                    $output=@fopen($destination,'xb');
                    if($input===false||$output===false)
                        throw new RecoveryException('media_stage_open_failed','باز کردن فایل رسانه‌ای ممکن نیست.',500);
                    try{
                        $h=hash_init('sha256');$size=0;
                        while(!feof($input)){
                            $bytes=fread($input,self::CHUNK);
                            if($bytes===false)throw new RuntimeException('media read failed');
                            $size+=strlen($bytes);
                            if($size>(int)$f['size']||$size>self::MAX_FILE_BYTES)
                                throw new RecoveryException('media_file_size_invalid','اندازه رسانه معتبر نیست.',422);
                            hash_update($h,$bytes);
                            if($bytes!==''&&fwrite($output,$bytes)!==strlen($bytes))
                                throw new RecoveryException('media_stage_write_failed','نوشتن رسانه کامل نشد.',500);
                        }
                        if($size!==(int)$f['size'] || !hash_equals((string)$f['sha256'],hash_final($h)))
                            throw new RecoveryException('media_hash_mismatch','صحت فایل رسانه‌ای تأیید نشد.',422);
                    }finally{fclose($input);fclose($output);}
                    @chmod($destination,0600);
                }
            }finally{$z->close();}
            $receipt=[
                'format'=>'sokna-media-stage-receipt-v1',
                'source_installation_id'=>$expectedSourceId,
                'paired_business_backup_sha256'=>$businessBackupSha,
                'archive_sha256'=>hash_file('sha256',$archive),
                'files'=>count($manifest['files']),'bytes'=>$manifest['total_bytes'],
                'activated'=>false,'public_authority_activated'=>false,
                'printer_activated'=>false,
            ];
            if(@file_put_contents($partial.DIRECTORY_SEPARATOR.'receipt.json',self::json($receipt),LOCK_EX)===false)
                throw new RecoveryException('media_stage_receipt_failed','ثبت رسید رسانه‌ای کامل نشد.',500);
            if(!@rename($partial,$stage))
                throw new RecoveryException('media_stage_commit_failed','ثبت ایمن رسانه‌های موقت کامل نشد.',500);
            return ['stage_only'=>true,'stage_path'=>$stage,
                'media_files'=>count($manifest['files']),'media_bytes'=>$manifest['total_bytes'],
                'media_archive_sha256'=>$receipt['archive_sha256'],
                'activation_authorized'=>false,'old_machine_fenced'=>false];
        }catch(Throwable $e){
            if($partial!==''&&is_dir($partial))self::removeCreatedPartial($partial);
            throw $e;
        }finally{@unlink($zip);}
    }

    private function collect(string $root): array
    {
        $out=[];$total=0;
        $iter=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach($iter as $entry){
            if($entry->isLink())throw new RecoveryException('media_symlink_forbidden',
                'پیوند فایل در پوشه تصاویر مجاز نیست.',409);
            if(!$entry->isFile())continue;
            $relative=str_replace('\\','/',substr($entry->getPathname(),strlen(rtrim($root,"\\/"))+1));
            self::validateRelative($relative);
            $size=$entry->getSize();
            if($size<0||$size>self::MAX_FILE_BYTES)
                throw new RecoveryException('media_file_oversize','فایل رسانه‌ای بیش از حد بزرگ است.',413);
            $total+=$size;
            if(++$count>self::MAX_FILES || $total>self::MAX_TOTAL_BYTES)
                throw new RecoveryException('media_vault_oversize','آرشیو رسانه‌ها از حد مجاز بزرگ‌تر است.',413);
            $sha=hash_file('sha256',$entry->getPathname());
            if(!is_string($sha))
                throw new RecoveryException('media_hash_failed','محاسبه اثرانگشت رسانه ممکن نیست.',500);
            $out[]=['path'=>$relative,'size'=>$size,'sha256'=>$sha];
        }
        usort($out,static fn(array $a,array $b):int=>strcmp($a['path'],$b['path']));
        return $out;
    }

    private function verifyPlainZip(string $path,string $sourceId,string $backupSha): array
    {
        $z=new ZipArchive();
        if($z->open($path)!==true)
            throw new RecoveryException('media_zip_invalid','آرشیو رسانه‌ای معتبر نیست.',422);
        try{
            $raw=$z->getFromName('manifest.json');
            if(!is_string($raw)||strlen($raw)>5000000)
                throw new RecoveryException('media_manifest_invalid','فهرست رسانه‌ها معتبر نیست.',422);
            $m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($m)||($m['format']??null)!=='sokna-private-media-v1' ||
                ($m['source_installation_id']??null)!==$sourceId ||
                ($m['business_backup_sha256']??null)!==$backupSha ||
                !is_array($m['files']??null))
                throw new RecoveryException('media_archive_pair_mismatch',
                    'فایل تصاویر با پشتیبان اطلاعاتی یا نصب مبدأ یکسان نیست.',409);
            $files=$m['files'];$expected=['manifest.json'=>true];$total=0;
            foreach($files as $f){
                if(!is_array($f))throw new RecoveryException('media_manifest_invalid','فهرست رسانه‌ها معتبر نیست.',422);
                $rel=(string)($f['path']??'');self::validateRelative($rel);
                $sha=(string)($f['sha256']??'');self::validateSha($sha);
                $size=$f['size']??null;
                if(!is_int($size)||$size<0||$size>self::MAX_FILE_BYTES)
                    throw new RecoveryException('media_manifest_invalid','اندازه فایل معتبر نیست.',422);
                $total+=$size;$key='vault/'.$rel;
                if(isset($expected[$key]))throw new RecoveryException('media_duplicate_path','فایل تکراری در آرشیو پیدا شد.',422);
                $expected[$key]=true;
            }
            if(count($files)>self::MAX_FILES || $total>self::MAX_TOTAL_BYTES ||
                $total!==($m['total_bytes']??null) || count($expected)!==$z->numFiles)
                throw new RecoveryException('media_archive_entries_invalid','تعداد یا حجم فایل‌های رسانه‌ای معتبر نیست.',422);
            for($i=0;$i<$z->numFiles;$i++){
                $member=$z->getNameIndex($i);
                if(!is_string($member)||!isset($expected[$member]))
                    throw new RecoveryException('media_archive_unknown_entry','فایل ناشناخته در آرشیو رسانه‌ها وجود دارد.',422);
            }
            return $m;
        }finally{$z->close();}
    }

    private function encrypt(string $zip,string $encrypted,string $password): void
    {
        $salt=random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,$salt,SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        sodium_memzero($key);
        $meta=self::json(['kdf'=>'argon2id13','salt'=>base64_encode($salt),
            'opslimit'=>SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            'memlimit'=>SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE]);
        $input=fopen($zip,'rb');$output=fopen($encrypted,'xb');
        if(!$input||!$output)throw new RecoveryException('media_file_open_failed','ساخت فایل انتقال ممکن نیست.',500);
        try{
            self::writeAll($output,self::MAGIC.pack('N',strlen($meta)).$meta.$header);
            $remaining=filesize($zip);
            while($remaining>0){
                $chunk=fread($input,min(self::CHUNK,$remaining));
                if(!is_string($chunk)||$chunk==='')throw new RuntimeException('Unexpected media archive EOF');
                $remaining-=strlen($chunk);
                $tag=$remaining===0?SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL:
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$chunk,'',$tag);
                self::writeAll($output,pack('N',strlen($cipher)).$cipher);
            }
        }finally{fclose($input);fclose($output);}
    }

    private function decrypt(string $archive,string $zip,string $password): void
    {
        $input=fopen($archive,'rb');$output=fopen($zip,'wb');
        if(!$input||!$output)throw new RecoveryException('media_file_open_failed','خواندن آرشیو ممکن نیست.',500);
        try{
            if(self::readExact($input,strlen(self::MAGIC))!==self::MAGIC)
                throw new RecoveryException('media_magic_invalid','فرمت فایل رسانه‌ای ناشناخته است.',422);
            $metaLen=unpack('N',self::readExact($input,4))[1];
            if($metaLen<20||$metaLen>4096)
                throw new RecoveryException('media_header_invalid','هدر رسانه‌ای معتبر نیست.',422);
            $meta=json_decode(self::readExact($input,$metaLen),true,16,JSON_THROW_ON_ERROR);
            $salt=base64_decode((string)($meta['salt']??''),true);
            if(($meta['kdf']??null)!=='argon2id13'||!is_string($salt)||
                strlen($salt)!==SODIUM_CRYPTO_PWHASH_SALTBYTES ||
                (int)($meta['opslimit']??0)!==SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE ||
                (int)($meta['memlimit']??0)!==SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE)
                throw new RecoveryException('media_header_invalid','پارامترهای رمزگذاری نامعتبر است.',422);
            $header=self::readExact($input,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
                $password,$salt,SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
            $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);
            sodium_memzero($key);$final=false;
            while(!feof($input)){
                $lengthBytes=fread($input,4);
                if($lengthBytes==='')break;
                if(!is_string($lengthBytes)||strlen($lengthBytes)!==4)
                    throw new RecoveryException('media_archive_truncated','فایل رسانه‌ای ناقص است.',422);
                $len=unpack('N',$lengthBytes)[1];
                if($len<17||$len>self::CHUNK+64)
                    throw new RecoveryException('media_cipher_length_invalid','اندازه داده رمزگذاری‌شده نامعتبر است.',422);
                $result=sodium_crypto_secretstream_xchacha20poly1305_pull(
                    $state,self::readExact($input,$len));
                if($result===false)
                    throw new RecoveryException('media_decrypt_failed','رمز یا یکپارچگی فایل رسانه‌ای معتبر نیست.',422);
                [$plain,$tag]=$result;
                self::writeAll($output,$plain);
                if($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL){
                    if(fread($input,1)!=='')
                        throw new RecoveryException('media_archive_trailing_bytes','داده اضافه در انتهای فایل وجود دارد.',422);
                    $final=true;break;
                }
            }
            if(!$final)throw new RecoveryException('media_archive_truncated','پایان معتبر آرشیو پیدا نشد.',422);
        }finally{fclose($input);fclose($output);}
    }

    private static function validateRelative(string $p): void
    {
        if($p===''||strlen($p)>350||str_contains($p,'\\')||
            str_contains($p,"\0")||str_starts_with($p,'/')||
            !preg_match('~^[A-Za-z0-9_./-]+$~D',$p) ||
            in_array('.',explode('/',$p),true)||in_array('..',explode('/',$p),true)||
            in_array('',explode('/',$p),true) || str_contains($p,':'))
            throw new RecoveryException('media_path_invalid','مسیر رسانه‌ای نامعتبر است.',422);
    }
    private static function validateSha(string $sha): void
    {
        if(!preg_match('/^[0-9a-f]{64}$/D',$sha))
            throw new RecoveryException('media_backup_sha_invalid','هش پشتیبان معتبر نیست.',422);
    }
    private static function validateInstallationId(string $id): string
    {
        $id=trim($id);
        if(strlen($id)<12||strlen($id)>128||!preg_match('/^[a-zA-Z0-9._-]+$/D',$id))
            throw new RecoveryException('media_installation_id_invalid','هویت نصب معتبر نیست.',422);
        return $id;
    }
    private static function requireCrypto(string $password): void
    {
        if(!extension_loaded('sodium')||!class_exists(ZipArchive::class))
            throw new RecoveryException('media_crypto_unavailable','افزونه رمزگذاری یا ZIP نصب نشده است.',500);
        if(strlen($password)<16)
            throw new RecoveryException('media_passphrase_weak','رمز انتقال حداقل ۱۶ نویسه باشد.',422);
    }
    private static function temporary(string $prefix,string $suffix): string
    {
        $path=tempnam(sys_get_temp_dir(),$prefix);
        if(!is_string($path))throw new RecoveryException('media_temp_failed','فایل موقت قابل ایجاد نیست.',500);
        @chmod($path,0600);return $path;
    }
    private static function readExact($file,int $size): string
    {
        $value='';
        while(strlen($value)<$size){
            $s=fread($file,$size-strlen($value));
            if(!is_string($s)||$s==='')throw new RecoveryException('media_archive_truncated','فایل انتقال ناقص است.',422);
            $value.=$s;
        }
        return $value;
    }
    private static function writeAll($file,string $value): void
    {
        $size=strlen($value);$done=0;
        while($done<$size){
            $n=fwrite($file,substr($value,$done));
            if(!is_int($n)||$n<1)throw new RecoveryException('media_file_write_failed','نوشتن آرشیو کامل نشد.',500);
            $done+=$n;
        }
    }
    private static function removeCreatedPartial(string $root): void
    {
        $iter=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iter as $entry){if($entry->isLink()||$entry->isFile())@unlink($entry->getPathname());
            elseif($entry->isDir())@rmdir($entry->getPathname());}
        @rmdir($root);
    }
    private static function json(array $v): string {
        return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    }
}
