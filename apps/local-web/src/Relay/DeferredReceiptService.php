<?php
declare(strict_types=1);

namespace Sokna\Local\Relay;

use PDO;
use RuntimeException;

final class DeferredReceiptService
{
    public function __construct(private readonly PDO $pdo) {}

    public function replayTx(string $installationId,array $envelope): ?array
    {
        $this->requireTx();
        $requestId=trim((string)($envelope['request_id']??''));
        $hash=self::requestHash($envelope);
        $stmt=$this->pdo->prepare(
            'SELECT * FROM deferred_work_receipts WHERE installation_id=? AND request_id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$installationId,$requestId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        if(!hash_equals((string)$row['request_hash'],$hash))
            throw new RuntimeException('شناسه Deferred با محتوای دیگری قبلاً ثبت شده است.');
        return self::rowResult($row,true);
    }

    public function committedTx(string $installationId,array $envelope,array $actor,array $result): array
    {
        return $this->insertTx($installationId,$envelope,$actor,'committed',$result,'',false);
    }

    public function rejectedTx(
        string $installationId,array $envelope,array $actor,string $errorCode,array $result=[]
    ): array {
        return $this->insertTx($installationId,$envelope,$actor,'rejected',$result,$errorCode,false);
    }

    public function reviewTx(
        string $installationId,array $envelope,array $actor,string $reasonCode,string $message,string $reviewType='conflict'
    ): array {
        $out=$this->insertTx(
            $installationId,$envelope,$actor,'needs_review',
            ['review_type'=>$reviewType,'message'=>$message],
            $reasonCode,false
        );
        $message=self::truncate(trim($message),500);
        $stmt=$this->pdo->prepare(
            "INSERT INTO deferred_review_items(receipt_id,review_type,financial_period_id,reason_code,message,state)
             VALUES(?,?,NULL,?,?,'pending')"
        );
        $stmt->execute([(int)$out['receipt_id'],$reviewType,$reasonCode,$message]);
        $reviewId=(int)$this->pdo->lastInsertId();
        $this->audit('deferred.needs_review','deferred_work_receipt',(int)$out['receipt_id'],(int)$actor['id'],[
            'request_id'=>$envelope['request_id']??'','kind'=>$envelope['kind']??'',
            'review_type'=>$reviewType,'reason_code'=>$reasonCode,'occurred_at'=>$envelope['occurred_at']??'',
        ]);
        $out['result']=['review_id'=>$reviewId,'message'=>$message];
        return $out;
    }

    public function pendingReviews(int $limit=100): array
    {
        $limit=max(1,min(250,$limit));
        return $this->pdo->query(
            "SELECT r.id review_id,r.review_type,r.financial_period_id,r.reason_code,r.message,r.created_at,
                    d.id receipt_id,d.installation_id,d.request_id,d.kind,d.actor_user_id,d.occurred_at,
                    u.display_name actor_name
             FROM deferred_review_items r
             JOIN deferred_work_receipts d ON d.id=r.receipt_id
             LEFT JOIN users u ON u.id=d.actor_user_id
             WHERE r.state='pending'
             ORDER BY r.created_at,r.id LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reviewLockedTx(int $reviewId): ?array
    {
        $this->requireTx();
        $stmt=$this->pdo->prepare(
            'SELECT r.*,d.installation_id,d.request_id,d.request_hash,d.kind,d.actor_projection_id,d.actor_user_id,
                    d.envelope_json,d.state receipt_state,d.result_json,d.error_code
             FROM deferred_review_items r
             JOIN deferred_work_receipts d ON d.id=r.receipt_id
             WHERE r.id=? FOR UPDATE'
        );
        $stmt->execute([$reviewId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function rejectReviewTx(array $review,int $reviewerUserId,string $reason): array
    {
        $this->requireTx();
        $reason=self::truncate(trim($reason),500);
        if($reason==='')throw new RuntimeException('دلیل تصمیم را ثبت کن.');
        if((string)$review['state']!=='pending')
            return ['state'=>(string)$review['state'],'idempotent'=>true];

        $this->pdo->prepare(
            "UPDATE deferred_review_items SET state='rejected',resolved_by_user_id=?,resolution_reason=?,resolved_at=NOW() WHERE id=?"
        )->execute([$reviewerUserId,$reason,(int)$review['id']]);
        $result=['review_id'=>(int)$review['id'],'resolution_reason'=>$reason];
        $this->pdo->prepare(
            "UPDATE deferred_work_receipts SET state='rejected',error_code='review_rejected',result_json=?,
             public_reconcile_pending=1 WHERE id=?"
        )->execute([self::json($result),(int)$review['receipt_id']]);
        $this->audit('deferred.review_rejected','deferred_review',(int)$review['id'],$reviewerUserId,[
            'request_id'=>$review['request_id'],'kind'=>$review['kind'],'reason'=>$reason,
        ]);
        return ['state'=>'rejected','result'=>$result,'error_code'=>'review_rejected','idempotent'=>false];
    }

    public function approveReviewTx(array $review,int $reviewerUserId,string $reason,array $result): array
    {
        $this->requireTx();
        $reason=self::truncate(trim($reason),500);
        if($reason==='')throw new RuntimeException('دلیل تصمیم را ثبت کن.');
        if((string)$review['state']!=='pending')
            return ['state'=>(string)$review['state'],'idempotent'=>true];

        $this->pdo->prepare(
            "UPDATE deferred_review_items SET state='approved',resolved_by_user_id=?,resolution_reason=?,resolved_at=NOW() WHERE id=?"
        )->execute([$reviewerUserId,$reason,(int)$review['id']]);
        $this->pdo->prepare(
            "UPDATE deferred_work_receipts SET state='committed',result_json=?,error_code=NULL,
             public_reconcile_pending=1,committed_at=NOW() WHERE id=?"
        )->execute([self::json($result),(int)$review['receipt_id']]);
        $this->audit('deferred.review_approved','deferred_review',(int)$review['id'],$reviewerUserId,[
            'request_id'=>$review['request_id'],'kind'=>$review['kind'],'reason'=>$reason,'result'=>$result,
        ]);
        return ['state'=>'committed','result'=>$result,'error_code'=>'','idempotent'=>false];
    }

    private function insertTx(
        string $installationId,array $envelope,array $actor,string $state,array $result,string $errorCode,bool $reconcile
    ): array {
        $this->requireTx();
        $requestId=trim((string)($envelope['request_id']??''));
        if($installationId===''||$requestId==='')
            throw new RuntimeException('شناسه اتصال یا درخواست Deferred کامل نیست.');
        $occurredTs=strtotime((string)($envelope['occurred_at']??''));
        if($occurredTs===false)throw new RuntimeException('زمان رخداد Deferred معتبر نیست.');

        $stmt=$this->pdo->prepare(
            'INSERT INTO deferred_work_receipts(installation_id,request_id,request_hash,kind,actor_projection_id,actor_user_id,
             occurred_at,envelope_json,state,result_json,error_code,financial_period_id,public_reconcile_pending,committed_at)
             VALUES(?,?,?,?,?,?,?, ?,?,?,?,NULL,?,?)'
        );
        $stmt->execute([
            $installationId,$requestId,self::requestHash($envelope),(string)($envelope['kind']??''),
            (string)($envelope['actor_projection_id']??''),(int)($actor['id']??0),
            date('Y-m-d H:i:s',$occurredTs),self::canonicalJson($envelope),$state,self::json($result),
            $errorCode!==''?$errorCode:null,$reconcile?1:0,$state==='committed'?date('Y-m-d H:i:s'):null,
        ]);
        $id=(int)$this->pdo->lastInsertId();
        $this->audit($state==='committed'?'deferred.committed':'deferred.rejected','deferred_work_receipt',$id,(int)($actor['id']??0),[
            'request_id'=>$requestId,'kind'=>$envelope['kind']??'','error_code'=>$errorCode,
        ]);
        return ['state'=>$state,'result'=>$result,'error_code'=>$errorCode,'receipt_id'=>$id,'idempotent'=>false];
    }

    private static function rowResult(array $row,bool $idempotent): array
    {
        $result=json_decode((string)($row['result_json']??''),true);
        return [
            'state'=>(string)$row['state'],'result'=>is_array($result)?$result:[],
            'error_code'=>(string)($row['error_code']??''),'receipt_id'=>(int)$row['id'],'idempotent'=>$idempotent,
        ];
    }

    public static function requestHash(array $envelope): string
    {
        return hash('sha256',self::canonicalJson($envelope));
    }

    public static function canonicalJson(array $value): string
    {
        $json=json_encode(self::normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
        if(!is_string($json))throw new RuntimeException('Deferred canonical JSON encoding failed.');
        return $json;
    }

    private static function normalize(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map([self::class,'normalize'],$value);
        ksort($value,SORT_STRING);
        foreach($value as $key=>$item)$value[$key]=self::normalize($item);
        return $value;
    }

    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void
    {
        $name=null;
        if($actorId>0){
            $stmt=$this->pdo->prepare('SELECT display_name FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$actorId]);$raw=$stmt->fetchColumn();$name=$raw===false?null:(string)$raw;
        }
        $this->pdo->prepare(
            'INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json)
             VALUES(?,?,?,?,?,?)'
        )->execute([$actorId>0?$actorId:null,$name,$action,$entityType,(string)$entityId,self::json($details)]);
    }

    private function requireTx(): void
    {
        if(!$this->pdo->inTransaction())throw new \LogicException('Deferred receipt mutation requires an open transaction.');
    }

    private static function json(array $value): string
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))throw new RuntimeException('Deferred JSON encoding failed.');
        return $json;
    }

    private static function truncate(string $value,int $length): string
    {
        return function_exists('mb_substr')?mb_substr($value,0,$length,'UTF-8'):substr($value,0,$length);
    }
}
