<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Update;
use Sokna\Local\Core\Observability;
use Throwable;
use ZipArchive;

final class LocalUpdateService
{
    private const FORMAT='sokna-component-package-v1';
    private const STATE_FORMAT='sokna-local-update-state-v1';
    private const MAX_FILES=12000;
    private const MAX_EXPANDED=536870912;
    public function __construct(
        private readonly Observability $observability,
        private readonly string $packageRoot,
        private readonly string $localWebRoot,
        private readonly string $compatibilityFile,
        private readonly string $trustFile,
    ){}

    public function snapshot(): array
    {
        $s=$this->state();$staged=(array)($s['staged']??[]);$history=array_slice(array_reverse((array)($s['history']??[])),0,30);
        return [
            'current_version'=>$this->currentVersion(),'active_version'=>(string)($s['active_version']??$this->currentVersion()),
            'previous_version'=>(string)($s['previous_version']??''),'lkg_recovery_id'=>(string)($s['lkg_recovery_id']??''),
            'staged'=>$staged,'history'=>$history,'signature_policy'=>(string)($this->trust()['signature_policy']??'unknown'),
            'stable_recovery'=>['path'=>'/local-recovery.php','ready'=>is_file($this->localWebRoot.'/public/local-recovery.php'),'token_configured'=>is_file($this->tokenFile())],
        ];
    }

    public function stageUploadedZip(string $zipPath,int $actorId=0): array
    {
        $meta=$this->verifyZip($zipPath);$version=(string)$meta['manifest']['version'];$hash=hash_file('sha256',$zipPath);
        $target=$this->root().'/staged/'.$version;
        if(is_dir($target)){
            $existing=$this->readJson($target.'/package.json');
            if(($existing['package_sha256']??'')!==$hash)throw new LocalUpdateException('immutable_version_conflict','برای این نسخه قبلاً بسته متفاوتی stage شده است.',409,['version'=>$version]);
        }else{
            $tmp=$target.'.staging-'.bin2hex(random_bytes(4));$this->removeTree($tmp);$this->mkdir($tmp.'/payload');
            $this->extractPayload($zipPath,$tmp.'/payload',(array)$meta['manifest']['files']);
            copy($zipPath,$tmp.'/package.zip');
            $this->writeJson($tmp.'/manifest.json',$meta['manifest']);
            $this->writeJson($tmp.'/package.json',['package_sha256'=>$hash,'signature_status'=>$meta['signature_status'],'staged_at'=>gmdate('c')]);
            $this->verifyDirectory($tmp.'/payload',(array)$meta['manifest']['files']);
            if(!@rename($tmp,$target)){ $this->removeTree($tmp); throw new LocalUpdateException('stage_failed','Stage بسته کامل نشد.',500); }
        }
        $s=$this->state();$s['staged']=['version'=>$version,'package_sha256'=>$hash,'source_commit'=>(string)$meta['manifest']['source_commit'],'signature_status'=>$meta['signature_status'],'staged_at'=>gmdate('c')];
        $this->event($s,'stage',$version,$actorId,['package_sha256'=>$hash]);$this->save($s);
        $this->observability->logEvent('warning','update.local_staged',['version'=>$version,'actor_user_id'=>$actorId,'package_sha256'=>$hash,'signature_status'=>$meta['signature_status']]);
        return $s['staged'];
    }

    public function activateStaged(int $actorId=0): array
    {
        $s=$this->state();$staged=(array)($s['staged']??[]);$version=(string)($staged['version']??'');if($version==='')throw new LocalUpdateException('nothing_staged','بسته‌ای برای فعال‌سازی آماده نیست.',409);
        $dir=$this->root().'/staged/'.$version;$manifest=$this->readJson($dir.'/manifest.json');$this->assertCompatible($manifest);$this->verifyDirectory($dir.'/payload',(array)($manifest['files']??[]));
        if($version===$this->currentVersion())throw new LocalUpdateException('same_version_requires_repair','برای نسخه فعلی از Repair استفاده کن.',409,['version'=>$version]);
        $recovery=$this->createRecoveryPoint($actorId,'before_activate_'.$version);$old=$this->currentVersion();
        try{$this->deployPayload($dir.'/payload');$this->healthCheck();}
        catch(Throwable $e){$this->restoreRecoveryPoint((string)$recovery['id']);$this->event($s,'rollback_auto',$version,$actorId,['error'=>'activation_health_failed']);$this->save($s);throw new LocalUpdateException('activation_failed','فعال‌سازی ناموفق بود و نسخه قبلی خودکار برگردانده شد.',500);}
        $s=$this->state();$s['previous_version']=$old;$s['active_version']=$version;$s['lkg_recovery_id']=(string)$recovery['id'];$s['staged']=null;
        $this->event($s,'activate',$version,$actorId,['previous'=>$old,'recovery_id'=>$recovery['id']]);$this->save($s);$this->observability->logEvent('warning','update.local_activated',['version'=>$version,'previous'=>$old,'actor_user_id'=>$actorId,'recovery_id'=>$recovery['id']]);
        return $this->snapshot();
    }

