<?php
declare(strict_types=1);
namespace Sokna\Local\Http;
use Sokna\Local\Runtime\RuntimeTriggerException;
use Sokna\Local\Runtime\RuntimeTriggerService;
final class RuntimeTriggerHttpAdapter
{
    public function __construct(private readonly RuntimeTriggerService $service,private readonly string $token) {}
    public function handle(array $headers,string $body): array
    {
        $authorization=trim((string)($headers['Authorization']??$headers['authorization']??''));$contract=trim((string)($headers['X-Sokna-Runtime-Contract']??$headers['x-sokna-runtime-contract']??''));
        if($this->token===''||!hash_equals('Bearer '.$this->token,$authorization))return ['status'=>401,'body'=>['success'=>false,'code'=>'unauthorized']];
        if($contract!=='1')return ['status'=>426,'body'=>['success'=>false,'code'=>'runtime_contract_upgrade_required']];
        $data=json_decode($body,true);if(!is_array($data))return ['status'=>400,'body'=>['success'=>false,'code'=>'invalid_request']];
        try{return ['status'=>200,'body'=>$this->service->accept($data)];}
        catch(RuntimeTriggerException $e){return ['status'=>$e->httpStatus,'body'=>['success'=>false,'code'=>$e->errorCode]];}
    }
}
