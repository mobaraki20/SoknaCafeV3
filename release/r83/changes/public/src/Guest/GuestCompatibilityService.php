<?php
declare(strict_types=1);

namespace Sokna\PublicEdge\Guest;

use Sokna\PublicEdge\Core\CanonicalJson;
use Sokna\PublicEdge\Realtime\RealtimeService;
use Sokna\PublicEdge\Remote\RemoteReadModelService;

final class GuestCompatibilityService
{
    public function __construct(
        private readonly GuestRuntimeService $runtime,
        private readonly RealtimeService $realtime,
        private readonly RemoteReadModelService $readModels,
    ) {
    }

    public function createOrder(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];
        $state = $this->runtime->actionState($bundle);
        if (($state['enabled'] ?? false) !== true) return $this->localUnavailable();

        $client = trim((string)($data['client_token'] ?? ''));
        $device = trim((string)($data['device_token'] ?? ''));
        if (strlen($client) < 16 || strlen($client) > 80 || strlen($device) > 80) {
            return $this->response(422, ['success' => false, 'code' => 'invalid_order', 'message' => 'اطلاعات سفارش معتبر نیست.']);
        }
        $payload = [
            'table_token' => (string)$table['token'],
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => $device,
            'client_token' => $client,
            'customer_note' => (string)($data['customer_note'] ?? ''),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
        $requestId = self::requestId('guest_order.submit', [
            'client_token' => $client,
            'table_token' => (string)$table['token'],
        ]);
        return $this->relay($installationId, 'guest_order.submit', $payload, $requestId, true, 45, 0);
    }

