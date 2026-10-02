<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
use Sokna\Local\Core\Config;
final class PublicEdgeSyncClient
{
    /** @var null|callable */ private $transport;
    public function __construct(private readonly Config $config,?callable $transport=null){$this->transport=$transport;}
    public function configured(): bool{return $this->baseUrl()!=='' && $this->secret()!=='' && $this->installationId()!=='';}
    public function publicBaseUrl(): string{return $this->safePublicBase($this->config->string('public.base_url',''));}
    public function safeOrigin(): string{$u=$this->publicBaseUrl();if($u==='')return '';$p=parse_url($u);if(!is_array($p))return '';$scheme=strtolower((string)($p['scheme']??''));$host=(string)($p['host']??'');if(!in_array($scheme,['http','https'],true)||$host==='')return '';$displayHost=str_contains($host,':')?'['.$host.']':$host;$port=isset($p['port'])?':'.(int)$p['port']:'';return $scheme.'://'.$displayHost.$port;}
    public function post(string $path,array $payload): array
    {
        $response=$this->postRaw($path,$payload);
        $status=(int)$response['status'];$body=$response['body'];
        if($status<200||$status>=300||($body['ok']??false)!==true)throw new PublicEdgeSyncException((string)($body['error']??'public_sync_failed'),'همگام‌سازی Public Edge پذیرفته نشد.',$status?:502);
        return $response;
    }

