<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class InstallationProjectionHttpAdapter
{
    public function __construct(private readonly Bootstrap $core) {}

    public function sync(string $installationId,string $method,string $path,string $timestamp,string $nonce,string $signature,string $rawBody): array
    {
        $v=$this->core->signedLocalRequests()->verify($installationId,$method,$path,$timestamp,$nonce,$rawBody,$signature);
        if(($v['ok']??false)!==true)return ['status'=>(int)$v['status'],'body'=>['ok'=>false,'error'=>(string)$v['error']]];
        $body=json_decode($rawBody,true);
        if(!is_array($body))return ['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_json']];
        return $this->core->installationProjection()->sync((string)$v['installation_id'],$body);
    }
}
