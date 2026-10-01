<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\System;

use Closure;
use Sokna\Local\Core\Config;
use Throwable;

final class WindowsServicesPairingService
{
    private const FORMAT='sokna-windows-services-pairing-ticket-v1';
    private const BUNDLE_FORMAT='sokna-windows-services-pairing-v1';
    private const TTL_SECONDS=600;
    private const RETAIN_TERMINAL_SECONDS=604800;

    /** @param Closure(string,array):array $createAgent @param Closure(int,int,string):void $retireAgent */
    public function __construct(
        private readonly Config $config,
        private readonly string $dataRoot,
        private readonly Closure $createAgent,
        private readonly Closure $retireAgent,
    ) {}

    public function create(array $actor,string $displayName=''): array
    {
        $this->assertAdmin($actor);$this->cleanup();$this->supersedeOutstanding();
        $displayName=trim($displayName);if($displayName==='')$displayName='Windows Services';
        $displayName=$this->cut($displayName,120);
        $actorId=(int)($actor['id']??0);
        $agent=null;
        try{
            $agent=($this->createAgent)($displayName,$actor);
            $agentId=max(0,(int)($agent['agent_id']??0));$printToken=trim((string)($agent['token']??''));
            if($agentId<1||strlen($printToken)<32)throw new WindowsServicesPairingException('print_agent_pairing_failed','ساخت دسترسی Print Agent کامل نشد.',500);
            $bundle=$this->buildBundle($printToken);
            $idHex=bin2hex(random_bytes(12));$pairingId='wsp-'.$idHex;$code='ws1_'.$idHex.'_'.bin2hex(random_bytes(24));$now=time();
            $sealed=$this->seal($bundle);
            $ticket=[
                'format'=>self::FORMAT,'schema_version'=>1,'pairing_id'=>$pairingId,'code_hash'=>hash('sha256',$code),
                'state'=>'issued','created_at'=>gmdate('c',$now),'created_at_unix_ms'=>$this->nextCreatedMs(),'expires_at'=>gmdate('c',$now+self::TTL_SECONDS),
                'actor_user_id'=>$actorId,'display_name'=>$displayName,'print_agent_id'=>$agentId,'local_base_url'=>$bundle['local_base_url'],
                'exchange_count'=>0,'exchanged_at'=>null,'confirmed_at'=>null,'canceled_at'=>null,
                'bundle_nonce'=>$sealed['nonce'],'bundle_ciphertext'=>$sealed['ciphertext'],
            ];
            $this->writeTicket($idHex,$ticket);
            return $this->publicTicket($ticket)+['pairing_code'=>$code,'ttl_seconds'=>self::TTL_SECONDS];
        }catch(Throwable $e){
            if(is_array($agent)&&($agent['agent_id']??0)>0){try{($this->retireAgent)((int)$agent['agent_id'],$actorId,'pairing_create_failed');}catch(Throwable){}}
            throw $e;
        }
    }

    public function status(): array
    {
        $this->cleanup();$files=$this->ticketFiles();if($files===[]){try{[$base]=$this->canonicalLoopback($this->config->string('runtime.local_base_url',''));}catch(Throwable){$base='';}return ['state'=>'not_created','active'=>false,'local_base_url'=>$base];}
        $tickets=[];foreach($files as $file){$ticket=$this->readTicketFile($file);if(is_array($ticket))$tickets[]=$ticket;}
        usort($tickets,fn(array $a,array $b)=>((int)($b['created_at_unix_ms']??((strtotime((string)($b['created_at']??''))?:0)*1000)))<=>((int)($a['created_at_unix_ms']??((strtotime((string)($a['created_at']??''))?:0)*1000))));
        if($tickets!==[])return $this->publicTicket($tickets[0]);
        return ['state'=>'not_created','active'=>false];
    }

    public function exchange(string $code,string $remoteAddress): array
    {
        $this->assertLoopback($remoteAddress);$ticket=$this->ticketForCode($code,false);$state=(string)($ticket['state']??'');
        if($state==='confirmed')throw new WindowsServicesPairingException('pairing_already_confirmed','این کد قبلاً مصرف شده است.',409);
        if(in_array($state,['canceled','expired','superseded'],true))throw new WindowsServicesPairingException('pairing_unavailable','این کد دیگر قابل استفاده نیست.',410);
        if(!in_array($state,['issued','exchanged'],true))throw new WindowsServicesPairingException('pairing_state_invalid','وضعیت اتصال معتبر نیست.',409);
        $bundle=$this->openBundle($ticket);
        $ticket['state']='exchanged';$ticket['exchange_count']=max(0,(int)($ticket['exchange_count']??0))+1;$ticket['exchanged_at']=$ticket['exchanged_at']??gmdate('c');
        $this->writeTicket($this->idHex($ticket),$ticket);
        return ['pairing_id'=>(string)$ticket['pairing_id'],'bundle'=>$bundle,'expires_at'=>(string)$ticket['expires_at']];
    }

