<?php
declare(strict_types=1);

// Source-actual guest compatibility test with fake *dependencies* only.
// No network, database, installation settings, or production credentials.
namespace Sokna\PublicEdge\Guest {
    final class GuestRuntimeService {
        public function __construct(public bool $online) {}
        public function bundle(string $installation): array {return [
            'snapshot'=>['tables'=>[['id'=>42,'token'=>'table-secret-42','name'=>'میز ۴۲']]],
            'availability'=>[],
        ];}
        public function relayReady(array $bundle, bool $requireOrderIntake): bool {return $this->online;}
    }
}
namespace Sokna\PublicEdge\Remote {
    final class RemoteReadModelService {
        public function __construct(public bool $stale) {}
        public function readInternal(string $id,string $key,int $seconds=90): array {
            $code='ord-42';$client='client-42';$device='device-42';
            return ['status'=>200,'body'=>[
                'ok'=>true,'stale'=>$this->stale,'last_sync_at'=>'2026-11-12 02:00:00',
                'connectivity'=>['local_fresh'=>!$this->stale ? true : true],
                'payload'=>[
                    'tables'=>['42'=>['active_session'=>['id'=>7,'token'=>'session-42','status'=>'active']]],
                    'orders'=>[[
                        'session_id'=>7,'device_hash'=>hash('sha256',$device),
                        'tracking_hash'=>hash('sha256',$code.'|'.$client),
                        'session_status'=>'active','accepted'=>false,
                        'public'=>['order_code'=>$code,'status'=>'pending_approval','status_label'=>'منتظر تأیید',
                            'client_token'=>$client,'order_number'=>17,'created_at'=>'2026-11-12 02:00:00'],
                    ]],
                ],
            ]];
        }
    }
}
namespace Sokna\PublicEdge\Realtime {
    final class RealtimeService {
        public int $enqueueCount=0;
        public int $resultCount=0;
        public bool $failEnqueue=false;
        public bool $reject=false;
        public function enqueueGuest(string $installation,array $env): array {
            $this->enqueueCount++;
            if($this->failEnqueue)return ['status'=>503,'body'=>['ok'=>false,'error'=>'local_unavailable']];
            return ['status'=>202,'body'=>['ok'=>true,'state'=>'queued','trace_id'=>'skt1-'.str_repeat('a',32)]];
        }
        public function result(array $session,string $requestId): array {
            $this->resultCount++;
            if($this->reject)return ['status'=>200,'body'=>['ok'=>true,'state'=>'rejected','terminal'=>true,'result'=>['success'=>false,'code'=>'order_not_found','message'=>'سفارش پیدا نشد.'],'error_code'=>'order_not_found']];
            $kind=str_starts_with($requestId,'guest-order-status')?'status':'list';
            $canonical=$kind==='status'?
                ['success'=>true,'order_code'=>'ord-42','status'=>'accounted','status_label'=>'تأییدشده','accepted'=>true]:
                ['success'=>true,'session_status'=>'active','orders'=>[
                    ['order_code'=>'ord-42','status'=>'accounted','status_label'=>'تأییدشده','order_number'=>17],
                ]];
            return ['status'=>200,'body'=>[
                'ok'=>true,'state'=>'committed','terminal'=>true,'business_terminal'=>true,
                'result'=>$canonical,'visibility'=>['state'=>'not_required','visible'=>true],
                'trace'=>[],'trace_id'=>'skt1-'.str_repeat('a',32),
            ]];
        }
    }
}
namespace {
    $file=$argv[1]??'';
    if(!is_file($file)){fwrite(STDERR,"USAGE php test_public_guest_projection.php /path/to/GuestCompatibilityService.php\n");exit(64);}
    require_once ($argv[2]??dirname(dirname($file)).'/Core/CanonicalJson.php');
    require_once $file;
    $cases=0;$failed=0;
    function verify(bool $test,string $label): void {
        global $cases,$failed;$cases++;
        if(!$test){$failed++;echo "FAIL {$label}\n";}else echo "PASS {$label}\n";
    }
    foreach([true,false] as $stale)foreach([true,false] as $online){
        $rt=new \Sokna\PublicEdge\Guest\GuestRuntimeService($online);
        $relay=new \Sokna\PublicEdge\Realtime\RealtimeService();
        $read=new \Sokna\PublicEdge\Remote\RemoteReadModelService($stale);
        $svc=new \Sokna\PublicEdge\Guest\GuestCompatibilityService($rt,$relay,$read);
        $list=$svc->guestOrders('install-42',[ 'action'=>'list','table_token'=>'table-secret-42','device_token'=>'device-42','session_token'=>'session-42']);
        $status=$svc->orderStatus('install-42',['order_code'=>'ord-42','client_token'=>'client-42']);
        $prefix=($stale?'stale':'fresh').'-'.($online?'local-online':'local-offline');
        $expectedCanonical=$stale&&$online;
        $expectedStatus=$expectedCanonical?'accounted':'pending_approval';
        verify(($list['status']??0)===200,$prefix.'-LIST_HTTP_OK');
        verify(($status['status']??0)===200,$prefix.'-STATUS_HTTP_OK');
        verify((string)($list['body']['orders'][0]['status']??'')===$expectedStatus,$prefix.'-LIST_CURRENT_OR_CACHED');
        verify((string)($status['body']['status']??'')===$expectedStatus,$prefix.'-STATUS_CURRENT_OR_CACHED');
        verify($relay->enqueueCount===($expectedCanonical?2:0),$prefix.'-CANONICAL_RELAY_ONLY_WHEN_NEEDED');
        verify((bool)($status['body']['degraded']??false)===($stale&&!$expectedCanonical),$prefix.'-DEGRADED_EXPLICIT');
    }
    // Fallback to stale cached UI is safe only on transport/availability failure,
    // never when Local rejects the business request definitively.
    foreach (['transport_down','business_rejected'] as $state) {
        $rt=new \Sokna\PublicEdge\Guest\GuestRuntimeService(true);
        $relay=new \Sokna\PublicEdge\Realtime\RealtimeService();
        $relay->failEnqueue=$state==='transport_down';$relay->reject=$state==='business_rejected';
        $read=new \Sokna\PublicEdge\Remote\RemoteReadModelService(true);
        $svc=new \Sokna\PublicEdge\Guest\GuestCompatibilityService($rt,$relay,$read);
        $list=$svc->guestOrders('install-42',['action'=>'list','table_token'=>'table-secret-42','device_token'=>'device-42','session_token'=>'session-42']);
        $status=$svc->orderStatus('install-42',['order_code'=>'ord-42','client_token'=>'client-42']);
        if($state==='transport_down'){
            verify(($list['status']??0)===200 && ($list['body']['degraded']??false)===true, 'STALE_LIST_FALLBACK_WHEN_RELAY_UNAVAILABLE');
            verify(($status['status']??0)===200 && ($status['body']['degraded']??false)===true, 'STALE_STATUS_FALLBACK_WHEN_RELAY_UNAVAILABLE');
        } else {
            verify(($list['status']??0)!==200 && ($list['body']['code']??'')==='order_not_found', 'REJECTED_LIST_NOT_MASKED_BY_STALE_CACHE');
            verify(($status['status']??0)!==200 && ($status['body']['code']??'')==='order_not_found', 'REJECTED_STATUS_NOT_MASKED_BY_STALE_CACHE');
        }
    }
    echo "RESULT {$cases} checks, {$failed} failures\n";
    exit($failed===0?0:1);
}