    public function repairStaged(int $actorId=0): array
    {
        $s=$this->state();$version=(string)(($s['staged']??[])['version']??'');if($version===''||$version!==$this->currentVersion())throw new LocalUpdateException('repair_requires_same_version','Repair فقط برای بسته stage‌شده همان نسخه فعال مجاز است.',409);
        $dir=$this->root().'/staged/'.$version;$this->verifyDirectory($dir.'/payload',(array)$this->readJson($dir.'/manifest.json')['files']);$recovery=$this->createRecoveryPoint($actorId,'before_repair_'.$version);
        try{$this->deployPayload($dir.'/payload');$this->healthCheck();}catch(Throwable){$this->restoreRecoveryPoint((string)$recovery['id']);throw new LocalUpdateException('repair_failed','Repair ناموفق بود و نسخه قبلی برگردانده شد.',500);}
        $s=$this->state();$s['staged']=null;$s['lkg_recovery_id']=(string)$recovery['id'];$this->event($s,'repair',$version,$actorId,['recovery_id'=>$recovery['id']]);$this->save($s);$this->observability->logEvent('warning','update.local_repaired',['version'=>$version,'actor_user_id'=>$actorId]);return $this->snapshot();
    }

    public function rollback(int $actorId=0): array
    {
        $s=$this->state();$id=(string)($s['lkg_recovery_id']??'');if($id==='')throw new LocalUpdateException('no_recovery_point','نقطه بازگشت معتبری وجود ندارد.',409);
        $from=$this->currentVersion();$meta=$this->restoreRecoveryPoint($id);$to=(string)($meta['version']??'unknown');$s=$this->state();$s['active_version']=$to;$s['previous_version']=$from;$this->event($s,'rollback',$to,$actorId,['from'=>$from,'recovery_id'=>$id]);$this->save($s);$this->observability->logEvent('critical','update.local_rollback',['from'=>$from,'to'=>$to,'actor_user_id'=>$actorId,'recovery_id'=>$id]);return $this->snapshot();
    }

    public function rotateRecoveryCode(int $actorId=0): array
    {
        $plain=strtoupper(implode('-',str_split(bin2hex(random_bytes(12)),8)));$this->writeJson($this->tokenFile(),['format'=>'sokna-local-recovery-token-v1','hash'=>password_hash($plain,PASSWORD_DEFAULT),'rotated_at'=>gmdate('c'),'actor_user_id'=>$actorId]);@chmod($this->tokenFile(),0600);
        $this->observability->logEvent('warning','update.recovery_code_rotated',['actor_user_id'=>$actorId]);return ['recovery_code'=>$plain,'path'=>'/local-recovery.php'];
    }

    public function currentVersion(): string{$raw=$this->readJson($this->root().'/state.json');$active=trim((string)($raw['active_version']??''));if($active!=='')return $active;$v=@file_get_contents($this->packageRoot.'/VERSION.txt');return is_string($v)&&trim($v)!==''?trim($v):'unknown';}
    public function incomingPath(string $id): string{if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new LocalUpdateException('invalid_upload','شناسه فایل معتبر نیست.',422);$p=$this->root().'/incoming/'.$id.'.zip';if(!is_file($p))throw new LocalUpdateException('upload_missing','فایل آپلودشده پیدا نشد.',404);return $p;}
    public function incomingDir(): string{$p=$this->root().'/incoming';$this->mkdir($p);return $p;}

