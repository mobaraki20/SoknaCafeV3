<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use PDOException;
use Throwable;

final class TableDraftService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OrderCatalogService $catalog,
        private readonly StaffQuickOrderService $quickOrders,
    ) {}

    public function get(int $tableId,array $user): array
    {
        $this->quickOrders->assertActor($user);
        if($tableId<1)throw new TableDraftException('invalid_table','میز فعال پیدا نشد.',404);
        $table=$this->pdo->prepare('SELECT id FROM cafe_tables WHERE id=? AND active=1 LIMIT 1');
        $table->execute([$tableId]);
        if($table->fetchColumn()===false)throw new TableDraftException('invalid_table','میز فعال پیدا نشد.',404);
        $stmt=$this->pdo->prepare("SELECT * FROM table_drafts WHERE table_id=? AND state='active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$tableId]);$draft=$stmt->fetch(PDO::FETCH_ASSOC);
        return ['success'=>true,'draft'=>is_array($draft)?$this->result($draft):null];
    }

    public function save(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$this->saveTx($data,$user);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Table Draft save requires an open transaction.');
        $actor=$this->quickOrders->assertActor($user);
        $userId=(int)$actor['id'];
        $tableId=(int)($data['table_id']??0);
        $expectedVersion=max(0,(int)($data['expected_version']??0));
        $expectedSessionId=max(0,(int)($data['expected_session_id']??0));
        $note=self::truncate(trim((string)($data['note']??'')),500);
        $rows=$this->catalog->normalizeRows($data['items']??[],true);

        $this->lockTable($tableId);
        $currentSessionId=$this->currentSessionIdLocked($tableId);
        if($currentSessionId!==$expectedSessionId){
            throw new TableDraftException('session_changed','حساب این میز تغییر کرده است؛ پیش‌نویس را تازه کنید.',409,['current_session_id'=>$currentSessionId]);
        }

        $draft=$this->activeLocked($tableId);
        $snapshots=$this->catalog->snapshotRowsTx($rows,'staff');
        if($draft===null){
            if($expectedVersion!==0)
                throw new TableDraftException('version_conflict','پیش‌نویس این میز تغییر کرده است؛ دوباره بارگذاری کنید.',409,['current_version'=>0]);
            try{
                $stmt=$this->pdo->prepare(
                    "INSERT INTO table_drafts(table_id,state,active_table_guard,version,expected_session_id,note,created_by_user_id,updated_by_user_id)
                     VALUES(?,'active',?,1,?,?,?,?)"
                );
                $stmt->execute([$tableId,$tableId,$expectedSessionId>0?$expectedSessionId:null,$note!==''?$note:null,$userId,$userId]);
            }catch(PDOException $e){
                if((string)$e->getCode()==='23000')
                    throw new TableDraftException('version_conflict','همکار دیگری هم‌زمان برای این میز پیش‌نویس ساخته است؛ دوباره بارگذاری کنید.',409);
                throw $e;
            }
            $draftId=(int)$this->pdo->lastInsertId();
            $this->replaceItems($draftId,$snapshots);
            $fresh=$this->byIdLocked($draftId);
            $this->audit('table_draft.created',$draftId,$actor,['table_id'=>$tableId,'version'=>1,'item_count'=>count($snapshots)]);
            return ['success'=>true,'created'=>true,'draft'=>$this->result($fresh)];
        }

        $currentVersion=(int)$draft['version'];
        if($expectedVersion!==$currentVersion)
            throw new TableDraftException('version_conflict','پیش‌نویس این میز توسط همکار دیگری تغییر کرده است؛ دوباره بارگذاری کنید.',409,['current_version'=>$currentVersion]);
        if((int)($draft['expected_session_id']??0)!==$expectedSessionId)
            throw new TableDraftException('session_changed','حساب میز با پیش‌نویس ذخیره‌شده هم‌خوان نیست.',409,['draft_session_id'=>(int)($draft['expected_session_id']??0)]);

        $next=$currentVersion+1;
        $update=$this->pdo->prepare("UPDATE table_drafts SET version=?,note=?,updated_by_user_id=? WHERE id=? AND state='active' AND version=?");
        $update->execute([$next,$note!==''?$note:null,$userId,(int)$draft['id'],$currentVersion]);
        if($update->rowCount()!==1)
            throw new TableDraftException('version_conflict','پیش‌نویس هم‌زمان تغییر کرده است؛ دوباره بارگذاری کنید.',409);
        $this->replaceItems((int)$draft['id'],$snapshots);
        $fresh=$this->byIdLocked((int)$draft['id']);
        $this->audit('table_draft.updated',(int)$draft['id'],$actor,['table_id'=>$tableId,'before_version'=>$currentVersion,'version'=>$next,'item_count'=>count($snapshots)]);
        return ['success'=>true,'created'=>false,'draft'=>$this->result($fresh)];
    }

    public function cancel(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$this->cancelTx($data,$user);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function cancelTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Table Draft cancel requires an open transaction.');
        $actor=$this->quickOrders->assertActor($user);
        $userId=(int)$actor['id'];
        $tableId=(int)($data['table_id']??0);
        $expectedVersion=max(0,(int)($data['expected_version']??0));
        $this->lockTable($tableId);
        $draft=$this->activeLocked($tableId);
        if($draft===null)return ['success'=>true,'cancelled'=>false,'draft'=>null];
        if($expectedVersion>0&&(int)$draft['version']!==$expectedVersion)
            throw new TableDraftException('version_conflict','پیش‌نویس این میز توسط همکار دیگری تغییر کرده است؛ دوباره بارگذاری کنید.',409,['current_version'=>(int)$draft['version']]);

        $next=(int)$draft['version']+1;
        $this->pdo->prepare("UPDATE table_drafts SET state='cancelled',active_table_guard=NULL,version=?,cancelled_by_user_id=?,cancelled_at=NOW(),updated_by_user_id=? WHERE id=? AND state='active'")
            ->execute([$next,$userId,$userId,(int)$draft['id']]);
        $this->audit('table_draft.cancelled',(int)$draft['id'],$actor,['table_id'=>$tableId,'version'=>$next]);
        return ['success'=>true,'cancelled'=>true,'draft_id'=>(int)$draft['id'],'version'=>$next];
    }

    public function finalize(array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $result=$this->finalizeTx($data,$user);
            $this->pdo->commit();
            return $result;
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function finalizeTx(array $data,array $user): array
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Table Draft finalize requires an open transaction.');
        $actor=$this->quickOrders->assertActor($user);
        $userId=(int)$actor['id'];
        $draftId=(int)($data['draft_id']??0);
        $expectedVersion=max(0,(int)($data['expected_version']??0));
        if($draftId<1)throw new TableDraftException('invalid_draft','پیش‌نویس معتبر نیست.',422);

        $probe=$this->pdo->prepare('SELECT table_id FROM table_drafts WHERE id=? LIMIT 1');
        $probe->execute([$draftId]);$tableId=(int)($probe->fetchColumn()?:0);
        if($tableId<1)throw new TableDraftException('invalid_draft','پیش‌نویس پیدا نشد.',404);
        $this->lockTable($tableId);
        $draft=$this->byIdLocked($draftId);
        if($draft===null)throw new TableDraftException('invalid_draft','پیش‌نویس پیدا نشد.',404);

        if((string)$draft['state']==='finalized'&&(int)($draft['final_order_id']??0)>0){
            $order=$this->pdo->prepare('SELECT id,business_order_number,total_amount,public_code FROM orders WHERE id=? LIMIT 1');
            $order->execute([(int)$draft['final_order_id']]);$row=$order->fetch(PDO::FETCH_ASSOC);
            return [
                'success'=>true,'duplicate'=>true,'draft_id'=>$draftId,
                'draft_version'=>(int)$draft['version'],
                'order_id'=>(int)$draft['final_order_id'],
                'order_number'=>(int)($row['business_order_number']??0),
                'total_amount'=>(int)($row['total_amount']??0),
                'public_code'=>(string)($row['public_code']??''),
            ];
        }
        if((string)$draft['state']!=='active')throw new TableDraftException('draft_closed','این پیش‌نویس دیگر فعال نیست.',409);
        if($expectedVersion>0&&(int)$draft['version']!==$expectedVersion)
            throw new TableDraftException('version_conflict','پیش‌نویس این میز توسط همکار دیگری تغییر کرده است؛ دوباره بارگذاری کنید.',409,['current_version'=>(int)$draft['version']]);

        $expectedSessionId=(int)($draft['expected_session_id']??0);
        $currentSessionId=$this->currentSessionIdLocked($tableId);
        if($currentSessionId!==$expectedSessionId)
            throw new TableDraftException('session_changed','حساب این میز تغییر کرده است؛ پیش‌نویس را تازه کنید.',409,['current_session_id'=>$currentSessionId]);

        $items=$this->items($draftId);
        if($items===[])throw new TableDraftException('empty_draft','پیش‌نویس خالی قابل ثبت نیست.',422);

        try{
            $order=$this->quickOrders->commitTx([
                'table_id'=>$tableId,
                'expected_session_id'=>$expectedSessionId,
                'request_token'=>self::orderToken($draftId),
                'note'=>(string)($draft['note']??''),
                'items'=>array_map(static fn(array $row):array=>[
                    'id'=>$row['id'],
                    'quantity'=>$row['quantity'],
                    'note'=>$row['note'],
                    'expected_price'=>$row['unit_price'],
                    'fulfillment_mode'=>$row['fulfillment_mode'],
                ],$items),
            ],$actor);
        }catch(StaffQuickOrderException $e){
            throw new TableDraftException('finalize_rejected',$e->getMessage(),$e->httpStatus,['cause'=>$e->errorCode]+$e->details);
        }

        $orderId=(int)($order['order_id']??0);
        if($orderId<1)throw new \RuntimeException('ثبت سفارش پیش‌نویس نتیجه معتبر برنگرداند.');
        $next=(int)$draft['version']+1;
        $update=$this->pdo->prepare("UPDATE table_drafts SET state='finalized',active_table_guard=NULL,version=?,finalized_by_user_id=?,finalized_at=NOW(),updated_by_user_id=?,final_order_id=? WHERE id=? AND state='active'");
        $update->execute([$next,$userId,$userId,$orderId,$draftId]);
        if($update->rowCount()!==1)
            throw new TableDraftException('version_conflict','پیش‌نویس هم‌زمان تغییر کرده است؛ دوباره وضعیت میز را بررسی کنید.',409);
        $this->audit('table_draft.finalized',$draftId,$actor,['table_id'=>$tableId,'order_id'=>$orderId,'version'=>$next]);

        return $order+['draft_id'=>$draftId,'draft_version'=>$next];
    }

    private function lockTable(int $tableId): array
    {
        if($tableId<1)throw new TableDraftException('invalid_table','میز فعال پیدا نشد.',404);
        $stmt=$this->pdo->prepare('SELECT id,name FROM cafe_tables WHERE id=? AND active=1 FOR UPDATE');
        $stmt->execute([$tableId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new TableDraftException('invalid_table','میز فعال پیدا نشد.',404);
        return $row;
    }

    private function currentSessionIdLocked(int $tableId): int
    {
        $stmt=$this->pdo->prepare("SELECT id FROM table_sessions WHERE table_id=? AND status IN('active','pending') ORDER BY FIELD(status,'active','pending'),id DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$tableId]);
        return (int)($stmt->fetchColumn()?:0);
    }

    private function activeLocked(int $tableId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM table_drafts WHERE table_id=? AND state='active' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$tableId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function byIdLocked(int $draftId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM table_drafts WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$draftId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private function replaceItems(int $draftId,array $snapshots): void
    {
        $this->pdo->prepare('DELETE FROM table_draft_items WHERE draft_id=?')->execute([$draftId]);
        if($snapshots===[])return;
        $stmt=$this->pdo->prepare(
            'INSERT INTO table_draft_items(draft_id,item_id,item_name_snapshot,unit_price_snapshot,sellable_kind_snapshot,quantity,item_note,fulfillment_mode,sort_order) VALUES(?,?,?,?,?,?,?,?,?)'
        );
        foreach($snapshots as $index=>$row){
            $stmt->execute([
                $draftId,$row['item_id'],$row['item_name'],$row['unit_price'],$row['sellable_kind'],$row['quantity'],
                $row['item_note'],$row['fulfillment_mode'],$index,
            ]);
        }
    }

    private function items(int $draftId): array
    {
        $stmt=$this->pdo->prepare('SELECT item_id,item_name_snapshot,unit_price_snapshot,sellable_kind_snapshot,quantity,item_note,fulfillment_mode,sort_order FROM table_draft_items WHERE draft_id=? ORDER BY sort_order,id');
        $stmt->execute([$draftId]);
        return array_map(static fn(array $row):array=>[
            'id'=>(int)$row['item_id'],
            'name'=>(string)$row['item_name_snapshot'],
            'unit_price'=>(int)$row['unit_price_snapshot'],
            'sellable_kind'=>(string)$row['sellable_kind_snapshot'],
            'quantity'=>(int)$row['quantity'],
            'note'=>(string)($row['item_note']??''),
            'fulfillment_mode'=>(string)$row['fulfillment_mode'],
            'sort_order'=>(int)$row['sort_order'],
        ],$stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function result(array $draft): array
    {
        return [
            'id'=>(int)$draft['id'],
            'table_id'=>(int)$draft['table_id'],
            'state'=>(string)$draft['state'],
            'version'=>(int)$draft['version'],
            'expected_session_id'=>(int)($draft['expected_session_id']??0),
            'note'=>(string)($draft['note']??''),
            'created_by_user_id'=>$draft['created_by_user_id']!==null?(int)$draft['created_by_user_id']:null,
            'updated_by_user_id'=>$draft['updated_by_user_id']!==null?(int)$draft['updated_by_user_id']:null,
            'final_order_id'=>$draft['final_order_id']!==null?(int)$draft['final_order_id']:null,
            'items'=>$this->items((int)$draft['id']),
        ];
    }

    private function audit(string $action,int $entityId,array $actor,array $details): void
    {
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)');
        $stmt->execute([(int)$actor['id'],(string)$actor['display_name'],$action,'table_draft',(string)$entityId,$json?:'{}']);
    }

    private static function orderToken(int $draftId): string
    {
        return 'draft-'.$draftId.'-'.substr(hash('sha256','sokna-table-draft:'.$draftId),0,32);
    }

    private static function truncate(string $value,int $length): string
    {
        if(function_exists('mb_substr'))return mb_substr($value,0,$length,'UTF-8');
        return substr($value,0,$length);
    }
}
