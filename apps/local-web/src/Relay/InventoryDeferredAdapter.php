<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use PDO;
use Sokna\Local\Domain\Inventory\InventoryCountService;
use Sokna\Local\Domain\Inventory\InventoryException;
use Sokna\Local\Domain\Inventory\InventoryService;
use Sokna\Local\Domain\Inventory\InventoryStateConflict;
use Throwable;

final class InventoryDeferredAdapter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly InventoryService $inventory,
        private readonly InventoryCountService $counts,
    ) {}

    /** @return array{state:string,result:array,error_code:string,idempotent:bool} */
    public function dispatch(array $envelope): array
    {
        $kind=trim((string)($envelope['kind']??''));
        if(!in_array($kind,['inventory.waste','inventory.count_draft'],true))
            return $this->rejected('unsupported_kind');

        $requestId=trim((string)($envelope['request_id']??''));
        if($requestId===''||strlen($requestId)>96||preg_match('/^[A-Za-z0-9._:-]+$/',$requestId)!==1)
            return $this->rejected('invalid_request_id');

        $projection=trim((string)($envelope['actor_projection_id']??''));
        if(!preg_match('/^user:(\d+)$/',$projection,$match))
            return $this->rejected('actor_invalid');

        $payload=is_array($envelope['payload']??null)?$envelope['payload']:[];
        $occurredAt=trim((string)($envelope['occurred_at']??''));

        $this->pdo->beginTransaction();
        try{
            $actorStmt=$this->pdo->prepare('SELECT id,username,display_name,role,active FROM users WHERE id=? FOR UPDATE');
            $actorStmt->execute([(int)$match[1]]);
            $actor=$actorStmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($actor)||(int)$actor['active']!==1){
                $this->pdo->rollBack();
                return $this->rejected('actor_invalid');
            }
            try{
                $actor=$this->inventory->assertActor($actor,'inventory_operations');
            }catch(InventoryException){
                $this->pdo->rollBack();
                return $this->rejected('permission_denied');
            }

            if($kind==='inventory.waste'){
                $itemId=(int)($payload['inventory_item_id']??0);
                $item=$this->inventory->itemTx($itemId);
                if($item===null||(int)$item['active']!==1){
                    $this->pdo->rollBack();
                    return $this->rejected('invalid_inventory_item');
                }
                $balance=$this->inventory->balanceTx($itemId);
                $expected=trim((string)($envelope['expected_version']??($payload['expected_balance_version']??'')));
                if($expected!==''&&!hash_equals((string)$balance['updated_at'],$expected)){
                    $this->pdo->commit();
                    return $this->review('inventory_balance_changed',[
                        'current_version'=>(string)$balance['updated_at'],
                        'current_quantity_base'=>(int)$balance['quantity_base'],
                    ]);
                }
                $qty=InventoryService::majorToBase($payload['quantity_major']??'',(string)$item['base_unit']);
                if($qty<1){
                    $this->pdo->rollBack();
                    return $this->rejected('invalid_quantity');
                }
                $movement=$this->inventory->recordMovementTx([
                    'item_id'=>$itemId,'movement_type'=>'waste','quantity_base'=>-$qty,
                    'department'=>InventoryService::normalizeDepartment((string)($payload['department']??$item['default_department']))??'shared',
                    'source_type'=>'deferred_waste','source_id'=>$requestId,
                    'idempotency_key'=>'deferred:waste:'.$requestId,
                    'metadata'=>['origin'=>'public_deferred'],
                    'note'=>self::truncate(trim((string)($payload['note']??'')),500)?:null,
                    'actor_user_id'=>(int)$actor['id'],
                    'occurred_at'=>$occurredAt,
                ]);
                $this->pdo->commit();
                return ['state'=>'committed','result'=>['movement_id'=>$movement,'quantity_base'=>$qty],'error_code'=>'','idempotent'=>false];
            }

            $sessionId=(int)($payload['session_id']??0);
            $lineId=(int)($payload['line_id']??0);
            $sessionStmt=$this->pdo->prepare('SELECT session_type,status FROM inventory_count_sessions WHERE id=? FOR UPDATE');
            $sessionStmt->execute([$sessionId]);$session=$sessionStmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($session)||(string)$session['status']!=='draft'){
                $this->pdo->commit();
                return $this->review('count_not_draft');
            }
            if((string)$session['session_type']==='opening'){
                $this->pdo->rollBack();
                return $this->rejected('opening_local_only');
            }

            $data=[
                'session_id'=>$sessionId,
                'line_id'=>$lineId,
                'actual_major'=>array_key_exists('actual_major',$payload)?(string)$payload['actual_major']:null,
                'note'=>(string)($payload['note']??''),
                'expected_version'=>(string)($envelope['expected_version']??''),
            ];
            try{
                $result=$this->counts->updateLineTx($data,(int)$actor['id'],true);
                $this->pdo->commit();
                return ['state'=>'committed','result'=>$result,'error_code'=>'','idempotent'=>false];
            }catch(InventoryStateConflict $e){
                $line=$this->pdo->prepare(
                    'SELECT l.actual_quantity,l.note,l.updated_at,i.base_unit FROM inventory_count_lines l JOIN inventory_items i ON i.id=l.inventory_item_id WHERE l.id=? AND l.session_id=?'
                );
                $line->execute([$lineId,$sessionId]);$current=$line->fetch(PDO::FETCH_ASSOC);
                if(is_array($current)){
                    $raw=trim((string)($payload['actual_major']??''));
                    $desired=$raw===''?null:InventoryService::majorToBase($raw,(string)$current['base_unit']);
                    $note=self::truncate(trim((string)($payload['note']??'')),500);
                    $same=($current['actual_quantity']===null?$desired===null:(int)$current['actual_quantity']===$desired)
                        && (string)($current['note']??'')===$note;
                    if($same){
                        $this->pdo->commit();
                        return [
                            'state'=>'committed',
                            'result'=>[
                                'line_id'=>$lineId,'session_id'=>$sessionId,
                                'actual_quantity'=>$current['actual_quantity']===null?null:(int)$current['actual_quantity'],
                                'version'=>(string)$current['updated_at'],
                            ],
                            'error_code'=>'','idempotent'=>true,
                        ];
                    }
                }
                $this->pdo->commit();
                return $this->review($e->errorCode,$e->details);
            }
        }catch(InventoryException $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            return $this->rejected($e->errorCode,$e->details);
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function review(string $code,array $result=[]): array
    {
        return ['state'=>'needs_review','result'=>$result,'error_code'=>$code,'idempotent'=>false];
    }

    private function rejected(string $code,array $result=[]): array
    {
        return ['state'=>'rejected','result'=>$result,'error_code'=>$code,'idempotent'=>false];
    }

    private static function truncate(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }
}