    public function confirm(string $code,string $remoteAddress): array
    {
        $this->assertLoopback($remoteAddress);$ticket=$this->ticketForCode($code,false);$state=(string)($ticket['state']??'');
        if($state==='confirmed')return ['pairing_id'=>(string)$ticket['pairing_id'],'confirmed'=>true,'idempotent'=>true];
        if(in_array($state,['canceled','expired','superseded'],true))throw new WindowsServicesPairingException('pairing_unavailable','این کد دیگر قابل استفاده نیست.',410);
        if($state!=='exchanged')throw new WindowsServicesPairingException('pairing_exchange_required','ابتدا بسته اتصال باید دریافت شود.',409);
        $ticket['state']='confirmed';$ticket['confirmed_at']=gmdate('c');unset($ticket['bundle_nonce'],$ticket['bundle_ciphertext']);
        $this->writeTicket($this->idHex($ticket),$ticket);
        return ['pairing_id'=>(string)$ticket['pairing_id'],'confirmed'=>true,'idempotent'=>false];
    }

    public function cancel(string $code,string $remoteAddress): array
    {
        $this->assertLoopback($remoteAddress);$ticket=$this->ticketForCode($code,false);$state=(string)($ticket['state']??'');
        if($state==='canceled')return ['pairing_id'=>(string)$ticket['pairing_id'],'canceled'=>true,'idempotent'=>true];
        if($state==='confirmed')throw new WindowsServicesPairingException('pairing_already_confirmed','اتصال قبلاً کامل شده است.',409);
        if(in_array($state,['expired','superseded'],true))return ['pairing_id'=>(string)$ticket['pairing_id'],'canceled'=>true,'idempotent'=>true];
        $this->retireTicketAgent($ticket,'pairing_canceled');$ticket['state']='canceled';$ticket['canceled_at']=gmdate('c');unset($ticket['bundle_nonce'],$ticket['bundle_ciphertext']);
        $this->writeTicket($this->idHex($ticket),$ticket);
        return ['pairing_id'=>(string)$ticket['pairing_id'],'canceled'=>true,'idempotent'=>false];
    }

    public function cancelActive(array $actor): array
    {
        $this->assertAdmin($actor);$this->cleanup();
        foreach($this->ticketFiles() as $file){$ticket=$this->readTicketFile($file);if(!is_array($ticket)||!in_array((string)($ticket['state']??''),['issued','exchanged'],true))continue;$this->retireTicketAgent($ticket,'pairing_admin_canceled');$ticket['state']='canceled';$ticket['canceled_at']=gmdate('c');unset($ticket['bundle_nonce'],$ticket['bundle_ciphertext']);$this->writeTicket($this->idHex($ticket),$ticket);return $this->publicTicket($ticket);}
        return ['state'=>'not_created','active'=>false];
    }

    private function buildBundle(string $printToken): array
    {
        $localToken=$this->config->string('runtime.local_token','');if(strlen($localToken)<32)throw new WindowsServicesPairingException('runtime_local_token_missing','توکن Runtime در Local آماده نیست.',500);
        $healthPath=$this->secretsDir().DIRECTORY_SEPARATOR.'runtime-health.token';$runtimeToken=is_file($healthPath)?trim((string)@file_get_contents($healthPath)):'';
        if(strlen($runtimeToken)<32)throw new WindowsServicesPairingException('runtime_health_token_missing','توکن سلامت Runtime آماده نیست.',500);
        [$base,$origin]=$this->canonicalLoopback($this->config->string('runtime.local_base_url',''));
        return [
            'format'=>self::BUNDLE_FORMAT,'schema_version'=>1,'local_base_url'=>$base,'local_bridge_allowed_origin'=>$origin,
            'runtime_token'=>$runtimeToken,'local_token'=>$localToken,'print_agent_token'=>$printToken,
            'runtime_triggers'=>[
                ['key'=>'inventory.order_events','intervalSeconds'=>15],['key'=>'public.relay_sync','intervalSeconds'=>5],
                ['key'=>'public.projection_sync','intervalSeconds'=>30],['key'=>'notifications.outbox','intervalSeconds'=>15],['key'=>'maintenance.health','intervalSeconds'=>60],
            ],
        ];
    }