    public function guestOrders(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $table = $context['table'];

        $action = trim((string)($data['action'] ?? 'list'));
        $base = [
            'table_token' => (string)$table['token'],
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => trim((string)($data['device_token'] ?? '')),
        ];
        if ($base['device_token'] === '' || strlen($base['device_token']) > 80) {
            return $this->response(422, ['success' => false, 'code' => 'invalid_context', 'message' => 'اطلاعات میز یا دستگاه معتبر نیست.']);
        }

        if ($action === 'submission_status') {
            $requestId=trim((string)($data['request_id']??''));$client=trim((string)($data['client_token']??''));
            if($client===''||strlen($client)>80||strlen($requestId)>120)return $this->response(422,['success'=>false,'code'=>'invalid_submission','message'=>'شناسه پیگیری ارسال معتبر نیست.']);
            if($requestId==='')$requestId=self::requestId('guest_order.submit',['client_token'=>$client,'table_token'=>(string)$table['token']]);
            $seed=$base['device_token']!==''?$base['device_token']:$client;$session=['installation_id'=>trim($installationId),'projection_id'=>'guest:'.substr(hash('sha256',trim($installationId).'|'.$seed),0,32),'capabilities'=>[]];
            $result=$this->realtime->result($session,$requestId);$status=(int)($result['status']??500);$body=is_array($result['body']??null)?$result['body']:[];
            if($status===404)return $this->response(404,['success'=>false,'code'=>'submission_not_found','message'=>'ارسال پیدا نشد.']);
            if($status<200||$status>=300)return $this->response($status,['success'=>false,'code'=>(string)($body['error']??'submission_unavailable'),'message'=>'وضعیت ارسال قابل خواندن نیست.']);
            return $this->response(200,['success'=>true,'request_id'=>$requestId,'trace_id'=>(string)($body['trace_id']??''),'trace'=>is_array($body['trace']??null)?$body['trace']:[],'state'=>(string)($body['state']??''),'terminal'=>(bool)($body['terminal']??false),'business_terminal'=>(bool)($body['business_terminal']??$body['terminal']??false),'visibility'=>is_array($body['visibility']??null)?$body['visibility']:['state'=>'not_required','visible'=>true],'result'=>is_array($body['result']??null)?$body['result']:[],'error_code'=>(string)($body['error_code']??''),'updated_at'=>(string)($body['updated_at']??'')]);
        }

        if ($action === 'list') {
            $projection=$this->guestOrderListFromProjection($installationId,$table,$base);
            if($projection!==null)return $projection;
            // Staggered-rollout fallback only. Once H242 projection exists, ordinary Guest reads
            // never wait for a Public->Local round trip.
            $requestId = self::requestId('guest_order.list', [
                'table_token' => $base['table_token'],
                'device_token' => $base['device_token'],
            ], false);
            return $this->relay($installationId, 'guest_order.list', $base + ['action' => 'list'], $requestId, false, 30, 7000);
        }

        if (!in_array($action, ['update', 'cancel'], true)) {
            return $this->response(422, ['success' => false, 'code' => 'invalid_action', 'message' => 'عملیات سفارش معتبر نیست.']);
        }

        $payload = $base + [
            'action' => $action,
            'order_code' => trim((string)($data['order_code'] ?? '')),
            'expected_signature' => trim((string)($data['expected_signature'] ?? '')),
            'customer_note' => (string)($data['customer_note'] ?? ''),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
        $kind = $action === 'update' ? 'order.edit' : 'order.cancel';
        $requestId = self::requestId($kind, [
            'order_code' => $payload['order_code'],
            'device_token' => $payload['device_token'],
            'expected_signature' => $payload['expected_signature'],
            'customer_note' => $payload['customer_note'],
            'items' => $payload['items'],
        ]);
        return $this->relay($installationId, $kind, $payload, $requestId, false, 45, 12000);
    }

    public function orderQuote(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];
        $quoteMode = (string)($data['quote_mode'] ?? 'create');
        $requireOrderIntake = $quoteMode !== 'update';
        if (!$this->runtime->relayReady($bundle, $requireOrderIntake)) {
            return $this->response(503, [
                'success' => false,
                'code' => 'local_unavailable',
                'message' => 'پیش‌نمایش مالی سفارش فعلاً در دسترس نیست؛ چند لحظه بعد دوباره تلاش کنید.',
            ]);
        }

        $payload = [
            'quote_mode' => $quoteMode,
            'table_token' => (string)$table['token'],
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => trim((string)($data['device_token'] ?? '')),
            'client_token' => trim((string)($data['client_token'] ?? '')),
            'order_code' => trim((string)($data['order_code'] ?? '')),
            'expected_signature' => trim((string)($data['expected_signature'] ?? '')),
            'customer_note' => (string)($data['customer_note'] ?? ''),
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
        $requestId = self::requestId('guest_order.quote', [
            'mode' => $payload['quote_mode'],
            'table_token' => $payload['table_token'],
            'device_token' => $payload['device_token'],
            'order_code' => $payload['order_code'],
            'expected_signature' => $payload['expected_signature'],
            'items' => $payload['items'],
        ], false);
        return $this->relay($installationId, 'guest_order.quote', $payload, $requestId, false, 30, 8000);
    }

    public function orderStatus(string $installationId, array $data): array
    {
        $payload = [
            'order_code' => trim((string)($data['order_code'] ?? '')),
            'client_token' => trim((string)($data['client_token'] ?? '')),
        ];
        if($payload['order_code']===''||$payload['client_token']===''||strlen($payload['order_code'])>32||strlen($payload['client_token'])>80){
            return $this->response(422,['success'=>false,'code'=>'invalid_tracking','message'=>'اطلاعات پیگیری معتبر نیست.']);
        }
        $projection=$this->guestOrderStatusFromProjection($installationId,$payload);
        if($projection!==null)return $projection;
        $requestId = self::requestId('guest_order.status', $payload, false);
        return $this->relay($installationId, 'guest_order.status', $payload, $requestId, false, 30, 7000);
    }

    public function tableContext(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, false);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];
        $device=trim((string)($data['device_token']??''));
        if(strlen($device)>80)return $this->response(422,['success'=>false,'code'=>'invalid_qr','message'=>'کد میز معتبر نیست.']);