    /** @return array{status:int,body:array} Signed POST that preserves non-2xx application responses for queue polling. */
    public function postRaw(string $path,array $payload): array
    {
        if(!$this->configured())throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز در config محلی pair نشده است.',409);
        if(!preg_match('#^/api/v1/local/[A-Za-z0-9/_-]+$#D',$path))throw new PublicEdgeSyncException('public_path_invalid','مسیر همگام‌سازی Public معتبر نیست.');
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$ts=(string)time();$nonce=bin2hex(random_bytes(16));$sig=hash_hmac('sha256',$this->signatureBase('POST',$path,$ts,$nonce,$body),$this->secret());
        $headers=['Content-Type'=>'application/json','Accept'=>'application/json','X-Sokna-Installation'=>$this->installationId(),'X-Sokna-Timestamp'=>$ts,'X-Sokna-Nonce'=>$nonce,'X-Sokna-Signature'=>$sig,'X-Correlation-ID'=>'g41-'.bin2hex(random_bytes(8))];
        if(is_callable($this->transport)){$r=($this->transport)($this->baseUrl().$path,'POST',$headers,$body);return $this->normalizeRawResponse($r);}
        $headerLines=[];foreach($headers as $k=>$v)$headerLines[]=$k.': '.$v;
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headerLines),'content'=>$body,'timeout'=>10,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $raw=@file_get_contents($this->baseUrl().$path,false,$ctx);$status=0;foreach((array)($http_response_header??[]) as $line)if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m)){$status=(int)$m[1];break;}
        if($raw===false&&$status===0)throw new PublicEdgeSyncException('public_unreachable','ارتباط با Public Edge برقرار نشد.',503);
        return $this->normalizeRawResponse(['status'=>$status,'body'=>(string)$raw]);
    }

    public function diagnostics(): array{return $this->post('/api/v1/local/diagnostics',[]);}
    public function health(): array
    {
        $base=$this->baseUrl();if($base==='')throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز پیکربندی نشده است.',409);
        if(is_callable($this->transport)){$r=($this->transport)($base.'/health','GET',['Accept'=>'application/json'],'');return $this->normalizeResponse($r);}
        $ctx=stream_context_create(['http'=>['method'=>'GET','header'=>"Accept: application/json\r\n",'timeout'=>5,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);$raw=@file_get_contents($base.'/health',false,$ctx);$status=0;foreach((array)($http_response_header??[]) as $line)if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m)){$status=(int)$m[1];break;}if($raw===false&&$status===0)throw new PublicEdgeSyncException('public_unreachable','ارتباط با Public Edge برقرار نشد.',503);return $this->normalizeResponse(['status'=>$status,'body'=>(string)$raw]);
    }
    public function initialPair(string $baseUrl,string $code,string $newSecret,string $displayName=''): array
    {
        $base=$this->normalizeBase($baseUrl);if($base==='')throw new PublicEdgeSyncException('public_url_invalid','نشانی Public معتبر نیست.',422);
        $payload=['installation_id'=>$this->installationId(),'pairing_code'=>$code,'shared_secret'=>$newSecret,'display_name'=>$displayName];
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(is_callable($this->transport)){$r=($this->transport)($base.'/setup-pair.php','POST',['Content-Type'=>'application/json'],$body);return $this->normalizeResponse($r);}
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",'content'=>$body,'timeout'=>10,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $raw=@file_get_contents($base.'/setup-pair.php',false,$ctx);$status=0;foreach((array)($http_response_header??[]) as $line)if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m)){$status=(int)$m[1];break;}
        if($raw===false&&$status===0)throw new PublicEdgeSyncException('public_unreachable','ارتباط با Public Edge برقرار نشد.',503);
        return $this->normalizeResponse(['status'=>$status,'body'=>(string)$raw]);
    }

    public function reenroll(string $baseUrl,string $code,string $newSecret,string $displayName=''): array
    {
        $base=$this->normalizeBase($baseUrl);if($base==='')throw new PublicEdgeSyncException('public_url_invalid','نشانی Public معتبر نیست.',422);$payload=['new_installation_id'=>$this->installationId(),'enrollment_code'=>$code,'new_shared_secret'=>$newSecret,'display_name'=>$displayName];$body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(is_callable($this->transport)){$r=($this->transport)($base.'/emergency.php?action=reenroll','POST',['Content-Type'=>'application/json'],$body);return $this->normalizeResponse($r);}
        $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\n",'content'=>$body,'timeout'=>10,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);$raw=@file_get_contents($base.'/emergency.php?action=reenroll',false,$ctx);$status=0;foreach((array)($http_response_header??[]) as $line)if(preg_match('#^HTTP/\S+\s+(\d{3})#',$line,$m)){$status=(int)$m[1];break;}if($raw===false&&$status===0)throw new PublicEdgeSyncException('public_unreachable','ارتباط با Public Edge برقرار نشد.',503);return $this->normalizeResponse(['status'=>$status,'body'=>(string)$raw]);
    }

    public function installationId(): string{return trim($this->config->string('installation.id',''));}
    private function baseUrl(): string{return $this->normalizeBase($this->config->string('public.base_url',''));}
    private function normalizeBase(string $u): string
    {
        $raw=trim($u);if($raw==='')return '';$p=parse_url($raw);if(!is_array($p)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment']))return '';
        $safe=$this->safePublicBase($raw);if($safe==='')return '';$scheme=strtolower((string)($p['scheme']??''));$host=strtolower((string)($p['host']??''));if($scheme==='https')return $safe;if($scheme==='http'&&in_array($host,['127.0.0.1','localhost','::1'],true))return $safe;return '';
    }
    private function safePublicBase(string $u): string
    {
        $p=parse_url(trim($u));if(!is_array($p))return '';$scheme=strtolower((string)($p['scheme']??''));$host=strtolower((string)($p['host']??''));if(!in_array($scheme,['http','https'],true)||$host==='')return '';
        $displayHost=str_contains($host,':')?'['.$host.']':$host;$port=isset($p['port'])?':'.(int)$p['port']:'';$path=(string)($p['path']??'');$path=$path===''||$path==='/'?'':'/'.trim($path,'/');
        return $scheme.'://'.$displayHost.$port.$path;
    }
    private function secret(): string{return trim($this->config->string('public.shared_secret',''));}
    private function signatureBase(string $method,string $path,string $timestamp,string $nonce,string $body): string{return implode("\n",['sokna-relay-v1',strtoupper($method),'/'.ltrim($path,'/'),$timestamp,$nonce,hash('sha256',$body)]);}
    private function normalizeResponse(array $r): array
    {
        $response=$this->normalizeRawResponse($r);$status=(int)$response['status'];$body=$response['body'];
        if($status<200||$status>=300||($body['ok']??false)!==true)throw new PublicEdgeSyncException((string)($body['error']??'public_sync_failed'),'همگام‌سازی Public Edge پذیرفته نشد.',$status?:502);
        return $response;
    }
    private function normalizeRawResponse(array $r): array
    {
        $status=(int)($r['status']??0);$body=$r['body']??[];if(is_string($body)){$d=json_decode($body,true);$body=is_array($d)?$d:[];}if(!is_array($body))$body=[];
        return ['status'=>$status,'body'=>$body];
    }
}
