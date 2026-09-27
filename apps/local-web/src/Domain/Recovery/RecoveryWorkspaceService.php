<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\Recovery;
use Sokna\Local\Core\Observability;
use Sokna\Local\Domain\Update\LocalUpdateException;
final class RecoveryWorkspaceService
{
    public function __construct(private readonly BusinessBackupService $backup,private readonly Observability $observability){}
    public function snapshot(): array{$dir=$this->dir();$rows=[];foreach(glob($dir.'/*.skbf')?:[] as $p){$rows[]=['id'=>basename($p,'.skbf'),'size_bytes'=>(int)filesize($p),'sha256'=>hash_file('sha256',$p),'modified_at'=>gmdate('c',(int)filemtime($p))];}usort($rows,fn($a,$b)=>strcmp($b['modified_at'],$a['modified_at']));return ['backups'=>array_slice($rows,0,20),'machine_identity_excluded'=>['runtime_machine_secret','print_agent_identity','tls_private_key'],'takeover_status'=>'G3_PUBLIC_REENROLLMENT_PENDING'];}
    public function create(string $passphrase,int $actorId): array{$id='business-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));$path=$this->dir().'/'.$id.'.skbf';$meta=$this->backup->createEncrypted($path,$passphrase,$actorId);$set=['format'=>'sokna-recovery-set-v1','created_at'=>$meta['created_at'],'business_backup'=>['path'=>basename($path),'sha256'=>$meta['sha256']],'source_installation_id'=>$meta['source_installation_id'],'excluded_machine_identity'=>$meta['excluded_machine_identity']];file_put_contents($this->dir().'/'.$id.'.recovery.json',json_encode($set,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",LOCK_EX);return ['id'=>$id,'size_bytes'=>$meta['size'],'sha256'=>$meta['sha256'],'download'=>'/system/recovery-download.php?id='.rawurlencode($id)];}
    public function inspect(string $id,string $passphrase): array{return $this->backup->inspectEncrypted($this->path($id),$passphrase);}
    public function restore(string $id,string $passphrase,int $actorId): array{return $this->backup->restoreEncryptedToEmptyTarget($this->path($id),$passphrase,$actorId);}
    public function path(string $id): string{if(!preg_match('/^(?:business-)?[A-Za-z0-9._-]{6,80}$/',$id))throw new RecoveryException('invalid_backup','شناسه پشتیبان معتبر نیست.',422);$p=$this->dir().'/'.$id.(str_ends_with($id,'.skbf')?'':'.skbf');if(!is_file($p))throw new RecoveryException('backup_missing','فایل پشتیبان پیدا نشد.',404);return $p;}
    public function incomingDir(): string{$p=$this->dir().'/incoming';if(!is_dir($p))@mkdir($p,0700,true);return $p;}
    public function registerIncoming(string $tmp,string $name): array{$id='uploaded-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));$dst=$this->dir().'/'.$id.'.skbf';if(!@rename($tmp,$dst)&&!@copy($tmp,$dst))throw new RecoveryException('upload_failed','ذخیره فایل بازیابی انجام نشد.',500);@chmod($dst,0600);return ['id'=>$id,'file_name'=>$name,'size_bytes'=>(int)filesize($dst),'sha256'=>hash_file('sha256',$dst)];}
    private function dir(): string{$p=$this->observability->dataRoot().'/recovery/business';if(!is_dir($p)&&!@mkdir($p,0700,true)&&!is_dir($p))throw new RecoveryException('recovery_storage','فضای بازیابی آماده نیست.',500);return $p;}
}
