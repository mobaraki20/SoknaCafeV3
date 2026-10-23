<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\GuestLan;

use Sokna\Local\Domain\LanStaff\LanStaffRealtimeReceiptService;
use Sokna\Local\Domain\Orders\GuestOrderException;
use Sokna\Local\Domain\Orders\GuestOrderService;
use Sokna\Local\Domain\Orders\WaiterCallException;
use Sokna\Local\Domain\Orders\WaiterCallService;
use Sokna\Local\Relay\RealtimeDispatchService;
use PDO;
use Throwable;

/** Guest-only LAN ingress. Local remains the sole business authority. */
final class GuestLanGatewayService
{
    public function __construct(
        private readonly string $installationId,
        private readonly PDO $pdo,
        private readonly GuestOrderService $orders,
        private readonly WaiterCallService $waiter,
        private readonly LanStaffRealtimeReceiptService $receipts,
        private readonly RealtimeDispatchService $realtime,
    ) {}

    public function createOrder(array $data): array
    {
        $ctx=$this->tableContextInput($data,false);if(isset($ctx['response']))return $ctx['response'];$table=$ctx['table'];
        $client=trim((string)($data['client_token']??''));$device=trim((string)($data['device_token']??''));
        if(strlen($client)<16||strlen($client)>80||strlen($device)>80)return $this->response(422,['success'=>false,'code'=>'invalid_order','message'=>'اطلاعات سفارش معتبر نیست.']);
        $payload=['table_token'=>(string)$table['token'],'session_token'=>trim((string)($data['session_token']??'')),'device_token'=>$device,'client_token'=>$client,'customer_note'=>(string)($data['customer_note']??''),'items'=>is_array($data['items']??null)?$data['items']:[]];
        $requestId=self::requestId('guest_order.submit',['client_token'=>$client,'table_token'=>(string)$table['token']]);
        return $this->mutation('guest_order.submit',$payload,$requestId,45);
    }

    public function guestOrders(array $data): array
    {
        $ctx=$this->tableContextInput($data,false);if(isset($ctx['response']))return $ctx['response'];$table=$ctx['table'];
        $action=trim((string)($data['action']??'list'));$base=['table_token'=>(string)$table['token'],'session_token'=>trim((string)($data['session_token']??'')),'device_token'=>trim((string)($data['device_token']??''))];
        if($base['device_token']===''||strlen($base['device_token'])>80)return $this->response(422,['success'=>false,'code'=>'invalid_context','message'=>'اطلاعات میز یا دستگاه معتبر نیست.']);
        if($action==='submission_status'){
            $client=trim((string)($data['client_token']??''));$requestId=trim((string)($data['request_id']??''));
            if($client===''||strlen($client)>80||strlen($requestId)>120)return $this->response(422,['success'=>false,'code'=>'invalid_submission','message'=>'شناسه پیگیری ارسال معتبر نیست.']);
            if($requestId==='')$requestId=self::requestId('guest_order.submit',['client_token'=>$client,'table_token'=>(string)$table['token']]);
            $receipt=$this->receipts->result($this->installationId,$requestId);
            if(!is_array($receipt))return $this->response(404,['success'=>false,'code'=>'submission_not_found','message'=>'ارسال پیدا نشد.']);
            return $this->receiptStatus($requestId,$receipt);
        }
        if($action==='list')return $this->domain(fn()=> $this->orders->list($base));
        if(!in_array($action,['update','cancel'],true))return $this->response(422,['success'=>false,'code'=>'invalid_action','message'=>'عملیات سفارش معتبر نیست.']);
        $payload=$base+['action'=>$action,'order_code'=>trim((string)($data['order_code']??'')),'expected_signature'=>trim((string)($data['expected_signature']??'')),'customer_note'=>(string)($data['customer_note']??''),'items'=>is_array($data['items']??null)?$data['items']:[]];
        $kind=$action==='update'?'order.edit':'order.cancel';
        $requestId=self::requestId($kind,['order_code'=>$payload['order_code'],'device_token'=>$payload['device_token'],'expected_signature'=>$payload['expected_signature'],'customer_note'=>$payload['customer_note'],'items'=>$payload['items']]);
        return $this->mutation($kind,$payload,$requestId,45);
    }

    public function orderQuote(array $data): array
    {
        $ctx=$this->tableContextInput($data,false);if(isset($ctx['response']))return $ctx['response'];$table=$ctx['table'];
        $payload=['quote_mode'=>(string)($data['quote_mode']??'create'),'table_token'=>(string)$table['token'],'session_token'=>trim((string)($data['session_token']??'')),'device_token'=>trim((string)($data['device_token']??'')),'client_token'=>trim((string)($data['client_token']??'')),'order_code'=>trim((string)($data['order_code']??'')),'expected_signature'=>trim((string)($data['expected_signature']??'')),'customer_note'=>(string)($data['customer_note']??''),'items'=>is_array($data['items']??null)?$data['items']:[]];
        return $this->domain(fn()=> $this->orders->quote($payload));
    }

