<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Integrations;

use Sokna\Local\Core\Config;

final class AccommodationTransport
{
    public function __construct(private readonly Config $config,private readonly ?\Closure $override=null) {}

    public function enabled(): bool{return (bool)$this->config->get('integrations.accommodation.enabled',false);}

    public function charge(array $payload): array{return $this->request('charge','POST',$payload,10);}
    public function void(array $payload): array{return $this->request('void','POST',$payload,10);}
    public function search(string $query): array{return $this->request('search','GET',['query'=>$query],7);}

    private function request(string $action,string $method,array $parameters,int $timeout): array
    {
        if($this->override!==null)return ($this->override)($action,$method,$parameters,$timeout);
        if(!$this->enabled())return ['success'=>false,'code'=>'integration_disabled','ambiguous'=>false,'retryable'=>false,'message'=>'اتصال اقامتگاه غیرفعال است.'];
        $base=rtrim($this->config->string('integrations.accommodation.base_url',''),'/');
        $apiKey=$this->config->string('integrations.accommodation.api_key','');
        if($base===''||$apiKey==='')throw new IntegrationException('accommodation_not_configured','تنظیمات اتصال اقامتگاه کامل نیست.',409);
        $parts=parse_url($base);$host=strtolower((string)($parts['host']??''));$scheme=strtolower((string)($parts['scheme']??''));
        if($scheme!=='https'&&!in_array($host,['localhost','127.0.0.1','::1'],true))throw new IntegrationException('insecure_endpoint','ارتباط اقامتگاه باید HTTPS باشد.',409);
        $url=preg_match('#/api\.php$#i',$base)?$base.'?action='.rawurlencode($action):$base.'/api.php?action='.rawurlencode($action);
        if($method==='GET'&&$parameters)$url.='&'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986);
        $headers="Accept: application/json\r\nAuthorization: Bearer {$apiKey}\r\n";
        $options=['http'=>['method'=>$method,'timeout'=>max(2,min(15,$timeout)),'ignore_errors'=>true,'header'=>$headers]];
        if($method!=='GET'){$body=json_encode($parameters,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$options['http']['header'].="Content-Type: application/json\r\n";$options['http']['content']=$body;}
        $ctx=stream_context_create($options);$raw=@file_get_contents($url,false,$ctx);$meta=$http_response_header??[];$status=0;
        foreach($meta as $h)if(preg_match('#^HTTP/\S+\s+(\d{3})#i',$h,$m)){$status=(int)$m[1];break;}
        if($raw===false||$status===0)return ['success'=>false,'code'=>'transport_error','ambiguous'=>true,'retryable'=>true,'message'=>'پاسخ قطعی از اقامتگاه دریافت نشد.'];
        $data=json_decode($raw,true);if(!is_array($data))return ['success'=>false,'code'=>'invalid_response','ambiguous'=>$status>=500,'retryable'=>$status>=500,'message'=>'پاسخ اقامتگاه معتبر نیست.'];
        $ok=$status>=200&&$status<300&&($data['success']??$data['ok']??false)===true;
        if($ok)return ['success'=>true,'idempotent'=>(bool)($data['idempotent']??false),'transaction_id'=>(string)($data['transaction_id']??$data['data']['transaction_id']??''),'original_transaction_id'=>(string)($data['original_transaction_id']??$data['data']['original_transaction_id']??''),'tracking_id'=>(string)($data['tracking_id']??''),'payload'=>$data];
        $code=strtolower(trim((string)($data['error']??$data['code']??'')))?:($status>=500?'remote_error':'rejected');
        $ambiguous=$status>=500||in_array($code,['timeout','transport_error','unknown_result','internal_error'],true);
        return ['success'=>false,'code'=>$code,'ambiguous'=>$ambiguous,'retryable'=>$ambiguous||$status===429,'message'=>(string)($data['message']??'عملیات اقامتگاه انجام نشد.'),'tracking_id'=>(string)($data['tracking_id']??'')];
    }
}
