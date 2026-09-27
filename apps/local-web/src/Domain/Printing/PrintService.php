<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Printing;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class PrintService
{
    public const PROTOCOL_VERSION=4;
    public const LEASE_SECONDS=45;
    public const MAX_CLAIM=5;

    public function __construct(private readonly PDO $pdo,private readonly IdentityRepository $identity,private readonly PrintTemplatePackageService $templates){}

    public function createAgent(string $name,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $name=self::cut(trim($name),120);
            if($name==='')throw new PrintException('name_required','نام Print Agent لازم است.',422);
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$hint=substr($token,-8);
            $this->pdo->prepare('INSERT INTO print_agents(name,token_hash,token_hint) VALUES(?,?,?)')->execute([$name,$hash,$hint]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit('printing.agent_created','print_agent',$id,(int)$actor['id'],['name'=>$name,'token_hint'=>$hint]);
            $this->pdo->commit();
            return ['agent_id'=>$id,'token'=>$token,'token_hint'=>$hint];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }


    public function configureDestination(string $destinationKey,array $data,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);$destinationKey=self::cut(trim($destinationKey),40);
            $stmt=$this->pdo->prepare('SELECT * FROM print_destinations WHERE destination_key=? FOR UPDATE');$stmt->execute([$destinationKey]);$destination=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($destination))throw new PrintException('destination_not_found','مقصد چاپ پیدا نشد.',404);
            $agentId=max(0,(int)($data['agent_id']??0));$queue=self::cut(trim((string)($data['windows_queue_name']??'')),190);$active=!empty($data['active']);
            if($agentId>0){$a=$this->pdo->prepare('SELECT id FROM print_agents WHERE id=? AND active=1 AND retired_at IS NULL FOR UPDATE');$a->execute([$agentId]);if($a->fetchColumn()===false)throw new PrintException('agent_not_ready','Print Agent انتخاب‌شده فعال نیست.',409);}
            if($active&&($agentId<1||$queue===''))throw new PrintException('destination_incomplete','برای فعال‌کردن مقصد چاپ، Agent و صف ویندوز را مشخص کن.',422);
            $copies=max(1,min(5,(int)($data['copies']??1)));$paper=max(40.0,min(120.0,(float)($data['paper_width_mm']??80)));$printable=max(30.0,min($paper,(float)($data['printable_width_mm']??72.1)));
            $layout=in_array((string)($data['layout_mode']??'combined'),['combined','separate'],true)?(string)($data['layout_mode']??'combined'):'combined';
            $this->pdo->prepare('UPDATE print_destinations SET agent_id=?,windows_queue_name=?,active=?,copies=?,paper_width_mm=?,printable_width_mm=?,layout_mode=? WHERE destination_key=?')
                ->execute([$agentId>0?$agentId:null,$queue!==''?$queue:null,$active?1:0,$copies,$paper,$printable,$layout,$destinationKey]);
            $this->audit('printing.destination_configured','print_destination',0,(int)$actor['id'],['destination_key'=>$destinationKey,'agent_id'=>$agentId?:null,'queue'=>$queue?:null,'active'=>$active,'copies'=>$copies]);
            $this->pdo->commit();return ['destination_key'=>$destinationKey,'active'=>$active,'agent_id'=>$agentId?:null,'windows_queue_name'=>$queue?:null];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function enqueueTest(string $destinationKey,string $requestId,array $user,?int $packageId=null): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);$destinationKey=self::cut(trim($destinationKey),40);$requestId=self::requestId($requestId);
            $stmt=$this->pdo->prepare('SELECT destination_type,label,active FROM print_destinations WHERE destination_key=? FOR UPDATE');$stmt->execute([$destinationKey]);$destination=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($destination)||(int)$destination['active']!==1)throw new PrintException('destination_not_ready','مقصد چاپ برای تست فعال نیست.',409);
            $prep=(string)$destination['destination_type']==='preparation';
            $payload=$prep
                ?['schema'=>'sokna-print-document-v2','document_kind'=>'preparation','title'=>'کافه سکنا','badge'=>'چاپ آزمایشی','table_name'=>'تست','order_number'=>'TEST','created_at'=>date('Y-m-d H:i:s'),'sections'=>[['title'=>'آزمون','items'=>[['name'=>'اگر این متن خواناست، مسیر چاپ آماده است','quantity'=>1,'note'=>'چاپ آزمایشی سکنا']]]]]
                :['schema'=>'sokna-print-document-v2','document_kind'=>'customer','title'=>'کافه سکنا','invoice_number'=>'TEST','created_at'=>date('Y-m-d H:i:s'),'currency'=>'تومان','sections'=>[['items'=>[['name'=>'چاپ آزمایشی سکنا','quantity'=>1,'unit_price'=>0,'line_total'=>0]]]],'subtotal'=>0,'discount'=>0,'tax'=>0,'total'=>0,'footer'=>'این برگه فقط برای آزمون مسیر چاپ است.'];
            $payload=$packageId!==null&&$packageId>0?$this->templates->applyPackage($payload,$packageId):$this->templates->applyActive($payload);
            $job=$this->enqueueTx($prep?'preparation_test':'customer_test',$destinationKey,$payload,'print_test',$requestId,(int)$actor['id'],'print:test:'.$requestId,false);
            $this->audit('printing.test_enqueued','print_job',(int)($job['job_id']??0),(int)$actor['id'],['destination_key'=>$destinationKey,'request_id'=>$requestId]);
            $this->pdo->commit();return $job;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function authenticate(string $bearer): array
    {
        $token=trim(preg_replace('/^Bearer\s+/i','',$bearer)??'');
        if(strlen($token)<32)throw new PrintException('unauthorized','Print Agent احراز هویت نشد.',401);
        $stmt=$this->pdo->prepare('SELECT * FROM print_agents WHERE token_hash=? AND active=1 AND retired_at IS NULL LIMIT 1');
        $stmt->execute([hash('sha256',$token)]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new PrintException('unauthorized','Print Agent احراز هویت نشد.',401);
        return $row;
    }

    public function probe(array $agent,string $agentVersion): array
    {
        return [
            'success'=>true,'protocol_version'=>self::PROTOCOL_VERSION,'minimum_agent_version'=>'6.2.5',
            'recommended_agent_version'=>'6.2.5','server_time'=>gmdate('c'),
            'destinations'=>$this->destinationsForAgent((int)$agent['id']),
            'capabilities'=>['durable_claim','durable_accept','attempt_status','submission_fence','unknown_resolution','local_wake_v1','preview_bridge_v1'],
            'agent_version'=>$agentVersion,
        ];
    }

    public function heartbeat(array $agent,array $data): array
    {
        $printers=is_array($data['printers']??null)?$data['printers']:[];
        $health=is_array($data['health']??null)?$data['health']:[];
        $this->pdo->prepare(
            'UPDATE print_agents SET hostname=?,agent_version=?,os_version=?,printers_json=?,health_json=?,bridge_protocol_version=?,bridge_port=?,bridge_pairing_id=?,bridge_origin=?,bridge_runtime_seen_at=NOW(),uptime_seconds=?,local_backlog_count=?,local_unknown_count=?,last_submission_at=?,sqlite_health=?,disk_free_mb=?,last_heartbeat_at=NOW(),last_seen_at=NOW(),last_error=NULL WHERE id=?'
        )->execute([
            self::nullable($data['hostname']??null,190),self::nullable($data['agent_version']??null,40),self::nullable($data['os_version']??null,190),
            self::json($printers),self::json($health),max(0,(int)($data['bridge_protocol_version']??0)),max(0,(int)($data['bridge_port']??0)),
            self::nullable($data['bridge_pairing_id']??null,128),self::nullable($data['bridge_origin']??null,240),max(0,(int)($data['uptime_seconds']??0)),
            max(0,(int)($data['local_backlog_count']??0)),max(0,(int)($data['local_unknown_count']??0)),self::nullable($data['last_submission_at']??null,30),
            self::nullable($data['sqlite_health']??null,30),max(0,(int)($data['disk_free_mb']??0)),(int)$agent['id']
        ]);
        return ['success'=>true,'server_time'=>gmdate('c'),'destinations'=>$this->destinationsForAgent((int)$agent['id'])];
    }

    public function enqueueTx(string $jobType,string $destinationKey,array $payload,string $entityType,?string $entityId,?int $actorUserId,string $idempotencyKey,bool $required=false): array
    {
        $this->requireTx();
        if(!$this->enabledTx())return ['job_id'=>null,'status'=>'disabled','duplicate'=>false];
        $jobType=self::cut(trim($jobType),40);$destinationKey=self::cut(trim($destinationKey),40);$entityType=self::cut(trim($entityType),60);
        $idempotencyKey=self::cut(trim($idempotencyKey),190);
        if($jobType===''||$destinationKey===''||$entityType===''||$idempotencyKey==='')throw new PrintException('invalid_job','درخواست چاپ معتبر نیست.',422);
        $json=self::json($payload);$hash=hash('sha256',$json);
        $dup=$this->pdo->prepare('SELECT id,status,content_sha256,destination_key FROM print_jobs WHERE idempotency_key=? LIMIT 1 FOR UPDATE');
        $dup->execute([$idempotencyKey]);$row=$dup->fetch(PDO::FETCH_ASSOC);
        if(is_array($row)){
            if(!hash_equals((string)$row['content_sha256'],$hash)||(string)$row['destination_key']!==$destinationKey)
                throw new PrintException('idempotency_conflict','شناسه چاپ قبلاً برای محتوای دیگری استفاده شده است.',409);
            return ['job_id'=>(int)$row['id'],'status'=>(string)$row['status'],'duplicate'=>true];
        }
        $dest=$this->pdo->prepare('SELECT * FROM print_destinations WHERE destination_key=? LIMIT 1 FOR UPDATE');$dest->execute([$destinationKey]);$d=$dest->fetch(PDO::FETCH_ASSOC);
        $status=is_array($d)&&(int)$d['active']===1?'pending':'blocked';$blocked=$status==='blocked'?'destination_not_ready':null;
        $token=bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO print_jobs(public_token,idempotency_key,contract_version,job_type,destination_key,required,status,blocked_reason,payload_json,content_sha256,entity_type,entity_id,requested_by_user_id,next_attempt_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([$token,$idempotencyKey,self::PROTOCOL_VERSION,$jobType,$destinationKey,$required?1:0,$status,$blocked,$json,$hash,$entityType,$entityId,$actorUserId]);
        return ['job_id'=>(int)$this->pdo->lastInsertId(),'status'=>$status,'duplicate'=>false];
    }

    public function enqueueOrderTx(int $orderId,?int $actorUserId=null): ?int
    {
        $this->requireTx();
        try{
            $stmt=$this->pdo->prepare(
                'SELECT o.id,o.business_order_number,o.public_code,o.customer_note,o.created_at,o.order_context,o.table_id,'.
                't.name table_name,sc.consumer_personnel_id,sc.consumer_name_snapshot '.
                'FROM orders o LEFT JOIN cafe_tables t ON t.id=o.table_id LEFT JOIN staff_consumptions sc ON sc.order_id=o.id WHERE o.id=? LIMIT 1'
            );
            $stmt->execute([$orderId]);$order=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($order))return null;
            $context=(string)($order['order_context']??'table_service');
            if($context==='staff_consumption'&&(int)($order['consumer_personnel_id']??0)<1)return null;
            $items=$this->pdo->prepare("SELECT item_name,quantity,item_note,fulfillment_mode,preparation_station FROM order_items WHERE order_id=? AND quantity>0 AND preparation_station<>'none' ORDER BY id");
            $items->execute([$orderId]);$rows=$items->fetchAll(PDO::FETCH_ASSOC);if(!$rows)return null;
            $consumer=trim((string)($order['consumer_name_snapshot']??''));
            $display=$context==='staff_consumption'?'پرسنل: '.($consumer!==''?$consumer:'—'):(string)($order['table_name']??'');
            $payload=[
                'document_kind'=>'preparation','order_id'=>$orderId,'order_number'=>(int)$order['business_order_number'],
                'order_context'=>$context,'table_name'=>$display,'badge'=>$context==='staff_consumption'?'مصرف پرسنل':'فیش آماده‌سازی',
                'consumer_personnel_id'=>$context==='staff_consumption'?(int)$order['consumer_personnel_id']:null,
                'consumer_name'=>$context==='staff_consumption'?$consumer:null,'customer_note'=>$order['customer_note'],
                'items'=>$rows,'created_at'=>(string)$order['created_at'],
            ];
            $payload=$this->templates->applyActive($payload);
            $r=$this->enqueueTx('preparation','prep_shared',$payload,'order',(string)$orderId,$actorUserId,'prep:order:'.$orderId,false);
            return $r['job_id']===null?null:(int)$r['job_id'];
        }catch(Throwable){return null;}
    }

    public function enqueueSettlementTx(int $settlementId,?int $actorUserId=null): ?int
    {
        $this->requireTx();
        try{
            $stmt=$this->pdo->prepare('SELECT id,invoice_number,invoice_snapshot_json,destination,status FROM settlement_records WHERE id=? LIMIT 1');
            $stmt->execute([$settlementId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($row)||(string)$row['status']!=='completed')return null;
            $snapshot=json_decode((string)$row['invoice_snapshot_json'],true);if(!is_array($snapshot))return null;
            $items=[];foreach((array)($snapshot['items']??[]) as $line)$items[]=['name'=>(string)($line['name']??'—'),'quantity'=>(int)($line['quantity']??1),'unit_price'=>(int)($line['unit_price']??0),'line_total'=>(int)($line['line_total']??0),'note'=>$line['note']??null];
            $payload=['schema'=>'sokna-print-document-v2','document_kind'=>'customer','settlement_id'=>$settlementId,'invoice_number'=>(string)$row['invoice_number'],'settlement_destination'=>(string)$row['destination'],'table_name'=>(string)($snapshot['table_name']??''),'created_at'=>(string)($snapshot['issued_at']??date(DATE_ATOM)),'currency'=>'تومان','sections'=>[['items'=>$items]],'subtotal'=>(int)($snapshot['subtotal']??0),'discount'=>(int)($snapshot['discount']??0),'taxable'=>(int)($snapshot['taxable']??0),'tax'=>(int)($snapshot['tax']??0),'total'=>(int)($snapshot['total']??0),'settlement_label'=>(string)$row['destination']];
            $payload=$this->templates->applyActive($payload);
            $r=$this->enqueueTx('customer_receipt','customer_receipt',$payload,'settlement',(string)$settlementId,$actorUserId,'receipt:settlement:'.$settlementId,false);
            if($r['job_id']!==null)$this->pdo->prepare('UPDATE settlement_records SET final_print_job_id=COALESCE(final_print_job_id,?) WHERE id=?')->execute([(int)$r['job_id'],$settlementId]);
            return $r['job_id']===null?null:(int)$r['job_id'];
        }catch(Throwable){return null;}
    }

    public function claim(array $agent,array $data): array
    {
        $this->pdo->beginTransaction();
        try{$r=$this->claimTx($agent,$data);$this->pdo->commit();return $r;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function claimTx(array $agent,array $data): array
    {
        $this->requireTx();$agentId=(int)$agent['id'];$requestId=self::requestId((string)($data['request_id']??''));$version=self::cut((string)($data['agent_version']??''),40);
        $limit=max(1,min(self::MAX_CLAIM,(int)($data['limit']??1)));$ready=self::stringList((array)($data['ready_destination_keys']??[]),40);
        $hash=self::hash(['request_id'=>$requestId,'agent_version'=>$version,'protocol_version'=>(int)($data['protocol_version']??0),'limit'=>$limit,'ready_destination_keys'=>$ready]);
        $existing=$this->pdo->prepare('SELECT * FROM print_claim_requests WHERE agent_id=? AND request_id=? LIMIT 1 FOR UPDATE');$existing->execute([$agentId,$requestId]);$claim=$existing->fetch(PDO::FETCH_ASSOC);
        if(is_array($claim)){
            if(!hash_equals((string)$claim['request_hash'],$hash))throw new PrintException('request_body_conflict','request_id قبلاً با بدنه دیگری استفاده شده است.',409);
            $snapshot=json_decode((string)($claim['response_snapshot_json']??''),true);if(!is_array($snapshot))throw new PrintException('claim_snapshot_invalid','Snapshot پایدار Claim معتبر نیست.',409);
            $snapshot['idempotent']=true;return $snapshot;
        }
        $this->pdo->prepare('INSERT INTO print_claim_requests(agent_id,request_id,request_hash,agent_version) VALUES(?,?,?,?)')->execute([$agentId,$requestId,$hash,$version]);$claimRowId=(int)$this->pdo->lastInsertId();
        $jobs=[];$attemptIds=[];
        if($ready){
            $ph=implode(',',array_fill(0,count($ready),'?'));$sql="SELECT j.*,d.windows_queue_name,d.paper_width_mm,d.printable_width_mm,d.copies,d.layout_mode FROM print_jobs j JOIN print_destinations d ON d.destination_key=j.destination_key WHERE j.status='pending' AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=NOW()) AND j.destination_key IN($ph) AND d.active=1 AND (d.agent_id IS NULL OR d.agent_id=?) ORDER BY j.id LIMIT {$limit} FOR UPDATE";
            $stmt=$this->pdo->prepare($sql);$stmt->execute([...$ready,$agentId]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $job){
                $attemptNo=(int)$job['attempt_count']+1;if($attemptNo>5)continue;
                $leaseToken=bin2hex(random_bytes(32));$leaseHash=hash('sha256',$leaseToken);$expiry=date('Y-m-d H:i:s',time()+self::LEASE_SECONDS);
                $dest=['destination_key'=>(string)$job['destination_key'],'windows_queue_name'=>(string)($job['windows_queue_name']??''),'paper_width_mm'=>(float)$job['paper_width_mm'],'printable_width_mm'=>(float)$job['printable_width_mm'],'copies'=>(int)$job['copies'],'layout_mode'=>(string)$job['layout_mode']];
                $this->pdo->prepare("INSERT INTO print_attempts(job_id,attempt_no,retry_cycle,cycle_attempt_no,agent_id,state,claim_request_id,lease_token_hash,lease_expires_at,destination_snapshot_json,leased_at) VALUES(?,?,?,?,?,'reserved',?,?,?,?,NOW())")
                    ->execute([(int)$job['id'],$attemptNo,(int)$job['retry_cycle'],$attemptNo,$agentId,$requestId,$leaseHash,$expiry,self::json($dest)]);
                $attemptId=(int)$this->pdo->lastInsertId();$attemptIds[]=$attemptId;
                $this->pdo->prepare("UPDATE print_jobs SET status='reserved',attempt_count=?,claimed_by_agent_id=?,claimed_at=NOW(),lease_expires_at=? WHERE id=? AND status='pending'")->execute([$attemptNo,$agentId,$expiry,(int)$job['id']]);
                $jobs[]=['attempt_id'=>$attemptId,'job_id'=>(int)$job['id'],'public_token'=>(string)$job['public_token'],'lease_token'=>$leaseToken,'lease_expires_at'=>gmdate('c',strtotime($expiry)),'job_type'=>(string)$job['job_type'],'destination'=>$dest,'payload'=>json_decode((string)$job['payload_json'],true),'content_sha256'=>(string)$job['content_sha256']];
            }
        }
        $response=['success'=>true,'request_id'=>$requestId,'jobs'=>$jobs,'server_time'=>gmdate('c'),'idempotent'=>false];
        $this->pdo->prepare('UPDATE print_claim_requests SET attempt_ids_json=?,response_snapshot_json=? WHERE id=?')->execute([self::json($attemptIds),self::json($response),$claimRowId]);
        return $response;
    }

    public function attemptStatus(array $agent,array $data): array
    {
        $this->pdo->beginTransaction();
        try{$a=$this->lockedAttempt((int)$agent['id'],(int)($data['attempt_id']??0),(string)($data['lease_token']??''));$receipt=trim((string)($data['local_receipt_id']??''));$state=(string)$a['state'];$terminal=in_array($state,['submitted','failed','unknown','recovery_hold','cancelled','expired'],true);$next=match($state){'reserved'=>'accept','claimed'=>'start','started'=>'report',default=>'none'};$r=['success'=>true,'attempt_id'=>(int)$a['id'],'job_id'=>(int)$a['job_id'],'attempt_state'=>$state,'job_state'=>(string)$a['job_status'],'receipt_matches'=>$receipt!==''&&hash_equals((string)($a['local_receipt_id']??''),$receipt),'next_action'=>$next,'terminal'=>$terminal,'requires_human_resolution'=>in_array($state,['unknown','recovery_hold'],true)&&empty($a['resolved_at']),'lease_expires_at'=>gmdate('c',strtotime((string)$a['lease_expires_at'])),'server_time'=>gmdate('c')];$this->pdo->commit();return $r;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function renew(array $agent,array $data): array { return $this->mutateAttempt($agent,$data,'renew'); }
    public function accept(array $agent,array $data): array { return $this->mutateAttempt($agent,$data,'accept'); }
    public function start(array $agent,array $data): array { return $this->mutateAttempt($agent,$data,'start'); }
    public function report(array $agent,array $data): array { return $this->mutateAttempt($agent,$data,'report'); }

    public function resolveAmbiguous(int $jobId,string $resolution,string $reason,array $user): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);if(!in_array($resolution,['submitted','retry','cancelled'],true))throw new PrintException('invalid_resolution','تصمیم چاپ معتبر نیست.',422);
            $reason=self::cut(trim($reason),500);if($reason==='')throw new PrintException('reason_required','دلیل تصمیم چاپ لازم است.',422);
            $stmt=$this->pdo->prepare("SELECT * FROM print_jobs WHERE id=? AND status IN('unknown','recovery_hold') FOR UPDATE");$stmt->execute([$jobId]);$job=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($job))throw new PrintException('job_not_ambiguous','کار چاپ مبهم پیدا نشد.',404);
            if($resolution==='retry')$this->pdo->prepare("UPDATE print_jobs SET status='pending',claimed_by_agent_id=NULL,claimed_at=NULL,lease_expires_at=NULL,accepted_at=NULL,local_receipt_id=NULL,next_attempt_at=NOW(),resolution_state='manual_retry',resolved_at=NOW(),resolved_by_user_id=?,resolution_note=? WHERE id=?")->execute([(int)$actor['id'],$reason,$jobId]);
            else $this->pdo->prepare('UPDATE print_jobs SET status=?,resolution_state=?,resolved_at=NOW(),resolved_by_user_id=?,resolution_note=?,completed_at=IF(?=\'submitted\',NOW(),completed_at) WHERE id=?')->execute([$resolution,'manual_'.$resolution,(int)$actor['id'],$reason,$resolution,$jobId]);
            $this->audit('printing.ambiguous_resolved','print_job',$jobId,(int)$actor['id'],['resolution'=>$resolution,'reason'=>$reason]);$this->pdo->commit();return ['job_id'=>$jobId,'resolution'=>$resolution];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function mutateAttempt(array $agent,array $data,string $action): array
    {
        $this->pdo->beginTransaction();
        try{
            $requestId=self::requestId((string)($data['request_id']??''));$attempt=$this->lockedAttempt((int)$agent['id'],(int)($data['attempt_id']??0),(string)($data['lease_token']??''));
            $field=$action.'_request_id';$hashField=$action.'_request_hash';$requestHash=self::hash($data);
            if(!empty($attempt[$field])){
                if((string)$attempt[$field]!==$requestId||!hash_equals((string)($attempt[$hashField]??''),$requestHash))throw new PrintException('request_body_conflict','request_id قبلاً با بدنه متفاوت استفاده شده است.',409);
                $this->pdo->commit();return ['success'=>true,'status'=>(string)$attempt['state'],'idempotent'=>true];
            }
            $state=(string)$attempt['state'];$attemptId=(int)$attempt['id'];$jobId=(int)$attempt['job_id'];
            if($action==='renew'){
                if($state!=='reserved'||strtotime((string)$attempt['lease_expires_at'])<time())throw new PrintException('lease_expired','Lease قابل تمدید نیست.',409);
                $expiry=date('Y-m-d H:i:s',time()+self::LEASE_SECONDS);$this->pdo->prepare('UPDATE print_attempts SET lease_expires_at=?,renew_request_id=?,renew_request_hash=? WHERE id=?')->execute([$expiry,$requestId,$requestHash,$attemptId]);$this->pdo->prepare("UPDATE print_jobs SET lease_expires_at=? WHERE id=? AND status='reserved'")->execute([$expiry,$jobId]);$this->pdo->commit();return ['success'=>true,'status'=>'reserved','lease_expires_at'=>gmdate('c',strtotime($expiry)),'idempotent'=>false];
            }
            if($action==='accept'){
                if($state!=='reserved'||strtotime((string)$attempt['lease_expires_at'])<time())throw new PrintException('lease_expired','Lease پیش از Accept منقضی شده است.',409);
                $receipt=self::cut(trim((string)($data['local_receipt_id']??'')),96);$content=strtolower(trim((string)($data['content_sha256']??'')));
                if(strlen($receipt)<8||!preg_match('/^[a-f0-9]{64}$/',$content)||!hash_equals((string)$attempt['content_sha256'],$content))throw new PrintException('content_fence_mismatch','Receipt یا hash محتوا با رزرو چاپ سازگار نیست.',409);
                $this->pdo->prepare("UPDATE print_attempts SET state='claimed',local_receipt_id=?,accept_request_id=?,accept_request_hash=?,accepted_at=NOW() WHERE id=? AND state='reserved'")->execute([$receipt,$requestId,$requestHash,$attemptId]);
                $this->pdo->prepare("UPDATE print_jobs SET status='claimed',accepted_at=NOW(),local_receipt_id=?,lease_expires_at=NULL WHERE id=? AND status='reserved'")->execute([$receipt,$jobId]);$this->pdo->commit();return ['success'=>true,'status'=>'claimed','idempotent'=>false];
            }
            if($action==='start'){
                if($state!=='claimed')throw new PrintException('invalid_transition','فقط Attempt پذیرفته‌شده قابل شروع است.',409);
                $this->pdo->prepare("UPDATE print_attempts SET state='started',start_request_id=?,start_request_hash=?,started_at=NOW() WHERE id=?")->execute([$requestId,$requestHash,$attemptId]);$this->pdo->prepare("UPDATE print_jobs SET status='started' WHERE id=? AND status='claimed'")->execute([$jobId]);$this->pdo->commit();return ['success'=>true,'status'=>'started','idempotent'=>false];
            }
            if($action==='report'){
                $status=(string)($data['status']??'');if(!in_array($status,['submitted','failed','unknown','recovery_hold'],true))throw new PrintException('invalid_status','وضعیت گزارش چاپ معتبر نیست.',422);
                $receipt=trim((string)($data['local_receipt_id']??''));if($receipt===''||!hash_equals((string)($attempt['local_receipt_id']??''),$receipt))throw new PrintException('local_receipt_conflict','Receipt محلی سازگار نیست.',409);
                if(!in_array($state,['started','unknown','recovery_hold'],true))throw new PrintException('invalid_transition','Attempt در وضعیت قابل گزارش نیست.',409);
                $spool=self::nullable($data['spooler_job_id']??null,96);$error=self::nullable($data['error_code']??null,80);$msg=self::nullable($data['error_message']??null,500);$retryable=!empty($data['retryable']);
                $this->pdo->prepare("UPDATE print_attempts SET state=?,report_request_id=?,report_request_hash=?,spooler_job_id=?,submitted_at=IF(?='submitted',NOW(),submitted_at),finished_at=NOW(),outcome=?,error_code=?,error_message=? WHERE id=?")->execute([$status,$requestId,$requestHash,$spool,$status,$status,$error,$msg,$attemptId]);
                if($status==='submitted')$this->pdo->prepare("UPDATE print_jobs SET status='submitted',submitted_at=NOW(),completed_at=NOW(),last_error_code=NULL,last_error=NULL,next_attempt_at=NULL WHERE id=?")->execute([$jobId]);
                elseif(in_array($status,['unknown','recovery_hold'],true))$this->pdo->prepare('UPDATE print_jobs SET status=?,last_error_code=?,last_error=?,next_attempt_at=NULL WHERE id=?')->execute([$status,$error?:$status,$msg?:'نتیجه چاپ قابل اثبات نیست.',$jobId]);
                elseif($retryable)$this->pdo->prepare("UPDATE print_jobs SET status='pending',claimed_by_agent_id=NULL,claimed_at=NULL,accepted_at=NULL,local_receipt_id=NULL,next_attempt_at=DATE_ADD(NOW(),INTERVAL 10 SECOND),last_error_code=?,last_error=? WHERE id=?")->execute([$error?:'pre_submit_failure',$msg?:'خطای قابل Retry پیش از submission fence.',$jobId]);
                else $this->pdo->prepare("UPDATE print_jobs SET status='failed',last_error_code=?,last_error=?,next_attempt_at=NULL WHERE id=?")->execute([$error?:'pre_submit_failure',$msg?:'خطای قطعی چاپ.',$jobId]);
                $this->pdo->commit();return ['success'=>true,'status'=>$status,'requires_human_resolution'=>in_array($status,['unknown','recovery_hold'],true),'idempotent'=>false];
            }
            throw new PrintException('unsupported_action','عملیات چاپ پشتیبانی نمی‌شود.',422);
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function lockedAttempt(int $agentId,int $attemptId,string $leaseToken): array
    {
        if($attemptId<1||strlen(trim($leaseToken))<16)throw new PrintException('invalid_attempt','Attempt معتبر نیست.',422);
        $stmt=$this->pdo->prepare('SELECT a.*,j.status job_status,j.content_sha256,j.resolved_at FROM print_attempts a JOIN print_jobs j ON j.id=a.job_id WHERE a.id=? AND a.agent_id=? FOR UPDATE');$stmt->execute([$attemptId,$agentId]);$a=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($a))throw new PrintException('attempt_not_found','Attempt پیدا نشد.',404);if(!hash_equals((string)$a['lease_token_hash'],hash('sha256',trim($leaseToken))))throw new PrintException('invalid_lease','Lease معتبر نیست.',403);return $a;
    }

    private function destinationsForAgent(int $agentId): array
    {
        $stmt=$this->pdo->prepare('SELECT destination_key,label,destination_type,windows_queue_name,active,paper_width_mm,printable_width_mm,copies,layout_mode FROM print_destinations WHERE agent_id=? OR fallback_agent_id=? ORDER BY destination_key');$stmt->execute([$agentId,$agentId]);return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private function enabledTx(): bool { $stmt=$this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='module.printing.enabled' LIMIT 1");$stmt->execute();$v=$stmt->fetchColumn();return $v===false||in_array(strtolower(trim((string)$v)),['1','true','yes','on'],true); }
    private function assertAdmin(array $user): array { $fresh=$this->identity->findActiveById((int)($user['id']??0));if($fresh===null||(string)($fresh['role']??'')!=='admin')throw new PrintException('forbidden','فقط مدیر فعال مجاز است.',403);return $fresh; }
    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void { $n=$this->pdo->prepare('SELECT display_name FROM users WHERE id=?');$n->execute([$actorId]);$name=$n->fetchColumn();$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')->execute([$actorId,$name!==false?$name:null,$action,$entityType,(string)$entityId,self::json($details)]); }
    private function requireTx(): void { if(!$this->pdo->inTransaction())throw new \LogicException('Print operation requires an open transaction.'); }
    private static function requestId(string $v): string { $v=trim($v);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/',$v))throw new PrintException('invalid_request_id','request_id معتبر نیست.',422);return $v; }
    private static function stringList(array $a,int $len): array { $o=[];foreach($a as $v){$v=self::cut(trim((string)$v),$len);if($v!==''&&!in_array($v,$o,true))$o[]=$v;}sort($o,SORT_STRING);return $o; }
    private static function hash(array $v): string { return hash('sha256',self::json(self::sortRecursive($v))); }
    private static function sortRecursive(mixed $v): mixed { if(!is_array($v))return $v;if(array_is_list($v))return array_map([self::class,'sortRecursive'],$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::sortRecursive($x);return $v; }
    private static function json(mixed $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function cut(string $v,int $n): string { return function_exists('mb_substr')?mb_substr($v,0,$n,'UTF-8'):substr($v,0,$n); }
    private static function nullable(mixed $v,int $n): ?string { $s=self::cut(trim((string)$v),$n);return $s===''?null:$s; }
}
