<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Printing;

use PDO;
use Sokna\Local\Core\IdentityRepository;
use Throwable;

final class PrintService
{
    public const PROTOCOL_VERSION=4;
    public const MINIMUM_AGENT_VERSION='6.2.5';
    public const RECOMMENDED_AGENT_VERSION='6.2.9';
    public const LEASE_SECONDS=45;
    public const MAX_CLAIM=5;
    public const MAX_ATTEMPTS_PER_CYCLE=5;
    public const AGENT_FRESH_SECONDS=180;

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


    public function prepareAgentTokenRotation(int $agentId,array $user): array
    {
        if($agentId<1)throw new PrintException('invalid_rotation','Print Agent معتبر نیست.',422);
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);
            $stmt=$this->pdo->prepare('SELECT id,name,active,retired_at FROM print_agents WHERE id=? FOR UPDATE');$stmt->execute([$agentId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||(int)($row['active']??0)!==1||!empty($row['retired_at']))throw new PrintException('agent_not_active','Print Agent فعال پیدا نشد.',404);
            $token=bin2hex(random_bytes(32));$hint=substr($token,-8);
            $this->audit('printing.agent_token_rotation_prepared','print_agent',$agentId,(int)$actor['id'],['name'=>(string)($row['name']??''),'token_hint'=>$hint]);
            $this->pdo->commit();
            return ['agent_id'=>$agentId,'name'=>(string)($row['name']??''),'token'=>$token,'token_hint'=>$hint];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function commitAgentTokenRotation(int $agentId,string $token,int $actorId): array
    {
        $token=trim($token);
        if($agentId<1||strlen($token)<32)throw new PrintException('invalid_rotation','اطلاعات چرخش توکن معتبر نیست.',422);
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin(['id'=>$actorId]);
            $stmt=$this->pdo->prepare('SELECT id,name,active,retired_at,token_hash FROM print_agents WHERE id=? FOR UPDATE');$stmt->execute([$agentId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||(int)($row['active']??0)!==1||!empty($row['retired_at']))throw new PrintException('agent_not_active','Print Agent فعال پیدا نشد.',404);
            $hash=hash('sha256',$token);$hint=substr($token,-8);$idempotent=hash_equals((string)$row['token_hash'],$hash);
            if(!$idempotent)$this->pdo->prepare('UPDATE print_agents SET token_hash=?,token_hint=?,last_error=NULL WHERE id=?')->execute([$hash,$hint,$agentId]);
            $this->audit('printing.agent_token_rotated','print_agent',$agentId,(int)$actor['id'],['name'=>(string)($row['name']??''),'token_hint'=>$hint,'idempotent'=>$idempotent]);
            $this->pdo->commit();
            return ['agent_id'=>$agentId,'token_hint'=>$hint,'idempotent'=>$idempotent];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function activeAgentSummaries(): array
    {
        $rows=$this->pdo->query("SELECT id,name,hostname,agent_version,last_heartbeat_at,created_at FROM print_agents WHERE active=1 AND retired_at IS NULL ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter($rows,'is_array'));
    }

    public function retireAgentByAdmin(int $agentId,array $user,string $reason='operator_removed'): array
    {
        $actor=$this->assertAdmin($user);
        return $this->retireAgent($agentId,(int)$actor['id'],$reason);
    }

    public function retireAgent(int $agentId,int $actorId,string $reason='pairing_canceled'): array
    {
        if($agentId<1)return ['agent_id'=>$agentId,'retired'=>false,'idempotent'=>true,'destinations_disabled'=>0,'fallbacks_cleared'=>0,'fallbacks_promoted'=>0];
        $reason=self::cut(trim($reason),80);if($reason==='')$reason='retired';
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT id,name,active,retired_at FROM print_agents WHERE id=? FOR UPDATE');$stmt->execute([$agentId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)){ $this->pdo->commit(); return ['agent_id'=>$agentId,'retired'=>false,'idempotent'=>true,'not_found'=>true,'destinations_disabled'=>0,'fallbacks_cleared'=>0,'fallbacks_promoted'=>0]; }
            if((int)($row['active']??0)!==1&&!empty($row['retired_at'])){ $this->pdo->commit(); return ['agent_id'=>$agentId,'retired'=>true,'idempotent'=>true,'destinations_disabled'=>0,'fallbacks_cleared'=>0,'fallbacks_promoted'=>0]; }

            $inflight=$this->pdo->prepare("SELECT id,status FROM print_jobs WHERE claimed_by_agent_id=? AND status IN ('reserved','claimed','started','unknown','recovery_hold') FOR UPDATE");
            $inflight->execute([$agentId]);$busy=$inflight->fetchAll(PDO::FETCH_ASSOC);
            if($busy!==[])throw new PrintException('agent_has_inflight_jobs','این Print Agent هنوز کار چاپ در حال اجرا یا مبهم دارد. ابتدا وضعیت آن کارها را تعیین تکلیف کن.',409,['agent_id'=>$agentId,'job_ids'=>array_map(static fn(array $r):int=>(int)$r['id'],$busy)]);

            $dest=$this->pdo->prepare('SELECT destination_key,agent_id,windows_queue_name,fallback_agent_id,fallback_windows_queue_name,active FROM print_destinations WHERE agent_id=? OR fallback_agent_id=? FOR UPDATE');
            $dest->execute([$agentId,$agentId]);$rows=$dest->fetchAll(PDO::FETCH_ASSOC);$disabled=0;$cleared=0;$promoted=0;
            foreach($rows as $d){
                $key=(string)$d['destination_key'];$primary=(int)($d['agent_id']??0);$fallback=(int)($d['fallback_agent_id']??0);
                if($primary===$agentId){
                    $fallbackUsable=false;
                    if($fallback>0&&$fallback!==$agentId){$q=$this->pdo->prepare('SELECT id FROM print_agents WHERE id=? AND active=1 AND retired_at IS NULL FOR UPDATE');$q->execute([$fallback]);$fallbackUsable=$q->fetchColumn()!==false;}
                    if($fallbackUsable&&trim((string)($d['fallback_windows_queue_name']??''))!==''){
                        $this->pdo->prepare('UPDATE print_destinations SET agent_id=fallback_agent_id,windows_queue_name=fallback_windows_queue_name,fallback_agent_id=NULL,fallback_windows_queue_name=NULL WHERE destination_key=?')->execute([$key]);
                        $promoted++;$this->audit('printing.destination_fallback_promoted','print_destination',0,max(0,$actorId),['destination_key'=>$key,'retired_agent_id'=>$agentId,'promoted_agent_id'=>$fallback]);
                    }else{
                        $this->pdo->prepare('UPDATE print_destinations SET active=0,agent_id=NULL,windows_queue_name=NULL,fallback_agent_id=NULL,fallback_windows_queue_name=NULL WHERE destination_key=?')->execute([$key]);
                        $disabled++;$this->audit('printing.destination_disabled_for_retired_agent','print_destination',0,max(0,$actorId),['destination_key'=>$key,'retired_agent_id'=>$agentId]);
                    }
                }elseif($fallback===$agentId){
                    $this->pdo->prepare('UPDATE print_destinations SET fallback_agent_id=NULL,fallback_windows_queue_name=NULL WHERE destination_key=?')->execute([$key]);
                    $cleared++;$this->audit('printing.destination_fallback_cleared','print_destination',0,max(0,$actorId),['destination_key'=>$key,'retired_agent_id'=>$agentId]);
                }
            }

            $this->pdo->prepare('UPDATE print_agents SET active=0,retired_at=COALESCE(retired_at,NOW()),retired_by_user_id=CASE WHEN ?>0 THEN ? ELSE retired_by_user_id END,last_error=NULL WHERE id=?')->execute([$actorId,$actorId,$agentId]);
            $this->audit('printing.agent_retired','print_agent',$agentId,max(0,$actorId),['name'=>(string)($row['name']??''),'reason'=>$reason,'destinations_disabled'=>$disabled,'fallbacks_cleared'=>$cleared,'fallbacks_promoted'=>$promoted]);
            $this->pdo->commit();
            return ['agent_id'=>$agentId,'retired'=>true,'idempotent'=>false,'destinations_disabled'=>$disabled,'fallbacks_cleared'=>$cleared,'fallbacks_promoted'=>$promoted];
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
            $fallbackAgentId=max(0,(int)($data['fallback_agent_id']??0));$fallbackQueue=self::cut(trim((string)($data['fallback_windows_queue_name']??'')),190);$required=!empty($data['required_for_operation']);
            foreach(array_values(array_unique(array_filter([$agentId,$fallbackAgentId]))) as $candidateAgent){$a=$this->pdo->prepare('SELECT id FROM print_agents WHERE id=? AND active=1 AND retired_at IS NULL FOR UPDATE');$a->execute([$candidateAgent]);if($a->fetchColumn()===false)throw new PrintException('agent_not_ready','یکی از Print Agentهای انتخاب‌شده فعال نیست.',409);}
            if($fallbackAgentId>0&&$fallbackAgentId===$agentId)throw new PrintException('fallback_same_agent','دستگاه پشتیبان باید با دستگاه اصلی متفاوت باشد.',422);
            if(($fallbackAgentId>0)!==($fallbackQueue!==''))throw new PrintException('fallback_incomplete','برای مسیر پشتیبان، دستگاه و چاپگر را هر دو مشخص کن.',422);
            if($active&&($agentId<1||$queue===''))throw new PrintException('destination_incomplete','برای فعال‌کردن مقصد چاپ، دستگاه و چاپگر ویندوز را مشخص کن.',422);
            $queueState=$active?$this->assertConfigurableQueue($agentId,$queue,false):null;$fallbackState=$fallbackAgentId>0?$this->assertConfigurableQueue($fallbackAgentId,$fallbackQueue,false):null;
            $copies=max(1,min(5,(int)($data['copies']??1)));$paper=max(40.0,min(120.0,(float)($data['paper_width_mm']??80)));$printable=max(30.0,min($paper,(float)($data['printable_width_mm']??72.1)));
            $layout=in_array((string)($data['layout_mode']??'combined'),['combined','separate'],true)?(string)($data['layout_mode']??'combined'):'combined';
            $this->pdo->prepare('UPDATE print_destinations SET agent_id=?,windows_queue_name=?,fallback_agent_id=?,fallback_windows_queue_name=?,required_for_operation=?,active=?,copies=?,paper_width_mm=?,printable_width_mm=?,layout_mode=? WHERE destination_key=?')
                ->execute([$agentId>0?$agentId:null,$queue!==''?$queue:null,$fallbackAgentId>0?$fallbackAgentId:null,$fallbackQueue!==''?$fallbackQueue:null,$required?1:0,$active?1:0,$copies,$paper,$printable,$layout,$destinationKey]);
            $this->audit('printing.destination_configured','print_destination',0,(int)$actor['id'],['destination_key'=>$destinationKey,'agent_id'=>$agentId?:null,'queue'=>$queue?:null,'fallback_agent_id'=>$fallbackAgentId?:null,'fallback_queue'=>$fallbackQueue?:null,'required'=>$required,'active'=>$active,'copies'=>$copies]);
            $this->pdo->commit();return ['destination_key'=>$destinationKey,'active'=>$active,'required_for_operation'=>$required,'agent_id'=>$agentId?:null,'windows_queue_name'=>$queue?:null,'fallback_agent_id'=>$fallbackAgentId?:null,'fallback_windows_queue_name'=>$fallbackQueue?:null,'queue_ready'=>$queueState===null?false:!empty($queueState['ready']),'queue_readiness_code'=>$queueState['readiness_code']??null,'fallback_ready'=>$fallbackState===null?false:!empty($fallbackState['ready']),'fallback_readiness_code'=>$fallbackState['readiness_code']??null];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function enqueueTest(string $destinationKey,string $requestId,array $user,?int $packageId=null): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);$destinationKey=self::cut(trim($destinationKey),40);$requestId=self::requestId($requestId);
            $stmt=$this->pdo->prepare('SELECT destination_type,label,active,agent_id,windows_queue_name FROM print_destinations WHERE destination_key=? FOR UPDATE');$stmt->execute([$destinationKey]);$destination=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($destination)||(int)$destination['active']!==1)throw new PrintException('destination_not_ready','مقصد چاپ برای تست فعال نیست.',409);
            $this->assertConfigurableQueue((int)($destination['agent_id']??0),(string)($destination['windows_queue_name']??''),true);
            $prep=(string)$destination['destination_type']==='preparation';
            $payload=$prep
                ?['schema'=>'sokna-print-document-v2','document_kind'=>'preparation','title'=>'کافه سکنا','badge'=>'چاپ آزمایشی','table_name'=>'تست','order_number'=>'TEST','created_at'=>date(DATE_ATOM),'display_date'=>\Sokna\Shared\IranTime::display(date(DATE_ATOM)),'sections'=>[['title'=>'آزمون','items'=>[['name'=>'اگر این متن خواناست، مسیر چاپ آماده است','quantity'=>1,'note'=>'چاپ آزمایشی سکنا']]]]]
                :['schema'=>'sokna-print-document-v2','document_kind'=>'customer','title'=>'کافه سکنا','invoice_number'=>'TEST','created_at'=>date(DATE_ATOM),'display_date'=>\Sokna\Shared\IranTime::display(date(DATE_ATOM)),'currency'=>'تومان','sections'=>[['items'=>[['name'=>'چاپ آزمایشی سکنا','quantity'=>1,'unit_price'=>0,'line_total'=>0]]]],'subtotal'=>0,'discount'=>0,'tax'=>0,'total'=>0,'footer'=>'این برگه فقط برای آزمون مسیر چاپ است.'];
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
            'success'=>true,'protocol_version'=>self::PROTOCOL_VERSION,'minimum_agent_version'=>self::MINIMUM_AGENT_VERSION,
            'recommended_agent_version'=>self::RECOMMENDED_AGENT_VERSION,'server_time'=>gmdate('c'),
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

    public function pdfTestBridge(array $user,int $preferredAgentId=0): array
    {
        $this->assertAdmin($user);
        $where="active=1 AND retired_at IS NULL AND bridge_protocol_version=1 AND bridge_port>0 AND bridge_pairing_id IS NOT NULL AND bridge_pairing_id<>'' AND last_heartbeat_at IS NOT NULL AND last_heartbeat_at >= (NOW() - INTERVAL ".self::AGENT_FRESH_SECONDS." SECOND)";
        if($preferredAgentId>0){
            $q=$this->pdo->prepare("SELECT id,name,agent_version,bridge_port,bridge_pairing_id,bridge_origin FROM print_agents WHERE id=? AND $where LIMIT 1");
            $q->execute([$preferredAgentId]);$agent=$q->fetch(PDO::FETCH_ASSOC);
        }else{
            $q=$this->pdo->query("SELECT id,name,agent_version,bridge_port,bridge_pairing_id,bridge_origin FROM print_agents WHERE $where ORDER BY last_heartbeat_at DESC,id DESC LIMIT 1");
            $agent=$q->fetch(PDO::FETCH_ASSOC);
        }
        if(!is_array($agent))throw new PrintException('pdf_test_bridge_unavailable','برای تنظیم مقصد تست PDF، Print Worker متصل و Local Bridge آماده لازم است.',409);
        $version=trim((string)($agent['agent_version']??''));
        if($version===''||version_compare($version,self::MINIMUM_AGENT_VERSION,'<'))throw new PrintException('agent_version_incompatible','نسخه Print Worker برای تنظیم مقصد تست PDF سازگار نیست.',409);
        return [
            'agent_id'=>(int)$agent['id'],
            'agent_name'=>(string)$agent['name'],
            'agent_version'=>$version,
            'port'=>(int)$agent['bridge_port'],
            'pairing_id'=>(string)$agent['bridge_pairing_id'],
            'origin'=>(string)($agent['bridge_origin']??''),
        ];
    }

    public function enqueueTx(string $jobType,string $destinationKey,array $payload,string $entityType,?string $entityId,?int $actorUserId,string $idempotencyKey,bool $required=false): array
    {
        $this->requireTx();
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
            $rows=$this->preparationRowsForOrder($orderId);if(!$rows)return null;
            $consumer=trim((string)($order['consumer_name_snapshot']??''));
            $display=$context==='staff_consumption'?'پرسنل: '.($consumer!==''?$consumer:'—'):(string)($order['table_name']??'');
            $payload=[
                'schema'=>'sokna-print-document-v2','document_kind'=>'preparation','order_id'=>$orderId,'order_number'=>(int)$order['business_order_number'],
                'order_context'=>$context,'table_name'=>self::faDigits($display),'badge'=>$context==='staff_consumption'?'مصرف پرسنل':'فیش آماده‌سازی',
                'consumer_personnel_id'=>$context==='staff_consumption'?(int)$order['consumer_personnel_id']:null,
                'consumer_name'=>$context==='staff_consumption'?$consumer:null,'customer_note'=>$order['customer_note'],
                // Keep top-level `items` during rolling upgrades, but the canonical
                // renderer contract is sections[].items[] with name/note fields.
                'items'=>$rows,'sections'=>self::preparationSections($rows),'created_at'=>\Sokna\Shared\IranTime::iso((string)$order['created_at']),'display_date'=>\Sokna\Shared\IranTime::display((string)$order['created_at']),
            ];
            $payload=$this->templates->applyActive($payload);
            $r=$this->enqueueTx('preparation','prep_shared',$payload,'order',(string)$orderId,$actorUserId,'prep:order:'.$orderId,false);
            return $r['job_id']===null?null:(int)$r['job_id'];
        }catch(Throwable $e){
            try{$this->audit('printing.enqueue_failed','order',$orderId,(int)($actorUserId??0),['error_code'=>$this->safeThrowableCode($e),'message'=>self::cut($e->getMessage(),300)]);}catch(Throwable){}
            return null;
        }
    }

    /**
     * Enqueue one small preparation correction slip for a committed item adjustment.
     * This never reprints the full preparation document and is safe to call in the
     * same transaction as the adjustment; idempotency is owned by adjustment id.
     */
    public function enqueueOrderAdjustmentTx(int $adjustmentId,int $actorUserId): ?array
    {
        $this->requireTx();
        try{
            $stmt=$this->pdo->prepare(
                'SELECT a.*,oi.item_name,oi.item_note,oi.fulfillment_mode,oi.preparation_station,o.business_order_number,o.customer_note,o.table_id,t.name table_name ' .
                'FROM order_item_adjustments a JOIN order_items oi ON oi.id=a.order_item_id JOIN orders o ON o.id=a.order_id LEFT JOIN cafe_tables t ON t.id=o.table_id WHERE a.id=? LIMIT 1'
            );
            $stmt->execute([$adjustmentId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return null;
            if(trim((string)($row['area_key']??''))==='')return null;
            $kind=(string)($row['adjustment_kind']??'quantity');$parts=[];$delta=$row['delta_quantity']===null?0:(int)$row['delta_quantity'];
            if($delta!==0)$parts[]=$delta>0?'افزایش تعداد '.self::faDigits((string)(int)$row['from_quantity']).' ← '.self::faDigits((string)(int)$row['to_quantity']):'کاهش تعداد '.self::faDigits((string)(int)$row['from_quantity']).' ← '.self::faDigits((string)(int)$row['to_quantity']);
            $fromMode=(string)($row['from_fulfillment_mode']??'');$toMode=(string)($row['to_fulfillment_mode']??'');
            $hasDistribution=$row['from_dine_in_quantity']!==null||$row['to_dine_in_quantity']!==null||$row['from_takeaway_quantity']!==null||$row['to_takeaway_quantity']!==null;
            $fromDine=(int)($row['from_dine_in_quantity']??0);$toDine=(int)($row['to_dine_in_quantity']??0);$fromTake=(int)($row['from_takeaway_quantity']??0);$toTake=(int)($row['to_takeaway_quantity']??0);
            if($hasDistribution&&($fromDine!==$toDine||$fromTake!==$toTake))$parts[]='نحوه سرو: داخل کافه '.self::faDigits((string)$fromDine).' ← '.self::faDigits((string)$toDine).' · بیرون‌بر '.self::faDigits((string)$fromTake).' ← '.self::faDigits((string)$toTake);
            elseif($fromMode!==''&&$toMode!==''&&$fromMode!==$toMode)$parts[]='نحوه سرو: '.($fromMode==='takeaway'?'بیرون‌بر':'داخل کافه').' ← '.($toMode==='takeaway'?'بیرون‌بر':'داخل کافه');
            if((string)($row['from_item_note']??'')!==(string)($row['to_item_note']??''))$parts[]='یادداشت آماده‌سازی تغییر کرد';
            if(!$parts)$parts[]='اصلاح سفارش';
            $summary=implode(' · ',$parts);$reason=trim((string)($row['reason']??''));if($reason!=='')$summary.=' · دلیل: '.$reason;
            $qty=$delta!==0?abs($delta):max(1,(int)$row['to_quantity']);
            $actualNote=trim((string)($row['to_item_note']??$row['item_note']??''));
            // The correction summary is printed once at document level; the actual item note is shown once across split fulfillment rows.
            $itemNote=$actualNote;
            $payload=[
                'schema'=>'sokna-print-document-v2','document_kind'=>'preparation','document_role'=>'preparation_adjustment','badge'=>'اصلاحیه آماده‌سازی',
                'order_id'=>(int)$row['order_id'],'order_number'=>(int)$row['business_order_number'],'table_name'=>self::faDigits((string)($row['table_name']??'')),'created_at'=>date(DATE_ATOM),'display_date'=>\Sokna\Shared\IranTime::display(date(DATE_ATOM)),
                'customer_note'=>$summary,'items'=>[],
            ];
            $payload['items']=self::adjustmentPreparationRows($row,$hasDistribution,$toDine,$toTake,$qty,$toMode,$itemNote);
            $payload['sections']=self::preparationSections($payload['items']);
            $payload['adjustment']=['id'=>$adjustmentId,'kind'=>$kind,'from_quantity'=>(int)$row['from_quantity'],'to_quantity'=>(int)$row['to_quantity'],'delta_quantity'=>$delta,'from_fulfillment_mode'=>$fromMode,'to_fulfillment_mode'=>$toMode,'from_fulfillment_quantities'=>['dine_in'=>$fromDine,'takeaway'=>$fromTake],'to_fulfillment_quantities'=>['dine_in'=>$toDine,'takeaway'=>$toTake],'summary'=>$summary];
            $payload=$this->templates->applyActive($payload);
            $job=$this->enqueueTx('preparation_adjustment','prep_shared',$payload,'order_item_adjustment',(string)$adjustmentId,$actorUserId,'prep:adjustment:'.$adjustmentId,false);
            $this->audit('printing.order_adjustment_enqueued','print_job',(int)($job['job_id']??0),$actorUserId,['adjustment_id'=>$adjustmentId,'order_id'=>(int)$row['order_id'],'kind'=>$kind,'status'=>(string)($job['status']??'')]);
            return $job+['adjustment_id'=>$adjustmentId,'order_id'=>(int)$row['order_id']];
        }catch(Throwable $e){
            try{$this->audit('printing.adjustment_enqueue_failed','order_item_adjustment',$adjustmentId,$actorUserId,['error_code'=>$this->safeThrowableCode($e),'message'=>self::cut($e->getMessage(),300)]);}catch(Throwable){}
            return null;
        }
    }

    public function enqueueOrderReprintTx(int $orderId,int $actorUserId,string $requestId): array
    {
        $this->requireTx();$requestId=self::requestId($requestId);
        $stmt=$this->pdo->prepare(
            'SELECT o.id,o.business_order_number,o.customer_note,o.created_at,o.order_context,o.table_id,t.name table_name ' .
            'FROM orders o LEFT JOIN cafe_tables t ON t.id=o.table_id WHERE o.id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$orderId]);$order=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order))throw new PrintException('order_not_found','سفارش برای چاپ مجدد پیدا نشد.',404);
        if((string)($order['order_context']??'table_service')!=='table_service')throw new PrintException('reprint_not_supported','چاپ مجدد این نوع سفارش از سالن پشتیبانی نمی‌شود.',409);
        $rows=$this->preparationRowsForOrder($orderId);
        if(!$rows)throw new PrintException('nothing_to_reprint','این سفارش قلمی برای فیش آماده‌سازی ندارد.',409);
        $payload=[
            'schema'=>'sokna-print-document-v2','document_kind'=>'preparation','order_id'=>$orderId,'order_number'=>(int)$order['business_order_number'],
            'order_context'=>'table_service','table_name'=>self::faDigits((string)($order['table_name']??'')),'badge'=>'چاپ مجدد · فیش آماده‌سازی',
            'customer_note'=>$order['customer_note'],'items'=>$rows,'sections'=>self::preparationSections($rows),'created_at'=>\Sokna\Shared\IranTime::iso((string)$order['created_at']),'display_date'=>\Sokna\Shared\IranTime::display((string)$order['created_at']),
            'reprint'=>true,'reprint_request_id'=>$requestId,'reprint_requested_by_user_id'=>$actorUserId,
        ];
        $payload=$this->templates->applyActive($payload);
        $job=$this->enqueueTx('preparation_reprint','prep_shared',$payload,'order_reprint',(string)$orderId,$actorUserId,'prep:reprint:order:'.$orderId.':'.$requestId,false);
        $this->audit('printing.order_reprint_enqueued','print_job',(int)($job['job_id']??0),$actorUserId,['order_id'=>$orderId,'request_id'=>$requestId,'status'=>(string)($job['status']??'')]);
        return $job+['order_id'=>$orderId,'request_id'=>$requestId];
    }

    public function enqueueSettlementTx(int $settlementId,?int $actorUserId=null): ?int
    {
        $this->requireTx();
        try{
            $stmt=$this->pdo->prepare('SELECT id,invoice_number,invoice_snapshot_json,destination,status FROM settlement_records WHERE id=? LIMIT 1');
            $stmt->execute([$settlementId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($row)||(string)$row['status']!=='completed')return null;
            $snapshot=json_decode((string)$row['invoice_snapshot_json'],true);if(!is_array($snapshot))return null;
            $items=[];foreach((array)($snapshot['items']??[]) as $line){$note=trim((string)($line['note']??''));$components=is_array($line['bundle_components']??null)?$line['bundle_components']:[];if($components!==[]){$labels=[];foreach($components as $component){$name=trim((string)($component['name']??''));if($name!=='')$labels[]=$name.' × '.max(1,(int)($component['quantity']??1));}if($labels!==[])$note.=($note!==''?' · ':'').'اجزا: '.implode('، ',$labels);}$items[]=['name'=>(string)($line['name']??'—'),'quantity'=>(int)($line['quantity']??1),'unit_price'=>(int)($line['unit_price']??0),'line_total'=>(int)($line['line_total']??0),'note'=>$note!==''?$note:null];}
            $payload=['schema'=>'sokna-print-document-v2','document_kind'=>'customer','settlement_id'=>$settlementId,'invoice_number'=>(string)$row['invoice_number'],'settlement_destination'=>(string)$row['destination'],'table_name'=>self::faDigits((string)($snapshot['table_name']??'')),'created_at'=>(string)($snapshot['issued_at']??date(DATE_ATOM)),'currency'=>'تومان','sections'=>[['items'=>$items]],'subtotal'=>(int)($snapshot['subtotal']??0),'discount'=>(int)($snapshot['discount']??0),'taxable'=>(int)($snapshot['taxable']??0),'tax'=>(int)($snapshot['tax']??0),'total'=>(int)($snapshot['total']??0),'settlement_label'=>self::settlementDestinationLabel((string)$row['destination'])];
            $payload=$this->templates->applyActive($payload);
            $r=$this->enqueueTx('customer_receipt','customer_receipt',$payload,'settlement',(string)$settlementId,$actorUserId,'receipt:settlement:'.$settlementId,false);
            if($r['job_id']!==null)$this->pdo->prepare('UPDATE settlement_records SET final_print_job_id=COALESCE(final_print_job_id,?) WHERE id=?')->execute([(int)$r['job_id'],$settlementId]);
            return $r['job_id']===null?null:(int)$r['job_id'];
        }catch(Throwable $e){
            try{$this->audit('printing.enqueue_failed','settlement',$settlementId,(int)($actorUserId??0),['error_code'=>$this->safeThrowableCode($e),'message'=>self::cut($e->getMessage(),300)]);}catch(Throwable){}
            return null;
        }
    }

    public function enqueueSettlementReprintTx(int $settlementId,int $actorUserId,string $requestId): array
    {
        $this->requireTx();$requestId=self::requestId($requestId);
        $stmt=$this->pdo->prepare('SELECT id,invoice_number,invoice_snapshot_json,destination,status FROM settlement_records WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$settlementId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new PrintException('settlement_not_found','فاکتور برای چاپ مجدد پیدا نشد.',404);
        if((string)$row['status']!=='completed')throw new PrintException('receipt_reprint_not_allowed','فقط فاکتور ثبت‌شده قابل چاپ مجدد است.',409);
        $snapshot=json_decode((string)$row['invoice_snapshot_json'],true);if(!is_array($snapshot))throw new PrintException('receipt_snapshot_invalid','اطلاعات فاکتور برای چاپ مجدد معتبر نیست.',409);
        $items=[];foreach((array)($snapshot['items']??[]) as $line)$items[]=['name'=>(string)($line['name']??'—'),'quantity'=>(int)($line['quantity']??1),'unit_price'=>(int)($line['unit_price']??0),'line_total'=>(int)($line['line_total']??0),'note'=>$line['note']??null];
        $payload=['schema'=>'sokna-print-document-v2','document_kind'=>'customer','document_role'=>'receipt_reprint','badge'=>'چاپ مجدد · فیش مشتری','settlement_id'=>$settlementId,'invoice_number'=>(string)$row['invoice_number'],'settlement_destination'=>(string)$row['destination'],'table_name'=>self::faDigits((string)($snapshot['table_name']??'')),'created_at'=>(string)($snapshot['issued_at']??gmdate('c')),'currency'=>'تومان','sections'=>[['items'=>$items]],'subtotal'=>(int)($snapshot['subtotal']??0),'discount'=>(int)($snapshot['discount']??0),'taxable'=>(int)($snapshot['taxable']??0),'tax'=>(int)($snapshot['tax']??0),'total'=>(int)($snapshot['total']??0),'settlement_label'=>self::settlementDestinationLabel((string)$row['destination']),'reprint'=>true,'reprint_request_id'=>$requestId,'reprint_requested_by_user_id'=>$actorUserId];
        $payload=$this->templates->applyActive($payload);
        $job=$this->enqueueTx('customer_receipt_reprint','customer_receipt',$payload,'settlement_reprint',(string)$settlementId,$actorUserId,'receipt:reprint:settlement:'.$settlementId.':'.$requestId,false);
        $this->audit('printing.settlement_reprint_enqueued','print_job',(int)($job['job_id']??0),$actorUserId,['settlement_id'=>$settlementId,'request_id'=>$requestId,'status'=>(string)($job['status']??'')]);
        return $job+['settlement_id'=>$settlementId,'request_id'=>$requestId];
    }

    public function claim(array $agent,array $data): array
    {
        $this->pdo->beginTransaction();
        try{$r=$this->claimTx($agent,$data);$this->pdo->commit();return $r;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function claimTx(array $agent,array $data): array
    {
        $this->requireTx();$this->releaseExpiredReservationsTx();$agentId=(int)$agent['id'];$requestId=self::requestId((string)($data['request_id']??''));$version=self::cut((string)($data['agent_version']??''),40);
        $limit=max(1,min(self::MAX_CLAIM,(int)($data['limit']??1)));$ready=self::stringList((array)($data['ready_destination_keys']??[]),40);
        $hash=self::hash(['request_id'=>$requestId,'agent_version'=>$version,'protocol_version'=>(int)($data['protocol_version']??0),'limit'=>$limit,'ready_destination_keys'=>$ready]);
        $existing=$this->pdo->prepare('SELECT * FROM print_claim_requests WHERE agent_id=? AND request_id=? LIMIT 1 FOR UPDATE');$existing->execute([$agentId,$requestId]);$claim=$existing->fetch(PDO::FETCH_ASSOC);
        if(is_array($claim)){
            if(!hash_equals((string)$claim['request_hash'],$hash))throw new PrintException('request_body_conflict','request_id قبلاً با بدنه دیگری استفاده شده است.',409);
            $snapshot=json_decode((string)($claim['response_snapshot_json']??''),true);if(!is_array($snapshot))throw new PrintException('claim_snapshot_invalid','Snapshot پایدار Claim معتبر نیست.',409);
            $snapshot=$this->normalizeClaimSnapshotTx($claim,$snapshot,$agentId,$requestId);
            $snapshot['idempotent']=true;return $snapshot;
        }
        // A connected Agent may poll while Windows exposes no ready queue. That is a healthy
        // no-work state, not an idempotent business mutation. Persisting one claim-request row
        // per poll creates unbounded MariaDB write churn without any possible print side effect.
        // Expired reservations were already released above; return the empty claim directly.
        if(!$ready)return ['success'=>true,'request_id'=>$requestId,'jobs'=>[],'server_time'=>gmdate('c'),'idempotent'=>false,'skipped'=>true,'reason'=>'no_ready_destinations'];
        $this->pdo->prepare('INSERT INTO print_claim_requests(agent_id,request_id,request_hash,agent_version) VALUES(?,?,?,?)')->execute([$agentId,$requestId,$hash,$version]);$claimRowId=(int)$this->pdo->lastInsertId();
        $jobs=[];$attemptIds=[];
        if($ready){
            $ph=implode(',',array_fill(0,count($ready),'?'));$sql="SELECT j.*,d.label,d.agent_id,d.windows_queue_name,d.fallback_agent_id,d.fallback_windows_queue_name,d.paper_width_mm,d.printable_width_mm,d.copies,d.layout_mode FROM print_jobs j JOIN print_destinations d ON d.destination_key=j.destination_key WHERE j.status='pending' AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=NOW()) AND j.destination_key IN($ph) AND d.active=1 AND (d.agent_id=? OR d.fallback_agent_id=?) ORDER BY j.id LIMIT {$limit} FOR UPDATE";
            $stmt=$this->pdo->prepare($sql);$stmt->execute([...$ready,$agentId,$agentId]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $job){
                $queueForAgent=$this->claimQueueForAgent($job,$agentId);if($queueForAgent===null)continue;
                $attemptNo=(int)$job['attempt_count']+1;$cycle=(int)($job['retry_cycle']??0);$c=$this->pdo->prepare('SELECT COUNT(*) FROM print_attempts WHERE job_id=? AND retry_cycle=?');$c->execute([(int)$job['id'],$cycle]);$cycleAttemptNo=(int)$c->fetchColumn()+1;
                if($cycleAttemptNo>self::MAX_ATTEMPTS_PER_CYCLE){$this->pdo->prepare("UPDATE print_jobs SET status='failed',next_attempt_at=NULL,last_error_code='attempt_limit_exhausted',last_error='سقف تلاش خودکار این چرخه چاپ تمام شد؛ تصمیم مدیر لازم است.' WHERE id=? AND status='pending'")->execute([(int)$job['id']]);$this->audit('printing.attempt_limit_exhausted','print_job',(int)$job['id'],0,['retry_cycle'=>$cycle]);continue;}
                $leaseToken=bin2hex(random_bytes(32));$leaseHash=hash('sha256',$leaseToken);$expiry=date('Y-m-d H:i:s',time()+self::LEASE_SECONDS);
                $dest=self::wireDestination($job,$queueForAgent);
                $this->pdo->prepare("INSERT INTO print_attempts(job_id,attempt_no,retry_cycle,cycle_attempt_no,agent_id,state,claim_request_id,lease_token_hash,lease_expires_at,destination_snapshot_json,leased_at) VALUES(?,?,?,?,?,'reserved',?,?,?,?,NOW())")
                    ->execute([(int)$job['id'],$attemptNo,$cycle,$cycleAttemptNo,$agentId,$requestId,$leaseHash,$expiry,self::json($dest)]);
                $attemptId=(int)$this->pdo->lastInsertId();$attemptIds[]=$attemptId;
                $this->pdo->prepare("UPDATE print_jobs SET status='reserved',attempt_count=?,claimed_by_agent_id=?,claimed_at=NOW(),lease_expires_at=? WHERE id=? AND status='pending'")->execute([$attemptNo,$agentId,$expiry,(int)$job['id']]);
                $jobs[]=self::wireClaimItem($job,$attemptId,$attemptNo,$leaseToken,$expiry,$dest);
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
            if($resolution==='retry')$this->pdo->prepare("UPDATE print_jobs SET status='pending',retry_cycle=retry_cycle+1,retry_cycle_started_at=NOW(),retry_cycle_started_by_user_id=?,claimed_by_agent_id=NULL,claimed_at=NULL,lease_expires_at=NULL,accepted_at=NULL,local_receipt_id=NULL,next_attempt_at=NOW(),resolution_state='manual_retry',resolved_at=NOW(),resolved_by_user_id=?,resolution_note=? WHERE id=?")->execute([(int)$actor['id'],(int)$actor['id'],$reason,$jobId]);
            else $this->pdo->prepare('UPDATE print_jobs SET status=?,resolution_state=?,resolved_at=NOW(),resolved_by_user_id=?,resolution_note=?,completed_at=IF(?=\'submitted\',NOW(),completed_at) WHERE id=?')->execute([$resolution,'manual_'.$resolution,(int)$actor['id'],$reason,$resolution,$jobId]);
            $this->audit('printing.ambiguous_resolved','print_job',$jobId,(int)$actor['id'],['resolution'=>$resolution,'reason'=>$reason]);$this->pdo->commit();return ['job_id'=>$jobId,'resolution'=>$resolution];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function controlJob(int $jobId,string $action,string $reason,array $user,?string $destinationKey=null): array
    {
        $this->pdo->beginTransaction();
        try{
            $actor=$this->assertAdmin($user);$action=trim($action);$reason=self::cut(trim($reason),500);if($reason==='')throw new PrintException('reason_required','دلیل اقدام چاپ لازم است.',422);
            $stmt=$this->pdo->prepare('SELECT * FROM print_jobs WHERE id=? FOR UPDATE');$stmt->execute([$jobId]);$job=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($job))throw new PrintException('job_not_found','کار چاپ پیدا نشد.',404);
            $status=(string)$job['status'];$safe=in_array($status,['pending','failed','blocked'],true);if(!$safe)throw new PrintException('job_action_unsafe','این کار چاپ در وضعیتی است که Retry/Reroute/Cancel خودکار می‌تواند چاپ تکراری ایجاد کند؛ از تعیین وضعیت ابهام استفاده کن.',409,['status'=>$status]);
            if($action==='cancel'){
                $this->pdo->prepare("UPDATE print_jobs SET status='cancelled',next_attempt_at=NULL,completed_at=NOW(),resolution_state='manual_cancelled',resolved_at=NOW(),resolved_by_user_id=?,resolution_note=? WHERE id=?")->execute([(int)$actor['id'],$reason,$jobId]);
            }elseif($action==='retry'){
                if(!in_array($status,['failed','blocked'],true))throw new PrintException('retry_not_required','این کار هنوز در صف است؛ Retry دستی لازم نیست.',409);
                $this->assertDestinationHasReadyRoute((string)$job['destination_key']);
                $this->pdo->prepare("UPDATE print_jobs SET status='pending',retry_cycle=retry_cycle+1,retry_cycle_started_at=NOW(),retry_cycle_started_by_user_id=?,blocked_reason=NULL,claimed_by_agent_id=NULL,claimed_at=NULL,lease_expires_at=NULL,accepted_at=NULL,local_receipt_id=NULL,next_attempt_at=NOW(),last_error_code=NULL,last_error=NULL,resolution_state='manual_retry',resolved_at=NOW(),resolved_by_user_id=?,resolution_note=?,completed_at=NULL WHERE id=?")->execute([(int)$actor['id'],(int)$actor['id'],$reason,$jobId]);
            }elseif($action==='reroute'){
                $target=self::cut(trim((string)$destinationKey),40);if($target===''||$target===(string)$job['destination_key'])throw new PrintException('reroute_destination_required','یک مقصد متفاوت برای انتقال چاپ انتخاب کن.',422);
                $this->assertDestinationHasReadyRoute($target);
                $this->pdo->prepare("UPDATE print_jobs SET destination_key=?,status='pending',retry_cycle=retry_cycle+1,retry_cycle_started_at=NOW(),retry_cycle_started_by_user_id=?,blocked_reason=NULL,claimed_by_agent_id=NULL,claimed_at=NULL,lease_expires_at=NULL,accepted_at=NULL,local_receipt_id=NULL,next_attempt_at=NOW(),last_error_code=NULL,last_error=NULL,resolution_state='manual_reroute',resolved_at=NOW(),resolved_by_user_id=?,resolution_note=?,completed_at=NULL WHERE id=?")->execute([$target,(int)$actor['id'],(int)$actor['id'],$reason,$jobId]);
            }else throw new PrintException('invalid_job_action','عملیات بازیابی چاپ معتبر نیست.',422);
            $this->audit('printing.job_controlled','print_job',$jobId,(int)$actor['id'],['action'=>$action,'from_status'=>$status,'destination_key'=>$destinationKey,'reason'=>$reason]);$this->pdo->commit();return ['job_id'=>$jobId,'action'=>$action];
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

    /** @return array<string,mixed> */
    private function assertDestinationHasReadyRoute(string $destinationKey): void
    {
        $stmt=$this->pdo->prepare('SELECT * FROM print_destinations WHERE destination_key=? AND active=1 LIMIT 1');$stmt->execute([$destinationKey]);$d=$stmt->fetch(PDO::FETCH_ASSOC);if(!is_array($d))throw new PrintException('destination_not_ready','مقصد چاپ فعال پیدا نشد.',409);
        $errors=[];
        try{$this->assertConfigurableQueue((int)($d['agent_id']??0),(string)($d['windows_queue_name']??''),true);return;}catch(PrintException $e){$errors[]=$e->errorCode;}
        if((int)($d['fallback_agent_id']??0)>0&&trim((string)($d['fallback_windows_queue_name']??''))!=='')try{$this->assertConfigurableQueue((int)$d['fallback_agent_id'],(string)$d['fallback_windows_queue_name'],true);return;}catch(PrintException $e){$errors[]=$e->errorCode;}
        throw new PrintException('destination_not_ready','هیچ مسیر آماده‌ای برای این مقصد چاپ وجود ندارد. ابتدا وضعیت دستگاه/چاپگر یا مسیر پشتیبان را اصلاح کن.',409,['reasons'=>$errors]);
    }

    private function assertConfigurableQueue(int $agentId,string $queue,bool $requireReady): array
    {
        $queue=trim($queue);
        if($agentId<1||$queue==='')throw new PrintException('destination_incomplete','دستگاه چاپ و چاپگر ویندوز را مشخص کن.',422);
        $stmt=$this->pdo->prepare("SELECT id,active,retired_at,last_heartbeat_at,printers_json,agent_version,sqlite_health,GREATEST(0,TIMESTAMPDIFF(SECOND,last_heartbeat_at,NOW())) heartbeat_age_seconds FROM print_agents WHERE id=? LIMIT 1");
        $stmt->execute([$agentId]);$agent=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($agent)||(int)($agent['active']??0)!==1||!empty($agent['retired_at']))throw new PrintException('agent_not_ready','سرویس چاپ انتخاب‌شده فعال نیست.',409);
        if(empty($agent['last_heartbeat_at']))throw new PrintException('agent_never_connected','سرویس چاپ هنوز هیچ ارتباط موفقی با سامانه ثبت نکرده است. ابتدا سرویس‌های ویندوز را متصل و وضعیت چاپ را تازه کن.',409);
        if((int)($agent['heartbeat_age_seconds']??999999)>PrintManagementService::HEARTBEAT_FRESH_SECONDS)throw new PrintException('agent_stale','ارتباط سرویس چاپ قدیمی است. وضعیت Windows Services و Print Worker را بررسی کن.',409,['heartbeat_age_seconds'=>(int)$agent['heartbeat_age_seconds']]);
        $version=trim((string)($agent['agent_version']??''));
        if($version===''||version_compare($version,self::MINIMUM_AGENT_VERSION,'<'))throw new PrintException('agent_version_incompatible','نسخه سرویس چاپ با این Local Web سازگار نیست.',409,['agent_version'=>$version,'minimum_version'=>self::MINIMUM_AGENT_VERSION]);
        $printers=json_decode((string)($agent['printers_json']??''),true);if(!is_array($printers))$printers=[];
        $found=null;
        foreach($printers as $printer)if(is_array($printer)&&strcasecmp(trim((string)($printer['name']??'')),$queue)===0){$found=$printer;break;}
        if(!is_array($found))throw new PrintException('printer_queue_not_reported','این چاپگر در فهرست چاپگرهای گزارش‌شده توسط Windows Print Worker وجود ندارد. چاپگر را از فهرست واقعی انتخاب کن.',409,['queue'=>$queue]);
        $capable=self::boolValue($found['automation_capable']??false);
        if(!$capable)throw new PrintException('printer_queue_not_automation_capable','این چاپگر/پورت برای چاپ خودکار بدون دخالت کاربر ایمن تشخیص داده نشده است.',409,['queue'=>$queue,'driver'=>self::cut((string)($found['driver']??''),190),'port'=>self::cut((string)($found['port']??''),190)]);
        $offline=self::boolValue($found['offline']??false);$paused=self::boolValue($found['paused']??false);$paperOut=self::boolValue($found['paper_out']??false);$error=self::boolValue($found['error']??false);
        $readiness=$offline?'printer_offline':($paused?'printer_paused':($paperOut?'printer_paper_out':($error?'printer_error':'ready')));
        $found['ready']=$readiness==='ready';$found['readiness_code']=$readiness;
        if($requireReady&&$readiness!=='ready'){
            $message=match($readiness){'printer_offline'=>'چاپگر آفلاین است.','printer_paused'=>'صف چاپ در ویندوز متوقف است.','printer_paper_out'=>'کاغذ چاپگر تمام شده است.','printer_error'=>'چاپگر در ویندوز خطا دارد.',default=>'چاپگر آماده نیست.'};
            throw new PrintException($readiness,$message,409,['queue'=>$queue]);
        }
        return $found;
    }

    /** @return list<array{title:string,items:list<array<string,mixed>>}> */
    /** @return list<array<string,mixed>> */
    private function preparationRowsForOrder(int $orderId): array
    {
        $stmt=$this->pdo->prepare("SELECT id,item_name,quantity,item_note,fulfillment_mode,preparation_station FROM order_items WHERE order_id=? AND quantity>0 ORDER BY id");
        $stmt->execute([$orderId]);$parents=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];if($parents===[])return [];
        $comp=$this->pdo->prepare('SELECT item_name_snapshot,quantity,preparation_station_snapshot,component_mode,choice_label_snapshot FROM order_item_bundle_components WHERE order_item_id=? ORDER BY id');$out=[];
        foreach($parents as $parent){
            $comp->execute([(int)$parent['id']]);$rows=$comp->fetchAll(PDO::FETCH_ASSOC)?:[];
            if($rows===[]){if((string)$parent['preparation_station']!=='none')$out[]=['item_name'=>(string)$parent['item_name'],'quantity'=>(int)$parent['quantity'],'item_note'=>$parent['item_note'],'fulfillment_mode'=>(string)$parent['fulfillment_mode'],'preparation_station'=>(string)$parent['preparation_station']];continue;}
            foreach($rows as $row){$note=trim((string)($parent['item_note']??''));$ctx='از ترکیب «'.(string)$parent['item_name'].'»';$note=$note!==''?$ctx.' · '.$note:$ctx;$out[]=['item_name'=>(string)$row['item_name_snapshot'],'quantity'=>(int)$row['quantity'],'item_note'=>$note,'fulfillment_mode'=>(string)$parent['fulfillment_mode'],'preparation_station'=>(string)$row['preparation_station_snapshot']];}
        }
        return self::oncePerSplitFulfillmentNote($out);
    }

    /** Keep independent same-mode notes, but print one shared note when a product is split into dine-in and takeaway. */
    private static function oncePerSplitFulfillmentNote(array $rows): array
    {
        $notedModes=[];
        foreach($rows as &$row){
            $note=trim((string)($row['item_note']??''));
            if($note==='')continue;
            $mode=(string)($row['fulfillment_mode']??'dine_in');
            $key=hash('sha256',json_encode([(string)($row['item_name']??''),(string)($row['preparation_station']??''),$note],JSON_UNESCAPED_UNICODE));
            if(isset($notedModes[$key])&&$notedModes[$key]!==$mode){$row['item_note']=null;continue;}
            $notedModes[$key]=$mode;
        }
        unset($row);
        return $rows;
    }

    /** The correction description belongs to its document; a changed note belongs to exactly one item row. */
    private static function adjustmentPreparationRows(array $row,bool $hasDistribution,int $toDine,int $toTake,int $qty,string $toMode,string $itemNote): array
    {
        $make=static fn(int $quantity,string $mode,?string $note): array=>[
            'item_name'=>(string)$row['item_name'],'quantity'=>$quantity,'item_note'=>$note,'fulfillment_mode'=>$mode,'preparation_station'=>(string)$row['preparation_station'],
        ];
        $items=[];
        if($hasDistribution){
            if($toDine>0)$items[]=$make($toDine,'dine_in',$itemNote!==''?$itemNote:null);
            if($toTake>0)$items[]=$make($toTake,'takeaway',$items===[]&&$itemNote!==''?$itemNote:null);
        }
        if(!$items)$items[]=$make($qty,$toMode!==''?$toMode:(string)($row['fulfillment_mode']??'dine_in'),$itemNote!==''?$itemNote:null);
        return $items;
    }

    private static function preparationSections(array $rows): array
    {
        $groups=[];$order=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $station=trim((string)($row['preparation_station']??'other'));if($station==='')$station='other';
            if(!isset($groups[$station])){$groups[$station]=[];$order[]=$station;}
            $groups[$station][]=[
                'name'=>(string)($row['item_name']??$row['name']??'—'),
                'quantity'=>max(1,(int)($row['quantity']??1)),
                'note'=>self::nullable($row['item_note']??$row['note']??null,500),
                'fulfillment_mode'=>(string)($row['fulfillment_mode']??'dine_in'),
            ];
        }
        $titles=['kitchen'=>'آشپزخانه','bar'=>'بار','other'=>'سایر'];$out=[];
        foreach($order as $station)$out[]=['title'=>$titles[$station]??'آماده‌سازی','items'=>$groups[$station]];
        return $out;
    }

    private static function faDigits(string $value): string
    {
        return strtr($value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    private static function settlementDestinationLabel(string $destination): string
    {
        return match(trim($destination)){
            'direct'=>'پرداخت مستقیم',
            'subscriber'=>'حساب مشتری',
            'accommodation'=>'حساب اقامتگاه',
            default=>'تسویه حساب',
        };
    }

    private function safeThrowableCode(Throwable $e): string
    {
        return $e instanceof PrintException?$e->errorCode:'print_enqueue_internal_error';
    }

    private static function boolValue(mixed $value): bool
    {
        if(is_bool($value))return $value;
        if(is_int($value)||is_float($value))return (int)$value===1;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function destinationsForAgent(int $agentId): array
    {
        $stmt=$this->pdo->prepare('SELECT d.destination_key,d.label,d.destination_type,d.agent_id,d.windows_queue_name,d.fallback_agent_id,d.fallback_windows_queue_name,d.active,d.paper_width_mm,d.printable_width_mm,d.copies,d.layout_mode FROM print_destinations d WHERE d.agent_id=? OR d.fallback_agent_id=? ORDER BY d.destination_key');
        $stmt->execute([$agentId,$agentId]);$out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            if((int)($row['agent_id']??0)===$agentId){$queue=(string)($row['windows_queue_name']??'');$routeRole='primary';}
            elseif((int)($row['fallback_agent_id']??0)===$agentId){if(!$this->primaryRouteUnavailable($row))continue;$queue=(string)($row['fallback_windows_queue_name']??'');$routeRole='fallback';$row['route_role']='fallback';}
            else continue;
            $wire=self::wireDestination($row,$queue);$wire['route_role']=$routeRole;$out[]=$wire;
        }
        return $out;
    }

    /** Canonical Print Agent v4 wire shape. PDO/MySQL returns DECIMAL/TINYINT as strings; never leak those strings to System.Text.Json. */
    private static function wireDestination(array $row,?string $queueOverride=null): array
    {
        return [
            'destination_key'=>(string)($row['destination_key']??''),
            'label'=>(string)($row['label']??$row['destination_key']??''),
            'windows_queue_name'=>trim((string)($queueOverride??$row['windows_queue_name']??'')),
            'paper_width_mm'=>(float)($row['paper_width_mm']??80.0),
            'printable_width_mm'=>(float)($row['printable_width_mm']??72.1),
            'copies'=>max(1,min(5,(int)($row['copies']??1))),
            'layout_mode'=>(string)($row['layout_mode']??'combined'),
        ];
    }

    /** Canonical nested ClaimItem expected by Print Agent 6.2.x. */
    private static function wireClaimItem(array $job,int $attemptId,int $attemptNo,string $leaseToken,string $expiry,array $destination): array
    {
        return [
            'job'=>[
                'id'=>(int)($job['id']??0),
                'public_token'=>(string)($job['public_token']??''),
                'job_type'=>(string)($job['job_type']??''),
                'required'=>self::boolValue($job['required']??false),
                'entity_type'=>self::nullableWireString($job['entity_type']??null),
                'entity_id'=>self::nullableWireString($job['entity_id']??null),
                'created_at'=>(string)($job['created_at']??''),
                'contract_version'=>(int)($job['contract_version']??self::PROTOCOL_VERSION),
                'content_sha256'=>(string)($job['content_sha256']??''),
                'payload_json'=>(string)($job['payload_json']??'{}'),
            ],
            'attempt'=>[
                'id'=>$attemptId,
                'attempt_no'=>$attemptNo,
                'lease_token'=>$leaseToken,
                'lease_expires_at'=>gmdate('c',strtotime($expiry)),
            ],
            'destination'=>$destination,
        ];
    }

    /** Repair an already-persisted pre-fix flat claim snapshot without changing request identity or lease token. */
    private function normalizeClaimSnapshotTx(array $claim,array $snapshot,int $agentId,string $requestId): array
    {
        $this->requireTx();
        $jobs=is_array($snapshot['jobs']??null)?$snapshot['jobs']:[];
        $legacy=false;
        foreach($jobs as $item){if(is_array($item)&&isset($item['attempt_id'])&&!isset($item['attempt'])){$legacy=true;break;}}
        if(!$legacy)return $snapshot;
        $rebuilt=[];
        foreach($jobs as $item){
            if(!is_array($item))throw new PrintException('claim_snapshot_invalid','Snapshot پایدار Claim معتبر نیست.',409);
            $attemptId=(int)($item['attempt_id']??0);$leaseToken=trim((string)($item['lease_token']??''));
            if($attemptId<1||strlen($leaseToken)<16)throw new PrintException('claim_snapshot_invalid','Snapshot قدیمی Claim اطلاعات Lease کافی ندارد.',409);
            $q=$this->pdo->prepare('SELECT a.id attempt_id,a.attempt_no,a.agent_id,a.claim_request_id,a.lease_token_hash,a.lease_expires_at attempt_lease_expires_at,a.destination_snapshot_json,j.* FROM print_attempts a JOIN print_jobs j ON j.id=a.job_id WHERE a.id=? AND a.agent_id=? AND a.claim_request_id=? LIMIT 1 FOR UPDATE');
            $q->execute([$attemptId,$agentId,$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||!hash_equals((string)$row['lease_token_hash'],hash('sha256',$leaseToken)))throw new PrintException('claim_snapshot_invalid','Snapshot قدیمی Claim با Attempt پایدار تطابق ندارد.',409);
            $dest=json_decode((string)($row['destination_snapshot_json']??''),true);
            if(!is_array($dest))throw new PrintException('claim_snapshot_invalid','Snapshot مقصد چاپ معتبر نیست.',409);
            $dest=self::wireDestination($dest,(string)($dest['windows_queue_name']??''));
            $rebuilt[]=self::wireClaimItem($row,$attemptId,(int)$row['attempt_no'],$leaseToken,(string)$row['attempt_lease_expires_at'],$dest);
        }
        $snapshot['jobs']=$rebuilt;
        $snapshot['success']=true;$snapshot['request_id']=$requestId;$snapshot['server_time']=(string)($snapshot['server_time']??gmdate('c'));
        $this->pdo->prepare('UPDATE print_claim_requests SET response_snapshot_json=? WHERE id=?')->execute([self::json($snapshot),(int)$claim['id']]);
        return $snapshot;
    }

    private static function nullableWireString(mixed $value): ?string
    {
        $s=trim((string)($value??''));return $s===''?null:$s;
    }

    private function claimQueueForAgent(array $destination,int $agentId): ?string
    {
        if((int)($destination['agent_id']??0)===$agentId)return trim((string)($destination['windows_queue_name']??''))?:null;
        if((int)($destination['fallback_agent_id']??0)===$agentId&&$this->primaryRouteUnavailable($destination))return trim((string)($destination['fallback_windows_queue_name']??''))?:null;
        return null;
    }

    private function primaryRouteUnavailable(array $destination): bool
    {
        $primaryId=(int)($destination['agent_id']??0);$queue=trim((string)($destination['windows_queue_name']??''));if($primaryId<1||$queue==='')return true;
        $stmt=$this->pdo->prepare('SELECT active,retired_at,last_heartbeat_at,printers_json,GREATEST(0,TIMESTAMPDIFF(SECOND,last_heartbeat_at,NOW())) heartbeat_age_seconds FROM print_agents WHERE id=? LIMIT 1');$stmt->execute([$primaryId]);$agent=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!is_array($agent)||(int)($agent['active']??0)!==1||!empty($agent['retired_at'])||empty($agent['last_heartbeat_at'])||(int)($agent['heartbeat_age_seconds']??999999)>self::AGENT_FRESH_SECONDS)return true;
        $printers=json_decode((string)($agent['printers_json']??''),true);if(!is_array($printers))return true;
        foreach($printers as $printer){if(!is_array($printer)||strcasecmp(trim((string)($printer['name']??'')),$queue)!==0)continue;$capable=$this->boolValue($printer['automation_capable']??false);$ready=$capable&&!$this->boolValue($printer['offline']??false)&&!$this->boolValue($printer['paused']??false)&&!$this->boolValue($printer['paper_out']??false)&&!$this->boolValue($printer['error']??false);return !$ready;}
        return true;
    }

    private function releaseExpiredReservationsTx(): void
    {
        $this->requireTx();$stmt=$this->pdo->query("SELECT a.id,a.job_id FROM print_attempts a JOIN print_jobs j ON j.id=a.job_id WHERE a.state='reserved' AND a.lease_expires_at<NOW() AND j.status='reserved' FOR UPDATE");$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row){$attemptId=(int)$row['id'];$jobId=(int)$row['job_id'];$this->pdo->prepare("UPDATE print_attempts SET state='expired',finished_at=NOW(),outcome='expired',error_code='lease_expired_before_accept',error_message='رزرو چاپ پیش از Accept منقضی شد و برای تحویل امن دوباره آزاد شد.' WHERE id=? AND state='reserved'")->execute([$attemptId]);$this->pdo->prepare("UPDATE print_jobs SET status='pending',claimed_by_agent_id=NULL,claimed_at=NULL,lease_expires_at=NULL,next_attempt_at=NOW(),last_error_code='lease_expired_before_accept',last_error='رزرو قبلی پیش از Accept منقضی شد؛ چاپ هنوز به Worker تحویل نشده بود و دوباره در صف قرار گرفت.' WHERE id=? AND status='reserved'")->execute([$jobId]);$this->audit('printing.expired_reservation_released','print_job',$jobId,0,['attempt_id'=>$attemptId]);}
    }
    private function assertAdmin(array $user): array { $fresh=$this->identity->findActiveById((int)($user['id']??0));if($fresh===null||(string)($fresh['role']??'')!=='admin')throw new PrintException('forbidden','فقط مدیر فعال مجاز است.',403);return $fresh; }
    private function audit(string $action,string $entityType,int $entityId,int $actorId,array $details): void { $name=false;if($actorId>0){$n=$this->pdo->prepare('SELECT display_name FROM users WHERE id=?');$n->execute([$actorId]);$name=$n->fetchColumn();}$this->pdo->prepare('INSERT INTO audit_log(actor_user_id,actor_display_name_snapshot,action,entity_type,entity_id,details_json) VALUES(?,?,?,?,?,?)')->execute([$actorId>0?$actorId:null,$name!==false?$name:null,$action,$entityType,(string)$entityId,self::json($details)]); }
    private function requireTx(): void { if(!$this->pdo->inTransaction())throw new \LogicException('Print operation requires an open transaction.'); }
    private static function requestId(string $v): string { $v=trim($v);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,79}$/',$v))throw new PrintException('invalid_request_id','request_id معتبر نیست.',422);return $v; }
    private static function stringList(array $a,int $len): array { $o=[];foreach($a as $v){$v=self::cut(trim((string)$v),$len);if($v!==''&&!in_array($v,$o,true))$o[]=$v;}sort($o,SORT_STRING);return $o; }
    private static function hash(array $v): string { return hash('sha256',self::json(self::sortRecursive($v))); }
    private static function sortRecursive(mixed $v): mixed { if(!is_array($v))return $v;if(array_is_list($v))return array_map([self::class,'sortRecursive'],$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::sortRecursive($x);return $v; }
    private static function json(mixed $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function cut(string $v,int $n): string { return function_exists('mb_substr')?mb_substr($v,0,$n,'UTF-8'):substr($v,0,$n); }
    private static function nullable(mixed $v,int $n): ?string { $s=self::cut(trim((string)$v),$n);return $s===''?null:$s; }
}
