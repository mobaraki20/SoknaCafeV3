<?php
declare(strict_types=1);
namespace Sokna\Local\Domain\LanStaff;
use PDO;use Throwable;
final class LanStaffRealtimeReceiptService
{
    private const GUEST_TRANSPORT_KINDS=['guest_order.submit','guest_table.context','order.edit','order.cancel','waiter_call.create','waiter_call.cancel'];

    public function __construct(private readonly PDO $pdo){}

    /**
     * ARCH-12 stable privacy-preserving trace identity. It is deterministic across Public/LAN
     * for the same installation + request_id, but does not expose either raw identifier.
     */
    public static function traceId(string $installationId,string $requestId): string
    {
        return 'skt1-'.substr(hash('sha256',trim($installationId)."\0".trim($requestId)),0,32);
    }

    private static function attachTransportTrace(string $installationId,string $requestId,array $result,string $state): array
    {
        $existing=is_array($result['_transport_trace']??null)?$result['_transport_trace']:[];
        $result['_transport_trace']=$existing+[
            'trace_id'=>self::traceId($installationId,$requestId),
            'local_finished_at'=>gmdate('c'),
            'local_state'=>$state,
        ];
        return $result;
    }

    /**
     * Legacy/non-mutating dispatch path. It intentionally keeps the historical two-phase
     * receipt/handler flow because read-style realtime operations do not own business writes.
     * Mutations must use dispatchAtomic().
     */
    public function dispatch(string $installationId,array $envelope,callable $handler): array
    {
        $requestId=trim((string)($envelope['request_id']??''));
        if($installationId===''||$requestId===''||strlen($requestId)>96) return ['state'=>'rejected','result'=>[],'error_code'=>'invalid_request_id','idempotent'=>false];
        $hash=self::requestHash($envelope);$kind=trim((string)($envelope['kind']??''));$actor=trim((string)($envelope['actor_projection_id']??''));
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT * FROM lan_staff_realtime_receipts WHERE installation_id=? AND request_id=? FOR UPDATE');$q->execute([$installationId,$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($row)){
                if(!self::requestHashMatches((string)$row['request_hash'],$envelope,$hash)){ $this->pdo->rollBack(); return ['state'=>'rejected','result'=>[],'error_code'=>'request_id_conflict','idempotent'=>true]; }
                $this->pdo->commit();return self::row($row,true);
            }
            $deadline=$this->deadlineResult($envelope);
            if($deadline!==null){$deadline['result']=self::attachTransportTrace($installationId,$requestId,$deadline['result'],'rejected');$id=$this->insertTerminalTx($installationId,$requestId,$hash,$kind,$actor,'rejected',$deadline['result'],$deadline['error_code']);$this->pdo->commit();return ['state'=>'rejected','result'=>$deadline['result'],'error_code'=>$deadline['error_code'],'idempotent'=>false,'receipt_id'=>$id];}
            $q=$this->pdo->prepare("INSERT INTO lan_staff_realtime_receipts(installation_id,request_id,request_hash,kind,actor_projection_id,state,result_json,error_code) VALUES(?,?,?,?,?,'processing',NULL,NULL)");
            $q->execute([$installationId,$requestId,$hash,$kind,$actor]);$id=(int)$this->pdo->lastInsertId();$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        [$state,$result,$error]=$this->invoke($handler);
        $result=self::attachTransportTrace($installationId,$requestId,$result,$state);
        $json=self::encode($result);
        $q=$this->pdo->prepare('UPDATE lan_staff_realtime_receipts SET state=?,result_json=?,error_code=?,completed_at=NOW() WHERE id=? AND state=\'processing\'');$q->execute([$state,$json,$error!==''?$error:null,$id]);
        return ['state'=>$state,'result'=>$result,'error_code'=>$error,'idempotent'=>false,'receipt_id'=>$id];
    }

    /**
     * ARCH-04 mutation path. Receipt intent, domain mutation, terminal result and optional
     * transport-result outbox write share one MariaDB transaction. If the process dies before
     * commit, InnoDB rolls the entire unit back; if commit succeeds, the canonical result and
     * durable delivery intent exist together.
     *
     * @param null|callable(array{state:string,result:array,error_code:string,idempotent:bool,receipt_id:int}):void $afterResultTx
     */
    public function dispatchAtomic(string $installationId,array $envelope,callable $handler,?callable $afterResultTx=null): array
    {
        $requestId=trim((string)($envelope['request_id']??''));
        if($installationId===''||$requestId===''||strlen($requestId)>96) return ['state'=>'rejected','result'=>[],'error_code'=>'invalid_request_id','idempotent'=>false];
        $hash=self::requestHash($envelope);$kind=trim((string)($envelope['kind']??''));$actor=trim((string)($envelope['actor_projection_id']??''));
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT * FROM lan_staff_realtime_receipts WHERE installation_id=? AND request_id=? FOR UPDATE');$q->execute([$installationId,$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($row)){
                if(!self::requestHashMatches((string)$row['request_hash'],$envelope,$hash)){
                    $this->pdo->rollBack();
                    return ['state'=>'rejected','result'=>[],'error_code'=>'request_id_conflict','idempotent'=>true];
                }
                $receipt=self::row($row,true);
                // A pre-ARCH-04 committed "processing" row is genuinely ambiguous because its
                // business transaction may have committed separately. Preserve that fact once,
                // but never re-run it under a fresh identity.
                if((string)$row['state']==='processing'){
                    $receipt=['state'=>'unknown_review','result'=>[],'error_code'=>'legacy_processing_outcome_unknown','idempotent'=>true,'receipt_id'=>(int)$row['id']];
                    $u=$this->pdo->prepare("UPDATE lan_staff_realtime_receipts SET state='unknown_review',result_json='{}',error_code='legacy_processing_outcome_unknown',completed_at=COALESCE(completed_at,NOW()) WHERE id=? AND state='processing'");
                    $u->execute([(int)$row['id']]);
                }
                if($afterResultTx!==null)$afterResultTx($receipt);
                $this->pdo->commit();
                return $receipt;
            }

            $deadline=$this->deadlineResult($envelope);
            if($deadline!==null){
                $deadline['result']=self::attachTransportTrace($installationId,$requestId,$deadline['result'],'rejected');
                $id=$this->insertTerminalTx($installationId,$requestId,$hash,$kind,$actor,'rejected',$deadline['result'],$deadline['error_code']);
                $receipt=['state'=>'rejected','result'=>$deadline['result'],'error_code'=>$deadline['error_code'],'idempotent'=>false,'receipt_id'=>$id];
                if($afterResultTx!==null)$afterResultTx($receipt);
                $this->pdo->commit();
                return $receipt;
            }

            $q=$this->pdo->prepare("INSERT INTO lan_staff_realtime_receipts(installation_id,request_id,request_hash,kind,actor_projection_id,state,result_json,error_code) VALUES(?,?,?,?,?,'processing',NULL,NULL)");
            $q->execute([$installationId,$requestId,$hash,$kind,$actor]);$id=(int)$this->pdo->lastInsertId();
            $this->pdo->exec('SAVEPOINT arch04_domain');
            try{
                $value=$handler();$state='committed';$result=is_array($value)?$value:[];$error='';
            }catch(Throwable $e){
                $this->pdo->exec('ROLLBACK TO SAVEPOINT arch04_domain');
                [$state,$result,$error]=$this->exceptionOutcome($e);
            }
            $this->pdo->exec('RELEASE SAVEPOINT arch04_domain');
            $result=self::attachTransportTrace($installationId,$requestId,$result,$state);
            $json=self::encode($result);
            $u=$this->pdo->prepare('UPDATE lan_staff_realtime_receipts SET state=?,result_json=?,error_code=?,completed_at=NOW() WHERE id=? AND state=\'processing\'');
            $u->execute([$state,$json,$error!==''?$error:null,$id]);
            if($u->rowCount()!==1)throw new \RuntimeException('atomic_realtime_receipt_finalize_failed');
            $receipt=['state'=>$state,'result'=>$result,'error_code'=>$error,'idempotent'=>false,'receipt_id'=>$id];
            if($afterResultTx!==null)$afterResultTx($receipt);
            $this->pdo->commit();
            return $receipt;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function result(string $installationId,string $requestId): ?array
    { $q=$this->pdo->prepare('SELECT * FROM lan_staff_realtime_receipts WHERE installation_id=? AND request_id=? LIMIT 1');$q->execute([$installationId,$requestId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?self::row($r,true):null; }
    public static function requestHash(array $value): string
    {
        $kind=trim((string)($value['kind']??''));
        if(in_array($kind,self::GUEST_TRANSPORT_KINDS,true)){
            // Public Guest retries deliberately permit transport timestamps to change.
            // Use the same logical identity as Public RealtimeService::enqueueGuest so
            // a Local-LAN commit can later converge through Public with the same
            // request_id instead of conflicting on created_at/expires_at.
            $logical=[
                'kind'=>$kind,
                'actor_projection_id'=>trim((string)($value['actor_projection_id']??'')),
                'payload'=>is_array($value['payload']??null)?$value['payload']:[],
            ];
            return hash('sha256',self::json($logical));
        }
        return self::legacyRequestHash($value);
    }

    private static function legacyRequestHash(array $value): string{return hash('sha256',self::json($value));}
    private static function requestHashMatches(string $stored,array $envelope,string $current): bool
    {
        if(hash_equals($stored,$current))return true;
        // Backward compatibility for pre-H339B Guest receipts that stored the full
        // envelope hash. Exact-envelope replay still resolves without rewriting rows.
        return in_array(trim((string)($envelope['kind']??'')),self::GUEST_TRANSPORT_KINDS,true)
            && hash_equals($stored,self::legacyRequestHash($envelope));
    }

    private function deadlineResult(array $envelope): ?array
    {
        $expiresRaw=trim((string)($envelope['expires_at']??''));$expiresTs=$expiresRaw!==''?(strtotime($expiresRaw)?:0):0;
        if($expiresTs>0&&$expiresTs>time())return null;
        $error=$expiresTs<=0?'invalid_command_deadline':'command_expired_before_execution';
        $result=['success'=>false,'code'=>$error,'message'=>$error==='command_expired_before_execution'?'مهلت اجرای این درخواست گذشته است؛ وضعیت را تازه کن و دوباره اقدام کن.':'مهلت اجرای درخواست معتبر نیست.'];
        return ['error_code'=>$error,'result'=>$result];
    }
    private function insertTerminalTx(string $installationId,string $requestId,string $hash,string $kind,string $actor,string $state,array $result,string $error): int
    {
        $q=$this->pdo->prepare('INSERT INTO lan_staff_realtime_receipts(installation_id,request_id,request_hash,kind,actor_projection_id,state,result_json,error_code,completed_at) VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)');
        $q->execute([$installationId,$requestId,$hash,$kind,$actor,$state,self::encode($result),$error!==''?$error:null]);
        return (int)$this->pdo->lastInsertId();
    }
    private function invoke(callable $handler): array
    {try{$value=$handler();return ['committed',is_array($value)?$value:[],''];}catch(Throwable $e){return $this->exceptionOutcome($e);}}
    private function exceptionOutcome(Throwable $e): array
    {
        $public=get_object_vars($e);$domainCode=trim((string)($public['errorCode']??''));
        if($domainCode!==''){
            $result=['success'=>false,'code'=>$domainCode,'message'=>$e->getMessage()];
            if(isset($public['details'])&&is_array($public['details'])&&$public['details']!==[])$result['details']=$public['details'];
            return ['rejected',$result,$domainCode];
        }
        // In dispatchAtomic all DB effects since the savepoint are rolled back before this
        // outcome is persisted, so a fresh unexpected exception is a known non-commit rather
        // than an "unknown outcome". Legacy processing rows remain the only ambiguous case.
        return ['rejected',['success'=>false,'code'=>'local_dispatch_exception_rolled_back','message'=>'اجرای درخواست در سامانه محلی کامل نشد و تغییرات آن بازگردانده شد.'],'local_dispatch_exception_rolled_back'];
    }
    private static function encode(array $result): string{$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    private static function json(mixed $v): string{if(is_array($v)){if(array_is_list($v))$v=array_map([self::class,'norm'],$v);else{$v=self::norm($v);}}$j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);return is_string($j)?$j:'{}';}
    private static function norm(mixed $v): mixed{if(!is_array($v))return $v;if(array_is_list($v))return array_map([self::class,'norm'],$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::norm($x);return $v;}
    private static function row(array $r,bool $idempotent): array{$x=json_decode((string)($r['result_json']??''),true);$state=(string)$r['state'];if($state==='processing')return ['state'=>'unknown_review','result'=>[],'error_code'=>'lan_direct_outcome_unknown','idempotent'=>true,'receipt_id'=>(int)$r['id']];return ['state'=>$state,'result'=>is_array($x)?$x:[],'error_code'=>(string)($r['error_code']??''),'idempotent'=>$idempotent,'receipt_id'=>(int)$r['id']];}
}
