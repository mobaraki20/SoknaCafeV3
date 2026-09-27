<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Orders;

use PDO;
use PDOException;
use Throwable;

final class WaiterCallService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BusinessClock $clock,
    ) {}

    public function create(array $data): array
    {
        $this->pdo->beginTransaction();
        try{$result=$this->createTx($data);$this->pdo->commit();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function createTx(array $data): array
    {
        $this->requireTx();
        $tableToken=trim((string)($data['table_token']??''));
        $publicContext=!empty($data['public_context']);
        $sessionToken=trim((string)($data['session_token']??''));
        $deviceToken=trim((string)($data['device_token']??''));
        $clientToken=trim((string)($data['client_token']??''));
        if($tableToken===''||strlen($tableToken)>80)throw new WaiterCallException('invalid_qr','کد میز معتبر نیست.',404);
        if(!$this->settingBool($publicContext?'public_waiter_call_enabled':'waiter_call_enabled',$publicContext?false:true))
            throw new WaiterCallException('waiter_disabled',$publicContext?'فراخوان گارسون از منوی عمومی فعال نیست.':'فراخوان گارسون فعلاً فعال نیست.',$publicContext?403:503);
        if(strlen($clientToken)<16||strlen($clientToken)>80||strlen($deviceToken)<16||strlen($deviceToken)>80)
            throw new WaiterCallException('invalid_request','درخواست کامل نیست.',422);

        $table=$this->table($tableToken,true);
        if($table===null)throw new WaiterCallException('invalid_qr','کد میز معتبر نیست.',404);
        $existing=$this->pdo->prepare('SELECT public_code,status FROM waiter_calls WHERE client_token=? LIMIT 1 FOR UPDATE');
        $existing->execute([$clientToken]);$found=$existing->fetch(PDO::FETCH_ASSOC);
        if(is_array($found))return ['success'=>true,'call_code'=>(string)$found['public_code'],'status'=>(string)$found['status'],'duplicate'=>true,'owned'=>true];

        if($publicContext){
            $recent=$this->pdo->prepare('SELECT COUNT(*) FROM waiter_calls WHERE device_token=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
            $recent->execute([$deviceToken]);
            if((int)$recent->fetchColumn()>=3)throw new WaiterCallException('rate_limited','تعداد درخواست‌ها زیاد شده؛ یک دقیقه دیگر دوباره امتحان کن.',429);
        }

        $session=null;
        if(!$publicContext&&$sessionToken!==''){
            $stmt=$this->pdo->prepare("SELECT id,public_token FROM table_sessions WHERE public_token=? AND table_id=? AND status='active' LIMIT 1 FOR UPDATE");
            $stmt->execute([$sessionToken,(int)$table['id']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);$session=is_array($row)?$row:null;
        }
        $active=$this->pdo->prepare("SELECT public_code,status FROM waiter_calls WHERE table_id=? AND status IN('new','accepted') ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $active->execute([(int)$table['id']]);$row=$active->fetch(PDO::FETCH_ASSOC);
        if(is_array($row))return ['success'=>true,'call_code'=>(string)$row['public_code'],'status'=>(string)$row['status'],'shared'=>true,'owned'=>false];
        $rate=$this->pdo->prepare('SELECT COUNT(*) FROM waiter_calls WHERE table_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
        $rate->execute([(int)$table['id']]);
        if((int)$rate->fetchColumn()>=3)throw new WaiterCallException('rate_limited','چند لحظه صبر کن و دوباره امتحان کن.',429);

        $code='W'.date('ymd').strtoupper(bin2hex(random_bytes(4)));$business=$this->clock->assignment();
        $insert=$this->pdo->prepare("INSERT INTO waiter_calls(public_code,client_token,device_token,table_id,session_id,status,active_table_guard,business_date,business_shift_key,business_shift_label,business_cutoff_snapshot) VALUES(?,?,?,?,?,'new',?,?,?,?,?)");
        try{
            $insert->execute([$code,$clientToken,$deviceToken,(int)$table['id'],$session['id']??null,(int)$table['id'],$business['business_date'],$business['shift_key'],$business['shift_label'],$business['cutoff']]);
        }catch(PDOException $e){
            if((string)$e->getCode()==='23000'){
                $lookup=$this->pdo->prepare("SELECT public_code,status FROM waiter_calls WHERE table_id=? AND status IN('new','accepted') ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $lookup->execute([(int)$table['id']]);$other=$lookup->fetch(PDO::FETCH_ASSOC);
                if(is_array($other))return ['success'=>true,'call_code'=>(string)$other['public_code'],'status'=>(string)$other['status'],'shared'=>true,'owned'=>false];
            }
            throw $e;
        }
        if($session!==null)$this->registerSessionClient((int)$session['id'],$deviceToken);
        return ['success'=>true,'call_code'=>$code,'status'=>'new','owned'=>true];
    }

    public function status(array $data): array
    {
        $table=$this->table(trim((string)($data['table_token']??'')),false);
        if($table===null)throw new WaiterCallException('invalid_qr','کد میز معتبر نیست.',404);
        $code=trim((string)($data['call_code']??''));$client=trim((string)($data['client_token']??''));
        if($code===''){$find=$this->pdo->prepare("SELECT public_code,status,updated_at,client_token FROM waiter_calls WHERE table_id=? AND status IN('new','accepted') ORDER BY id DESC LIMIT 1");$find->execute([(int)$table['id']]);}
        else{$find=$this->pdo->prepare('SELECT public_code,status,updated_at,client_token FROM waiter_calls WHERE public_code=? AND table_id=? LIMIT 1');$find->execute([$code,(int)$table['id']]);}
        $row=$find->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return ['success'=>true,'status'=>'none'];
        $owned=$client!==''&&hash_equals((string)$row['client_token'],$client);
        return ['success'=>true,'call_code'=>(string)$row['public_code'],'status'=>(string)$row['status'],'updated_at'=>(string)$row['updated_at'],'owned'=>$owned];
    }

    public function cancel(array $data): array
    {
        $this->pdo->beginTransaction();
        try{$result=$this->cancelTx($data);$this->pdo->commit();return $result;}
        catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function cancelTx(array $data): array
    {
        $this->requireTx();$client=trim((string)($data['client_token']??''));$code=trim((string)($data['call_code']??''));
        if($client===''||$code==='')throw new WaiterCallException('not_owner','این درخواست از همین دستگاه ثبت نشده است.',403);
        $table=$this->table(trim((string)($data['table_token']??'')),true);if($table===null)throw new WaiterCallException('invalid_qr','کد میز معتبر نیست.',404);
        $lookup=$this->pdo->prepare('SELECT status,client_token FROM waiter_calls WHERE public_code=? AND table_id=? LIMIT 1 FOR UPDATE');
        $lookup->execute([$code,(int)$table['id']]);$row=$lookup->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return ['success'=>true,'status'=>'unchanged'];
        if(!hash_equals((string)$row['client_token'],$client))throw new WaiterCallException('not_owner','این درخواست از همین دستگاه ثبت نشده است.',403);
        if((string)$row['status']==='cancelled')return ['success'=>true,'status'=>'cancelled','duplicate'=>true];
        if((string)$row['status']!=='new')return ['success'=>true,'status'=>'unchanged'];
        $update=$this->pdo->prepare("UPDATE waiter_calls SET status='cancelled',active_table_guard=NULL,cancelled_at=NOW(),cancel_reason='customer' WHERE public_code=? AND table_id=? AND client_token=? AND status='new'");
        $update->execute([$code,(int)$table['id'],$client]);
        return ['success'=>true,'status'=>$update->rowCount()?'cancelled':'unchanged'];
    }

    private function table(string $token,bool $lock): ?array
    {
        if($token==='')return null;$sql='SELECT id,name,code,access_token FROM cafe_tables WHERE access_token=? AND active=1 LIMIT 1'.($lock?' FOR UPDATE':'');
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$token]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    private function settingBool(string $key,bool $default): bool
    {
        $stmt=$this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1'.($this->pdo->inTransaction()?' FOR UPDATE':''));$stmt->execute([$key]);$value=$stmt->fetchColumn();
        if($value===false||$value===null)return $default;return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function registerSessionClient(int $sessionId,string $device): void
    {
        $this->pdo->prepare('INSERT INTO table_session_clients(session_id,device_token,first_seen_at,last_seen_at) VALUES(?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_seen_at=NOW()')->execute([$sessionId,$device]);
    }

    private function requireTx(): void { if(!$this->pdo->inTransaction())throw new \LogicException('Waiter operation requires an open transaction.'); }
}
