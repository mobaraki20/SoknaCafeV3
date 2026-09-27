<?php
declare(strict_types=1);
namespace Sokna\PublicEdge\Health;
use PDO;use Sokna\PublicEdge\Core\SafeErrors;use Throwable;
final class PublicHealthService
{
    public function __construct(private readonly PDO $pdo,private readonly string $componentRoot='',private readonly string $storageRoot=''){}
    public function status(?string $correlationId=null): array
    {
        try{$probe=$this->pdo->query('SELECT 1')->fetchColumn();if((int)$probe!==1)return SafeErrors::response(503,'public_unavailable',$correlationId);$count=(int)$this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();$version=$this->version();$update=$this->updateState();return ['status'=>200,'body'=>['ok'=>true,'component'=>'public-edge','database'=>'ready','applied_migrations'=>$count,'version'=>$version,'emergency_ready'=>$this->componentRoot!==''&&is_file($this->componentRoot.'/public/emergency.php'),'update'=>['active_version'=>(string)($update['active_version']??$version),'previous_version'=>(string)($update['previous_version']??''),'staged_version'=>(string)(($update['staged']??[])['version']??''),'lkg_ready'=>(string)($update['lkg_recovery_id']??'')!==''],'correlation_id'=>SafeErrors::correlationId($correlationId)]];}catch(Throwable){$safe=SafeErrors::response(503,'public_unavailable',$correlationId);$safe['body']['component']='public-edge';$safe['body']['database']='unavailable';$safe['body']['version']=$this->version();$safe['body']['emergency_ready']=$this->componentRoot!==''&&is_file($this->componentRoot.'/public/emergency.php');return $safe;}
    }
    private function version(): string{$p=$this->componentRoot!==''?$this->componentRoot.'/VERSION.txt':'';$v=$p!==''?@file_get_contents($p):false;return is_string($v)&&trim($v)!==''?trim($v):'unknown';}
    private function updateState(): array{$p=$this->storageRoot!==''?rtrim($this->storageRoot,"\\/").'/emergency/update/state.json':'';if($p===''||!is_file($p))return [];try{$v=json_decode((string)file_get_contents($p),true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(Throwable){return [];}}
}
