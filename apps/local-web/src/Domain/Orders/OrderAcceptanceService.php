<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use Throwable;
use Sokna\Local\Core\IdentityRepository;
use Sokna\Local\Core\Capabilities;

final class OrderAcceptanceService
{
    private const KEYS=[
        'cafe'=>'orders_accepting.cafe',
        'kitchen'=>'orders_accepting.kitchen',
        'bar'=>'orders_accepting.bar',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly IdentityRepository $identity,
        private readonly Capabilities $capabilities,
    ){}

    /** @return array{cafe:bool,kitchen:bool,bar:bool} */
    public function snapshot(): array
    {
        $rows=$this->pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('orders_accepting.cafe','orders_accepting.kitchen','orders_accepting.bar')")->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'cafe'=>$this->toBool((string)($rows[self::KEYS['cafe']]??'1')),
            'kitchen'=>$this->toBool((string)($rows[self::KEYS['kitchen']]??'1')),
            'bar'=>$this->toBool((string)($rows[self::KEYS['bar']]??'1')),
        ];
    }

    /** @return array{saved:bool,acceptance:array{cafe:bool,kitchen:bool,bar:bool},changed:list<string>} */
    public function update(array $data,array $actor): array
    {
        $fresh=$this->assertSupervisor($actor);
        $next=[];
        foreach(self::KEYS as $scope=>$key){
            if(!array_key_exists($scope,$data))throw new OrderAcceptanceException('acceptance_scope_missing','وضعیت هر سه بخش کافه، آشپزخانه و بار باید مشخص باشد.',422,['scope'=>$scope]);
            $next[$scope]=$this->boolInput($data[$scope],$scope);
        }
        $this->pdo->beginTransaction();
        try{
            $current=$this->snapshotForUpdate();$changed=[];
            $up=$this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
            foreach(self::KEYS as $scope=>$key){
                if($current[$scope]!==$next[$scope]){
                    $up->execute([$key,$next[$scope]?'1':'0']);
                    $changed[]=$scope;
                }
            }
            if($changed!==[]){
                $details=['before'=>$current,'after'=>$next,'changed'=>$changed];
                $this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')
                    ->execute([(int)$fresh['id'],(string)($fresh['display_name']??$fresh['username']??''),'orders.acceptance_updated','setting','orders_accepting',json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            }
            $this->pdo->commit();
            return ['saved'=>true,'acceptance'=>$next,'changed'=>$changed];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function assertSupervisor(array $actor): array
    {
        $id=(int)($actor['id']??0);$fresh=$id>0?$this->identity->findActiveById($id):null;
        if($fresh===null||!$this->capabilities->has('shift_supervision',$fresh))
            throw new OrderAcceptanceException('forbidden','تغییر پذیرش سفارش آنلاین فقط برای سرپرست شیفت یا مدیر فعال است.',403);
        return $fresh;
    }

    /** @return array{cafe:bool,kitchen:bool,bar:bool} */
    private function snapshotForUpdate(): array
    {
        $rows=[];$q=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1 FOR UPDATE');
        foreach(self::KEYS as $scope=>$key){$q->execute([$key]);$v=$q->fetchColumn();$rows[$scope]=$v===false||$v===null?true:$this->toBool((string)$v);}
        return $rows;
    }

    private function boolInput(mixed $value,string $scope): bool
    {
        if(is_bool($value))return $value;
        if(is_int($value)&&($value===0||$value===1))return $value===1;
        if(is_string($value)){
            $v=strtolower(trim($value));
            if(in_array($v,['1','true','yes','on'],true))return true;
            if(in_array($v,['0','false','no','off'],true))return false;
        }
        throw new OrderAcceptanceException('acceptance_value_invalid','وضعیت پذیرش سفارش معتبر نیست.',422,['scope'=>$scope]);
    }
    private function toBool(string $value): bool{return in_array(strtolower(trim($value)),['1','true','yes','on'],true);}
}
