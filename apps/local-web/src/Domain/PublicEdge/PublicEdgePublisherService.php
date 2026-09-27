<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\PublicEdge;
use PDO;
use Sokna\Local\Core\Observability;
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
        if(!$this->client->configured()){$this->record('all','disabled',null,null,['reason'=>'not_configured']);throw new PublicEdgeSyncException('public_not_configured','Public Edge هنوز pair نشده است.',409);}
        $installationId=$this->client->installationId();$results=[];
        $jobs=[
            'installation'=>['/api/v1/local/installation',$this->builder->installation($installationId)],
            'auth'=>['/api/v1/local/auth-projections',['projections'=>$this->builder->authProjections()]],
            'guest_publish'=>['/api/v1/local/guest/publish',$this->builder->guestPublish()],
            'availability'=>['/api/v1/local/guest/availability',$this->builder->availability()],
            'read_models'=>['/api/v1/local/read-models',['models'=>$this->builder->remoteModels()]],
            'heartbeat'=>['/api/v1/local/heartbeat',['local_version'=>$this->localVersion,'runtime_status'=>'healthy','telemetry'=>['source'=>'local-web','synced_at'=>gmdate('c')]]],
        ];
        foreach($jobs as $channel=>[$path,$payload]){$version=PublicProjectionBuilder::hash($payload);try{$r=$this->client->post($path,$payload);$this->record($channel,'ok',$version,(int)$r['status'],$r['body']);$results[$channel]=$r['body'];}catch(Throwable $e){$status=$e instanceof PublicEdgeSyncException?$e->httpStatus:500;$this->record($channel,'error',$version,$status,['code'=>$e instanceof PublicEdgeSyncException?$e->errorCode:'exception']);$this->obs->logEvent('error','public.sync_failed',['channel'=>$channel,'status'=>$status,'code'=>$e instanceof PublicEdgeSyncException?$e->errorCode:'exception']);throw $e;}}
        $this->obs->logEvent('info','public.sync_completed',['channels'=>array_keys($results),'origin'=>$this->client->safeOrigin()]);return ['success'=>true,'synced_at'=>gmdate('c'),'channels'=>$results,'state'=>$this->snapshot()];
    }
    private function record(string $channel,string $status,?string $version,?int $http,array $detail): void{$q=$this->pdo->prepare('INSERT INTO public_sync_state(channel,source_version,status,last_http_status,detail_json,last_attempt_at,last_success_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),CASE WHEN ?=\'ok\' THEN UTC_TIMESTAMP() ELSE NULL END) ON DUPLICATE KEY UPDATE source_version=VALUES(source_version),status=VALUES(status),last_http_status=VALUES(last_http_status),detail_json=VALUES(detail_json),last_attempt_at=UTC_TIMESTAMP(),last_success_at=CASE WHEN VALUES(status)=\'ok\' THEN UTC_TIMESTAMP() ELSE last_success_at END');$q->execute([$channel,$version,$status,$http,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$status]);}
}
