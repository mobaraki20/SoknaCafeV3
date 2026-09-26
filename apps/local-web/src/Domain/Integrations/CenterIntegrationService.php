<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use PDO;
use Sokna\Local\Core\Config;
use Sokna\Local\Core\IdentityRepository;

final class CenterIntegrationService
{
    public function __construct(private readonly PDO $pdo,private readonly Config $config,private readonly IdentityRepository $identity) {}

    public function projection(): array
    {
        $rows=$this->pdo->query('SELECT id,display_name,role,active,updated_at FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$users=[];
        foreach($rows as $r)$users[]=['local_user_id'=>(int)$r['id'],'display_name'=>(string)$r['display_name'],'role'=>(string)$r['role'],'active'=>(int)$r['active']===1,'updated_at'=>(string)$r['updated_at']];
        $canonical=json_encode($users,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return ['version'=>1,'source_version'=>hash('sha256',$canonical),'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'users'=>$users];
    }

    public function recordProjectionAttempt(array $projection,string $state,string $errorCode=''): void
    {
        $state=in_array($state,['pending','synced','failed'],true)?$state:'failed';
        $stmt=$this->pdo->prepare("INSERT INTO center_projection_receipts(source_version,user_count,state,attempt_count,last_error_code,acknowledged_at) VALUES(?,?,?,1,?,IF(?='synced',NOW(),NULL)) ON DUPLICATE KEY UPDATE state=VALUES(state),attempt_count=attempt_count+1,last_error_code=VALUES(last_error_code),acknowledged_at=IF(VALUES(state)='synced',NOW(),acknowledged_at)");
        $stmt->execute([(string)$projection['source_version'],count((array)$projection['users']),$state,$errorCode?:null,$state]);
    }

    public function entitlementState(int $userId): array
    {
        if($userId<1)return ['state'=>'deny','fresh'=>true];
        $fingerprint=$this->connectionFingerprint();
        $stmt=$this->pdo->prepare('SELECT * FROM center_entitlement_cache WHERE local_user_id=? LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||!hash_equals((string)$row['connection_fingerprint'],$fingerprint)||strtotime((string)$row['expires_at'])<time())return ['state'=>'unknown','fresh'=>false];
        return ['state'=>$row['allowed']===null?'unknown':((int)$row['allowed']===1?'allow':'deny'),'fresh'=>true,'checked_at'=>(string)$row['checked_at']];
    }

    public function cacheEntitlement(int $userId,?bool $allowed,?string $remoteSubject=null,int $ttlSeconds=600,string $errorCode=''): void
    {
        if($this->identity->findActiveById($userId)===null)throw new IntegrationException('user_not_found','کاربر محلی فعال نیست.',404);
        $stmt=$this->pdo->prepare('INSERT INTO center_entitlement_cache(local_user_id,allowed,remote_subject,checked_at,expires_at,connection_fingerprint,last_error_code) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed),remote_subject=VALUES(remote_subject),checked_at=VALUES(checked_at),expires_at=VALUES(expires_at),connection_fingerprint=VALUES(connection_fingerprint),last_error_code=VALUES(last_error_code)');
        $now=date('Y-m-d H:i:s');$stmt->execute([$userId,$allowed===null?null:($allowed?1:0),self::clip(trim((string)$remoteSubject),120)?:null,$now,date('Y-m-d H:i:s',time()+max(30,min(3600,$ttlSeconds))),$this->connectionFingerprint(),$errorCode?:null]);
    }

    public function buildHandoffToken(int $userId,string $purpose='handoff'): string
    {
        $user=$this->identity->findActiveById($userId);if($user===null)throw new IntegrationException('user_not_found','کاربر محلی فعال نیست.',404);
        $secret=$this->config->string('integrations.center.secret','');$base=rtrim($this->config->string('integrations.center.base_url',''),'/');
        if($secret===''||$base==='')throw new IntegrationException('center_not_configured','اتصال مرکز سکنا تنظیم نشده است.',409);
        $now=time();$payload=['iss'=>'cafe','sub'=>(string)$userId,'context'=>'CAFE','aud'=>$base,'purpose'=>$purpose,'iat'=>$now,'exp'=>$now+60,'nonce'=>bin2hex(random_bytes(24))];
        $header=self::b64(json_encode(['typ'=>'SOKNA-HANDOFF','alg'=>'HS256'],JSON_UNESCAPED_SLASHES));$body=self::b64(json_encode($payload,JSON_UNESCAPED_SLASHES));$sig=self::b64(hash_hmac('sha256',$header.'.'.$body,$secret,true));return $header.'.'.$body.'.'.$sig;
    }

    private function connectionFingerprint(): string{return hash('sha256',$this->config->string('integrations.center.base_url','').'|'.$this->config->string('integrations.center.secret',''));}
    private static function b64(string $v): string{return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
    private static function clip(string $v,int $n): string{return function_exists('mb_substr')?mb_substr($v,0,$n,'UTF-8'):substr($v,0,$n);}
}