    public function orderStatus(array $data): array
    { return $this->domain(fn()=> $this->orders->status(['order_code'=>trim((string)($data['order_code']??'')),'client_token'=>trim((string)($data['client_token']??''))])); }

    public function tableContext(array $data): array
    {
        $ctx=$this->tableContextInput($data,false);if(isset($ctx['response']))return $ctx['response'];
        return $this->domain(fn()=> $this->orders->tableContext(['table_token'=>(string)$ctx['table']['token'],'device_token'=>trim((string)($data['device_token']??''))]));
    }

    public function waiterCall(array $data): array
    {
        $ctx=$this->tableContextInput($data,true);if(isset($ctx['response']))return $ctx['response'];$table=$ctx['table'];
        $action=trim((string)($data['action']??'status'));$isPublic=trim((string)($data['table_token']??''))===''&&(int)($data['public_table_id']??0)>0;
        $payload=['table_token'=>(string)$table['token'],'public_context'=>$isPublic,'session_token'=>trim((string)($data['session_token']??'')),'device_token'=>trim((string)($data['device_token']??'')),'client_token'=>trim((string)($data['client_token']??'')),'call_code'=>trim((string)($data['call_code']??''))];
        if($action==='status')return $this->domain(fn()=> $this->waiter->status($payload));
        if($action==='create'){
            $requestId=self::requestId('waiter_call.create',['client_token'=>$payload['client_token'],'table_token'=>$payload['table_token']]);
            return $this->mutation('waiter_call.create',$payload,$requestId,45);
        }
        if($action==='cancel'){
            $requestId=self::requestId('waiter_call.cancel',['call_code'=>$payload['call_code'],'client_token'=>$payload['client_token'],'table_token'=>$payload['table_token']]);
            return $this->mutation('waiter_call.cancel',$payload,$requestId,30);
        }
        return $this->response(422,['success'=>false,'code'=>'invalid_action','message'=>'عملیات فراخوان معتبر نیست.']);
    }

    public function changes(array $data): array
    {
        $cursor=trim((string)($data['cursor']??''));if(strlen($cursor)>128)$cursor='';$waitMs=max(0,min(10000,(int)($data['wait_ms']??9000)));$deadline=microtime(true)+$waitMs/1000;
        do{$next=$this->changeCursor();if($cursor===''||!hash_equals($cursor,$next)||microtime(true)>=$deadline)break;usleep(750000);}while(true);
        $changed=$cursor===''||!hash_equals($cursor,$next);
        return $this->response(200,['success'=>true,'ok'=>true,'changed'=>$changed,'cursor'=>$next,'last_sync_at'=>gmdate('c'),'stale'=>false,'connectivity'=>['local_fresh'=>true,'mode'=>'local_lan'],'watch'=>['retry_after_ms'=>$changed?0:350]]);
    }

    public function metric(array $data=[]): array{return $this->response(200,['success'=>true]);}

    public static function requestId(string $kind,array $identity,bool $stable=true): string
    {
        $prefix=preg_replace('/[^a-z0-9]+/i','-',strtolower($kind))?:'guest';$seed=self::canonicalJson($identity);if(!$stable)$seed.='|'.bin2hex(random_bytes(12));return substr($prefix,0,30).':'.substr(hash('sha256',$seed),0,56);
    }

    private function mutation(string $kind,array $payload,string $requestId,int $ttl): array
    {
        $device=trim((string)($payload['device_token']??''));$client=trim((string)($payload['client_token']??''));$seed=$device!==''?$device:($client!==''?$client:$requestId);$now=time();
        $envelope=['request_id'=>$requestId,'kind'=>$kind,'created_at'=>gmdate('c',$now),'expires_at'=>gmdate('c',$now+max(10,min(120,$ttl))),'actor_projection_id'=>'guest:'.substr(hash('sha256',$this->installationId.'|'.$seed),0,32),'payload'=>$payload];
        $receipt=$this->receipts->dispatchAtomic($this->installationId,$envelope,fn()=> $this->realtime->dispatch($envelope));
        return $this->terminal($requestId,$receipt);
    }

    private function terminal(string $requestId,array $receipt): array
    {
        $state=(string)($receipt['state']??'');$result=is_array($receipt['result']??null)?$receipt['result']:[];$code=(string)($receipt['error_code']??'');$trace=is_array($result['_transport_trace']??null)?$result['_transport_trace']:[];unset($result['_transport_trace']);
        $traceId=(string)($trace['trace_id']??LanStaffRealtimeReceiptService::traceId($this->installationId,$requestId));
        if($state==='committed')return $this->response(200,array_merge($result,['request_id'=>$requestId,'trace_id'=>$traceId,'transport_mode'=>'local_lan']));
        if($state==='unknown_review')return $this->response(409,['success'=>false,'code'=>'outcome_unknown','message'=>'نتیجه درخواست قبلی قطعی نیست؛ دوباره آن را اجرا نکن.','request_id'=>$requestId,'trace_id'=>$traceId]);
        if($result===[])$result=['success'=>false,'code'=>$code!==''?$code:'request_rejected','message'=>'درخواست انجام نشد؛ دوباره تلاش کنید.'];
        return $this->response($this->errorStatus((string)($result['code']??$code)),array_merge($result,['request_id'=>$requestId,'trace_id'=>$traceId,'transport_mode'=>'local_lan']));
    }