    private function verifyZip(string $path): array
    {
        if(!class_exists(ZipArchive::class))throw new LocalUpdateException('zip_unavailable','افزونه ZIP روی PHP فعال نیست.',500);if(!is_file($path))throw new LocalUpdateException('package_missing','بسته پیدا نشد.',404);
        $z=new ZipArchive();if($z->open($path)!==true)throw new LocalUpdateException('invalid_zip','فایل ZIP معتبر نیست.',422);
        try{
            $raw=$z->getFromName('manifest.json');if(!is_string($raw))throw new LocalUpdateException('manifest_missing','manifest.json داخل بسته وجود ندارد.',422);$manifest=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($manifest)||($manifest['format']??'')!==self::FORMAT||(int)($manifest['schema_version']??0)!==1||(string)($manifest['component']??'')!=='local')throw new LocalUpdateException('manifest_contract','قرارداد بسته Local معتبر نیست.',422);
            $version=trim((string)($manifest['version']??''));if(!preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/',$version))throw new LocalUpdateException('invalid_version','نسخه بسته معتبر نیست.',422);
            $files=(array)($manifest['files']??[]);if($files===[]||count($files)>self::MAX_FILES)throw new LocalUpdateException('invalid_file_count','تعداد فایل‌های بسته معتبر نیست.',422);
            $expected=[];$expanded=0;foreach($files as $e){if(!is_array($e))throw new LocalUpdateException('manifest_contract','رکورد فایل معتبر نیست.',422);$rel=$this->safeRelative((string)($e['path']??''));$size=(int)($e['size']??-1);$hash=strtolower((string)($e['sha256']??''));if($size<0||!preg_match('/^[a-f0-9]{64}$/',$hash))throw new LocalUpdateException('manifest_contract','هش فایل معتبر نیست.',422);if(isset($expected[$rel]))throw new LocalUpdateException('duplicate_entry','مسیر تکراری در manifest مجاز نیست.',422,['file'=>$rel]);$expected[$rel]=[$size,$hash];$expanded+=$size;if($expanded>self::MAX_EXPANDED)throw new LocalUpdateException('package_too_large','حجم بازشده بسته بیش از حد مجاز است.',413);}
            $actual=[];for($i=0;$i<$z->numFiles;$i++){$name=(string)$z->getNameIndex($i);if($name==='manifest.json'||$name==='signature.json'||str_ends_with($name,'/'))continue;if(!str_starts_with($name,'payload/'))throw new LocalUpdateException('unexpected_entry','فایل خارج از payload داخل بسته وجود دارد.',422,['entry'=>$name]);$rel=$this->safeRelative(substr($name,8));if(isset($actual[$rel]))throw new LocalUpdateException('duplicate_entry','مسیر تکراری در ZIP مجاز نیست.',422,['file'=>$rel]);$actual[$rel]=true;}
            if(array_keys($expected)!==array_keys(array_intersect_key($expected,$actual))||count($actual)!==count($expected))throw new LocalUpdateException('payload_set_mismatch','فهرست payload با manifest یکسان نیست.',422);
            foreach($expected as $rel=>[$size,$hash]){$stream=$z->getStream('payload/'.$rel);if(!is_resource($stream))throw new LocalUpdateException('missing_file','فایل اعلام‌شده در payload نیست.',422,['file'=>$rel]);$ctx=hash_init('sha256');$read=0;while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false)break;$read+=strlen($buf);hash_update($ctx,$buf);}fclose($stream);if($read!==$size||!hash_equals($hash,hash_final($ctx)))throw new LocalUpdateException('hash_mismatch','هش یکی از فایل‌های بسته معتبر نیست.',422,['file'=>$rel]);}
            $this->assertCompatible($manifest);$signature=$this->verifySignature($z,$raw);return ['manifest'=>$manifest,'signature_status'=>$signature];
        }catch(LocalUpdateException $e){throw $e;}catch(Throwable){throw new LocalUpdateException('package_invalid','ساختار بسته update معتبر نیست.',422);}finally{$z->close();}
    }

    private function verifySignature(ZipArchive $z,string $manifestRaw): string
    {
        $trust=$this->trust();$sigRaw=$z->getFromName('signature.json');$required=(string)($trust['signature_policy']??'')==='required';if(!is_string($sigRaw)){if($required)throw new LocalUpdateException('signature_required','امضای بسته الزامی است.',422);return 'not_present_pre_release_allowed';}
        $sig=json_decode($sigRaw,true,16,JSON_THROW_ON_ERROR);$keyId=(string)($sig['key_id']??'');$alg=(string)($sig['algorithm']??'');$b64=(string)($sig['signature_base64']??'');$keys=(array)($trust['ed25519_public_keys']??[]);$pub=base64_decode((string)($keys[$keyId]??''),true);$raw=base64_decode($b64,true);
        if($alg!=='ed25519'||!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!is_string($raw)||strlen($raw)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($raw,$manifestRaw,$pub))throw new LocalUpdateException('bad_signature','امضای بسته معتبر نیست.',422);return 'verified_ed25519';
    }

    private function extractPayload(string $zipPath,string $target,array $files): void
    { $z=new ZipArchive();$z->open($zipPath);try{foreach($files as $e){$rel=$this->safeRelative((string)$e['path']);$dst=$target.'/'.$rel;$this->mkdir(dirname($dst));$in=$z->getStream('payload/'.$rel);$out=fopen($dst,'wb');if(!is_resource($in)||!is_resource($out))throw new LocalUpdateException('extract_failed','استخراج بسته کامل نشد.',500);stream_copy_to_stream($in,$out);fclose($in);fclose($out);}}finally{$z->close();}}
    private function assertCompatible(array $manifest): void
    { $compat=$this->readJson($this->compatibilityFile);$req=(array)(($compat['components']['local']??[])['requires']??[]);$contracts=(array)($manifest['contracts']??[]);foreach($req as $k=>$want){if(in_array($k,['php_version_id','pdo_mysql'],true))continue;if(!array_key_exists($k,$contracts))throw new LocalUpdateException('missing_contract','قرارداد موردنیاز بسته وجود ندارد.',422,['contract'=>$k]);$actual=$contracts[$k];if(is_int($want)&&(int)$actual!==$want)throw new LocalUpdateException('incompatible_contract','نسخه قرارداد سازگار نیست.',409,['contract'=>$k]);if(is_string($want)&&str_starts_with($want,'>=')){if((string)explode('.',(string)$actual)[0]!=='1')throw new LocalUpdateException('incompatible_contract','نسخه قرارداد سازگار نیست.',409,['contract'=>$k]);}} }

    private function createRecoveryPoint(int $actorId,string $reason): array
    { $id=gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$dir=$this->root().'/recovery-points/'.$id;$this->copyTree($this->localWebRoot,$dir.'/payload');$files=$this->hashTree($dir.'/payload');$meta=['format'=>'sokna-local-recovery-point-v1','id'=>$id,'version'=>$this->currentVersion(),'created_at'=>gmdate('c'),'reason'=>$reason,'actor_user_id'=>$actorId,'files'=>$files];$this->writeJson($dir.'/manifest.json',$meta);return $meta; }
    private function restoreRecoveryPoint(string $id): array
    { if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/',$id))throw new LocalUpdateException('invalid_recovery_id','شناسه نقطه بازگشت معتبر نیست.',422);$dir=$this->root().'/recovery-points/'.$id;$m=$this->readJson($dir.'/manifest.json');if(($m['format']??'')!=='sokna-local-recovery-point-v1')throw new LocalUpdateException('recovery_missing','نقطه بازگشت معتبر نیست.',404);$this->verifyDirectory($dir.'/payload',(array)$m['files']);$this->deployPayload($dir.'/payload');$this->healthCheck();return $m; }
    private function deployPayload(string $source): void
    { $parent=dirname($this->localWebRoot);$candidate=$parent.'/.sokna-candidate-'.bin2hex(random_bytes(4));$old=$parent.'/.sokna-old-'.bin2hex(random_bytes(4));$this->copyTree($source,$candidate);$stable=$this->localWebRoot.'/public/local-recovery.php';if(is_file($stable)){@mkdir($candidate.'/public',0755,true);copy($stable,$candidate.'/public/local-recovery.php');}if(!@rename($this->localWebRoot,$old)){ $this->removeTree($candidate);throw new LocalUpdateException('activate_swap_failed','جابه‌جایی نسخه فعلی ممکن نشد.',500);}if(!@rename($candidate,$this->localWebRoot)){@rename($old,$this->localWebRoot);$this->removeTree($candidate);throw new LocalUpdateException('activate_swap_failed','فعال‌سازی نسخه جدید ممکن نشد.',500);} $this->removeTree($old); }
    private function healthCheck(): void
    { foreach(['bootstrap.php','public/_app.php','public/index.php','public/local-recovery.php'] as $rel)if(!is_file($this->localWebRoot.'/'.$rel))throw new LocalUpdateException('health_failed','فایل حیاتی نسخه جدید وجود ندارد.',500,['file'=>$rel]);if(PHP_BINARY!==''){foreach(['bootstrap.php','public/_app.php','public/index.php','public/local-recovery.php'] as $rel){$cmd=escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->localWebRoot.'/'.$rel).' 2>&1';exec($cmd,$out,$code);if($code!==0)throw new LocalUpdateException('health_failed','PHP syntax health check ناموفق بود.',500,['file'=>$rel]);}} }

    private function verifyDirectory(string $root,array $files): void
    { $expected=[];foreach($files as $e){$rel=$this->safeRelative((string)($e['path']??''));$expected[$rel]=true;$p=$root.'/'.$rel;if(!is_file($p)||(int)filesize($p)!==(int)$e['size']||!hash_equals(strtolower((string)$e['sha256']),strtolower((string)hash_file('sha256',$p))))throw new LocalUpdateException('staged_integrity_failed','یکپارچگی Stage معتبر نیست.',422,['file'=>$rel]);}$actual=[];$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$actual[str_replace('\\','/',substr($f->getPathname(),strlen($root)+1))]=true;if(count($actual)!==count($expected)||array_diff_key($actual,$expected)||array_diff_key($expected,$actual))throw new LocalUpdateException('payload_set_mismatch','مجموعه فایل‌های Stage تغییر کرده است.',422); }
    private function hashTree(string $root): array{$out=[];$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile()){$rel=str_replace('\\','/',substr($f->getPathname(),strlen($root)+1));$out[]=['path'=>$rel,'size'=>$f->getSize(),'sha256'=>hash_file('sha256',$f->getPathname())];}usort($out,fn($a,$b)=>strcmp($a['path'],$b['path']));return $out;}
    private function copyTree(string $src,string $dst): void{$this->removeTree($dst);$this->mkdir($dst);$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);foreach($it as $f){$rel=substr($f->getPathname(),strlen($src)+1);$to=$dst.'/'.$rel;if($f->isDir())$this->mkdir($to);elseif($f->isFile()){ $this->mkdir(dirname($to));if(!copy($f->getPathname(),$to))throw new LocalUpdateException('copy_failed','کپی فایل lifecycle ناموفق بود.',500);}}}
    private function removeTree(string $p): void{if(!file_exists($p))return;if(is_file($p)||is_link($p)){@unlink($p);return;}$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());}@rmdir($p);}
    private function root(): string{$p=$this->observability->dataRoot().'/lifecycle/local';$this->mkdir($p);return $p;}
    private function tokenFile(): string{return $this->root().'/recovery-token.json';}
    private function state(): array{$s=$this->readJson($this->root().'/state.json');if(($s['format']??'')!==self::STATE_FORMAT)$s=['format'=>self::STATE_FORMAT,'schema_version'=>1,'active_version'=>$this->currentVersion(),'previous_version'=>'','lkg_recovery_id'=>'','staged'=>null,'history'=>[]];return $s;}
    private function save(array $s): void{$this->writeJson($this->root().'/state.json',$s);}
    private function event(array &$s,string $action,string $version,int $actorId,array $extra=[]): void{$s['history'][]=['at'=>gmdate('c'),'action'=>$action,'version'=>$version,'actor_user_id'=>$actorId,'result'=>'success','correlation_id'=>$this->observability->correlationId()]+$extra;$s['history']=array_slice($s['history'],-100);}
    private function readJson(string $p): array{$raw=@file_get_contents($p);if(!is_string($raw)||$raw==='')return [];try{$d=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($d)?$d:[];}catch(Throwable){return [];}}
    private function writeJson(string $p,array $v): void{$this->mkdir(dirname($p));$tmp=$p.'.tmp-'.bin2hex(random_bytes(4));$json=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$p)){@unlink($tmp);throw new LocalUpdateException('state_write_failed','ثبت وضعیت lifecycle ناموفق بود.',500);} @chmod($p,0600);}
    private function safeRelative(string $r): string{$r=str_replace('\\','/',trim($r));if($r===''||str_starts_with($r,'/')||preg_match('#(^|/)\.\.(/|$)#',$r)||str_contains($r,"\0"))throw new LocalUpdateException('unsafe_path','مسیر فایل بسته ناامن است.',422,['path'=>$r]);return $r;}
    private function mkdir(string $p): void{if(!is_dir($p)&&!@mkdir($p,0700,true)&&!is_dir($p))throw new LocalUpdateException('storage_unavailable','فضای lifecycle قابل نوشتن نیست.',500);}
    private function trust(): array{return $this->readJson($this->trustFile);}
}
