<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Update;

use Closure;
use Throwable;

final class LocalUpdateUploadService
{
    public const CHUNK_BYTES=1048576;
    private const MAX_BYTES=134217728;
    private const TTL_SECONDS=86400;

    /** @param Closure(string,int):array $stage */
    public function __construct(private readonly string $incomingDir,private readonly Closure $stage){}

    public function init(string $name,int $size,int $actorId): array
    {
        if($size<100||$size>self::MAX_BYTES)throw new LocalUpdateException('package_size','حجم بسته به‌روزرسانی معتبر نیست.',413,['max_bytes'=>self::MAX_BYTES]);
        $this->cleanupExpired();$id=bin2hex(random_bytes(16));$meta=['format'=>'sokna-local-chunk-upload-v1','id'=>$id,'name'=>basename(trim($name)),'size'=>$size,'received'=>0,'actor_user_id'=>$actorId,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c')];
        $this->writeMeta($id,$meta);$part=$this->partPath($id);$fh=@fopen($part,'xb');if(!is_resource($fh)){@unlink($this->metaPath($id));throw new LocalUpdateException('upload_storage_unavailable','فضای موقت بارگذاری روی سرور آماده نیست.',500);}fclose($fh);@chmod($part,0600);
        return $this->publicMeta($meta);
    }

    public function append(string $id,int $offset,string $bytes,int $actorId): array
    {
        $meta=$this->meta($id,$actorId);$length=strlen($bytes);if($length<1||$length>self::CHUNK_BYTES)throw new LocalUpdateException('upload_chunk_size','اندازه قطعه بارگذاری معتبر نیست.',422,['chunk_bytes'=>$length]);
        if($offset!==(int)$meta['received'])throw new LocalUpdateException('upload_offset_conflict','ترتیب قطعه‌های بارگذاری تغییر کرده است؛ بارگذاری را دوباره شروع کن.',409,['expected_offset'=>(int)$meta['received'],'received_offset'=>$offset]);
        if($offset+$length>(int)$meta['size'])throw new LocalUpdateException('upload_size_conflict','حجم قطعه از اندازه فایل انتخاب‌شده بیشتر است.',422);
        $fh=@fopen($this->partPath($id),'c+b');if(!is_resource($fh))throw new LocalUpdateException('upload_storage_unavailable','فایل موقت بارگذاری در دسترس نیست.',500);
        try{if(!flock($fh,LOCK_EX))throw new LocalUpdateException('upload_storage_unavailable','قفل فایل موقت بارگذاری در دسترس نیست.',500);clearstatcache(true,$this->partPath($id));$actual=(int)filesize($this->partPath($id));if($actual!==$offset)throw new LocalUpdateException('upload_offset_conflict','ترتیب قطعه‌های بارگذاری تغییر کرده است؛ بارگذاری را دوباره شروع کن.',409,['expected_offset'=>$actual,'received_offset'=>$offset]);if(fseek($fh,$offset)!==0)throw new LocalUpdateException('upload_storage_unavailable','نوشتن فایل موقت ممکن نیست.',500);$written=0;while($written<$length){$n=fwrite($fh,substr($bytes,$written));if($n===false||$n===0)throw new LocalUpdateException('upload_storage_unavailable','نوشتن فایل موقت ممکن نیست.',500);$written+=$n;}fflush($fh);flock($fh,LOCK_UN);}finally{fclose($fh);}
        $meta['received']=$offset+$length;$meta['updated_at']=gmdate('c');$this->writeMeta($id,$meta);return $this->publicMeta($meta);
    }

    public function finalize(string $id,int $actorId): array
    {
        $meta=$this->meta($id,$actorId);$part=$this->partPath($id);clearstatcache(true,$part);$actual=is_file($part)?(int)filesize($part):-1;
        if((int)$meta['received']!==(int)$meta['size']||$actual!==(int)$meta['size'])throw new LocalUpdateException('upload_incomplete','بارگذاری بسته هنوز کامل نشده است.',409,['received'=>(int)$meta['received'],'expected'=>(int)$meta['size']]);
        $staged=($this->stage)($part,$actorId);$this->remove($id);return ['staged'=>$staged,'transport'=>'chunked-v1'];
    }

    public function abort(string $id,int $actorId): array{$this->meta($id,$actorId);$this->remove($id);return ['aborted'=>true];}

    private function meta(string $id,int $actorId): array
    {
        $this->assertId($id);$raw=@file_get_contents($this->metaPath($id));if(!is_string($raw)||$raw==='')throw new LocalUpdateException('upload_missing','بارگذاری نیمه‌کاره پیدا نشد؛ دوباره شروع کن.',404);
        try{$meta=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){$meta=null;}if(!is_array($meta)||($meta['format']??'')!=='sokna-local-chunk-upload-v1')throw new LocalUpdateException('upload_missing','بارگذاری نیمه‌کاره معتبر نیست؛ دوباره شروع کن.',404);
        if((int)($meta['actor_user_id']??-1)!==$actorId)throw new LocalUpdateException('upload_forbidden','این بارگذاری متعلق به نشست دیگری است.',403);return $meta;
    }
    private function publicMeta(array $m): array{return ['id'=>(string)$m['id'],'name'=>(string)$m['name'],'size'=>(int)$m['size'],'received'=>(int)$m['received'],'chunk_size'=>self::CHUNK_BYTES,'complete'=>(int)$m['received']===(int)$m['size']];}
    private function assertId(string $id): void{if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new LocalUpdateException('invalid_upload','شناسه بارگذاری معتبر نیست.',422);}
    private function metaPath(string $id): string{return rtrim($this->incomingDir,'/\\').DIRECTORY_SEPARATOR.$id.'.upload.json';}
    private function partPath(string $id): string{return rtrim($this->incomingDir,'/\\').DIRECTORY_SEPARATOR.$id.'.part';}
    private function writeMeta(string $id,array $meta): void{$dir=rtrim($this->incomingDir,'/\\');if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new LocalUpdateException('upload_storage_unavailable','فضای موقت بارگذاری روی سرور آماده نیست.',500);$tmp=$this->metaPath($id).'.tmp';$json=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json,LOCK_EX)===false||!@rename($tmp,$this->metaPath($id))){@unlink($tmp);throw new LocalUpdateException('upload_storage_unavailable','ثبت وضعیت بارگذاری ممکن نیست.',500);} @chmod($this->metaPath($id),0600);}
    private function remove(string $id): void{@unlink($this->partPath($id));@unlink($this->metaPath($id));}
    private function cleanupExpired(): void{$dir=rtrim($this->incomingDir,'/\\');if(!is_dir($dir))return;$cut=time()-self::TTL_SECONDS;foreach(glob($dir.DIRECTORY_SEPARATOR.'*.upload.json')?:[] as $meta){$mtime=@filemtime($meta);if($mtime!==false&&$mtime<$cut){$id=basename($meta,'.upload.json');if(preg_match('/^[a-f0-9]{32}$/D',$id))$this->remove($id);}}}
}
