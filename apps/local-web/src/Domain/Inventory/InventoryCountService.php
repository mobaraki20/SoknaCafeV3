<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Inventory;

use PDO;
use Throwable;

final class InventoryCountService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly InventoryService $inventory,
    ) {}

    public function start(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->inventory->assertActor($user,'inventory_operations');
            if(!$this->inventory->configuredTx())
                throw new InventoryException('inventory_disabled','انبار در حال حاضر غیرفعال است.',409);

            $type=in_array((string)($data['session_type']??'periodic'),['opening','periodic'],true)
                ?(string)($data['session_type']??'periodic'):'periodic';
            $scope=$type==='opening'?'full':(in_array((string)($data['scope_type']??'full'),['full','category'],true)?(string)($data['scope_type']??'full'):'full');
            $category=$scope==='category'?trim((string)($data['scope_category_key']??'')):null;
            if($scope==='category'&&$category==='')throw new InventoryException('category_required','دسته شمارش را انتخاب کن.',422);

            $this->pdo->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('inventory_count_start_guard','1') ON DUPLICATE KEY UPDATE setting_value=setting_value")->execute();
            $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key='inventory_count_start_guard' FOR UPDATE")->fetchColumn();
            $open=$this->pdo->query("SELECT id FROM inventory_count_sessions WHERE status='draft' ORDER BY id LIMIT 1 FOR UPDATE")->fetchColumn();
            if($open!==false)throw new InventoryException('count_already_open','یک شمارش دیگر هنوز باز است؛ ابتدا همان شمارش را ادامه بده یا لغو کن.',409,['session_id'=>(int)$open]);

            if($scope==='category'){
                $check=$this->pdo->prepare('SELECT name,active FROM inventory_categories WHERE category_key=? FOR UPDATE');
                $check->execute([$category]);$row=$check->fetch(PDO::FETCH_ASSOC);
                if(!is_array($row)||(int)$row['active']!==1)throw new InventoryException('invalid_category','این دسته برای شروع شمارش قابل استفاده نیست.',422);
            }

            $title=self::truncate(trim((string)($data['title']??'')),160);
            if($title==='')$title=$type==='opening'?'موجودی اولیه':'شمارش دوره‌ای';
            $stmt=$this->pdo->prepare(
                "INSERT INTO inventory_count_sessions(title,session_type,scope_type,scope_category_key,status,snapshot_at,created_by_user_id)
                 VALUES(?,?,?,?,'draft',NOW(),?)"
            );
            $stmt->execute([$title,$type,$scope,$category,(int)$actor['id']]);
            $sessionId=(int)$this->pdo->lastInsertId();

            $sql="SELECT i.id,COALESCE(b.quantity_base,0) quantity_base,b.average_unit_cost
                  FROM inventory_items i LEFT JOIN inventory_balances b ON b.inventory_item_id=i.id
                  WHERE i.active=1";
            $params=[];
            if($scope==='category'){$sql.=' AND i.category=?';$params[]=$category;}
            $sql.=' ORDER BY i.category,i.name,i.id';
            $items=$this->pdo->prepare($sql);$items->execute($params);$rows=$items->fetchAll(PDO::FETCH_ASSOC);
            if(!$rows)throw new InventoryException('empty_scope','در محدوده انتخاب‌شده کالای فعالی برای شمارش وجود ندارد.',409);

            $line=$this->pdo->prepare(
                'INSERT INTO inventory_count_lines(session_id,inventory_item_id,system_quantity_snapshot,unit_cost_snapshot,actual_quantity,actual_total_cost,difference_base)
                 VALUES(?,?,?,?,NULL,NULL,NULL)'
            );
            foreach($rows as $row){
                $system=$type==='opening'?(int)$row['quantity_base']:0;
                $cost=$type==='opening'&&$row['average_unit_cost']!==null?(float)$row['average_unit_cost']:null;
                $line->execute([$sessionId,(int)$row['id'],$system,$cost]);
            }

            $this->audit('inventory.count_started',$sessionId,(int)$actor['id'],[
                'session_type'=>$type,'title'=>$title,'scope_type'=>$scope,'scope_category_key'=>$category,'item_count'=>count($rows),
            ]);
            $this->pdo->commit();
            return ['session_id'=>$sessionId,'line_count'=>count($rows)];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateLine(array $data,array $user,bool $remote=false): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->inventory->assertActor($user,'inventory_operations');
            $result=$this->updateLineTx($data,(int)$actor['id'],$remote);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateLineTx(array $data,int $actorUserId,bool $remote=false): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory count edit requires an open transaction.');
        $sessionId=(int)($data['session_id']??0);$lineId=(int)($data['line_id']??0);
        if($sessionId<1||$lineId<1)throw new InventoryException('invalid_count_line','قلم شمارش معتبر نیست.',422);

        $sessionStmt=$this->pdo->prepare('SELECT id,status,session_type FROM inventory_count_sessions WHERE id=? FOR UPDATE');
        $sessionStmt->execute([$sessionId]);$session=$sessionStmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($session)||(string)$session['status']!=='draft')
            throw new InventoryStateConflict('count_not_draft','این شمارش دیگر قابل تغییر نیست.',409);
        if($remote&&(string)$session['session_type']==='opening')
            throw new InventoryException('opening_local_only','موجودی اولیه فقط روی Local قابل ویرایش است.',409);

        $lineStmt=$this->pdo->prepare(
            'SELECT l.*,i.base_unit FROM inventory_count_lines l JOIN inventory_items i ON i.id=l.inventory_item_id
             WHERE l.id=? AND l.session_id=? FOR UPDATE'
        );
        $lineStmt->execute([$lineId,$sessionId]);$line=$lineStmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($line))throw new InventoryException('count_line_not_found','قلم شمارش پیدا نشد.',404);

        $expected=trim((string)($data['expected_version']??''));
        $current=(string)$line['updated_at'];
        if($expected!==''&&!hash_equals($current,$expected))
            throw new InventoryStateConflict('count_line_changed','این قلم شمارش از زمان نسخه قبلی تغییر کرده است.',409,['current_version'=>$current]);

        $isOpening=(string)$session['session_type']==='opening';
        $raw=array_key_exists('actual_major',$data)?trim((string)$data['actual_major']):'';
        $actual=$raw===''?null:InventoryService::majorToBase($raw,(string)$line['base_unit']);
        $costRaw=trim((string)($data['opening_total_cost']??''));
        $openingCost=$isOpening&&$costRaw!==''?self::money($costRaw):null;
        $note=self::truncate(trim((string)($data['note']??'')),500);

        if($actual===null){
            if($isOpening){
                $this->pdo->prepare(
                    'UPDATE inventory_count_lines SET actual_quantity=NULL,actual_total_cost=NULL,note=?,difference_base=NULL,counted_by_user_id=NULL,counted_at=NULL WHERE id=? AND session_id=?'
                )->execute([$note?:null,$lineId,$sessionId]);
            }else{
                $this->pdo->prepare(
                    'UPDATE inventory_count_lines SET system_quantity_snapshot=0,unit_cost_snapshot=NULL,actual_quantity=NULL,actual_total_cost=NULL,note=?,difference_base=NULL,counted_by_user_id=NULL,counted_at=NULL WHERE id=? AND session_id=?'
                )->execute([$note?:null,$lineId,$sessionId]);
            }
        }elseif(!$isOpening&&$line['counted_at']===null){
            $balance=$this->inventory->balanceTx((int)$line['inventory_item_id']);
            $this->pdo->prepare(
                'UPDATE inventory_count_lines SET system_quantity_snapshot=?,unit_cost_snapshot=?,actual_quantity=?,actual_total_cost=NULL,note=?,difference_base=NULL,counted_by_user_id=?,counted_at=NOW() WHERE id=? AND session_id=?'
            )->execute([
                (int)$balance['quantity_base'],$balance['average_unit_cost']!==null?(float)$balance['average_unit_cost']:null,
                $actual,$note?:null,$actorUserId,$lineId,$sessionId,
            ]);
        }else{
            $this->pdo->prepare(
                'UPDATE inventory_count_lines SET actual_quantity=?,actual_total_cost=?,note=?,counted_by_user_id=?,counted_at=COALESCE(counted_at,NOW()) WHERE id=? AND session_id=?'
            )->execute([$actual,$openingCost,$note?:null,$actorUserId,$lineId,$sessionId]);
        }

        $fresh=$this->pdo->prepare('SELECT id,session_id,inventory_item_id,actual_quantity,system_quantity_snapshot,updated_at FROM inventory_count_lines WHERE id=?');
        $fresh->execute([$lineId]);$row=$fresh->fetch(PDO::FETCH_ASSOC)?:[];
        return [
            'line_id'=>$lineId,'session_id'=>$sessionId,
            'actual_quantity'=>$row['actual_quantity']===null?null:(int)$row['actual_quantity'],
            'system_quantity_snapshot'=>(int)($row['system_quantity_snapshot']??0),
            'version'=>(string)($row['updated_at']??''),
        ];
    }

    public function finalize(int $sessionId,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->inventory->assertActor($user,'inventory_finalize');
            $result=$this->finalizeTx($sessionId,(int)$actor['id']);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function finalizeTx(int $sessionId,int $actorUserId): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Inventory count finalize requires an open transaction.');
        if(!$this->inventory->configuredTx())throw new InventoryException('inventory_disabled','انبار در حال حاضر غیرفعال است.',409);
        $stmt=$this->pdo->prepare('SELECT * FROM inventory_count_sessions WHERE id=? FOR UPDATE');
        $stmt->execute([$sessionId]);$session=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($session))throw new InventoryException('count_not_found','شمارش پیدا نشد.',404);
        if((string)$session['status']!=='draft')throw new InventoryStateConflict('count_closed','این شمارش قبلاً بسته شده است.',409);

        $lines=$this->pdo->prepare(
            'SELECT l.*,i.base_unit,i.name FROM inventory_count_lines l JOIN inventory_items i ON i.id=l.inventory_item_id WHERE l.session_id=? ORDER BY l.id FOR UPDATE'
        );
        $lines->execute([$sessionId]);$rows=$lines->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){
            if($row['actual_quantity']===null){
                $message=(string)$session['session_type']==='opening'
                    ?'قبل از نهایی‌سازی، موجودی اولیه را مرور کن تا اقلام خالی به صفر ثبت شوند.'
                    :'همه اقلام این شمارش باید مقدار واقعی داشته باشند؛ برای کالای بدون موجودی عدد صفر ثبت کن.';
                throw new InventoryException('count_incomplete',$message,409);
            }
        }

        $movements=0;$differences=0;
        $diffStmt=$this->pdo->prepare('UPDATE inventory_count_lines SET difference_base=? WHERE id=?');
        foreach($rows as $row){
            $actual=max(0,(int)$row['actual_quantity']);$system=(int)$row['system_quantity_snapshot'];
            $difference=$actual-$system;
            $diffStmt->execute([$difference,(int)$row['id']]);
            if($difference===0)continue;
            $differences++;
            $type=(string)$session['session_type']==='opening'?'opening_balance':'count_adjustment';
            $openingCost=(string)$session['session_type']==='opening'&&$row['actual_total_cost']!==null?max(0,(int)$row['actual_total_cost']):null;
            $this->inventory->recordMovementTx([
                'item_id'=>(int)$row['inventory_item_id'],'movement_type'=>$type,'quantity_base'=>$difference,
                'unit_cost_snapshot'=>$openingCost===null&&$row['unit_cost_snapshot']!==null?(float)$row['unit_cost_snapshot']:null,
                'total_cost_delta'=>$openingCost,
                'cost_status'=>$openingCost!==null?'known':($row['unit_cost_snapshot']!==null?'estimated':'unknown'),
                'source_type'=>'inventory_count_session','source_id'=>$sessionId,
                'idempotency_key'=>'inventory:count:'.$sessionId.':line:'.(int)$row['id'],
                'metadata'=>[
                    'count_line_id'=>(int)$row['id'],'system_quantity_snapshot'=>$system,
                    'actual_quantity'=>$actual,'opening_total_cost'=>$openingCost,
                ],
                'actor_user_id'=>$actorUserId,
            ]);
            $movements++;
        }

        $this->pdo->prepare("UPDATE inventory_count_sessions SET status='finalized',finalized_by_user_id=?,finalized_at=NOW() WHERE id=?")
            ->execute([$actorUserId,$sessionId]);
        if((string)$session['session_type']==='opening'){
            $this->setSetting('inventory_initialized','1');
            $this->setSetting('inventory_reconciliation_required','0');
        }elseif((string)($session['scope_type']??'full')==='full'&&$this->settingBool('inventory_reconciliation_required',false)){
            $this->setSetting('inventory_reconciliation_required','0');
            $this->audit('inventory.reconciliation_completed',$sessionId,$actorUserId,['session_type'=>$session['session_type'],'scope_type'=>'full']);
        }
        $this->audit('inventory.count_finalized',$sessionId,$actorUserId,[
            'session_type'=>$session['session_type'],'movement_count'=>$movements,'difference_count'=>$differences,
        ]);
        return ['movements'=>$movements,'differences'=>$differences];
    }

    public function cancel(int $sessionId,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->inventory->assertActor($user,'inventory_operations');
            $stmt=$this->pdo->prepare('SELECT * FROM inventory_count_sessions WHERE id=? FOR UPDATE');
            $stmt->execute([$sessionId]);$session=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($session))throw new InventoryException('count_not_found','شمارش پیدا نشد.',404);
            if((string)$session['status']!=='draft')throw new InventoryStateConflict('count_closed','فقط شمارش در حال انجام قابل لغو است.',409);
            $this->pdo->prepare("UPDATE inventory_count_sessions SET status='cancelled' WHERE id=?")->execute([$sessionId]);
            $this->audit('inventory.count_cancelled',$sessionId,(int)$actor['id'],['session_type'=>$session['session_type'],'title'=>$session['title']]);
            $this->pdo->commit();
            return ['success'=>true,'session_id'=>$sessionId,'status'=>'cancelled'];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function setSetting(string $key,string $value): void
    {
        $this->pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')
            ->execute([$key,$value]);
    }

    private function settingBool(string $key,bool $default): bool
    {
        $stmt=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$key]);$value=$stmt->fetchColumn();
        if($value===false||$value===null)return $default;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function audit(string $action,int $sessionId,int $actorId,array $details): void
    {
        $display=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
        $display->execute([$actorId]);$name=$display->fetchColumn();
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)'
        )->execute([$actorId,$name!==false?$name:null,$action,'inventory_count_session',(string)$sessionId,$json?:'{}']);
    }

    private static function money(string $value): int
    {
        $raw=str_replace([',','٬',' '],'',trim($value));
        if($raw===''||!preg_match('/^\d+$/',$raw)||strlen($raw)>18)
            throw new InventoryException('invalid_cost','مبلغ واردشده معتبر نیست.',422);
        return (int)$raw;
    }

    private static function truncate(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }
}
