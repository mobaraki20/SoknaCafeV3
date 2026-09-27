<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Http;
use Sokna\PublicEdge\Core\Bootstrap;
use Sokna\PublicEdge\Emergency\PublicUpdateException;
final class EmergencyLocalHttpAdapter
{
    public function __construct(private readonly Bootstrap $core){}
    public function handle(string $installationId,string $method,string $path,string $timestamp,string $nonce,string $signature,string $rawBody): array
    {
        $v=$this->core->signedLocalRequests()->verify($installationId,$method,$path,$timestamp,$nonce,$rawBody,$signature);if(($v['ok']??false)!==true)return ['status'=>(int)$v['status'],'body'=>['ok'=>false,'error'=>(string)$v['error']]];
        $body=json_decode($rawBody,true);if(!is_array($body))return ['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_json']];
        try{
            if($path==='/api/v1/local/emergency/access'){$r=$this->core->emergencyAccess()->provisionHash((string)($body['password_hash']??''),'local:'.$installationId);return ['status'=>200,'body'=>['ok'=>true]+$r];}
            if($path==='/api/v1/local/diagnostics')return ['status'=>200,'body'=>['ok'=>true,'health'=>$this->core->health()->status()['body']??[],'update'=>$this->core->publicUpdates()->snapshot(),'recent_logs'=>$this->core->emergencyAccess()->recentAudit(40)]];
            $u=$this->core->publicUpdates();$actor='local:'.$installationId;
            if($path==='/api/v1/local/update/status')return ['status'=>200,'body'=>['ok'=>true,'update'=>$u->snapshot()]];
            if($path==='/api/v1/local/update/stage'){$b64=(string)($body['package_base64']??'');if(strlen($b64)>100663296)return ['status'=>413,'body'=>['ok'=>false,'error'=>'package_too_large']];$bytes=base64_decode($b64,true);if(!is_string($bytes)||$bytes==='')return ['status'=>422,'body'=>['ok'=>false,'error'=>'invalid_package']];$want=strtolower((string)($body['sha256']??''));if(!preg_match('/^[a-f0-9]{64}$/',$want)||!hash_equals($want,hash('sha256',$bytes)))return ['status'=>422,'body'=>['ok'=>false,'error'=>'package_hash_mismatch']];$tmp=$u->incomingDir().'/local-'.bin2hex(random_bytes(8)).'.zip';file_put_contents($tmp,$bytes,LOCK_EX);try{$staged=$u->stageUploadedZip($tmp,$actor);}finally{@unlink($tmp);}return ['status'=>200,'body'=>['ok'=>true,'staged'=>$staged]];}
            if($path==='/api/v1/local/update/activate')return ['status'=>200,'body'=>['ok'=>true,'update'=>$u->activateStaged($actor)]];
            if($path==='/api/v1/local/update/repair')return ['status'=>200,'body'=>['ok'=>true,'update'=>$u->repairStaged($actor)]];
            if($path==='/api/v1/local/update/rollback')return ['status'=>200,'body'=>['ok'=>true,'update'=>$u->rollback($actor)]];
            return ['status'=>404,'body'=>['ok'=>false,'error'=>'route_not_found']];
        }catch(PublicUpdateException $e){return ['status'=>$e->httpStatus,'body'=>['ok'=>false,'error'=>$e->errorCode]];}
    }
}