    private function ticketForCode(string $code,bool $requireBundle): array
    {
        $code=trim($code);if(!preg_match('/^ws1_([a-f0-9]{24})_([a-f0-9]{48})$/D',$code,$m))throw new WindowsServicesPairingException('pairing_code_invalid','کد اتصال معتبر نیست.',422);
        $file=$this->ticketDir().DIRECTORY_SEPARATOR.$m[1].'.json';$ticket=$this->readTicketFile($file);if(!is_array($ticket))throw new WindowsServicesPairingException('pairing_not_found','کد اتصال پیدا نشد.',404);
        $stored=(string)($ticket['code_hash']??'');if(strlen($stored)!==64||!hash_equals($stored,hash('sha256',$code)))throw new WindowsServicesPairingException('pairing_code_invalid','کد اتصال معتبر نیست.',403);
        if($this->isExpired($ticket)&&in_array((string)($ticket['state']??''),['issued','exchanged'],true)){$this->expireTicket($ticket);throw new WindowsServicesPairingException('pairing_expired','کد اتصال منقضی شده است. کد تازه‌ای بسازید.',410);}
        if($requireBundle&&(!isset($ticket['bundle_nonce'],$ticket['bundle_ciphertext'])))throw new WindowsServicesPairingException('pairing_bundle_unavailable','بسته اتصال دیگر در دسترس نیست.',410);
        return $ticket;
    }