        $view=$this->guestProjection($installationId);
        if($view!==null){
            $payload=(array)($view['payload']??[]);$tableState=(array)(($payload['tables']??[])[(string)(int)($table['id']??0)]??[]);
            $active=is_array($tableState['active_session']??null)?$tableState['active_session']:null;
            $pending=is_array($tableState['pending_session']??null)?$tableState['pending_session']:null;
            $state=$this->runtime->actionState($bundle);$live=($state['command_ready']??$state['mutation_ready']??false)===true;
            $orderReady=($state['order_ready']??$state['enabled']??false)===true;
            $orderIntakeEnabled=($state['order_intake_enabled']??false)===true;
            $acceptance=is_array($payload['order_acceptance']??null)?$payload['order_acceptance']:['cafe'=>false,'kitchen'=>false,'bar'=>false];
            $messages=is_array($payload['order_acceptance_messages']??null)?$payload['order_acceptance_messages']:[
                'cafe'=>'سفارش‌گیری فعلاً در دسترس نیست.','kitchen'=>'آشپزخانه فعلاً در دسترس نیست.','bar'=>'بار فعلاً در دسترس نیست.',
            ];
            if(!$live){$messages['cafe']='ارتباط زنده با کافه موقتاً در دسترس نیست؛ منو همچنان قابل مشاهده است.';}
            $known=array_values(array_map('strval',is_array($tableState['known_device_hashes']??null)?$tableState['known_device_hashes']:[]));
            $deviceHash=$device===''?'':hash('sha256',$device);$isKnown=$deviceHash!==''&&in_array($deviceHash,$known,true);
            $late=false;
            if($active!==null&&$deviceHash!==''&&!$isKnown){
                $minutes=max(5,(int)($payload['new_device_alert_minutes']??20));$started=strtotime((string)($active['started_at']??''))?:time();
                $late=time()-$started>$minutes*60;
                if($live){
                    $requestId=self::requestId('guest_table.context',[
                        'table_token'=>(string)$table['token'],'device_token'=>$device,'session_token'=>(string)($active['token']??''),
                    ]);
                    $this->enqueueGuestBackground($installationId,'guest_table.context',[
                        'table_token'=>(string)$table['token'],'device_token'=>$device,
                    ],$requestId,30);
                }
            }
            $modelStale=(bool)($view['stale']??false);
            $acceptanceHardGate=!$modelStale;
            $cafeAccepting=!empty($acceptance['cafe']);
            return $this->response(200,[
                'success'=>true,
                'table'=>['id'=>(int)($table['id']??0),'name'=>(string)($table['name']??''),'code'=>(string)($table['code']??'')],
                'session'=>$active===null?null:['token'=>(string)($active['token']??''),'started_at'=>(string)($active['started_at']??''),'status'=>(string)($active['status']??'active')],
                'pending_session'=>$pending===null?null:['token'=>(string)($pending['token']??''),'started_at'=>(string)($pending['started_at']??''),'status'=>(string)($pending['status']??'pending')],
                'can_order'=>$orderReady&&(!$acceptanceHardGate||$cafeAccepting),'order_policy_ready'=>$orderIntakeEnabled&&(!$acceptanceHardGate||$cafeAccepting),'order_intake_enabled'=>$orderIntakeEnabled,'mutation_ready'=>$live,'command_ready'=>$live,'order_ready'=>$orderReady,'model_stale'=>$modelStale,'order_acceptance_fresh'=>!$modelStale,'order_acceptance'=>$acceptance,'order_acceptance_messages'=>$messages,
                'station_states'=>is_array($bundle['availability']['station_states']??null)?$bundle['availability']['station_states']:[],
                'station_state_hash'=>(string)($bundle['availability']['station_state_hash']??''),
                'waiter_policy_enabled'=>$modelStale||!empty($payload['waiter_enabled_table']),
                'waiter_enabled'=>$live&&($modelStale||!empty($payload['waiter_enabled_table'])),
                'active_call'=>is_array($tableState['active_call']??null)?$tableState['active_call']:null,
                'late_join'=>$late,
                'requires_operator_confirmation'=>!empty($bundle['snapshot']['features']['table_sessions_enabled'])&&$active===null,
                'degraded'=>(bool)($view['stale']??false)||!$live,
                'last_sync_at'=>(string)($view['last_sync_at']??''),
            ]);
        }

