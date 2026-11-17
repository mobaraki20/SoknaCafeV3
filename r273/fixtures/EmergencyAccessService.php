<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Emergency;

use Throwable;

final class EmergencyAccessService
{
    private const LOGIN_WINDOW_SECONDS = 600;
    private const LOGIN_MAX_FAILURES = 5;
    private const LOGIN_LOCK_SECONDS = 900;

    public function __construct(private readonly string $storageRoot){}

    public function configured(): bool
    {
        return (string)($this->state()['password_hash'] ?? '') !== '';
    }

    public function provisionHash(string $hash,string $actorHint='local'): array
    {
        if($hash==='' || !password_get_info($hash)['algo']) {
            throw new PublicUpdateException('invalid_access_hash','Emergency access hash is invalid.');
        }
        $this->write([
            'format'=>'sokna-public-emergency-access-v1',
            'password_hash'=>$hash,
            'rotated_at'=>gmdate('c'),
            'actor_hint'=>$actorHint,
        ]);
        $this->audit('access_rotated',null,$actorHint,[]);
        return ['ok'=>true,'rotated_at'=>gmdate('c')];
    }

    public function verify(string $code): bool
    {
        $h=(string)($this->state()['password_hash']??'');
        return $h!=='' && $code!=='' && password_verify($code,$h);
    }

    /** @return array{allowed:bool,retry_after:int,remaining:int} */
    public function loginStatus(string $clientKey): array
    {
        $key=$this->clientKey($clientKey);
        $all=$this->readThrottle();
        $now=time();
        $row=is_array($all[$key]??null)?$all[$key]:[];
        $lockUntil=(int)($row['lock_until']??0);
        $failures=array_values(array_filter((array)($row['failures']??[]),static fn($v): bool => is_int($v)||ctype_digit((string)$v)));
        $failures=array_values(array_filter(array_map('intval',$failures),static fn(int $ts): bool => $ts>=$now-self::LOGIN_WINDOW_SECONDS));
        if($lockUntil>$now) {
            return ['allowed'=>false,'retry_after'=>$lockUntil-$now,'remaining'=>0];
        }
        return ['allowed'=>true,'retry_after'=>0,'remaining'=>max(0,self::LOGIN_MAX_FAILURES-count($failures))];
    }

    /** @return array{allowed:bool,retry_after:int,remaining:int} */
    public function recordLoginFailure(string $clientKey): array
    {
        $key=$this->clientKey($clientKey);
        $all=$this->readThrottle();
        $now=time();
        $row=is_array($all[$key]??null)?$all[$key]:[];
        $failures=array_values(array_filter(array_map('intval',(array)($row['failures']??[])),static fn(int $ts): bool => $ts>=$now-self::LOGIN_WINDOW_SECONDS));
        $failures[]=$now;
        $lockUntil=(int)($row['lock_until']??0);
        if(count($failures)>=self::LOGIN_MAX_FAILURES) {
            $lockUntil=max($lockUntil,$now+self::LOGIN_LOCK_SECONDS);
            $failures=[];
        }
        $all[$key]=['failures'=>$failures,'lock_until'=>$lockUntil,'updated_at'=>$now];
        $this->pruneThrottle($all,$now);
        $this->writeThrottle($all);
        return $this->loginStatus($clientKey);
    }

    public function recordLoginSuccess(string $clientKey): void
    {
        $key=$this->clientKey($clientKey);
        $all=$this->readThrottle();
        if(isset($all[$key])) {
            unset($all[$key]);
            $this->writeThrottle($all);
        }
    }

    public function recentAudit(int $limit=80): array
    {
        $p=$this->auditFile();
        if(!is_file($p))return [];
        $lines=@file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];
        $lines=array_slice($lines,-max(1,min(200,$limit)));
        $out=[];
        foreach(array_reverse($lines) as $line){
            try{
                $r=json_decode($line,true,16,JSON_THROW_ON_ERROR);
                if(is_array($r))$out[]=$r;
            }catch(Throwable){}
        }
        return $out;
    }

    public function audit(string $action,?string $installationId,string $actorHint,array $details): void
    {
        $this->mkdir($this->dir());
        $row=[
            'at'=>gmdate('c'),
            'action'=>$action,
            'installation_id'=>$installationId,
            'actor_hint'=>substr($actorHint,0,120),
            'details'=>$this->redact($details),
        ];
        @file_put_contents($this->auditFile(),json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
        @chmod($this->auditFile(),0600);
    }

    public function clientActorHint(string $clientKey): string
    {
        return 'web-console:'.substr($this->clientKey($clientKey),0,16);
    }

    private function redact(array $v): array
    {
        foreach($v as $k=>$x){
            if(preg_match('/secret|pass|token|code/i',(string)$k))$v[$k]='[redacted]';
            elseif(is_array($x))$v[$k]=$this->redact($x);
        }
        return $v;
    }

    private function state(): array
    {
        $p=$this->file();
        if(!is_file($p))return [];
        try{
            $x=json_decode((string)file_get_contents($p),true,16,JSON_THROW_ON_ERROR);
            return is_array($x)?$x:[];
        }catch(Throwable){return [];}
    }

    private function write(array $v): void
    {
        $this->mkdir($this->dir());
        $tmp=$this->file().'.tmp-'.bin2hex(random_bytes(4));
        file_put_contents($tmp,json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
        @chmod($tmp,0600);
        if(!@rename($tmp,$this->file())){
            @unlink($tmp);
            throw new PublicUpdateException('access_write_failed','Emergency access state could not be written.',500);
        }
    }

    private function clientKey(string $raw): string
    {
        $raw=trim($raw);
        if($raw==='')$raw='unknown';
        return hash('sha256','sokna-public-emergency-login|'.$raw);
    }

    private function readThrottle(): array
    {
        $p=$this->throttleFile();
        if(!is_file($p))return [];
        try{
            $x=json_decode((string)file_get_contents($p),true,32,JSON_THROW_ON_ERROR);
            return is_array($x)?$x:[];
        }catch(Throwable){return [];}
    }

    private function writeThrottle(array $v): void
    {
        $this->mkdir($this->dir());
        $tmp=$this->throttleFile().'.tmp-'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,json_encode($v,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX)===false){
            @unlink($tmp);
            return;
        }
        @chmod($tmp,0600);
        if(!@rename($tmp,$this->throttleFile()))@unlink($tmp);
    }

    private function pruneThrottle(array &$all,int $now): void
    {
        foreach($all as $key=>$row){
            if(!is_array($row)){unset($all[$key]);continue;}
            $updated=(int)($row['updated_at']??0);
            $lockUntil=(int)($row['lock_until']??0);
            if($updated<$now-86400 && $lockUntil<$now)unset($all[$key]);
        }
        if(count($all)>500)$all=array_slice($all,-500,null,true);
    }

    private function dir(): string{return rtrim($this->storageRoot,"\\/").'/emergency';}
    private function file(): string{return $this->dir().'/access.json';}
    private function throttleFile(): string{return $this->dir().'/login-throttle.json';}
    private function auditFile(): string{return $this->dir().'/events.log';}
    private function mkdir(string $p): void
    {
        if(!is_dir($p)&&!@mkdir($p,0700,true)&&!is_dir($p)){
            throw new PublicUpdateException('storage_unavailable','Emergency storage is unavailable.',500);
        }
    }
}