    private function seal(array $bundle): array
    {
        if(!function_exists('sodium_crypto_secretbox'))throw new WindowsServicesPairingException('pairing_crypto_unavailable','رمزنگاری Pairing روی این نصب آماده نیست.',500);
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$plain=json_encode($bundle,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$cipher=sodium_crypto_secretbox($plain,$nonce,$this->key());
        return ['nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($cipher)];
    }

    private function openBundle(array $ticket): array
    {
        $nonce=base64_decode((string)($ticket['bundle_nonce']??''),true);$cipher=base64_decode((string)($ticket['bundle_ciphertext']??''),true);
        if(!is_string($nonce)||strlen($nonce)!==SODIUM_CRYPTO_SECRETBOX_NONCEBYTES||!is_string($cipher))throw new WindowsServicesPairingException('pairing_bundle_corrupt','بسته اتصال قابل بازیابی نیست.',500);
        $plain=sodium_crypto_secretbox_open($cipher,$nonce,$this->key());if(!is_string($plain))throw new WindowsServicesPairingException('pairing_bundle_corrupt','بسته اتصال قابل بازیابی نیست.',500);
        $bundle=json_decode($plain,true);if(!is_array($bundle)||($bundle['format']??'')!==self::BUNDLE_FORMAT)throw new WindowsServicesPairingException('pairing_bundle_corrupt','بسته اتصال معتبر نیست.',500);return $bundle;
    }

    private function key(): string
    {
        $this->ensureDir($this->secretsDir());$path=$this->secretsDir().DIRECTORY_SEPARATOR.'windows-services-pairing.key';$key=is_file($path)?@file_get_contents($path):false;
        if(is_string($key)&&strlen($key)===SODIUM_CRYPTO_SECRETBOX_KEYBYTES)return $key;
        $key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);if(file_put_contents($path,$key,LOCK_EX)===false)throw new WindowsServicesPairingException('pairing_storage_unavailable','محل نگهداری امن Pairing قابل نوشتن نیست.',500);@chmod($path,0600);return $key;
    }

    private function cleanup(): void
    {
        $now=time();foreach($this->ticketFiles() as $file){$ticket=$this->readTicketFile($file);if(!is_array($ticket))continue;$state=(string)($ticket['state']??'');if(in_array($state,['issued','exchanged'],true)&&$this->isExpired($ticket)){$this->expireTicket($ticket);continue;}$terminal=in_array($state,['confirmed','canceled','expired','superseded'],true);$stamp=strtotime((string)($ticket['confirmed_at']??$ticket['canceled_at']??$ticket['expires_at']??''));if($terminal&&$stamp!==false&&$now-$stamp>self::RETAIN_TERMINAL_SECONDS)@unlink($file);}
    }

    private function supersedeOutstanding(): void
    {
        foreach($this->ticketFiles() as $file){$ticket=$this->readTicketFile($file);if(!is_array($ticket)||!in_array((string)($ticket['state']??''),['issued','exchanged'],true))continue;$this->retireTicketAgent($ticket,'pairing_superseded');$ticket['state']='superseded';$ticket['canceled_at']=gmdate('c');unset($ticket['bundle_nonce'],$ticket['bundle_ciphertext']);$this->writeTicket($this->idHex($ticket),$ticket);}
    }

    private function expireTicket(array $ticket): void
    {
        $this->retireTicketAgent($ticket,'pairing_expired');$ticket['state']='expired';unset($ticket['bundle_nonce'],$ticket['bundle_ciphertext']);$this->writeTicket($this->idHex($ticket),$ticket);
    }

    private function retireTicketAgent(array $ticket,string $reason): void
    {
        $agentId=max(0,(int)($ticket['print_agent_id']??0));if($agentId<1)return;($this->retireAgent)($agentId,max(0,(int)($ticket['actor_user_id']??0)),$reason);
    }

    private function publicTicket(array $ticket): array
    {
        $expires=(string)($ticket['expires_at']??'');$ts=strtotime($expires);$state=(string)($ticket['state']??'not_created');
        return ['pairing_id'=>(string)($ticket['pairing_id']??''),'state'=>$state,'active'=>in_array($state,['issued','exchanged'],true)&&!$this->isExpired($ticket),'created_at'=>(string)($ticket['created_at']??''),'expires_at'=>$expires,'seconds_remaining'=>$ts===false?0:max(0,$ts-time()),'exchanged_at'=>$ticket['exchanged_at']??null,'confirmed_at'=>$ticket['confirmed_at']??null,'canceled_at'=>$ticket['canceled_at']??null,'display_name'=>(string)($ticket['display_name']??''),'local_base_url'=>(string)($ticket['local_base_url']??'')];
    }

    private function canonicalLoopback(string $value): array
    {
        $parts=parse_url(trim($value));if(!is_array($parts))throw new WindowsServicesPairingException('local_base_url_invalid','آدرس Local Web برای Windows Services معتبر نیست.',500);
        $scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));$port=isset($parts['port'])?(int)$parts['port']:($scheme==='https'?443:80);
        if(!in_array($scheme,['http','https'],true)||!in_array($host,['127.0.0.1','localhost','::1'],true)||$port<1024||$port>65535||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array((string)($parts['path']??''),['','/'],true))throw new WindowsServicesPairingException('local_base_url_invalid','آدرس Local Web باید یک origin محلی معتبر باشد.',500);
        $displayHost=$host==='::1'?'[::1]':$host;$origin=$scheme.'://'.$displayHost.':'.$port;return [$origin.'/',$origin];
    }

    private function assertLoopback(string $remote): void
    {
        $remote=trim($remote);if(!in_array($remote,['127.0.0.1','::1','::ffff:127.0.0.1'],true))throw new WindowsServicesPairingException('pairing_loopback_required','Pairing فقط از همین دستگاه مجاز است.',403);
    }

    private function assertAdmin(array $actor): void
    {
        if((int)($actor['id']??0)<1||(string)($actor['role']??'')!=='admin')throw new WindowsServicesPairingException('forbidden','فقط مدیر فعال مجاز است.',403);
    }

    private function nextCreatedMs(): int{$next=(int)floor(microtime(true)*1000);foreach($this->ticketFiles() as $file){$ticket=$this->readTicketFile($file);if(!is_array($ticket))continue;$seen=(int)($ticket['created_at_unix_ms']??0);if($seen>=$next)$next=$seen+1;}return $next;}
    private function ticketFiles(): array{$dir=$this->ticketDir();$files=glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[];return array_values(array_filter($files,'is_file'));}
    private function ticketDir(): string{$dir=rtrim($this->dataRoot,"\\/").DIRECTORY_SEPARATOR.'secrets'.DIRECTORY_SEPARATOR.'windows-services-pairing';$this->ensureDir($dir);return $dir;}
    private function secretsDir(): string{$dir=rtrim($this->dataRoot,"\\/").DIRECTORY_SEPARATOR.'secrets';$this->ensureDir($dir);return $dir;}
    private function ensureDir(string $dir): void{if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new WindowsServicesPairingException('pairing_storage_unavailable','محل نگهداری Pairing قابل ایجاد نیست.',500);@chmod($dir,0700);}
    private function writeTicket(string $idHex,array $ticket): void{$path=$this->ticketDir().DIRECTORY_SEPARATOR.$idHex.'.json';$json=json_encode($ticket,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);if(file_put_contents($path,$json."\n",LOCK_EX)===false)throw new WindowsServicesPairingException('pairing_storage_unavailable','ثبت وضعیت Pairing کامل نشد.',500);@chmod($path,0600);}
    private function readTicketFile(string $file): ?array{try{$raw=@file_get_contents($file);$v=is_string($raw)?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;return is_array($v)&&($v['format']??'')===self::FORMAT?$v:null;}catch(Throwable){return null;}}
    private function idHex(array $ticket): string{$id=(string)($ticket['pairing_id']??'');if(!preg_match('/^wsp-([a-f0-9]{24})$/D',$id,$m))throw new WindowsServicesPairingException('pairing_ticket_corrupt','وضعیت Pairing معتبر نیست.',500);return $m[1];}
    private function isExpired(array $ticket): bool{$ts=strtotime((string)($ticket['expires_at']??''));return $ts===false||$ts<time();}
    private function cut(string $v,int $n): string{return function_exists('mb_substr')?mb_substr($v,0,$n,'UTF-8'):substr($v,0,$n);}
}