    private function receiptStatus(string $requestId,array $receipt): array
    {
        $state=(string)($receipt['state']??'');$result=is_array($receipt['result']??null)?$receipt['result']:[];$trace=is_array($result['_transport_trace']??null)?$result['_transport_trace']:[];unset($result['_transport_trace']);$terminal=$state!=='processing';
        return $this->response(200,['success'=>true,'request_id'=>$requestId,'trace_id'=>(string)($trace['trace_id']??LanStaffRealtimeReceiptService::traceId($this->installationId,$requestId)),'trace'=>$trace,'state'=>$state,'terminal'=>$terminal,'business_terminal'=>$terminal,'visibility'=>['state'=>'not_required','visible'=>true],'result'=>$result,'error_code'=>(string)($receipt['error_code']??''),'updated_at'=>(string)($trace['local_finished_at']??gmdate('c'))]);
    }

    private function tableContextInput(array $data,bool $allowPublicId): array
    {
        // API requests must stay cheap under LAN fan-out. Validate the same QR/table
        // capability directly against canonical Local state rather than rebuilding the
        // complete published Guest catalog for every order/status/watch request.
        $token=trim((string)($data['table_token']??''));$id=$allowPublicId?(int)($data['public_table_id']??0):0;
        if($token!==''&&strlen($token)<=80){
            $q=$this->pdo->prepare('SELECT id,name,code,access_token FROM cafe_tables WHERE access_token=? AND active=1 LIMIT 1');$q->execute([$token]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($row))return ['table'=>['id'=>(int)$row['id'],'name'=>(string)$row['name'],'code'=>(string)$row['code'],'token'=>(string)$row['access_token']]];
        }elseif($token===''&&$id>0){
            $q=$this->pdo->prepare('SELECT id,name,code,access_token FROM cafe_tables WHERE id=? AND active=1 LIMIT 1');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(is_array($row))return ['table'=>['id'=>(int)$row['id'],'name'=>(string)$row['name'],'code'=>(string)$row['code'],'token'=>(string)$row['access_token']]];
        }
        return ['response'=>$this->response(404,['success'=>false,'code'=>'invalid_qr','message'=>$token===''&&$id>0?'میز انتخاب‌شده فعال نیست.':'کد میز معتبر نیست.'])];
    }

    private function domain(callable $fn): array
    {
        try{$value=$fn();return $this->response(200,is_array($value)?$value:[]);}catch(GuestOrderException|WaiterCallException $e){$body=['success'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage()];if($e->details!==[])$body['details']=$e->details;return $this->response($e->httpStatus,$body);}catch(Throwable){return $this->response(500,['success'=>false,'code'=>'local_guest_error','message'=>'عملیات مهمان در سامانه محلی کامل نشد.']);}
    }

    private function changeCursor(): string
    {
        // Keep the LAN long-poll cheap: the projection outbox is Local-owned and every
        // operational Guest mutation already advances its monotonic sequence. Using the
        // global high-water mark may cause harmless extra refreshes for unrelated Local
        // changes, but it avoids rebuilding Guest/read models for every waiting browser.
        $sequence=(int)$this->pdo->query('SELECT COALESCE(MAX(sequence_id),0) FROM public_projection_outbox')->fetchColumn();
        return 'gl2-'.$sequence;
    }

    private static function canonicalJson(mixed $value): string
    { $json=json_encode(self::normalize($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);return is_string($json)?$json:'null'; }
    private static function normalize(mixed $value): mixed
    { if(!is_array($value))return $value;if(array_is_list($value))return array_map([self::class,'normalize'],$value);ksort($value,SORT_STRING);foreach($value as $key=>$child)$value[$key]=self::normalize($child);return $value; }
    private function errorStatus(string $code): int
    { return match($code){'invalid_qr','order_not_found'=>404,'not_owner','actor_invalid'=>403,'page_expired'=>419,'ordering_paused'=>423,'rate_limited'=>429,'invalid_context','invalid_order','invalid_tracking','invalid_action','invalid_request','invalid_submission'=>422,'items_unavailable','prices_changed','service_unavailable','session_inactive','order_changed','order_not_editable','pending_order_exists','itemized_settlement_active','settlement_pending','takeaway_not_allowed','request_id_conflict'=>409,default=>409}; }
    private function response(int $status,array $body): array{return ['status'=>$status,'body'=>$body];}
}
