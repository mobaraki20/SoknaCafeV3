<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Http;

use Sokna\PublicEdge\Core\Bootstrap;

final class RemoteReadModelHttpAdapter
{
    public function __construct(private readonly Bootstrap $core)
    {
    }

    public function sync(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawBody,
    ): array {
        $verification = $this->core->signedLocalRequests()->verify(
            $installationId,
            $method,
            $path,
            $timestamp,
            $nonce,
            $rawBody,
            $signature,
        );
        if (($verification['ok'] ?? false) !== true) {
            return [
                'status' => (int)$verification['status'],
                'body' => ['ok' => false, 'error' => (string)$verification['error']],
            ];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
        }

        return $this->core->remoteReadModels()->sync(
            (string)$verification['installation_id'],
            $payload,
        );
    }


    public function syncModelV2(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawBody,
    ): array {
        $started=microtime(true);
        $verification=$this->core->signedLocalRequests()->verify($installationId,$method,$path,$timestamp,$nonce,$rawBody,$signature);
        if(($verification['ok']??false)!==true)return ['status'=>(int)$verification['status'],'body'=>['ok'=>false,'error'=>(string)$verification['error']]];
        $verifiedAt=microtime(true);
        $payload=json_decode($rawBody,true);if(!is_array($payload))return ['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_json']];
        $decodedAt=microtime(true);
        $result=$this->core->remoteReadModels()->syncModelV2((string)$verification['installation_id'],$payload);
        $completedAt=microtime(true);
        $timing=[
            'verify_ms'=>(int)round(($verifiedAt-$started)*1000),
            'decode_ms'=>(int)round(($decodedAt-$verifiedAt)*1000),
            'model_store_ms'=>(int)round(($completedAt-$decodedAt)*1000),
        ];
        // Timing is returned only from the authenticated Local-to-Public endpoint.
        // Do not log content, installation identifiers, signatures or financial data.
        if(is_array($result['body']??null)){
            $storage=$result['body']['storage_timing']??[];
            unset($result['body']['storage_timing']);
            if(is_array($storage))foreach($storage as $phase=>$ms){
                if(in_array($phase,['model_preflight_ms','cursor_insert_ms','cursor_lock_ms','model_read_ms','model_apply_ms','cursor_update_ms','commit_ms','rollback_ms'],true)&&is_int($ms)){
                    $timing[$phase]=$ms;
                }
            }
            $result['body']['sync_timing']=$timing;
            // Explicit receiver capability: older senders ignore the additive field.
            if(function_exists('gzdecode'))$result['body']['model_encodings']=['gzip-base64-v1'];
        }
        if(($completedAt-$started)>=1.0){
            $key=(string)($payload['model']['model_key']??'');
            if(!preg_match('/^[a-z_]{1,48}$/D',$key))$key='invalid';
            error_log('SOKNA_READ_MODEL_V2_SLOW '.json_encode([
                'model'=>$key,'request_bytes'=>strlen($rawBody),
                'status'=>(int)($result['status']??0),
                'total_ms'=>(int)round(($completedAt-$started)*1000),
                'verify_ms'=>$timing['verify_ms'],'decode_ms'=>$timing['decode_ms'],
                'model_store_ms'=>$timing['model_store_ms'],
                'model_preflight_ms'=>$timing['model_preflight_ms']??null,
                // Cursor upsert may wait for an exclusive lock before SELECT FOR UPDATE.
                // Without this number a slow INSERT can be mistaken for network delay.
                'cursor_insert_ms'=>$timing['cursor_insert_ms']??null,
                'cursor_lock_ms'=>$timing['cursor_lock_ms']??null,
                'model_apply_ms'=>$timing['model_apply_ms']??null,
                'commit_ms'=>$timing['commit_ms']??null,
            ],JSON_UNESCAPED_SLASHES));
        }
        return $result;
    }

    public function syncEvents(
        string $installationId,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawBody,
    ): array {
        $verification=$this->core->signedLocalRequests()->verify($installationId,$method,$path,$timestamp,$nonce,$rawBody,$signature);
        if(($verification['ok']??false)!==true)return ['status'=>(int)$verification['status'],'body'=>['ok'=>false,'error'=>(string)$verification['error']]];
        $payload=json_decode($rawBody,true);if(!is_array($payload))return ['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_json']];
        return $this->core->remoteReadModels()->syncEventBatch((string)$verification['installation_id'],$payload);
    }

    public function watch(string $sessionToken,array $modelKeys,string $cursor,int $waitMs): array
    {
        $session=$this->core->publicSessions()->resolve($sessionToken);
        if(!is_array($session))return ['status'=>401,'body'=>['ok'=>false,'error'=>'unauthorized']];
        return $this->core->remoteReadModels()->watch($session,$modelKeys,$cursor,$waitMs);
    }

    public function read(string $sessionToken, string $modelKey): array
    {
        $session = $this->core->publicSessions()->resolve($sessionToken);
        if (!is_array($session)) {
            return ['status' => 401, 'body' => ['ok' => false, 'error' => 'unauthorized']];
        }
        return $this->core->remoteReadModels()->read($session, $modelKey);
    }
}
