<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
use Sokna\Local\Core\Config;
final class PublicReenrollmentService
{
    public function __construct(private readonly Config $config,private readonly PublicEdgeSyncClient $client,private readonly string $configPath){}
    public function complete(string $baseUrl,string $code,string $displayName='',int $actorId=0): array
    {
        $code=trim($code);if($code==='')throw new PublicEdgeSyncException('enrollment_code_required','کد re-enrollment لازم است.',422);$secret=bin2hex(random_bytes(32));$r=$this->client->reenroll($baseUrl,$code,$secret,$displayName);$raw=is_file($this->configPath)?require $this->configPath:[];if(!is_array($raw))throw new PublicEdgeSyncException('config_unavailable','config محلی قابل به‌روزرسانی نیست.',500);$raw['public']=is_array($raw['public']??null)?$raw['public']:[];$raw['public']['base_url']=rtrim(trim($baseUrl),'/');$raw['public']['shared_secret']=$secret;$tmp=$this->configPath.'.tmp-'.bin2hex(random_bytes(4));$php="<?php\ndeclare(strict_types=1);\nreturn ".var_export($raw,true).";\n";if(file_put_contents($tmp,$php,LOCK_EX)===false||!@rename($tmp,$this->configPath)){@unlink($tmp);throw new PublicEdgeSyncException('config_write_failed','ثبت pairing جدید در config محلی کامل نشد.',500);}@chmod($this->configPath,0600);return ['ok'=>true,'public'=>$r['body'],'reload_required'=>true,'actor_user_id'=>$actorId];
    }
}
