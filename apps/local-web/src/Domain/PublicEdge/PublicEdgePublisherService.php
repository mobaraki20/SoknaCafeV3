<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
use PDO;
use Sokna\Local\Core\Observability;
use Sokna\Local\Runtime\RuntimeEvidence;
use Throwable;
final class PublicEdgePublisherService
{
    public function __construct(private readonly PDO $pdo,private readonly PublicEdgeSyncClient $client,private readonly PublicProjectionBuilder $builder,private readonly Observability $obs,private readonly string $localVersion='unknown'){}
    public function snapshot(): array
    {
        $rows=$this->pdo->query('SELECT channel,source_version,status,last_http_status,last_attempt_at,last_success_at FROM public_sync_state ORDER BY channel')->fetchAll(PDO::FETCH_ASSOC);return ['configured'=>$this->client->configured(),'origin'=>$this->client->safeOrigin(),'installation_id'=>$this->client->installationId(),'channels'=>$rows?:[]];
    }
    public function syncAll(): array
    {
        if(!$this->client->configured()){
            $this->record('all','disabled',null,null,['reason'=>'not_configured']);
            throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز pair نشده است.',409);
        }
        $installationId=$this->client->installationId();
        $results=[];
        $guestPublish=$this->builder->guestPublish();
        $mediaPayloads=$this->builder->guestMediaPayloads((array)($guestPublish['media_manifest']??[]));
        if($mediaPayloads!==[]){
            $mediaVersion=PublicProjectionBuilder::hash(array_map(static fn(array $m): string => (string)($m['sha256']??''),$mediaPayloads));
            try{
                $uploaded=0;
                foreach($mediaPayloads as $payload){$this->client->post('/api/v1/local/guest/media',$payload);$uploaded++;}
                $this->record('guest_media','ok',$mediaVersion,200,['uploaded'=>$uploaded]);
                $results['guest_media']=['ok'=>true,'uploaded'=>$uploaded];
            }catch(Throwable $e){
                $status=$e instanceof PublicEdgeSyncException?$e->httpStatus:500;
                $this->record('guest_media','error',$mediaVersion,$status,['code'=>$e instanceof PublicEdgeSyncException?$e->errorCode:'exception']);
                $this->obs->logEvent('error','public.sync_failed',['channel'=>'guest_media','status'=>$status,'code'=>$e instanceof PublicEdgeSyncException?$e->errorCode:'exception']);
                throw $e;
            }
        }
        $runtime=RuntimeEvidence::snapshot($this->pdo);
        $jobs=[
            'installation'=>['/api/v1/local/installation',$this->builder->installation($installationId)],
            'auth'=>['/api/v1/local/auth-projections',['projections'=>$this->builder->authProjections()]],
            'guest_publish'=>['/api/v1/local/guest/publish',$guestPublish],
            'availability'=>['/api/v1/local/guest/availability',$this->builder->availability()],
            'read_models'=>['/api/v1/local/read-models',['models'=>$this->builder->remoteModels()]],
            'heartbeat'=>['/api/v1/local/heartbeat',[
                'local_version'=>$this->localVersion,
                'runtime_status'=>(string)($runtime['status']??'unavailable'),
                'telemetry'=>[
                    'source'=>'local-web',
                    'synced_at'=>gmdate('c'),
                    'runtime_last_seen_at'=>(string)($runtime['last_seen_at']??''),
                    'runtime_age_seconds'=>$runtime['age_seconds']??null,
                    'runtime_recent_failed_count'=>(int)($runtime['recent_failed_count']??0),
                    'runtime_unresolved_failure'=>(bool)($runtime['unresolved_failure']??false),
                ],
            ]],
        ];
        foreach($jobs as $channel=>[$path,$payload]){
            $version=PublicProjectionBuilder::hash($payload);
            try{
                $r=$this->client->post($path,$payload);
                $this->record($channel,'ok',$version,(int)$r['status'],$r['body']);
                $results[$channel]=$r['body'];
            }catch(Throwable $e){
                $status=$e instanceof PublicEdgeSyncException?$e->httpStatus:500;
                $this->record($channel,'error',$version,$status,['code'=>$e instanceof PublicEdgeSyncException?$e->errorCode:'exception']);
                $this->obs->logEvent('error','public.sync_failed',['channel'=>$channel,'status'=>$status,'code'=>$e instanceof PublicEdgeSyncException?$e->errorCode:'exception']);
                throw $e;
            }
        }
        $this->obs->logEvent('info','public.sync_completed',['channels'=>array_keys($results),'origin'=>$this->client->safeOrigin()]);
        return ['success'=>true,'synced_at'=>gmdate('c'),'channels'=>$results,'state'=>$this->snapshot()];
    }

    public function rotateEmergencyCode(): array
    {
        $plain=strtoupper(implode('-',str_split(bin2hex(random_bytes(12)),8)));$hash=password_hash($plain,PASSWORD_DEFAULT);$r=$this->client->post('/api/v1/local/emergency/access',['password_hash'=>$hash]);$this->record('emergency_access','ok',hash('sha256',$hash),(int)$r['status'],['rotated'=>true]);$this->obs->logEvent('warning','public.emergency_access_rotated',['origin'=>$this->client->safeOrigin()]);return ['emergency_code'=>$plain,'emergency_url'=>$this->client->publicBaseUrl().'/emergency.php'];
    }
    public function updateStatus(): array{return $this->client->post('/api/v1/local/update/status',[])['body'];}
    public function stageUpdate(string $zipPath): array
    {
        if(!is_file($zipPath))throw new PublicEdgeSyncException('public_package_missing','بسته Public پیدا نشد.',404);$size=(int)filesize($zipPath);if($size<=0||$size>67108864)throw new PublicEdgeSyncException('public_package_too_large','حجم بسته Public بیش از حد مجاز است.',413);$bytes=file_get_contents($zipPath);if(!is_string($bytes))throw new PublicEdgeSyncException('public_package_read_failed','خواندن بسته Public انجام نشد.',500);$r=$this->client->post('/api/v1/local/update/stage',['package_base64'=>base64_encode($bytes),'sha256'=>hash('sha256',$bytes)]);$this->obs->logEvent('warning','public.update_staged',['size_bytes'=>$size]);return $r['body'];
    }
    public function activateUpdate(): array{return $this->client->post('/api/v1/local/update/activate',[])['body'];}
    public function repairUpdate(): array{return $this->client->post('/api/v1/local/update/repair',[])['body'];}
    public function rollbackUpdate(): array{return $this->client->post('/api/v1/local/update/rollback',[])['body'];}

    private function record(string $channel,string $status,?string $version,?int $http,array $detail): void{$q=$this->pdo->prepare('INSERT INTO public_sync_state(channel,source_version,status,last_http_status,detail_json,last_attempt_at,last_success_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),CASE WHEN ?=\'ok\' THEN UTC_TIMESTAMP() ELSE NULL END) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),status=VALUES(status),last_http_status=VALUES(last_http_status),detail_json=VALUES(detail_json),last_attempt_at=UTC_TIMESTAMP(),last_success_at=CASE WHEN VALUES(status)=\'ok\' THEN UTC_TIMESTAMP() ELSE last_success_at END');$q->execute([$channel,$version,$status,$http,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status]);}
}