        // Transition fallback for Public upgraded before Local222 has published guest_operational.
        $payload = ['table_token'=>(string)$table['token'],'device_token'=>$device];
        $state = $this->runtime->actionState($bundle);
        if (($state['local_fresh'] ?? false) === true) {
            $requestId = self::requestId('guest_table.context', ['table_token'=>$payload['table_token'],'device_token'=>$payload['device_token']], false);
            $relay = $this->relay($installationId, 'guest_table.context', $payload, $requestId, false, 30, 6500);
            if (($relay['status'] ?? 500) === 200 && (($relay['body']['success'] ?? false) === true)) return $relay;
        }
        return $this->tableContextFromAvailability($bundle,$table,$state);
    }

    public function changes(string $installationId,array $data): array
    {
        $cursor=trim((string)($data['cursor']??''));if(strlen($cursor)>128)$cursor='';
        $waitMs=max(0,min(10000,(int)($data['wait_ms']??9000)));
        $result=$this->readModels->watchInternal($installationId,['guest_operational'],$cursor,$waitMs);
        if((int)($result['status']??500)===200&&is_array($result['body']??null)){
            $body=(array)$result['body'];$body['success']=($body['ok']??false)===true;
            $bundle=$this->runtime->bundle($installationId);$state=$bundle===[]?[]:$this->runtime->actionState($bundle);
            $body['mutation_ready']=($state['command_ready']??$state['mutation_ready']??false)===true;
            $body['order_ready']=($state['order_ready']??$state['enabled']??false)===true;
            $body['order_intake_enabled']=($state['order_intake_enabled']??false)===true;
            $acceptance=is_array($bundle['availability']['order_acceptance']??null)?$bundle['availability']['order_acceptance']:[];
            $body['model_stale']=($body['stale']??true)===true;
            $body['order_acceptance_fresh']=!$body['model_stale'];
            $body['order_policy_ready']=$body['order_intake_enabled']&&($body['model_stale']||!empty($acceptance['cafe']));
            $result['body']=$body;
        }
        return $result;
    }

    public function waiterCall(string $installationId, array $data): array
    {
        $context = $this->bundleAndTable($installationId, $data, true);
        if (isset($context['response'])) return $context['response'];
        $bundle = $context['bundle'];
        $table = $context['table'];
        $action = trim((string)($data['action'] ?? 'status'));
        $isPublic = trim((string)($data['table_token'] ?? '')) === '' && (int)($data['public_table_id'] ?? 0) > 0;

        $payload = [
            'table_token' => (string)$table['token'],
            'public_context' => $isPublic,
            'session_token' => trim((string)($data['session_token'] ?? '')),
            'device_token' => trim((string)($data['device_token'] ?? '')),
            'client_token' => trim((string)($data['client_token'] ?? '')),
            'call_code' => trim((string)($data['call_code'] ?? '')),
        ];

        if ($action === 'create') {
            $state = $this->runtime->actionState($bundle);
            $policyFresh=($state['model_fresh']??$state['availability_fresh']??false)===true;
            $allowed = !$policyFresh || ($isPublic ? !empty($bundle['availability']['waiter_enabled_public']) : !empty($bundle['availability']['waiter_enabled_table']));
            if (($state['command_ready'] ?? $state['mutation_ready'] ?? false) !== true) return $this->localUnavailable();
            if (!$allowed) return $this->response(409, ['success' => false, 'code' => 'waiter_disabled', 'message' => 'فراخوان گارسون فعلاً فعال نیست.']);
            $client = $payload['client_token'];
            if (strlen($client) < 16 || strlen($client) > 80) {
                return $this->response(422, ['success' => false, 'code' => 'invalid_request', 'message' => 'درخواست کامل نیست.']);
            }
            $requestId = self::requestId('waiter_call.create', [
                'client_token' => $client,
                'table_token' => $payload['table_token'],
            ]);
            return $this->relay($installationId, 'waiter_call.create', $payload, $requestId, false, 45, 12000);
        }

        if ($action === 'cancel') {
            $requestId = self::requestId('waiter_call.cancel', [
                'call_code' => $payload['call_code'],
                'client_token' => $payload['client_token'],
                'table_token' => $payload['table_token'],
            ]);
            return $this->relay($installationId, 'waiter_call.cancel', $payload, $requestId, false, 30, 8000);
        }

        if ($action === 'status') {
            if ($payload['call_code'] === '') return $this->response(422, ['success'=>false,'code'=>'invalid_tracking','message'=>'رسید فراخوان معتبر نیست.']);
            $view=$this->guestProjection($installationId);
            // A lagging snapshot cannot prove that a tracked waiter call has
            // ended or was never created. Relay the read-only status to Local
            // when available; otherwise keep the caller's last known state.
            $confirmed=$view===null?null:self::waiterProjectionStatus($view,(int)($table['id']??0),$payload['call_code']);
            if($confirmed!==null)return $this->response(200,['success'=>true,'call_code'=>$payload['call_code'],'status'=>$confirmed,'owned'=>true,'degraded'=>false,'last_sync_at'=>(string)($view['last_sync_at']??'')]);
            $requestId = self::requestId('waiter_call.status', [
                'call_code' => $payload['call_code'],
                'table_token' => $payload['table_token'],
            ], false);
            return $this->relay($installationId, 'waiter_call.status', $payload, $requestId, false, 30, 7000);
        }

        return $this->response(422, ['success' => false, 'code' => 'invalid_action', 'message' => 'عملیات فراخوان معتبر نیست.']);
    }

    /**
     * An active-only snapshot can prove presence, never absence of a tracked call.
     * Null means unresolved: the caller should query Local if possible and must
     * not erase a persisted waiter receipt on a stale/missing read model.
     */
    public static function waiterProjectionStatus(array $view, int $tableId, string $callCode): ?string
    {
        if(($view['stale']??true)!==false||$tableId<=0||trim($callCode)==='')return null;
        $payload=is_array($view['payload']??null)?$view['payload']:[];
        $tables=is_array($payload['tables']??null)?$payload['tables']:[];
        $table=is_array($tables[(string)$tableId]??null)?$tables[(string)$tableId]:[];
        $active=is_array($table['active_call']??null)?$table['active_call']:null;
        if($active!==null&&hash_equals((string)($active['public_code']??''),$callCode)){
            $status=(string)($active['status']??'');
            if(in_array($status,['new','accepted'],true))return $status;
            return null; // Unknown state must never masquerade as a completed call.
        }
        // This active-only projection carries no terminal receipt or causal watermark
        // for the tracked call. Even a recently synced snapshot can predate its commit.
        // Resolve absence at Local; never erase the guest receipt from elapsed age alone.
        return null;
    }

    public function metric(): array
    {
        return $this->response(200, ['success' => true]);
    }

    public static function requestId(string $kind, array $identity, bool $stable = true): string
    {
        $prefix = preg_replace('/[^a-z0-9]+/i', '-', strtolower($kind)) ?: 'guest';
        $seed = CanonicalJson::encode($identity);
        if (!$stable) $seed .= '|' . bin2hex(random_bytes(12));
        return substr($prefix, 0, 30) . ':' . substr(hash('sha256', $seed), 0, 56);
    }

    /** @return array{payload:array<string,mixed>,stale:bool,last_sync_at:string,connectivity:array<string,mixed>}|null */
    private function guestProjection(string $installationId): ?array
    {
        $read=$this->readModels->readInternal($installationId,'guest_operational',30);
        if((int)($read['status']??500)!==200||($read['body']['ok']??false)!==true)return null;
        $body=(array)$read['body'];
        return [
            'payload'=>is_array($body['payload']??null)?$body['payload']:[],
            'stale'=>(bool)($body['stale']??true),
            'last_sync_at'=>(string)($body['last_sync_at']??''),
            'connectivity'=>is_array($body['connectivity']??null)?$body['connectivity']:[],
        ];
    }

    private function guestOrderListFromProjection(string $installationId,array $table,array $base): ?array
    {
        $view=$this->guestProjection($installationId);if($view===null)return null;
        $payload=$view['payload'];$tableId=(int)($table['id']??0);$state=(array)(($payload['tables']??[])[(string)$tableId]??[]);
        $requested=trim((string)($base['session_token']??''));$active=is_array($state['active_session']??null)?$state['active_session']:null;$pending=is_array($state['pending_session']??null)?$state['pending_session']:null;
        $session=null;
        if($requested!==''){
            foreach([$active,$pending] as $candidate)if(is_array($candidate)&&hash_equals((string)($candidate['token']??''),$requested)){$session=$candidate;break;}
        }else{$session=$active??$pending;}
        // During projection lag an absent session does not prove an empty account.
        // Use the Local read-only route when online; fail visibly when offline.
        if($session===null&&($view['stale']??true)!==false)return null;
        if($session===null)return $this->response(200,['success'=>true,'session_status'=>null,'orders'=>[],'degraded'=>false,'last_sync_at'=>$view['last_sync_at']]);
        $deviceHash=hash('sha256',(string)$base['device_token']);$sessionId=(int)($session['id']??0);$orders=[];
        foreach(is_array($payload['orders']??null)?$payload['orders']:[] as $entry){
            if(!is_array($entry)||(int)($entry['session_id']??0)!==$sessionId||!hash_equals($deviceHash,(string)($entry['device_hash']??'')))continue;
            $public=is_array($entry['public']??null)?$entry['public']:[];if($public!==[])$orders[]=$public;
        }
        usort($orders,static fn(array $a,array $b):int=>strcmp((string)($a['created_at']??''),(string)($b['created_at']??''))?:((int)($a['order_number']??0)<=> (int)($b['order_number']??0)));
        return $this->response(200,['success'=>true,'session_status'=>(string)($session['status']??null),'orders'=>$orders,'degraded'=>(bool)$view['stale'],'last_sync_at'=>$view['last_sync_at']]);
    }

    private function guestOrderStatusFromProjection(string $installationId,array $request): ?array
    {
        $view=$this->guestProjection($installationId);if($view===null)return null;
        $code=(string)$request['order_code'];$tracking=hash('sha256',$code.'|'.(string)$request['client_token']);
        foreach(is_array($view['payload']['orders']??null)?$view['payload']['orders']:[] as $entry){
            if(!is_array($entry)||!hash_equals($tracking,(string)($entry['tracking_hash']??'')))continue;
            $public=is_array($entry['public']??null)?$entry['public']:[];
            if(!hash_equals($code,(string)($public['order_code']??'')))continue;
            if((string)($entry['session_status']??'')==='closed')return $this->response(200,[
                'success'=>true,'expired'=>true,'status'=>'session_closed','status_label'=>'نشست این میز پایان یافته است.',
                'updated_at'=>(string)($entry['session_ended_at']??($public['updated_at']??'')),'degraded'=>(bool)$view['stale'],'last_sync_at'=>$view['last_sync_at'],
            ]);
            return $this->response(200,[
                'success'=>true,'status'=>(string)($public['status']??''),'status_label'=>(string)($public['status_label']??''),
                'accepted'=>(bool)($entry['accepted']??false),'updated_at'=>(string)($public['updated_at']??''),
                'degraded'=>(bool)$view['stale'],'last_sync_at'=>$view['last_sync_at'],
            ]);
        }
        // The Guest model is deliberately bounded, and a lagging snapshot can
        // omit recent orders. Absence is not proof of nonexistence even when
        // Public is the only online host. Never return a definitive 404 here.
        if(($view['connectivity']['local_fresh']??false)===true)return null;
        return $this->response(503,['success'=>false,'code'=>'order_tracking_unconfirmed','message'=>'وضعیت این سفارش هنوز قابل تأیید نیست؛ بعداً دوباره بررسی کن.','degraded'=>true,'last_sync_at'=>$view['last_sync_at']]);
    }

    private function enqueueGuestBackground(string $installationId,string $kind,array $payload,string $requestId,int $ttlSeconds): void
    {
        $device=trim((string)($payload['device_token']??''));$client=trim((string)($payload['client_token']??''));$seed=$device!==''?$device:($client!==''?$client:$requestId);
        $now=time();$envelope=[
            'request_id'=>$requestId,'kind'=>$kind,'created_at'=>gmdate('c',$now),'expires_at'=>gmdate('c',$now+max(10,min(120,$ttlSeconds))),
            'actor_projection_id'=>'guest:'.substr(hash('sha256',trim($installationId).'|'.$seed),0,32),'payload'=>$payload,
        ];
        try{$this->realtime->enqueueGuest($installationId,$envelope);}catch(\Throwable){}
    }

    private function tableContextFromAvailability(array $bundle,array $table,array $state): array
    {
        $availability=is_array($bundle['availability']??null)?$bundle['availability']:[];
        $acceptance=is_array($availability['order_acceptance']??null)?$availability['order_acceptance']:['cafe'=>false,'kitchen'=>false,'bar'=>false];
        $messages=is_array($availability['order_acceptance_messages']??null)?$availability['order_acceptance_messages']:[
            'cafe'=>'سفارش‌گیری فعلاً در دسترس نیست.','kitchen'=>'آشپزخانه فعلاً در دسترس نیست.','bar'=>'بار فعلاً در دسترس نیست.',
        ];
        $commandReady=($state['command_ready']??$state['mutation_ready']??false)===true;$orderReady=($state['order_ready']??$state['enabled']??false)===true;$orderIntakeEnabled=($state['order_intake_enabled']??false)===true;$modelStale=($state['model_fresh']??$state['availability_fresh']??false)!==true;$cafeAccepting=!empty($acceptance['cafe']);
        if(!$commandReady){$messages['cafe']='ارتباط زنده با کافه موقتاً در دسترس نیست؛ منو همچنان قابل مشاهده است.';}
        $tableStates=is_array($availability['tables']??null)?$availability['tables']:[];$tableState=is_array($tableStates[(string)(int)($table['id']??0)]??null)?$tableStates[(string)(int)($table['id']??0)]:[];
        $session=is_array($tableState['session']??null)?$tableState['session']:null;$pending=is_array($tableState['pending_session']??null)?$tableState['pending_session']:null;$activeCall=is_array($tableState['active_call']??null)?$tableState['active_call']:null;
        return $this->response(200,[
            'success'=>true,'table'=>['id'=>(int)($table['id']??0),'name'=>(string)($table['name']??''),'code'=>(string)($table['code']??'')],
            'session'=>$session?['token'=>(string)($session['token']??''),'started_at'=>(string)($session['started_at']??''),'status'=>(string)($session['status']??'active')]:null,
            'pending_session'=>$pending?['token'=>(string)($pending['token']??''),'started_at'=>(string)($pending['started_at']??''),'status'=>(string)($pending['status']??'pending')]:null,
            'can_order'=>$orderReady&&($modelStale||$cafeAccepting),'order_policy_ready'=>$orderIntakeEnabled&&($modelStale||$cafeAccepting),'order_intake_enabled'=>$orderIntakeEnabled,'mutation_ready'=>$commandReady,'command_ready'=>$commandReady,'order_ready'=>$orderReady,'model_stale'=>$modelStale,'order_acceptance_fresh'=>!$modelStale,'order_acceptance'=>$acceptance,'order_acceptance_messages'=>$messages,
            'station_states'=>is_array($availability['station_states']??null)?$availability['station_states']:[],'station_state_hash'=>(string)($availability['station_state_hash']??''),
            'waiter_policy_enabled'=>$modelStale||!empty($availability['waiter_enabled_table']),'waiter_enabled'=>$commandReady&&($modelStale||!empty($availability['waiter_enabled_table'])),'active_call'=>$activeCall,
            'late_join'=>false,'requires_operator_confirmation'=>!empty($bundle['snapshot']['features']['table_sessions_enabled'])&&$session===null,'degraded'=>true,
        ]);
    }

    private function relay(
        string $installationId,
        string $kind,
        array $payload,
        string $requestId,
        bool $requireOrderIntake,
        int $ttlSeconds,
        int $waitMilliseconds,
    ): array {
        $bundle = $this->runtime->bundle($installationId);
        if ($bundle === [] || empty($bundle['snapshot'])) {
            return $this->response(503, ['success' => false, 'code' => 'guest_menu_unpublished', 'message' => 'منوی عمومی در دسترس نیست.']);
        }
        if (!$this->liveState($bundle, $requireOrderIntake)) return $this->localUnavailable();

        $device = trim((string)($payload['device_token'] ?? ''));
        $client = trim((string)($payload['client_token'] ?? ''));
        $actorSeed = $device !== '' ? $device : ($client !== '' ? $client : $requestId);
        $actorProjectionId = 'guest:' . substr(hash('sha256', trim($installationId) . '|' . $actorSeed), 0, 32);
        $now = time();
        $envelope = [
            'request_id' => $requestId,
            'kind' => $kind,
            'created_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + max(10, min(120, $ttlSeconds))),
            'actor_projection_id' => $actorProjectionId,
            'payload' => $payload,
        ];

        $queued = $this->realtime->enqueueGuest($installationId, $envelope);
        if (($queued['body']['ok'] ?? false) !== true) {
            return $this->response((int)($queued['status'] ?? 409), [
                'success' => false,
                'code' => (string)($queued['body']['error'] ?? 'relay_rejected'),
                'message' => 'درخواست قابل ثبت نیست.',
            ]);
        }

        $session = [
            'installation_id' => trim($installationId),
            'projection_id' => $actorProjectionId,
            'capabilities' => [],
        ];
        if($waitMilliseconds<=0){
            $result=$this->realtime->result($session,$requestId);$rb=is_array($result['body']??null)?$result['body']:[];
            if(($result['status']??500)===200&&($rb['terminal']??false)===true){
                $pendingVisibility=(string)($rb['state']??'')==='committed'&&(string)(($rb['visibility']['state']??'not_required'))==='pending';
                if(!$pendingVisibility)return $this->terminalResponse($rb);
                return $this->response(202,[
                    'success'=>true,'accepted'=>true,'terminal'=>false,'business_terminal'=>true,'request_id'=>$requestId,'trace_id'=>(string)($rb['trace_id']??$queued['body']['trace_id']??''),'trace'=>is_array($rb['trace']??null)?$rb['trace']:[],'state'=>'committed',
                    'visibility'=>is_array($rb['visibility']??null)?$rb['visibility']:[],'result'=>is_array($rb['result']??null)?$rb['result']:[],
                    'status'=>'syncing_display','status_label'=>'ثبت شد؛ نمایش در حال تازه‌شدن است','client_token'=>(string)($payload['client_token']??''),
                ]);
            }
            return $this->response(202,[
                'success'=>true,'accepted'=>true,'terminal'=>false,'business_terminal'=>false,'request_id'=>$requestId,'trace_id'=>(string)($queued['body']['trace_id']??''),'state'=>(string)($queued['body']['state']??'queued'),
                'status'=>'submitting','status_label'=>'در حال ارسال به کافه','client_token'=>(string)($payload['client_token']??''),
            ]);
        }

        $deadline = microtime(true) + (max(0, $waitMilliseconds) / 1000);$committedPending=null;
        do {
            $result = $this->realtime->result($session, $requestId);$rb=is_array($result['body']??null)?$result['body']:[];
            if (($result['status'] ?? 500) === 200 && ($rb['terminal'] ?? false) === true) {
                $pendingVisibility=(string)($rb['state']??'')==='committed'&&(string)(($rb['visibility']['state']??'not_required'))==='pending';
                if(!$pendingVisibility)return $this->terminalResponse($rb);
                $committedPending=$rb;
            }
            if (microtime(true) >= $deadline) break;
            usleep(120000);
        } while (true);

        if(is_array($committedPending)){
            $canonical=is_array($committedPending['result']??null)?$committedPending['result']:[];
            return $this->response(200,array_merge($canonical,[
                'trace_id'=>(string)($committedPending['trace_id']??''),'trace'=>is_array($committedPending['trace']??null)?$committedPending['trace']:[],
                'visibility_pending'=>true,'visibility'=>is_array($committedPending['visibility']??null)?$committedPending['visibility']:[],
                'message'=>'عملیات در کافه ثبت شد؛ نمایش عمومی هنوز در حال تازه‌شدن است.',
            ]));
        }
        return $this->response(504, [
            'success' => false,
            'code' => 'relay_timeout',
            'message' => 'نتیجه درخواست هنوز قطعی نیست؛ با همان عملیات دوباره تلاش نکن؛ چند لحظه بعد وضعیت را تازه کن.',
        ]);
    }

    private function terminalResponse(array $body): array
    {
        $state = (string)($body['state'] ?? '');
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        $code = (string)($body['error_code'] ?? '');
        $trace=['trace_id'=>(string)($body['trace_id']??''),'trace'=>is_array($body['trace']??null)?$body['trace']:[],'visibility'=>is_array($body['visibility']??null)?$body['visibility']:[]];

        if ($state === 'committed') return $this->response(200, array_merge($result,$trace));
        if ($state === 'expired') {
            return $this->response(504, [
                'success' => false,
                'code' => 'request_expired',
                'message' => 'زمان پاسخ کافه تمام شد؛ دوباره تلاش کنید.',
            ]);
        }
        if ($result === []) {
            $resolvedCode = $code !== '' ? $code : 'request_rejected';
            $result = ['success' => false, 'code' => $resolvedCode, 'message' => $this->fallbackErrorMessage($resolvedCode)];
        }
        return $this->response($this->errorStatus((string)($result['code'] ?? $code)), array_merge($result,$trace));
    }

    private function bundleAndTable(string $installationId, array $data, bool $allowPublicId): array
    {
        $bundle = $this->runtime->bundle(trim($installationId));
        if ($bundle === [] || empty($bundle['snapshot'])) {
            return ['response' => $this->response(503, ['success' => false, 'code' => 'guest_menu_unpublished', 'message' => 'منوی عمومی در دسترس نیست.'])];
        }
        $table = $this->findTable((array)$bundle['snapshot'], $data, $allowPublicId);
        if ($table === null) {
            $isPublic = trim((string)($data['table_token'] ?? '')) === '' && (int)($data['public_table_id'] ?? 0) > 0;
            return ['response' => $this->response(404, [
                'success' => false,
                'code' => 'invalid_qr',
                'message' => $isPublic ? 'میز انتخاب‌شده فعال نیست.' : 'کد میز معتبر نیست.',
            ])];
        }
        return ['bundle' => $bundle, 'table' => $table];
    }

    private function findTable(array $snapshot, array $data, bool $allowPublicId): ?array
    {
        $token = trim((string)($data['table_token'] ?? ''));
        if ($token !== '') {
            foreach (($snapshot['tables'] ?? []) as $row) {
                if (is_array($row) && isset($row['token']) && hash_equals((string)$row['token'], $token)) return $row;
            }
            return null;
        }
        if ($allowPublicId) {
            $id = (int)($data['public_table_id'] ?? 0);
            foreach (($snapshot['tables'] ?? []) as $row) {
                if (is_array($row) && (int)($row['id'] ?? 0) === $id) return $row;
            }
        }
        return null;
    }

    private function liveState(array $bundle, bool $requireOrderIntake): bool
    {
        return $this->runtime->relayReady($bundle, $requireOrderIntake);
    }

    private function fallbackErrorMessage(string $code): string
    {
        return match ($code) {
            'pending_order_exists' => 'یک سفارش از همین دستگاه هنوز منتظر تأیید است؛ همان سفارش را باز کن و تغییر بده.',
            'prices_changed' => 'قیمت بعضی آیتم‌ها تغییر کرده است؛ سفارش را تازه کن و دوباره بررسی کن.',
            'session_inactive' => 'نشست این میز دیگر فعال نیست؛ صفحه را تازه کن.',
            'ordering_paused' => 'ثبت سفارش مهمان فعلاً متوقف است.',
            'items_unavailable', 'service_unavailable' => 'بعضی آیتم‌های سفارش در حال حاضر قابل ثبت نیستند.',
            default => 'درخواست انجام نشد؛ دوباره تلاش کنید.',
        };
    }

    private function errorStatus(string $code): int
    {
        return match ($code) {
            'invalid_qr', 'order_not_found' => 404,
            'not_owner' => 403,
            'page_expired' => 419,
            'ordering_paused' => 423,
            'rate_limited' => 429,
            'invalid_context', 'invalid_order', 'invalid_tracking', 'invalid_action' => 422,
            'items_unavailable', 'prices_changed', 'service_unavailable', 'session_inactive',
            'order_changed', 'order_not_editable', 'pending_order_exists', 'itemized_settlement_active',
            'settlement_pending', 'takeaway_not_allowed' => 409,
            default => 409,
        };
    }

    private function localUnavailable(): array
    {
        return $this->response(503, [
            'success' => false,
            'code' => 'local_unavailable',
            'message' => 'ارتباط زنده با کافه موقتاً در دسترس نیست.',
        ]);
    }

    private function response(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
